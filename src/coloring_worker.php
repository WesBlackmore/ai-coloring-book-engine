<?php

declare(strict_types=1);

/*
 * Coloring book generation as a background job (docs/COLORING-BOOK-LAUNCH-SPEC.md 4.6).
 *
 * It mirrors the text-book worker: the browser only starts the job and polls; cron (and the status
 * poll's kick) does the work, one unit per claim. The stage is derived from the data, so a crashed
 * run resumes where it stopped: plan -> pages -> cover -> cover outline -> finish.
 * Credits: the whole price is reserved at start and captured only when the book is ready. A stall
 * releases the hold, so a customer never pays for a book that did not arrive.
 */

require_once __DIR__ . '/coloring_book.php';
require_once __DIR__ . '/shims.php';
require_once __DIR__ . '/coloring_cover.php';
require_once __DIR__ . '/ratelimit.php';
require_once __DIR__ . '/background_worker_wake.php';

const COLORING_WORKER_LOCK_SECONDS = 300;
const COLORING_BOOK_CREDITS = 35;
const COLORING_COVER_CREDITS = 9;
const COLORING_PAGE_REGEN_CREDITS = 1;
const COLORING_FREE_COVER_REDOS = 2;

/** Pages one worker run may draw before it hands over to the next run (owner approved 12). */
function coloring_worker_page_limit(): int
{
    $configured = (int) getenv('COLORING_WORKER_LIMIT');

    return $configured > 0 ? $configured : 12;
}

function coloring_reservation_key(int $projectId): string
{
    return 'coloring:' . $projectId . ':book';
}

/**
 * Queue the project. The price is "reserved" through the no-op credit shim, so the host sees the same key and amount a
 * paying app would; the standalone engine never refuses a start. The free-first-book branch of the host app is gone.
 */
function coloring_worker_start(int $projectId): array
{
    $pdo = db();
    /* A PDO-owned transaction, the first statement a harmless write on the project row, which takes the SQLite write lock:
       one writer at a time from the status read to the UPDATE, so two callers cannot both queue the same book. */
    $ownsTransaction = !$pdo->inTransaction();
    if ($ownsTransaction) $pdo->beginTransaction();
    try {
        $pdo->prepare('UPDATE coloring_projects SET updated_at = updated_at WHERE id = ?')->execute([$projectId]);
        $project = coloring_project($projectId);
        if ($project === null) throw new InvalidArgumentException('Coloring book not found.');
        if (!in_array((string) $project['status'], ['draft', 'planned'], true)) {
            if ($ownsTransaction) $pdo->commit();
            return $project;
        }
        $key = coloring_reservation_key($projectId);
        $price = coloring_book_price($project);
        reserve_ai_credits(0, $price, $key, 'coloring_book', null);
        $queued = $pdo->prepare("UPDATE coloring_projects SET status = 'queued', reservation_key = ?, credits_charged = ?,
            failure_count = 0, failure_kind = NULL, failure_at = NULL,
            last_activity_at = datetime('now'), updated_at = datetime('now') WHERE id = ? AND status IN ('draft', 'planned')");
        $queued->execute([$key, $price, $projectId]);
        if ($queued->rowCount() !== 1) throw new RuntimeException('This coloring book changed while it was starting.');
        if ($ownsTransaction) $pdo->commit();
    } catch (Throwable $exception) {
        if ($ownsTransaction && $pdo->inTransaction()) $pdo->rollBack();
        throw $exception;
    }
    /* D-649: the worker hears of the book now, not at its next minute, and only once the queued row is committed. Never throws. */
    if ($ownsTransaction) background_worker_nudge();

    return coloring_project($projectId);
}

/**
 * The oldest project that is queued or part way through, is not locked and is not waiting out a failure. A project with no
 * credit hold is never picked: the pre-worker routes left test projects in `generating`, and drawing those would be free work.
 */
function coloring_worker_next_project(?PDO $pdo = null): ?array
{
    $pdo ??= db();
    /* D-606: exhausted rows are filtered in SQL and the rest is paged in a stable order, so a full batch of
       projects still in backoff cannot hide a due one behind it (same shape as the text worker, D-573). */
    $query = $pdo->prepare("SELECT * FROM coloring_projects WHERE status IN ('queued', 'generating') AND reservation_key IS NOT NULL
        AND (generating_until IS NULL OR generating_until < datetime('now')) AND failure_count < ?
        ORDER BY last_activity_at, id LIMIT 50 OFFSET ?");
    for ($offset = 0;; $offset += 50) {
        $query->execute([flow_failure_limit(), $offset]);
        $rows = $query->fetchAll();
        foreach ($rows as $row) {
            if (flow_failure_state($row) === 'retry') return $row;
        }
        if (count($rows) < 50) return null;
    }
}

function coloring_worker_claim(int $projectId, ?PDO $pdo = null): ?string
{
    $token = bin2hex(random_bytes(8));
    /* D-656 review: a claim on a book being built also stamps updated_at, so the building screen measures a quiet spell from
       the later of the last saved drawing and the start of this run, never counting the wait for a run against it. Review 2: not
       once a failure is recorded, so updated_at then stays the last saved drawing and the screen can tell a retry from progress. */
    $statement = ($pdo ?? db())->prepare("UPDATE coloring_projects SET generating_token = ?,
        generating_until = datetime('now', '+" . COLORING_WORKER_LOCK_SECONDS . " seconds'),
        updated_at = CASE WHEN status IN ('queued', 'generating') AND COALESCE(failure_count, 0) = 0 THEN datetime('now') ELSE updated_at END
        WHERE id = ? AND (generating_until IS NULL OR generating_until < datetime('now'))");
    $statement->execute([$token, $projectId]);

    return $statement->rowCount() === 1 ? $token : null;
}

/**
 * Providers are looked up by name so a self-check can swap them for fakes: `plan`, `page`, `cover`, `cover_outline`, `kdp`.
 * Each takes the project row and returns what the real function returns.
 */
function coloring_worker_provider(string $name): callable
{
    $override = $GLOBALS['coloring_worker_providers'][$name] ?? null;
    if (is_callable($override)) return $override;

    return match ($name) {
        'plan' => fn(array $project) => coloring_generate_plan($project),
        'page' => fn(array $project, int $sceneId) => coloring_generate_one_page($project, $sceneId),
        'cover' => fn(array $project) => coloring_generate_cover($project),
        'cover_outline' => fn(array $project) => coloring_generate_cover_outline($project),
        'kdp' => fn(array $project) => generate_kdp_metadata_coloring($project),
    };
}

/** @return array<int,int> scene ids that have no finished page yet */
function coloring_worker_missing_scenes(array $project): array
{
    $plan = json_decode((string) $project['plan_json'], true) ?: [];
    $pages = json_decode((string) ($project['pages_json'] ?: '[]'), true) ?: [];
    $done = [];
    foreach ($pages as $page) {
        if (($page['status'] ?? '') === 'done') $done[(int) $page['scene_id']] = true;
    }
    $missing = [];
    foreach ($plan['scenes'] ?? [] as $scene) {
        if (!isset($done[(int) $scene['id']])) $missing[] = (int) $scene['id'];
    }

    return $missing;
}

/** What the next unit of work is: plan, pages, cover, cover_outline, finish or done. */
function coloring_worker_stage(array $project): string
{
    if ((string) $project['status'] === 'ready') return 'done';
    if (trim((string) $project['plan_json']) === '') return 'plan';
    if (coloring_worker_missing_scenes($project) !== []) return 'pages';
    if ((string) ($project['cover_status'] ?? '') !== 'done') return 'cover';
    if (!coloring_has_cover_outline($project)) return 'cover_outline';

    return 'finish';
}

/**
 * Do one unit of work on a project: the plan, up to a run's worth of pages, the cover, or the finish.
 * `$pageLimit` lowers the run's page count. `$pool` sends those pages through the parallel pool even with a limit (the status
 * poll's kick, D-646); otherwise a limit takes the serial branch. Returns the stage that ran. A failure is recorded on the row
 * and rethrown for the caller to log.
 */
function coloring_worker_advance(array $project, ?int $pageLimit = null, bool $pool = false): string
{
    $id = (int) $project['id'];
    $token = coloring_worker_claim($id);
    if ($token === null) return 'locked';
    try {
        /* Read the row and choose the stage only after the claim: the caller's row may be minutes old, and another run may have
           finished the book since (a stale `cover` stage would pay for a second cover). */
        $select = db()->prepare('SELECT * FROM coloring_projects WHERE id = ?');
        $select->execute([$id]);
        $project = $select->fetch() + ['lease_token' => $token];
        /* D-639: a fetched but unfinished SELECT keeps this connection on an old WAL snapshot, so the next write fails with
           "database is locked" if anyone committed meanwhile, and busy_timeout cannot help. Close the cursor every time. */
        $select->closeCursor();
        $stage = coloring_worker_stage($project);
        db()->prepare("UPDATE coloring_projects SET status = 'generating' WHERE id = ? AND status = 'queued'")->execute([$id]);
        if ($stage === 'plan') {
            coloring_worker_provider('plan')($project);
        } elseif ($stage === 'pages' && ($pageLimit === null || $pool) && coloring_page_parallel() > 1) {
            /* D-645: a worker unit draws K pages at once with the cover chain beside them. The unit's page budget scales with
               K, so it lasts about as long as the owner-approved 12 serial pages. The status poll's kick (D-646) draws its few
               pages the same way. Any other explicit limit and COLORING_PAGE_PARALLEL=1 take the serial branch below, unchanged. */
            require_once __DIR__ . '/coloring_parallel.php';
            coloring_parallel_pages($id, $token, $pageLimit ?? coloring_worker_page_limit() * coloring_page_parallel(), coloring_page_parallel());
        } elseif ($stage === 'pages') {
            foreach (array_slice(coloring_worker_missing_scenes($project), 0, $pageLimit ?? coloring_worker_page_limit()) as $sceneId) {
                $select->execute([$id]);
                $project = $select->fetch() + ['lease_token' => $token];
                $select->closeCursor();
                coloring_worker_provider('page')($project, $sceneId);
                coloring_worker_touch($id, $token);
            }
        } elseif ($stage === 'cover') {
            coloring_worker_provider('cover')($project);
        } elseif ($stage === 'cover_outline') {
            coloring_worker_provider('cover_outline')($project);
        } elseif ($stage === 'finish') {
            coloring_worker_finish($project, $token);
        }
        db()->prepare("UPDATE coloring_projects SET failure_count = 0, failure_kind = NULL, failure_at = NULL,
            last_activity_at = datetime('now') WHERE id = ? AND generating_token = ?")->execute([$id, $token]);
    } catch (Throwable $exception) {
        coloring_worker_fail($project, $exception, $token);
        throw $exception;
    } finally {
        coloring_worker_release_lease($id, $token);
    }

    return $stage;
}

/**
 * Release the work lease. A leaked lease parks the book for COLORING_WORKER_LOCK_SECONDS, so a failed release is retried once on a
 * fresh connection (D-393, D-639). Never throws: it runs in a `finally` and must not mask the exception that is already in flight.
 */
function coloring_worker_release_lease(int $projectId, string $token): void
{
    $sql = 'UPDATE coloring_projects SET generating_until = NULL, generating_token = NULL WHERE id = ? AND generating_token = ?';
    try {
        db()->prepare($sql)->execute([$projectId, $token]);
        return;
    } catch (PDOException $first) {
        try {
            $fresh = db_fresh_connection(db());
            if ($fresh === null) throw $first;
            $fresh->prepare($sql)->execute([$projectId, $token]);
        } catch (Throwable $second) {
            error_log('coloring-engine: coloring lease release failed for project ' . $projectId . ': '
                . substr($first->getMessage(), 0, 120) . ' / retry: ' . substr($second->getMessage(), 0, 120));
        }
    }
}

/**
 * How long a book must sit untouched before the status poll draws for it while parallel drawing is on (D-645). 90 s, not 150
 * (D-646): the looping worker starts a pass every 60 s, so a book idle for 90 s has been missed by a pass.
 */
const COLORING_KICK_IDLE_SECONDS = 90;
/** The building screen says the worker has not picked the book up once nothing has moved for this long (D-646). */
const COLORING_IDLE_HINT_SECONDS = 150;

/**
 * Pages one status-poll kick draws: one on the serial path, as before; with parallel drawing up to four at once, with the cover
 * chain beside them, so a book whose worker is absent still finishes in minutes, not the best part of an hour (D-646).
 */
function coloring_worker_kick_pages(): int
{
    $lanes = coloring_page_parallel();

    return $lanes === 1 ? 1 : min($lanes, 4);
}

/**
 * Should the status poll draw a page itself? With COLORING_PAGE_PARALLEL=1, always, as before. Otherwise only when the
 * background worker looks absent: a poll that took the lease would draw one page at a time and lock the worker's parallel
 * unit out for that pass.
 */
function coloring_worker_kick_due(array $project, ?int $now = null): bool
{
    if (coloring_page_parallel() === 1) return true;
    $last = trim((string) ($project['last_activity_at'] ?? ''));
    $at = $last === '' ? false : strtotime($last . ' UTC');

    return $at === false || $at < ($now ?? time()) - COLORING_KICK_IDLE_SECONDS;
}

/** Keep the lock alive while a run draws page after page. */
function coloring_worker_touch(int $projectId, string $token): void
{
    $touch = db()->prepare("UPDATE coloring_projects SET last_activity_at = datetime('now'),
        generating_until = datetime('now', '+" . COLORING_WORKER_LOCK_SECONDS . " seconds')
        WHERE id = ? AND generating_token = ?");
    $touch->execute([$projectId, $token]);
    /* D-609: a run whose lease was taken over stops drawing instead of paying for pages nobody will keep. */
    if ($touch->rowCount() !== 1) throw new RuntimeException('This coloring run lost its work lease.');
}

/**
 * Capture the held credits and mark the book ready, in one transaction fenced on the claim (D-609): a stale run cannot
 * capture, and a failed capture cannot leave a ready book unpaid. Safe to repeat: capture is idempotent on its key.
 */
function coloring_worker_finish(array $project, ?string $token = null): void
{
    $key = (string) ($project['reservation_key'] ?: coloring_reservation_key((int) $project['id']));
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $ready = $pdo->prepare("UPDATE coloring_projects SET status = 'ready', updated_at = datetime('now')
            WHERE id = ?" . ($token === null ? '' : ' AND generating_token = ?'));
        $ready->execute($token === null ? [(int) $project['id']] : [(int) $project['id'], $token]);
        if ($ready->rowCount() !== 1) throw new RuntimeException('This coloring finish lost its work lease.');
        if (!str_starts_with($key, 'free:')) {
            capture_ai_credits($key, $key . ':capture', (string) $project['id'], null, $pdo);
            /* D-632: a book paid for in credits is unlocked by the same transaction that takes them. */
            $pdo->prepare('UPDATE coloring_projects SET unlocked = 1 WHERE id = ?')->execute([(int) $project['id']]);
        }
        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $exception;
    }
    /* Generate the Amazon KDP paperback metadata once, right after the project becomes ready
       (D-491). The project view then reads `kdp_metadata_json` directly, no on-view AI call.
       Failures log and are swallowed; the project is already ready and must not roll back. */
    try {
        require_once __DIR__ . '/ai.php';
        $kdp = coloring_worker_provider('kdp')($project);
        db()->prepare('UPDATE coloring_projects SET kdp_metadata_json = :json WHERE id = :id')
            ->execute(['json' => json_encode($kdp, JSON_UNESCAPED_UNICODE), 'id' => (int) $project['id']]);
    } catch (Throwable $exception) {
        error_log('coloring-engine: KDP metadata (coloring) generation failed on finish for project '
            . (int) $project['id'] . ': ' . substr($exception->getMessage(), 0, 200));
    }
}

function coloring_worker_fail(array $project, Throwable $exception, ?string $token = null): void
{
    $count = (int) ($project['failure_count'] ?? 0) + 1;
    /* D-609: only the current claim records a failure or gives the credits back; a stale run must not touch a successor's book. */
    $failed = db()->prepare("UPDATE coloring_projects SET failure_count = ?, failure_kind = ?, failure_at = datetime('now')
        WHERE id = ?" . ($token === null ? '' : ' AND generating_token = ?'));
    $failed->execute(array_merge([$count, mb_substr(get_class($exception) . ': ' . $exception->getMessage(), 0, 200),
        (int) $project['id']], $token === null ? [] : [$token]));
    if ($failed->rowCount() !== 1) return;
    $key = (string) ($project['reservation_key'] ?: coloring_reservation_key((int) $project['id']));
    if ($count >= flow_failure_limit() && !str_starts_with($key, 'free:')) {
        /* The free hold is the durable record of the account's one free book (D-632), so a stall keeps it: a stalled
           free book keeps its free hold and needs a manual support reset on the same project; it is never replaced by a new free start. */
        release_ai_credits($key);
    }
}

/**
 * Tell the host a finished book is ready (optional COLORING_READY_WEBHOOK, inert when unset).
 * Never throws and never touches the book, so a mail fault cannot undo a finished book. The send key makes a repeat harmless.
 */
function coloring_worker_notify_ready(int $projectId): void
{
    try {
        $project = coloring_project($projectId);
        if ($project !== null) notify_coloring_ready($project);
    } catch (Throwable $exception) {
        error_log('coloring-engine: coloring ready email failed for project ' . $projectId . ': ' . substr($exception->getMessage(), 0, 200));
    }
}

/** Run the queue: at most `$limit` units. Returns counts for the CLI log line. */
function coloring_worker_run(int $limit = 5): array
{
    $summary = ['advanced' => 0, 'failed' => 0, 'ready' => 0, 'stalled' => 0];
    for ($i = 0; $i < $limit; $i++) {
        $project = coloring_worker_next_project();
        if ($project === null) break;
        try {
            $stage = coloring_worker_advance($project);
            if ($stage === 'finish') {
                $summary['ready']++;
                coloring_worker_notify_ready((int) $project['id']);
            }
            if ($stage !== 'locked') $summary['advanced']++;
        } catch (Throwable $exception) {
            $summary['failed']++;
            app_diagnostic_log($exception, ['area' => 'coloring_worker', 'project_id' => (int) $project['id']]);
        }
    }

    /* Say what has given up, every run, by name, as the text worker does: a book that silently stops moving is the failure to avoid. */
    $summary['stalled'] = 0;
    foreach (coloring_worker_stalled() as $stalled) {
        $summary['stalled']++;
        error_log(sprintf('coloring-engine: coloring book %d has stalled at "%s" after %d attempts. Last fault: %s',
            (int) $stalled['id'], coloring_worker_stage($stalled),
            (int) $stalled['failure_count'], (string) ($stalled['failure_kind'] ?: 'not recorded')));
    }

    return $summary;
}

/** Books that spent their attempts and are waiting for a person. Their credits were already released. */
function coloring_worker_stalled(?PDO $pdo = null): array
{
    $statement = ($pdo ?? db())->prepare("SELECT * FROM coloring_projects WHERE status IN ('queued', 'generating')
        AND failure_count >= ?");
    $statement->execute([flow_failure_limit()]);

    return $statement->fetchAll();
}

/**
 * What the page shows while the job runs. Never names a fault.
 *
 * `pages` lists the finished drawings in plan order, each with a 400 px preview URL on the owner-checked image route (a locked
 * book never gets more than that), so the building screen can show them as they land. `cover_url` is set once the cover exists.
 * `drawings_left_seconds` is the time the drawings still need at the rate seen so far, from the build's reservation time; null
 * when there is no honest figure (nothing drawn yet, not drawing pages, or a rate that says the book stalled on the way).
 */
function coloring_worker_status(array $project): array
{
    $plan = json_decode((string) $project['plan_json'], true) ?: [];
    $total = count($plan['scenes'] ?? []) ?: coloring_project_pages($project);
    $state = flow_failure_state($project);
    $id = (int) ($project['id'] ?? 0);
    $image = static fn (string $path): string => '/?p=coloring-image&id=' . $id . '&path=' . rawurlencode($path) . '&size=400';
    $donePaths = [];
    $doneAt = [];
    foreach (json_decode((string) ($project['pages_json'] ?: '[]'), true) ?: [] as $page) {
        if (($page['status'] ?? '') === 'done' && is_string($page['path'] ?? null)) {
            $donePaths[(int) $page['scene_id']] = $page['path'];
            $doneAt[(int) $page['scene_id']] = (string) ($page['done_at'] ?? '');
        }
    }
    $pages = [];
    foreach ($plan['scenes'] ?? [] as $n => $scene) {
        $sceneId = (int) $scene['id'];
        if (isset($donePaths[$sceneId])) {
            /* `n` is the page number in plan order and `at` when it landed (D-656: the building screen shows the newest first). */
            $pages[] = ['id' => $sceneId, 'n' => $n + 1, 'at' => $doneAt[$sceneId], 'url' => $image($donePaths[$sceneId]),
                'text' => (string) ($scene['description'] ?? '')];
        }
    }
    $stage = coloring_worker_stage($project);
    $done = count($pages);
    $coverDone = (string) ($project['cover_status'] ?? '') === 'done' && (string) ($project['cover_path'] ?? '') !== '';
    /* D-646: pages land in bursts of K, so the time left is the waves still to draw times the median time one page took (its
       `ms` stamp), and only while a run holds the book: between runs nobody can say when the next one starts. Null otherwise. */
    $left = null;
    $leased = trim((string) ($project['generating_until'] ?? '')) > gmdate('Y-m-d H:i:s');
    if ($stage === 'pages' && $done > 0 && $leased) {
        $times = [];
        foreach (json_decode((string) ($project['pages_json'] ?: '[]'), true) ?: [] as $page) {
            if (($page['status'] ?? '') === 'done' && is_int($page['ms'] ?? null) && $page['ms'] > 0) $times[] = $page['ms'];
        }
        if ($times !== []) {
            sort($times);
            $estimate = intdiv((int) ceil(($total - $done) / coloring_page_parallel()) * $times[intdiv(count($times), 2)], 1000);
            $left = $estimate <= 3600 ? $estimate : null;
        }
    }
    /* D-647: an empty stamp is unknown. strtotime(' UTC') is not false: PHP 8.2 reads the local wall clock as UTC, so on the host
       (America/Chicago) a row with no last_activity_at looked 6 hours idle and showed the worker hint at once. */
    $lastStamp = trim((string) ($project['last_activity_at'] ?? ''));
    $lastActivity = $lastStamp === '' ? false : strtotime($lastStamp . ' UTC');
    $running = in_array((string) ($project['status'] ?? ''), ['queued', 'generating'], true);
    $idle = $running && !$leased && $lastActivity !== false ? max(0, time() - $lastActivity) : null;
    /* D-656: updated_at moves when the book starts, when a run claims it with no failure recorded, and when each drawing (pages,
       cover, outline) is saved, and on nothing else the worker does, so while a run holds the book it is the time of its last
       progress. Same empty-stamp rule as above. */
    $changedStamp = trim((string) ($project['updated_at'] ?? ''));
    $changedAt = $changedStamp === '' ? false : strtotime($changedStamp . ' UTC');
    $quiet = $running && $changedAt !== false ? max(0, time() - $changedAt) : null;
    /* D-656 review 2: a failed unit and its retries stay slow until a new drawing lands. The failure is stamped once the unit's
       last request ends, so a drawing saved after failure_at is new progress. */
    $failStamp = trim((string) ($project['failure_at'] ?? ''));
    $failedAt = (int) ($project['failure_count'] ?? 0) > 0 && $failStamp !== '' ? strtotime($failStamp . ' UTC') : false;
    $failedSinceDrawing = $running && $failedAt !== false && ($changedAt === false || $changedAt <= $failedAt);

    return [
        'status' => (string) $project['status'],
        'stage' => $stage,
        'pages_done' => $total - count(coloring_worker_missing_scenes($project)),
        'pages_total' => $total,
        'cover' => (string) ($project['cover_status'] ?? '') === 'done',
        'cover_outline' => coloring_has_cover_outline($project),
        'stalled' => $state === 'stalled',
        'waiting' => $state === 'wait',
        'pages' => $pages,
        'cover_url' => $coverDone ? $image((string) $project['cover_path']) : null,
        'drawings_left_seconds' => $left,
        'idle_seconds' => $idle,
        /* D-649: a run holds the book right now, so the screen can say drawing has started before the first picture lands. */
        'leased' => $leased,
        /* D-656: the screen drops its estimate and says the book is slow (COLORING_SLOW_TEXT). */
        'slow' => coloring_build_slow($stage, $done, $quiet, $idle, $leased, $failedSinceDrawing),
    ];
}

/** D-656: a run holds the book and no new page has been saved for this long: the screen drops its estimate and says it is slow. */
const COLORING_SLOW_AFTER_DRAWING_SECONDS = 45;
/** Before the first drawing: a first picture takes 25 to 60 s from the start of the run, so the screen waits this long instead. */
const COLORING_SLOW_BEFORE_FIRST_SECONDS = 90;
/** D-656 review: the cover takes about 50 s to paint and the outline about 30 s more, so the cover stages wait this long. */
const COLORING_SLOW_COVER_SECONDS = 90;

/**
 * Is a running build slow? Only while drawings are due (pages, cover, outline; never planning or finishing). While a run holds the
 * book: no progress (`$quiet`, seconds since the later of the last saved drawing and the run's claim) for
 * COLORING_SLOW_AFTER_DRAWING_SECONDS once a page is drawn, COLORING_SLOW_BEFORE_FIRST_SECONDS before the first, and
 * COLORING_SLOW_COVER_SECONDS in the cover stages. With no run holding it (waiting for pickup, or between runs) only a long wait
 * counts: no run for COLORING_IDLE_HINT_SECONDS (`$idle`). So the wait for a run never counts against the first drawing.
 * D-656 review 2: `$failedSinceDrawing` (a failure is recorded and no drawing has landed since) is slow, held or not, so the line
 * does not vanish during the retry wait or restart at the retry's claim.
 */
function coloring_build_slow(string $stage, int $drawn, ?int $quiet, ?int $idle, bool $leased = true, bool $failedSinceDrawing = false): bool
{
    if (!in_array($stage, ['pages', 'cover', 'cover_outline'], true)) return false;
    if ($failedSinceDrawing) return true;
    if (!$leased) return $idle !== null && $idle >= COLORING_IDLE_HINT_SECONDS;
    if ($quiet === null) return false;
    $limit = $stage !== 'pages' ? COLORING_SLOW_COVER_SECONDS
        : ($drawn === 0 ? COLORING_SLOW_BEFORE_FIRST_SECONDS : COLORING_SLOW_AFTER_DRAWING_SECONDS);

    return $quiet >= $limit;
}

/**
 * Pay for one more cover (9 credits) or one redrawn page (1 credit) on a finished book. The price is held first and taken only
 * when the new image is saved, so a failed draw costs nothing. The project lock stops a double click paying twice.
 *
 * @param string $what 'cover' or 'page'
 * @return array{credits:int}
 */
function coloring_worker_redo(int $userId, int $projectId, string $what, int $sceneId = 0): array
{
    /* The website redo button is driven by the account owner, not by MCP. Mark the request so the
       MCP pilot-spend guard does not catch it (D-516). Cron-driven original generation does not
       set this flag, so MCP-mapped projects stay capped on their initial run. */
    $GLOBALS['aiep_coloring_website_redo'] = true;
    try {
        return coloring_worker_redo_inner($userId, $projectId, $what, $sceneId);
    } finally {
        unset($GLOBALS['aiep_coloring_website_redo']);
    }
}

function coloring_worker_redo_inner(int $userId, int $projectId, string $what, int $sceneId = 0): array
{
    $project = coloring_project($projectId, $userId);
    if ($project === null || (string) $project['status'] !== 'ready') throw new InvalidArgumentException('Coloring book not ready.');
    if (!in_array($what, ['cover', 'page', 'cover_outline'], true)) throw new InvalidArgumentException('Unknown redo.');
    $token = coloring_worker_claim($projectId);
    if ($token === null) throw new RuntimeException('This book is busy. Try again in a moment.');
    /* A cover may have changed while this request waited for the claim. Price and redraw from
       the current row, never from the pre-claim snapshot. */
    $project = coloring_project($projectId, $userId);
    if ($project !== null) $project['lease_token'] = $token;
    if ($project === null || (string) $project['status'] !== 'ready') {
        db()->prepare('UPDATE coloring_projects SET generating_until = NULL, generating_token = NULL
            WHERE id = ? AND generating_token = ?')->execute([$projectId, $token]);
        throw new InvalidArgumentException('Coloring book not ready.');
    }
    /* Older completed books can add the included opening page without redrawing their cover. */
    if ($what === 'cover_outline') {
        try {
            coloring_generate_cover_outline($project);
            return ['credits' => 0];
        } finally {
            db()->prepare('UPDATE coloring_projects SET generating_until = NULL, generating_token = NULL
                WHERE id = ? AND generating_token = ?')->execute([$projectId, $token]);
        }
    }
    $credits = $what === 'cover' ? COLORING_COVER_CREDITS : COLORING_PAGE_REGEN_CREDITS;
    $count = $what === 'cover' ? (int) ($project['cover_extra_count'] ?? 0) + 1 : 0;
    /* Deterministic key per try (D-558, D-559): a crashed retry must reuse the same key so
       `reserve_ai_credits` and `ai_usage` dedupe instead of charging twice. The `tries` counter
       on the page row drives this the same way coloring_generate_one_page builds its action_key. */
    $try = 1;
    if ($what === 'page') {
        foreach (json_decode((string) ($project['pages_json'] ?: '[]'), true) ?: [] as $p) {
            if ((int) ($p['scene_id'] ?? 0) === $sceneId) $try = (int) ($p['tries'] ?? 1) + 1;
        }
    }
    $key = $what === 'cover' ? "coloring:$projectId:cover:$count" : "coloring:$projectId:page:$sceneId:$try";
    try {
        reserve_ai_credits($userId, $credits, $key, 'coloring_' . $what, null);
        try {
            $before = $what === 'cover' ? (string) $project['cover_path'] : '';
            if ($what === 'cover') {
                coloring_generate_cover($project, $count);
            } else {
                $pages = json_decode((string) ($project['pages_json'] ?: '[]'), true) ?: [];
                foreach ($pages as $page) {
                    if ((int) $page['scene_id'] === $sceneId) $before = (string) $page['path'];
                }
                if ($before === '') throw new InvalidArgumentException('Scene not found.');
                coloring_worker_provider('page')($project, $sceneId);
            }
        } catch (Throwable $exception) {
            release_ai_credits($key);
            throw $exception;
        }
        try {
            capture_ai_credits($key, $key . ':capture', (string) $projectId);
            /* Only persist the bump once capture succeeds (review2 F8). On a capture failure the
               row keeps its old count, so a next redo reuses the same action_key and ai_usage
               dedupes instead of double-billing. */
            if ($what === 'cover') {
                db()->prepare('UPDATE coloring_projects SET cover_extra_count = ? WHERE id = ?')->execute([$count, $projectId]);
            }
        } catch (Throwable $exception) {
            /* The new image is saved and the customer has it. A capture that fails now must not strand the hold: give it back,
               record the fault, and let the redo stand for free. */
            release_ai_credits($key);
            app_diagnostic_log($exception, ['area' => 'coloring_redo_capture', 'project_id' => $projectId, 'user_id' => $userId]);
            $credits = 0;
        }
        if ($before !== '' && is_file(application_data_directory() . '/' . $before)) @unlink(application_data_directory() . '/' . $before);
    } finally {
        db()->prepare('UPDATE coloring_projects SET generating_until = NULL, generating_token = NULL
            WHERE id = ? AND generating_token = ?')->execute([$projectId, $token]);
    }

    return ['credits' => $credits];
}
