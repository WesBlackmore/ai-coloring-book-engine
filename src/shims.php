<?php

declare(strict_types=1);

/*
 * Standalone replacements for the host app's helpers. The engine does not bill and does not know users; the
 * consuming project does both. What stays real: the retry/backoff policy and the AI spend guard (src/ratelimit.php).
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/ratelimit.php';

/** Preview size for a locked book's cover thumbnail (coloring_cover_asset.php). Nothing is ever locked here; kept so the constant resolves. */
const COLORING_LOCKED_IMAGE_MAX = 400;

/* Billing is the host's job: reserving, capturing and releasing always succeed. */
function reserve_ai_credits(int $userId, int $amount, string $reservationKey, string $operation, ?string $reference = null, ?PDO $pdo = null): array
{
    return ['reservation_key' => $reservationKey, 'amount' => $amount, 'status' => 'reserved'];
}

function capture_ai_credits(string $reservationKey, string $eventKey, ?string $resultReference = null, ?int $amount = null, ?PDO $pdo = null): void
{
}

function release_ai_credits(string $reservationKey, ?PDO $pdo = null): void
{
}

/** Every project is unlocked in the standalone engine (billing and paywalls are the host's job). */
function coloring_project_is_unlocked(array $project): bool
{
    return true;
}

/** error_log plus a JSON line in data/logs/app.log. Returns the diagnostic id. */
function app_diagnostic_log(Throwable $exception, array $context = []): string
{
    $id = bin2hex(random_bytes(8));
    $directory = application_data_directory() . '/logs';
    if (!is_dir($directory)) @mkdir($directory, 0700, true);
    $line = json_encode(['time' => gmdate('c'), 'id' => $id, 'context' => $context, 'exception' => get_class($exception),
        'message' => mb_substr($exception->getMessage(), 0, 1000), 'file' => $exception->getFile(), 'line' => $exception->getLine()],
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (is_string($line)) @file_put_contents($directory . '/app.log', $line . PHP_EOL, FILE_APPEND | LOCK_EX);
    error_log('coloring-engine diagnostic ' . $id . ': ' . get_class($exception) . ': ' . mb_substr($exception->getMessage(), 0, 300));

    return $id;
}

/* Retry policy, copied from the host's src/onboarding.php: how many failures a book may have, and how long to wait between them. */
const FLOW_QUICK_RETRY_FAULTS = ['did not move the manuscript forward', 'malformed outline'];
const FLOW_QUICK_RETRY_SECONDS = 120;
const COLORING_QUICK_RETRY_FAULTS = ['timed out', '(HTTP 5', '(HTTP 429)', 'AI generation is busy', 'lost its work lease'];
const COLORING_QUICK_RETRY_ATTEMPTS = 3;

/** Attempts before a book stalls. BOOK_RETRY_LIMIT overrides the default of 6. */
function flow_failure_limit(): int
{
    $configured = trim((string) getenv('BOOK_RETRY_LIMIT'));

    return $configured === '' ? 6 : max(1, (int) $configured);
}

function flow_failure_backoff_seconds(int $failureCount, string $failureKind = '', bool $coloring = false): int
{
    foreach ($coloring ? COLORING_QUICK_RETRY_FAULTS : FLOW_QUICK_RETRY_FAULTS as $fault) {
        if (str_contains($failureKind, $fault) && (!$coloring || $failureCount <= COLORING_QUICK_RETRY_ATTEMPTS)) return FLOW_QUICK_RETRY_SECONDS;
    }
    $attempt = max(1, $failureCount);

    return min(6 * 3600, 18 * 60 * $attempt * $attempt);
}

/** `retry` when the wait has passed, `wait` while it has not, `stalled` once the attempts are spent. */
function flow_failure_state(array $book, ?string $now = null): string
{
    $count = (int) ($book['failure_count'] ?? 0);
    if ($count < 1) return 'retry';
    if ($count >= flow_failure_limit()) return 'stalled';

    $failedAt = trim((string) ($book['failure_at'] ?? ''));
    if ($failedAt === '') return 'retry';
    $due = strtotime($failedAt . ' UTC');
    if ($due === false) return 'retry';

    $clock = $now === null ? time() : (strtotime($now . ' UTC') ?: time());
    $coloring = array_key_exists('pages_json', $book);

    return $clock >= $due + flow_failure_backoff_seconds($count, (string) ($book['failure_kind'] ?? ''), $coloring) ? 'retry' : 'wait';
}

/**
 * Optional "book is ready" webhook. Inert unless COLORING_READY_WEBHOOK is set. POSTs JSON; never throws, so a dead
 * endpoint cannot undo a finished book.
 */
function notify_coloring_ready(array $project): void
{
    $url = trim((string) getenv('COLORING_READY_WEBHOOK'));
    if ($url === '') return;
    $body = json_encode(['event' => 'coloring.ready', 'project_id' => (int) $project['id'], 'owner_ref' => $project['owner_ref'] ?? null,
        'title' => (string) ($project['title'] ?? '')], JSON_UNESCAPED_SLASHES);
    $handle = curl_init($url);
    curl_setopt_array($handle, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['content-type: application/json'], CURLOPT_CONNECTTIMEOUT => 3, CURLOPT_TIMEOUT => 10]);
    curl_exec($handle);
    $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    if ($status < 200 || $status >= 300) error_log('coloring-engine: ready webhook answered ' . $status . ' for project ' . (int) $project['id']);
}
