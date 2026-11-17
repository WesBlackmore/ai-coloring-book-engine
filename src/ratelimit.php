<?php

declare(strict_types=1);

/**
 * Fixed-window counters held as files under `data/ratelimit/`, copied from the host app. The spend guard: a ceiling on
 * provider calls per hour and per day, and the slot pools that cap concurrent provider calls. The web-request parts of
 * the original (client IP, sign-in throttling) are gone: the engine's only actor is itself.
 *
 * Limits come from the environment: AI_RATE_ACTOR_HOUR (default 150), AI_RATE_GLOBAL_DAY (default 3000),
 * AI_CONCURRENT_LIMIT, AI_IMAGE_CONCURRENT_LIMIT, COLORING_PAGE_PARALLEL.
 *
 * ponytail: a fixed window lets a caller spend two windows' worth of calls across the boundary between them.
 */

require_once __DIR__ . '/db.php';

/** Requests per actor, per hour, across every AI provider call. */
const AI_RATE_ACTOR_HOUR = 150;
/** Requests across the whole installation, per day. The last stop before a surprise bill. */
const AI_RATE_GLOBAL_DAY = 3000;

function rate_limit_dir(): string
{
    $dir = application_data_directory() . '/ratelimit';
    if (!is_dir($dir)) {
        mkdir($dir, 0700, true);
    }

    return $dir;
}

/**
 * Count one hit against `$key`. Answer false when this window is already over `$max`.
 * A disk that cannot be written answers false: silently allowing requests defeats the boundary.
 */
function rate_limit_ok(string $key, int $max, int $windowSeconds): bool
{
    $window = intdiv(time(), max(1, $windowSeconds));
    $file = rate_limit_dir() . '/' . hash('sha256', $key . '|' . $window) . '.count';
    $handle = @fopen($file, 'c+');
    if ($handle === false) {
        error_log('coloring-engine: rate-limit storage is unavailable; refusing the request.');
        return false;
    }
    if (!flock($handle, LOCK_EX)) {
        fclose($handle);
        error_log('coloring-engine: rate-limit lock could not be acquired; refusing the request.');
        return false;
    }
    $count = (int) stream_get_contents($handle) + 1;
    rewind($handle);
    ftruncate($handle, 0);
    $written = fwrite($handle, (string) $count);
    fflush($handle);
    flock($handle, LOCK_UN);
    fclose($handle);
    if ($written === false) {
        error_log('coloring-engine: rate-limit counter could not be written; refusing the request.');
        return false;
    }
    rate_limit_sweep();

    return $count <= $max;
}

/** An expired window can never be read again. Delete it here rather than adding a second cron job. */
function rate_limit_sweep(): void
{
    if (random_int(1, 200) !== 1) {
        return;
    }
    foreach (glob(rate_limit_dir() . '/*.count') ?: [] as $file) {
        if ((int) @filemtime($file) < time() - 172800) {
            @unlink($file);
        }
    }
}

/** Who is spending. The engine has no users; the host meters its own callers. */
function rate_limit_actor(): string
{
    return 'engine';
}

function rate_limit_env(string $name, int $default): int
{
    $value = (int) getenv($name);

    return $value > 0 ? $value : $default;
}

/** The single spend guard. Every provider call passes through ai_http_json(), which runs this once. */
function rate_limit_ai_call(): void
{
    $perActor = rate_limit_env('AI_RATE_ACTOR_HOUR', AI_RATE_ACTOR_HOUR);
    if (!rate_limit_ok('ai:' . rate_limit_actor(), $perActor, 3600)) {
        throw new RuntimeException('The hourly limit for AI requests was reached. Please try again in an hour.');
    }
    $perDay = rate_limit_env('AI_RATE_GLOBAL_DAY', AI_RATE_GLOBAL_DAY);
    if (!rate_limit_ok('ai:global', $perDay, 86400)) {
        error_log('coloring-engine: the global daily AI call ceiling of ' . $perDay . ' was reached.');
        throw new RuntimeException('The daily AI generation limit was reached. Please try again tomorrow.');
    }
}

/**
 * Coloring pages drawn at the same time by one worker unit (D-645). 1 is the serial path; the default is 4. Raise it with
 * COLORING_PAGE_PARALLEL once memory_limit allows (image memory counts against it).
 */
function coloring_page_parallel(): int
{
    $configured = trim((string) getenv('COLORING_PAGE_PARALLEL'));

    return $configured === '' ? 4 : min(8, max(1, (int) $configured));
}

/** Which slot pool a provider call takes: the coloring image calls have their own, so pages cannot starve text calls. */
function ai_concurrency_pool(?array $usageContext): string
{
    return in_array($usageContext['operation'] ?? '', ['coloring_page', 'coloring_cover', 'coloring_cover_check',
        'coloring_cover_outline'], true) ? 'image' : 'text';
}

/** Non-blocking global admission control so long provider calls cannot occupy every worker. */
function ai_concurrency_acquire(string $pool = 'text')
{
    [$limit, $prefix] = $pool === 'image'
        ? [min(32, max(1, rate_limit_env('AI_IMAGE_CONCURRENT_LIMIT', coloring_page_parallel() + 1))), 'ai-image-slot-']
        : [min(32, max(1, rate_limit_env('AI_CONCURRENT_LIMIT', 2))), 'ai-slot-'];
    for ($slot = 0; $slot < $limit; $slot++) {
        $file = rate_limit_dir() . '/' . $prefix . $slot . '.lock';
        $handle = @fopen($file, 'c+');
        if ($handle !== false && flock($handle, LOCK_EX | LOCK_NB)) {
            return $handle;
        }
        if ($handle !== false) fclose($handle);
    }
    throw new RuntimeException('AI generation is busy. Please try again in a moment.');
}

function ai_concurrency_release($handle): void
{
    if (is_resource($handle)) {
        flock($handle, LOCK_UN);
        fclose($handle);
    }
}
