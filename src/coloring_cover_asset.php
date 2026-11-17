<?php

declare(strict_types=1);

/*
 * A coloring cover on a path Cloudflare will cache (D-442).
 *
 * The library asked for `/?p=coloring-image&path=…&size=512`. That is a query string, so Cloudflare
 * never cached it; it started a session and checked the login on every view; and its cache header
 * was `private`. On this host each of those requests waits seconds for PHP, which is why the coloring
 * cover always arrived last. A text book's cover has none of that (D-352), so this gives the coloring
 * cover the same treatment: a `.jpg` path, served before any session, cached for a year.
 *
 * The file name holds 96 random bits and must belong to a real project, as a book cover's does.
 */

/** @return string|null a cacheable thumbnail URL, or null when the path is not a coloring cover */
function coloring_cover_asset_url(?string $coverPath): ?string
{
    if (!is_string($coverPath) || preg_match('#^coloring/cover-([a-f0-9]{24})\.jpg$#', $coverPath, $m) !== 1) {
        return null;
    }

    return '/coloring-covers/thumb/' . $m[1] . '.jpg';
}

/** Send the 512px thumbnail for a cover, then stop. Answers 404 for anything that is not a project's cover. */
function coloring_cover_asset_serve(string $asset): void
{
    $relative = 'coloring/cover-' . $asset . '.jpg';
    $known = preg_match('/^[a-f0-9]{24}$/', $asset) === 1;
    if ($known) {
        $row = db()->prepare('SELECT * FROM coloring_projects WHERE cover_path = ? LIMIT 1');
        $row->execute([$relative]);
        $project = $row->fetch();
        $known = is_array($project);
    }
    $source = application_data_directory() . '/' . $relative;
    if (!$known || !is_file($source)) {
        http_response_code(404);
        exit('Cover not found.');
    }

    /* The same file the project page already renders, so an existing thumbnail is reused. */
    /* D-632: a locked project's cover is a 400 px preview and is not cached for a year; the unlock changes what this URL returns. */
    require_once __DIR__ . '/coloring_book.php';
    $locked = !coloring_project_is_unlocked($project);
    $width = $locked ? COLORING_LOCKED_IMAGE_MAX : 512;
    $directory = application_data_directory() . '/coloring/thumbs';
    $thumb = $directory . '/' . $width . '_cover-' . $asset . '.jpg';
    if (!is_file($thumb) && function_exists('imagecreatefromjpeg')
        && (is_dir($directory) || @mkdir($directory, 0755, true))) {
        $image = @imagecreatefromjpeg($source);
        if ($image !== false) {
            $small = imagescale($image, $width);
            if ($small !== false) {
                imagejpeg($small, $thumb . '.tmp', 82);
                rename($thumb . '.tmp', $thumb);
            }
        }
    }
    /* A missing GD extension shows the cover slowly, never not at all. Never the original for a locked project. */
    if ($locked && !is_file($thumb)) {
        http_response_code(404);
        exit('Cover not found.');
    }
    $serve = is_file($thumb) ? $thumb : $source;

    header('Content-Type: image/jpeg');
    header('Content-Length: ' . filesize($serve));
    header('Cache-Control: ' . ($locked ? 'private, max-age=300' : 'public, max-age=31536000, immutable'));
    readfile($serve);
    exit;
}
