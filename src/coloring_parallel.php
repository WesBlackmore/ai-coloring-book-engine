<?php

declare(strict_types=1);

/*
 * Parallel page drawing (D-645).
 *
 * One worker unit of the pages stage runs every missing page, and the cover chain beside them, as a Fiber. Their provider
 * calls go through the unchanged ai_http_json(), which suspends its fiber at the network call (ai_http_in_pool()); the loop
 * below sends the suspended requests together with curl_multi and resumes each fiber with its own response as it lands.
 * Everything else is the code the serial path runs: the prompts, the edge-ink redo (a second request from the same fiber),
 * the usage record and the lease-fenced writes. Only one fiber runs at a time, so every database write still happens one
 * at a time on this connection, and a finished page is saved the moment its fiber ends.
 */

require_once __DIR__ . '/coloring_worker.php';

/** Renew the 300 second lease at least this often while requests are in flight. One request is capped at AI_CALL_TIMEOUT. */
const COLORING_POOL_TOUCH_SECONDS = 30;
/** A 429 is sent again after 5, 10, 20 and 40 seconds before it counts as the page's failure. */
const COLORING_POOL_429_RETRIES = 4;
/** Circuit breaker (D-646): this many page errors before any page succeeds and no new page starts in this unit. */
const COLORING_POOL_BREAKER_ERRORS = 2;
/** A pool request gets this long to connect: the loop is single threaded, so a long GD step can delay connection setup (D-646). */
const COLORING_POOL_CONNECT_SECONDS = 10;
/**
 * D-656: once a response has started, a body that brings no new bytes for this long has stalled and is dropped and sent again.
 * A body that keeps moving is never cut for being slow, only by COLORING_POOL_BODY_MAX_SECONDS. A drawing is about 1 MB now; the
 * stall seen on 2026-11-10 was 917 KB of a 5 MB response in 180 s.
 */
const COLORING_POOL_BODY_SECONDS = 30;
/** D-656 review: a body still trickling in this long after its first byte is dropped too, so a trickle cannot run for minutes. */
const COLORING_POOL_BODY_MAX_SECONDS = 120;
/** D-656: a request that failed in transport (timeout, stalled body, reset) is sent again at once this many times, in the same unit. */
const COLORING_POOL_NET_RETRIES = 1;

/** The book as saved now, stamped with this run's claim (D-609), with its cursor closed (D-639). */
function coloring_parallel_row(int $id, string $token): array
{
    $select = db()->prepare('SELECT * FROM coloring_projects WHERE id = ?');
    $select->execute([$id]);
    $row = $select->fetch();
    $select->closeCursor();
    if (!is_array($row)) throw new RuntimeException('Coloring book not found.');

    return $row + ['lease_token' => $token];
}

/**
 * Draw up to `$pageLimit` missing pages, `$lanes` at a time, and the cover and its outline beside them if they are not done.
 * A failed page does not stop its siblings; once everything in flight has ended the first failure is thrown, so the book's
 * failure count and backoff work as before. A lost lease stops the unit at once. Two page errors before any success start no
 * more pages (D-646): in an outage with hung requests every new wave would wait out its timeout and may still be billed.
 */
function coloring_parallel_pages(int $id, string $token, int $pageLimit, int $lanes): array
{
    $started = hrtime(true);
    $row = coloring_parallel_row($id, $token);
    $cover = ['cover' => '-', 'outline' => '-'];
    $jobs = [];
    if ((string) ($row['cover_status'] ?? '') !== 'done' || !coloring_has_cover_outline($row)) {
        /* The cover needs only the plan (D-645), so it no longer waits for the last page. */
        $jobs[] = ['key' => 'cover', 'lane' => false, 'run' => function () use ($id, $token, &$cover): void {
            $project = coloring_parallel_row($id, $token);
            if ((string) ($project['cover_status'] ?? '') !== 'done') {
                $at = hrtime(true);
                coloring_worker_provider('cover')($project);
                $cover['cover'] = intdiv(hrtime(true) - $at, 1000000) . 'ms';
                $project = coloring_parallel_row($id, $token);
            }
            if (!coloring_has_cover_outline($project)) {
                $at = hrtime(true);
                coloring_worker_provider('cover_outline')($project);
                $cover['outline'] = intdiv(hrtime(true) - $at, 1000000) . 'ms';
            }
        }];
    }
    foreach (array_slice(coloring_worker_missing_scenes($row), 0, $pageLimit) as $sceneId) {
        /* Each page reads the row when it starts, so it sees every page saved before it. */
        $jobs[] = ['key' => 'page' . $sceneId, 'lane' => true,
            'run' => fn() => coloring_worker_provider('page')(coloring_parallel_row($id, $token), $sceneId)];
    }

    $errors = [];
    $pageMs = [];
    $pool = ai_http_pool_run($jobs, $lanes, function (string $key, ?Throwable $error, int $ms) use ($id, $token, &$errors, &$pageMs): bool {
        if ($error !== null) {
            $errors[] = $error;
            /* A write refused for a lost lease means a successor owns the book: stop rather than pay for more (D-609). */
            if (str_contains($error->getMessage(), 'lost its work lease')) throw $error;
            return !(count($errors) >= COLORING_POOL_BREAKER_ERRORS && $pageMs === []);
        }
        if ($key !== 'cover') $pageMs[] = $ms;
        coloring_worker_touch($id, $token);
        return true;
    }, fn() => coloring_worker_touch($id, $token));

    sort($pageMs);
    $rss = (getrusage()['ru_maxrss'] ?? 0) / (PHP_OS_FAMILY === 'Darwin' ? 1048576 : 1024);
    $stats = ['k' => $lanes, 'pages' => count($pageMs), 'failed' => count($errors), 'http429' => $pool['http429'], 'breaker' => (int) $pool['breaker'],
        'net_retry' => $pool['net_retry'], 'in_mb' => round($pool['bytes_in'] / 1048576, 1),
        'cover' => $cover['cover'], 'outline' => $cover['outline'], 'page_ms_median' => $pageMs[intdiv(count($pageMs), 2)] ?? 0,
        'wall_s' => round((hrtime(true) - $started) / 1e9, 1), 'peak_mb' => round(memory_get_peak_usage(true) / 1048576, 1),
        'rss_mb' => round($rss, 1)];
    error_log(sprintf('coloring-engine: coloring pages unit book=%d k=%d pages=%d failed=%d http429=%d breaker=%d net_retry=%d in_mb=%.1f '
        . 'cover=%s outline=%s page_ms_median=%d wall=%.1fs peak_mb=%.1f rss_mb=%.1f', $id, $stats['k'], $stats['pages'], $stats['failed'],
        $stats['http429'], $stats['breaker'], $stats['net_retry'], $stats['in_mb'], $stats['cover'], $stats['outline'],
        $stats['page_ms_median'], $stats['wall_s'], $stats['peak_mb'], $stats['rss_mb']));
    if ($pool['stopped'] !== null) throw $pool['stopped'];
    if ($errors !== []) throw $errors[0];

    return $stats;
}

/**
 * Run each job's callable in its own Fiber, at most `$lanes` lane jobs at once plus every non-lane job (the cover), and send
 * the AI requests they suspend on together. `$after(key, error, ms)` runs outside the fibers when a job ends, `$tick()` at
 * least every COLORING_POOL_TOUCH_SECONDS. If either throws the pool stops: nothing new starts, and each fiber still running
 * is unwound at its request, so its slot is released and it writes nothing. If `$after` returns false no new lane job starts,
 * but the jobs already running finish (the breaker). A 429 lowers the requests in flight for the rest of the unit and is sent
 * again after a pause. D-656: each request has its own connection (no HTTP/2 multiplexing, so one bad connection cannot stall
 * every drawing in flight at once), a response body that stalls is dropped, and a transport failure is sent again at once,
 * so a lost page is redrawn in this unit, not after the worker's retry wait.
 *
 * @param list<array{key:string,lane:bool,run:callable}> $jobs
 * @return array{http429:int,stopped:?Throwable,breaker:bool,net_retry:int,bytes_in:int}
 */
function ai_http_pool_run(array $jobs, int $lanes, callable $after, callable $tick): array
{
    $GLOBALS['ai_http_pool_fibers'] ??= new WeakMap();
    $multi = curl_multi_init();
    curl_multi_setopt($multi, CURLMOPT_PIPELINING, CURLPIPE_NOTHING);
    $running = [];
    $cap = $lanes + count(array_filter($jobs, fn(array $job) => !$job['lane']));
    $http429 = 0;
    $netRetry = 0;
    $bytesIn = 0;
    $stopped = null;
    $breaker = false;
    $lastTick = microtime(true);
    $next = 0;
    $spool = coloring_pool_spool_dir();

    $finish = function (int $n, ?Throwable $error) use (&$running, &$stopped, &$breaker, $after): void {
        $job = $running[$n];
        unset($running[$n]);
        try {
            if ($after($job['key'], $error, intdiv(hrtime(true) - $job['started'], 1000000)) === false) $breaker = true;
        } catch (Throwable $exception) {
            $stopped ??= $exception;
        }
    };
    /* Start or resume one fiber and record what it waits for next: a request to send, or a short pause for a slot. */
    $step = function (int $n, Closure $how) use (&$running, $finish): void {
        $fiber = $running[$n]['fiber'];
        try {
            $out = $how($fiber);
        } catch (Throwable $exception) {
            $finish($n, $exception);
            return;
        }
        if ($fiber->isTerminated()) {
            $finish($n, null);
            return;
        }
        $running[$n]['request'] = $out['request'] ?? null;
        $running[$n]['due'] = microtime(true) + (isset($out['request']) ? 0.0 : (float) ($out['wait'] ?? 0.25));
    };

    try {
        while (true) {
            @set_time_limit(AI_CALL_TIMEOUT + 60);
            while ($stopped === null && $jobs !== []) {
                $lanesUsed = count(array_filter($running, fn(array $job) => $job['lane']));
                if ($jobs[0]['lane'] && ($lanesUsed >= $lanes || $breaker)) break;
                $job = array_shift($jobs);
                $fiber = new Fiber($job['run']);
                $GLOBALS['ai_http_pool_fibers'][$fiber] = true;
                $running[$next] = ['key' => $job['key'], 'lane' => $job['lane'], 'fiber' => $fiber, 'started' => hrtime(true),
                    'request' => null, 'due' => 0.0, 'handle' => null, 'body' => null, 'tries429' => 0, 'triesNet' => 0];
                $step($next++, fn(Fiber $f) => $f->start());
            }
            if ($stopped !== null || $running === []) break;

            $now = microtime(true);
            $inFlight = count(array_filter($running, fn(array $job) => $job['handle'] !== null));
            foreach (array_keys($running) as $n) {
                if ($stopped !== null) break;
                if (!isset($running[$n]) || $running[$n]['handle'] !== null || $running[$n]['due'] > $now) continue;
                if ($running[$n]['request'] === null) {
                    $step($n, fn(Fiber $f) => $f->resume(null));
                    continue;
                }
                if ($inFlight >= $cap) continue;
                $handle = ai_http_handle($running[$n]['request']);
                curl_setopt($handle, CURLOPT_CONNECTTIMEOUT, min(COLORING_POOL_CONNECT_SECONDS, (int) $running[$n]['request']['timeout']));
                /* The body goes to a file, not memory: responses that land while another page is being processed wait on disk,
                   so peak memory stays near one drawing whatever K is. Without a usable file the body stays in memory. */
                $body = coloring_pool_spool_file($spool);
                if ($body !== null) curl_setopt($handle, CURLOPT_FILE, $body);
                /* D-656: a body that stops moving, or still trickles long after its first byte, is dropped; waiting for the drawing
                   itself (no bytes yet) is bounded by the request timeout as before. */
                curl_setopt($handle, CURLOPT_NOPROGRESS, false);
                curl_setopt($handle, CURLOPT_XFERINFOFUNCTION, coloring_pool_body_guard());
                curl_multi_add_handle($multi, $handle);
                $running[$n]['handle'] = $handle;
                $running[$n]['body'] = $body;
                $inFlight++;
            }

            do { $code = curl_multi_exec($multi, $active); } while ($code === CURLM_CALL_MULTI_PERFORM);
            if ($inFlight === 0 || curl_multi_select($multi, 0.5) === -1) usleep(50000);
            do { $code = curl_multi_exec($multi, $active); } while ($code === CURLM_CALL_MULTI_PERFORM);

            /* After a stop nothing more is resumed: what is still in flight is abandoned in the finally below. */
            while ($stopped === null && ($info = curl_multi_info_read($multi)) !== false) {
                $n = null;
                foreach ($running as $key => $job) {
                    if ($job['handle'] === $info['handle']) { $n = $key; break; }
                }
                curl_multi_remove_handle($multi, $info['handle']);
                if ($n === null) continue;
                $handle = $info['handle'];
                $stream = $running[$n]['body'];
                $running[$n]['handle'] = null;
                $running[$n]['body'] = null;
                $ok = $info['result'] === CURLE_OK;
                $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
                $bytesIn += (int) curl_getinfo($handle, CURLINFO_SIZE_DOWNLOAD_T);
                if (!$ok && $running[$n]['triesNet'] < COLORING_POOL_NET_RETRIES && empty($running[$n]['request']['mcp_pilot'])
                    && coloring_pool_count_resend()) {
                    /* D-656: a timeout, a stalled body or a reset costs this page one more request now, not the whole unit a retry wait.
                       The resend is a second provider call, so it is counted against the AI rate ceilings first. Review 2: a request
                       with an MCP pilot spend reservation is never resent (it has one reservation), so the fiber gets the failure and
                       ai_http_json() marks the spend unresolved and blocks further MCP work, as on the serial path. */
                    $running[$n]['triesNet']++;
                    $netRetry++;
                    error_log('coloring-engine: coloring pool request sent again after: ' . (curl_error($handle) ?: curl_strerror($info['result'])));
                    $running[$n]['due'] = microtime(true);
                    if (is_resource($stream)) fclose($stream);
                    continue;
                }
                if ($ok && $status === 429) {
                    $http429++;
                    $cap = max(1, $cap - 1);
                    if ($running[$n]['tries429'] < COLORING_POOL_429_RETRIES) {
                        $running[$n]['due'] = microtime(true) + 5 * 2 ** $running[$n]['tries429']++;
                        if (is_resource($stream)) fclose($stream);
                        continue;
                    }
                }
                $error = $ok ? '' : (curl_error($handle) ?: curl_strerror($info['result'])
                    . ($info['result'] === CURLE_OPERATION_TIMEDOUT ? ' (timed out)' : ''))
                    . ($info['result'] === CURLE_ABORTED_BY_CALLBACK ? ' (the response stalled)' : '');
                if ($stream === null) {
                    /* No spool file: the body was kept in memory; hand it over as a stream all the same. */
                    $stream = fopen('php://temp', 'w+b');
                    fwrite($stream, (string) curl_multi_getcontent($handle));
                }
                if (!$ok) fclose($stream);
                /* The fiber reads and closes the stream itself, so this loop never holds a copy of a drawing's response. */
                $step($n, fn(Fiber $f) => $f->resume([$ok ? $stream : false, $status, $error]));
            }

            if (microtime(true) - $lastTick >= COLORING_POOL_TOUCH_SECONDS) {
                $lastTick = microtime(true);
                try {
                    $tick();
                } catch (Throwable $exception) {
                    $stopped ??= $exception;
                }
            }
        }
    } finally {
        /* Only after a stop or an unexpected throw is anything left: abandon it. A request that already reached the provider
           may still be billed, but without the lease it cannot be saved safely, so it is not saved at all (D-609). */
        foreach ($running as $job) {
            if ($job['handle'] !== null) curl_multi_remove_handle($multi, $job['handle']);
            if (is_resource($job['body'])) fclose($job['body']);
            try {
                if ($job['fiber']->isSuspended()) $job['fiber']->throw(new RuntimeException('This coloring run stopped before the request finished.'));
            } catch (Throwable) {
            }
        }
        curl_multi_close($multi);
    }

    return ['http429' => $http429, 'stopped' => $stopped, 'breaker' => $breaker, 'net_retry' => $netRetry, 'bytes_in' => $bytesIn];
}

/**
 * D-656: a curl progress callback for one request, with its own state (a by-reference variable in the send loop would be shared by
 * every request in flight). Non-zero aborts the transfer: once the body has started, when no new bytes have come for
 * COLORING_POOL_BODY_SECONDS, or when it is still arriving COLORING_POOL_BODY_MAX_SECONDS after its first byte. `$clock` is for
 * the selfcheck.
 */
function coloring_pool_body_guard(?Closure $clock = null): Closure
{
    $clock ??= static fn (): float => microtime(true);
    $first = null;
    $bytes = 0;
    $movedAt = 0.0;

    return function ($curl, $total, int $now) use ($clock, &$first, &$bytes, &$movedAt): int {
        if ($now <= 0) return 0;
        $at = $clock();
        $first ??= $at;
        if ($now > $bytes) {
            $bytes = $now;
            $movedAt = $at;
        }

        return $at - $movedAt > COLORING_POOL_BODY_SECONDS || $at - $first > COLORING_POOL_BODY_MAX_SECONDS ? 1 : 0;
    };
}

/**
 * D-656 review: a resend is a second provider call (the first may already be billed), so it is counted against the same AI rate
 * ceilings as every call (per actor per hour, global per day). Over a ceiling it is not sent and the page fails as before. The
 * failed first attempt has no usage row: with no response there are no token counts to record (the serial path is the same).
 */
function coloring_pool_count_resend(): bool
{
    try {
        rate_limit_ai_call();

        return true;
    } catch (RuntimeException $exception) {
        error_log('coloring-engine: coloring pool request not sent again: ' . $exception->getMessage());

        return false;
    }
}

/**
 * Where pool responses wait (D-646): a folder beside the database, not the system temp folder, swept of anything older than an
 * hour each time a unit starts. Each file is unlinked as soon as it is open, so even a killed worker leaves nothing behind on
 * Linux; the sweep covers the moment between the open and the unlink. Null when the folder cannot be made.
 */
function coloring_pool_spool_dir(): ?string
{
    $dir = application_data_directory() . '/tmp/coloring-pool';
    if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) return null;
    foreach (glob($dir . '/pool-*') ?: [] as $old) {
        if ((int) @filemtime($old) < time() - 3600) @unlink($old);
    }

    return $dir;
}

/** An open, already unlinked file for one response body, or null (the caller then keeps the body in memory). */
function coloring_pool_spool_file(?string $dir)
{
    if ($dir === null) return null;
    $path = $dir . '/pool-' . getmypid() . '-' . bin2hex(random_bytes(6));
    $file = @fopen($path, 'w+b');
    if ($file === false) return null;
    @unlink($path);

    return $file;
}
