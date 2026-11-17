<?php

declare(strict_types=1);

/*
 * D-649: a started book is picked up at once instead of at the next minute.
 *
 * Three small files in the data directory. `background-worker.wake` is touched when a book is queued; a running loop
 * watches its mtime and starts a pass within about a second. `background-worker.alive` is touched by the loop at every
 * pass start. When it is older than 90 s no loop is running (HostGator resets the cron), so the start spawns one detached
 * single pass instead, at most one per 30 s (`background-worker.spawned`), under the same lock file as the cron line.
 * Nothing here throws or blocks a request: a failure is logged once and the cron picks the book up as before.
 */

require_once __DIR__ . '/db.php';

const BACKGROUND_WORKER_ALIVE_SECONDS = 90;
const BACKGROUND_WORKER_SPAWN_GAP_SECONDS = 30;
/** A signal file dated further ahead than this was written by a clock that has since stepped back: it counts as old. */
const BACKGROUND_WORKER_FUTURE_SLACK_SECONDS = 2;

function background_worker_signal_path(string $name): string
{
    return application_data_directory() . '/background-worker.' . $name;
}

/** Touch a signal file. False when it cannot be written; that is logged once per process and is otherwise harmless. */
function background_worker_touch(string $name): bool
{
    static $logged = false;
    try {
        $path = background_worker_signal_path($name);
        $dir = dirname($path);
        if ((is_dir($dir) || @mkdir($dir, 0775, true)) && @touch($path)) return true;
    } catch (Throwable) {
    }
    if (!$logged) {
        $logged = true;
        error_log('coloring-engine: background worker signal file cannot be written in ' . application_data_directory());
    }

    return false;
}

/** Tell a running loop to start a pass now. COLORING_WORKER_WAKE=0 turns it off. */
function background_worker_wake(): void
{
    if (getenv('COLORING_WORKER_WAKE') === '0') return;
    background_worker_touch('wake');
}

/** The loop's sign of life, touched at every pass start. */
function background_worker_heartbeat(): void
{
    background_worker_touch('alive');
}

/** True when the signal file was touched in the last $seconds. A future date (the clock stepped back) is not recent. */
function background_worker_recent(string $name, int $seconds, ?int $now = null): bool
{
    $path = background_worker_signal_path($name);
    clearstatcache(true, $path);
    $at = @filemtime($path);
    $now ??= time();

    return $at !== false && $at >= $now - $seconds && $at <= $now + BACKGROUND_WORKER_FUTURE_SLACK_SECONDS;
}

/** True when a loop started a pass in the last 90 s. */
function background_worker_alive(?int $now = null): bool
{
    return background_worker_recent('alive', BACKGROUND_WORKER_ALIVE_SECONDS, $now);
}

/** A book was queued: wake the loop, and when no loop is alive start one pass. Never throws. */
function background_worker_nudge(): void
{
    try {
        background_worker_wake();
        if (!background_worker_alive()) background_worker_spawn();
    } catch (Throwable $exception) {
        error_log('coloring-engine: background worker nudge failed: ' . substr($exception->getMessage(), 0, 200));
    }
}

/**
 * The PHP CLI to run the worker with: COLORING_WORKER_PHP when set, PHP_BINARY under the CLI and the built-in server (under
 * FPM or CGI it is not the CLI), else /usr/local/bin/php (the host's CLI). Null when none is runnable.
 */
function background_worker_php(): ?string
{
    $configured = trim((string) getenv('COLORING_WORKER_PHP'));
    if ($configured !== '') return is_file($configured) && is_executable($configured) ? $configured : null;
    if (in_array(PHP_SAPI, ['cli', 'cli-server'], true)) return PHP_BINARY;

    return is_executable('/usr/local/bin/php') ? '/usr/local/bin/php' : null;
}

function background_worker_home(): string
{
    $home = trim((string) getenv('HOME'));
    if ($home === '' && function_exists('posix_getpwuid') && function_exists('posix_geteuid')) {
        $home = (string) (posix_getpwuid(posix_geteuid())['dir'] ?? '');
    }

    return $home;
}

/** The cron line's `flock -n` file (README, Worker cron); BACKGROUND_WORKER_LOCK_FILE overrides it. Null when unknown. */
function background_worker_lock_path(): ?string
{
    $configured = trim((string) getenv('BACKGROUND_WORKER_LOCK_FILE'));
    if ($configured !== '') return $configured;
    $home = background_worker_home();

    return $home === '' ? null : $home . '/.config/coloring-engine-worker.lock';
}

function background_worker_can_spawn(): bool
{
    if (!function_exists('proc_open')) return false;
    $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));

    return !in_array('proc_open', $disabled, true);
}

/**
 * Start one detached worker pass (`--limit=20`, the cron shape; one pass draws a whole book at K=4) and return at once.
 * A shell backgrounds the worker and exits, so the request only waits for that shell and the worker is re-parented to init;
 * it outlives the request. The worker takes the cron lock itself (`--lock-file`) and leaves at once when a loop holds it.
 * COLORING_WORKER_SPAWN=0 turns it off; under the plain CLI (selfchecks, the worker itself) it is off unless set to 1.
 * True when a worker was started.
 */
function background_worker_spawn(): bool
{
    $switch = getenv('COLORING_WORKER_SPAWN');
    if ($switch === '0' || ($switch !== '1' && PHP_SAPI === 'cli')) return false;
    if (!background_worker_can_spawn()) return false;
    $php = background_worker_php();
    $lock = background_worker_lock_path();
    if ($php === null || $lock === null) return false;
    /* ponytail: check then touch is not atomic, two starts in the same instant can both spawn; the lock lets only one run. */
    if (background_worker_recent('spawned', BACKGROUND_WORKER_SPAWN_GAP_SECONDS - 1)) return false;
    if (!background_worker_touch('spawned')) return false;

    $root = dirname(__DIR__);
    $log = application_data_directory() . '/logs/worker.log';
    if (!is_dir(dirname($log)) || !is_writable(dirname($log)) || (is_file($log) && !is_writable($log))) $log = '/dev/null';
    $envFile = trim((string) getenv('COLORING_WORKER_ENV_FILE'));
    if ($envFile === '') $envFile = background_worker_home() . '/.config/coloring-engine-worker.env';
    /* The worker gets what cron gives it: the worker env file when there is one, and the database this request used. */
    $script = 'log=$1; env_file=$2; shift 2; if [ -r "$env_file" ]; then set -a; . "$env_file"; set +a; fi; '
        . 'if command -v setsid >/dev/null 2>&1; then setsid "$@" </dev/null >>"$log" 2>&1 & else "$@" </dev/null >>"$log" 2>&1 & fi';
    $env = ['COLORING_DATABASE_PATH' => application_database_path()] + getenv();
    if (!isset($env['PATH'])) $env['PATH'] = '/usr/local/bin:/usr/bin:/bin';
    if (!isset($env['HOME']) && background_worker_home() !== '') $env['HOME'] = background_worker_home();
    $process = @proc_open(['/bin/sh', '-c', $script, 'sh', $log, $envFile, $php, $root . '/bin/worker',
        '--limit=20', '--lock-file=' . $lock], [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'],
        2 => ['file', '/dev/null', 'w']], $pipes, $root, $env);
    if (!is_resource($process)) {
        error_log('coloring-engine: background worker spawn failed');
        return false;
    }
    proc_close($process);

    return true;
}
