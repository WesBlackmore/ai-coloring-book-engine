<?php

declare(strict_types=1);

const BACKGROUND_BOOK_WORKER_LOOP_MAX_SECONDS = 3300;
const BACKGROUND_BOOK_WORKER_LOOP_MAX_FAILURES = 5;
/** D-649: a wake never starts passes closer together than this, so a stream of wakes cannot spin the loop. */
const BACKGROUND_BOOK_WORKER_WAKE_GAP_SECONDS = 2.0;

/**
 * Parse the value of --loop-seconds. Null when it is not a whole number from 0 to 3300; 0 means a
 * single pass, exactly as before the loop existed.
 */
function background_book_worker_parse_loop_seconds(string $value): ?int
{
    if (!preg_match('/^\d{1,4}$/', $value)) return null;
    $seconds = (int) $value;

    return $seconds <= BACKGROUND_BOOK_WORKER_LOOP_MAX_SECONDS ? $seconds : null;
}

/** Seconds between pass starts: 60, or BACKGROUND_WORKER_LOOP_INTERVAL when set (a test hook only). */
function background_book_worker_loop_interval(): float
{
    $override = (float) getenv('BACKGROUND_WORKER_LOOP_INTERVAL');

    return $override > 0 ? $override : 60.0;
}

/**
 * Files a pass loads: a deploy that touches one of them ends a running loop (see the loop's $watch). The coloring files are
 * here because the loop keeps their code in memory: a pre-render with old layout code would be saved under the new code's
 * signature and served as fresh (D-646).
 */
function background_book_worker_watched_files(): array
{
    $root = dirname(__DIR__);
    $files = ['bin/worker', 'src/background_book_worker_loop.php', 'src/db.php', 'src/shims.php', 'src/coloring_worker.php',
        'src/ai.php', 'src/coloring_book.php', 'src/coloring_cover.php', 'src/coloring_pdf_cache.php', 'src/ratelimit.php',
        'src/coloring_parallel.php', 'src/background_worker_wake.php'];

    return array_map(static fn (string $file): string => $root . '/' . $file, $files);
}

/** @param list<string> $files @return array<string,int|false> */
function background_book_worker_mtimes(array $files): array
{
    clearstatcache();
    $mtimes = [];
    foreach ($files as $file) $mtimes[$file] = @filemtime($file);

    return $mtimes;
}

/**
 * The wake file's mark: its mtime, 0 when it does not exist, -1 when it was touched this very second.
 * ponytail: mtime has 1 s resolution, so a second wake inside the same second as this read would look unchanged. A pass that
 * starts on a -1 mark is followed by one more pass 2 s later, instead of a book left waiting for the minute.
 */
function background_book_worker_wake_mark(string $path): int
{
    clearstatcache(true, $path);
    $mtime = @filemtime($path);
    if ($mtime === false) return 0;

    /* Only this very second. A future mtime (the clock stepped back) is an ordinary mark: it wakes once when it appears and is
       compared like any other after that, so it cannot make every pass look woken. */
    return $mtime === time() ? -1 : $mtime;
}

/**
 * Run $pass, then again while $more() says there is work it may have missed, at most $extra more times. A spawned single pass
 * holds the cron lock, so a book queued after its last queue read (during the side tasks, or past its limit) would otherwise
 * wait for the next cron tick (D-649 review). Returns the passes run.
 */
function background_book_worker_drain(callable $pass, callable $more, int $extra = 3): int
{
    $passes = 0;
    do {
        $pass();
        $passes++;
    } while ($passes <= $extra && $more());

    return $passes;
}

/**
 * Wait until $until in slices of at most 1 s. Leaves early on a stop, or on a wake (the mark differs from $seen) once
 * $earliest has passed. The total sleep is capped at $cap, so a clock that steps back cannot stretch the wait.
 */
function background_book_worker_wait(float $until, float $earliest, float $cap, string $wake, int $seen, callable $sleep, callable $clock, ?callable $stop): void
{
    $slept = 0.0;
    $woken = false;
    while ($slept < $cap) {
        if ($stop !== null && $stop()) return;
        $now = $clock();
        if ($now >= $until) return;
        $woken = $woken || $seen === -1 || background_book_worker_wake_mark($wake) !== $seen;
        if ($woken && $now >= $earliest) return;
        $step = min(1.0, ($woken ? $earliest : $until) - $now, $cap - $slept);
        $sleep($step);
        $slept += $step;
    }
}

/**
 * Run $pass repeatedly so one cron start behaves like many one-minute starts.
 *
 * A pass starts every $interval seconds (at once if the last one overran), no pass starts at or
 * after the deadline, and a pass in progress at the deadline finishes. A throwing pass is logged and
 * the loop carries on; five in a row end it with exit 1 so cron starts a clean process.
 *
 * @param callable():mixed $pass  one worker pass; throw to report failure
 * @param callable(float):void $sleep
 * @param callable():float $clock seconds
 * @param null|callable():bool $stop true to leave the loop (SIGTERM)
 * @param null|list<string> $watch files whose mtime is compared after each pass; a change ends the loop
 *        (exit 0, 'restart' true) so cron starts the new code. Null watches the worker's own files.
 * @param null|string $wake the wake file (D-649). Given, the wait between passes is 1 s slices that end early when the file
 *        changed since the last pass started (no closer than 2 s from that start), and wakes are heard up to the deadline.
 *        Null keeps the single sleep to the next minute.
 * @return array{passes:int,failed:int,exit:int,restart:bool}
 */
function background_book_worker_loop(int $seconds, callable $pass, callable $sleep, callable $clock, float $interval = 60.0, ?callable $stop = null, ?array $watch = null, ?string $wake = null): array
{
    $watch ??= background_book_worker_watched_files();
    $mtimes = background_book_worker_mtimes($watch);
    $restart = false;
    $deadline = $clock() + $seconds;
    $passes = $failed = $consecutive = 0;
    $exit = 0;
    while (true) {
        $startedAt = $clock();
        /* Read at the pass start, so a book queued during the pass gets the next pass at once. */
        $wakeSeen = $wake === null ? 0 : background_book_worker_wake_mark($wake);
        $passes++;
        try {
            $pass();
            $consecutive = 0;
        } catch (Throwable $exception) {
            $failed++;
            $consecutive++;
            error_log('background book worker pass failed: ' . $exception->getMessage());
            if ($consecutive >= BACKGROUND_BOOK_WORKER_LOOP_MAX_FAILURES) {
                $exit = 1;
                break;
            }
        }
        if (background_book_worker_mtimes($watch) !== $mtimes) {
            $restart = true;
            break;
        }
        $next = $startedAt + $interval;
        if ($wake !== null) {
            if ($stop !== null && $stop()) break;
            background_book_worker_wait(min($next, $deadline), $startedAt + BACKGROUND_BOOK_WORKER_WAKE_GAP_SECONDS, $interval,
                $wake, $wakeSeen, $sleep, $clock, $stop);
        } else {
            if ($next >= $deadline || ($stop !== null && $stop())) break;
            $wait = $next - $clock();
            if ($wait > 0) $sleep(min($wait, $interval));
        }
        if ($stop !== null && $stop()) break;
        if ($clock() >= $deadline) break;
    }

    return ['passes' => $passes, 'failed' => $failed, 'exit' => $exit, 'restart' => $restart];
}
