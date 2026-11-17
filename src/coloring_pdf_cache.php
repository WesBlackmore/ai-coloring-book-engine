<?php

declare(strict_types=1);

/*
 * The coloring interior PDF, rendered once (D-643).
 *
 * The download used to render the whole book inside the request, every time, with no-store: 26 to
 * 40 s for 16 pages. The rendered file is now kept next to the eBook exports, keyed by a content
 * signature in `<file>.sig` (the D-566 pattern), and written to a temp name then renamed (D-567).
 * The signature covers everything the PDF is built from: the project fields the layout reads, the
 * size and mtime of every drawing and the cover outline, and the code that lays it out. So a
 * reorder, a redraw, a new outline, a caption or a deploy of the layout code renders afresh on the
 * next request, and nothing else does.
 *
 * Render points: the first download (behind a per-project lock; a second request meanwhile gets a
 * short "preparing" page that retries by itself), and the background worker, which renders the
 * unlocked books that changed in the last two days so the buyer's first click is served from disk.
 * A locked book is never rendered. At most COLORING_PDF_CACHE_MAX files are kept, least recently
 * served first out; deleting a project deletes its file.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/coloring_book.php';
require_once __DIR__ . '/shims.php';

/** About 3 to 5 MB each. */
const COLORING_PDF_CACHE_MAX = 40;
/** The worker renders unlocked ready books whose row changed this recently (unlock, finish, redraw, reorder). */
const COLORING_PDF_PRERENDER_WINDOW = '-2 days';

function coloring_pdf_cache_dir(): string
{
    return application_data_directory() . '/exports';
}

function coloring_pdf_cache_path(int $projectId): string
{
    return coloring_pdf_cache_dir() . '/coloring-' . $projectId . '.pdf';
}

function coloring_pdf_signature(array $project): string
{
    clearstatcache();
    $dataDir = isset($GLOBALS['mcp_coloring_data_directory']) && is_string($GLOBALS['mcp_coloring_data_directory'])
        ? $GLOBALS['mcp_coloring_data_directory'] : application_data_directory();
    $files = array_column(json_decode((string) ($project['pages_json'] ?? '') ?: '[]', true) ?: [], 'path');
    $files[] = (string) ($project['cover_outline_path'] ?? '');
    $stats = [];
    foreach ($files as $file) {
        $path = $dataDir . '/' . $file;
        $stats[] = [$file, is_file($path) ? filesize($path) : -1, is_file($path) ? filemtime($path) : -1];
    }
    $fields = [];
    foreach (['id', 'title', 'trim', 'audience', 'captions', 'pages_json', 'plan_json', 'cover_outline_path'] as $key) {
        $fields[$key] = (string) ($project[$key] ?? '');
    }
    /* The layout code, the print margins it reads from src/ai.php and the dompdf release that renders it (D-646). */
    $code = [filemtime(__DIR__ . '/coloring_book.php'), filemtime(__DIR__ . '/coloring_cover.php'), filemtime(__FILE__),
        COLORING_MARGIN_GUTTER_IN, COLORING_MARGIN_OUTSIDE_IN, trim((string) @file_get_contents(dirname(__DIR__) . '/vendor/dompdf/dompdf/VERSION'))];

    return md5(serialize([$fields, $stats, $code]));
}

/** The cached file when it matches the project as it is now, else null. */
function coloring_pdf_cache_fresh(array $project, ?string $signature = null): ?string
{
    $path = coloring_pdf_cache_path((int) $project['id']);
    if (!is_file($path) || @file_get_contents($path . '.sig') !== ($signature ?? coloring_pdf_signature($project))) return null;
    @touch($path . '.sig');   // least recently served goes first when the cache is full

    return $path;
}

/**
 * The cached PDF path, rendering it first when needed. Returns null when another request or the
 * worker is rendering this book right now. Throws for a locked book or a failed render.
 */
function coloring_pdf_cache_build(array $project): ?string
{
    if (!coloring_project_is_unlocked($project)) throw new RuntimeException('A locked coloring book is never rendered.');
    $signature = coloring_pdf_signature($project);
    $fresh = coloring_pdf_cache_fresh($project, $signature);
    if ($fresh !== null) return $fresh;

    $dir = coloring_pdf_cache_dir();
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) throw new RuntimeException('Unable to create the export directory.');
    $path = coloring_pdf_cache_path((int) $project['id']);
    $lock = @fopen($path . '.lock', 'c');
    if ($lock === false) throw new RuntimeException('Unable to open the PDF render lock.');
    /* Busy only when another process holds the lock. A filesystem that cannot lock at all (some network mounts) renders
       without it rather than answering "almost ready" for ever (D-646). */
    $locked = flock($lock, LOCK_EX | LOCK_NB, $wouldBlock);
    if (!$locked && $wouldBlock) {
        fclose($lock);
        return null;
    }
    try {
        /* Another process may have finished it while this one waited for the lock. */
        $fresh = coloring_pdf_cache_fresh($project, $signature);
        if ($fresh !== null) return $fresh;
        @set_time_limit(180);
        $limit = ini_get('memory_limit');
        if ($limit !== '-1' && ini_parse_quantity((string) $limit) < 512 * 1024 * 1024) @ini_set('memory_limit', '512M');
        $bytes = coloring_export_pdf($project);
        $tmp = $path . '.tmp.' . getmypid() . '-' . bin2hex(random_bytes(4));
        if (file_put_contents($tmp, $bytes) !== strlen($bytes) || !rename($tmp, $path)) {
            @unlink($tmp);
            throw new RuntimeException('Could not write the coloring PDF.');
        }
        file_put_contents($path . '.sig', $signature, LOCK_EX);
        @unlink($path . '.failed');
        coloring_pdf_cache_prune($path);

        return $path;
    } finally {
        if ($locked) flock($lock, LOCK_UN);
        fclose($lock);
    }
}

/**
 * Keep at most COLORING_PDF_CACHE_MAX files; the least recently served go first, with their signature, note and lock. Also
 * sweeps what a killed render leaves behind: temp files and orphan locks older than an hour (D-646).
 */
function coloring_pdf_cache_prune(string $keep = ''): void
{
    $hourAgo = time() - 3600;
    $dir = coloring_pdf_cache_dir();
    foreach (array_merge(glob($dir . '/coloring-*.pdf.tmp.*') ?: [], glob($dir . '/coloring-*.pdf.lock') ?: []) as $leftover) {
        $orphan = str_ends_with($leftover, '.lock') ? !is_file(substr($leftover, 0, -5)) : true;
        if ($orphan && (int) @filemtime($leftover) < $hourAgo) @unlink($leftover);
    }
    $files = glob(coloring_pdf_cache_dir() . '/coloring-*.pdf') ?: [];
    if (count($files) <= COLORING_PDF_CACHE_MAX) return;
    $used = [];
    foreach ($files as $file) {
        if ($file !== $keep) $used[$file] = (int) @filemtime(is_file($file . '.sig') ? $file . '.sig' : $file);
    }
    asort($used);
    foreach (array_slice(array_keys($used), 0, count($files) - COLORING_PDF_CACHE_MAX) as $file) {
        foreach (['', '.sig', '.failed', '.lock'] as $suffix) @unlink($file . $suffix);
    }
}

/** Deleting a project deletes its PDF, signature, failure note and lock. */
function coloring_pdf_cache_forget(int $projectId): void
{
    foreach (['', '.sig', '.failed', '.lock'] as $suffix) @unlink(coloring_pdf_cache_path($projectId) . $suffix);
}

/**
 * Background pass: render up to `$limit` unlocked, ready books that changed recently and have no
 * fresh file. A book whose render failed is not retried until it changes (the `.failed` note holds
 * the signature that failed). Returns the number rendered.
 */
function coloring_pdf_prerender_run(int $limit = 1): int
{
    if (is_dir(coloring_pdf_cache_dir())) coloring_pdf_cache_prune();
    /* The unlock filter is in the query, before the LIMIT, so locked free books cannot crowd out a newly unlocked one (D-646).
       A plan opens every book without touching updated_at, so a subscriber's books qualify whatever their age; those older
       than the window wait while the cache is full, so a big plan library cannot evict and re-render in a loop. */
    $rows = db()->prepare("SELECT p.*, p.updated_at >= datetime('now', ?) AS recent FROM coloring_projects p
        WHERE p.status = 'ready' AND p.unlocked = 1 AND p.updated_at >= datetime('now', ?)
        ORDER BY p.updated_at DESC LIMIT 100");
    $rows->execute([COLORING_PDF_PRERENDER_WINDOW, COLORING_PDF_PRERENDER_WINDOW]);
    $candidates = $rows->fetchAll();
    $full = count(glob(coloring_pdf_cache_dir() . '/coloring-*.pdf') ?: []) >= COLORING_PDF_CACHE_MAX;
    $rendered = 0;
    foreach ($candidates as $project) {
        if ($rendered >= $limit) break;
        $recent = (int) $project['recent'] === 1;
        unset($project['recent']);
        if (!coloring_project_is_unlocked($project) || ($full && !$recent)) continue;
        $signature = coloring_pdf_signature($project);
        $path = coloring_pdf_cache_path((int) $project['id']);
        if (coloring_pdf_cache_fresh($project, $signature) !== null || @file_get_contents($path . '.failed') === $signature) continue;
        try {
            if (coloring_pdf_cache_build($project) !== null) $rendered++;
        } catch (Throwable $exception) {
            if (is_dir(coloring_pdf_cache_dir())) @file_put_contents($path . '.failed', $signature);
            app_diagnostic_log($exception, ['area' => 'coloring_pdf_prerender', 'project' => (int) $project['id']]);
        }
    }

    return $rendered;
}

/** Send a cached file with validators, so a repeat or resumed download is a 304 or a 206. */
function coloring_pdf_send(string $path, string $filename): void
{
    $size = (int) filesize($path);
    $mtime = (int) filemtime($path);
    $etag = '"' . (string) @file_get_contents($path . '.sig') . '"';
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: private, max-age=0, must-revalidate');
    header_remove('Pragma');    // the session's nocache limiter; the validators below decide freshness
    header_remove('Expires');
    header('ETag: ' . $etag);
    header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $mtime) . ' GMT');
    header('Accept-Ranges: bytes');

    $ifNoneMatch = trim((string) ($_SERVER['HTTP_IF_NONE_MATCH'] ?? ''));
    $tags = array_map(static fn (string $tag): string => preg_replace('#^W/#', '', trim($tag)), explode(',', $ifNoneMatch));
    $ifModifiedSince = strtotime((string) ($_SERVER['HTTP_IF_MODIFIED_SINCE'] ?? '')) ?: 0;
    if ($ifNoneMatch !== '' ? ($ifNoneMatch === '*' || in_array($etag, $tags, true)) : ($ifModifiedSince >= $mtime)) {
        http_response_code(304);
        return;
    }

    $ifRange = trim((string) ($_SERVER['HTTP_IF_RANGE'] ?? ''));
    if (($ifRange === '' || $ifRange === $etag)
        && preg_match('/^bytes=(\d*)-(\d*)$/', trim((string) ($_SERVER['HTTP_RANGE'] ?? '')), $m) === 1 && $m[1] . $m[2] !== '') {
        [$start, $end] = $m[1] === '' ? [max(0, $size - (int) $m[2]), $size - 1] : [(int) $m[1], $m[2] === '' ? $size - 1 : min((int) $m[2], $size - 1)];
        if ($start > $end || $start >= $size) {
            http_response_code(416);
            header('Content-Range: bytes */' . $size);
            return;
        }
        http_response_code(206);
        header('Content-Range: bytes ' . $start . '-' . $end . '/' . $size);
        header('Content-Length: ' . ($end - $start + 1));
        $in = fopen($path, 'rb');
        $out = fopen('php://output', 'wb');
        stream_copy_to_stream($in, $out, $end - $start + 1, $start);
        fclose($in);
        return;
    }
    header('Content-Length: ' . $size);
    readfile($path);
}
