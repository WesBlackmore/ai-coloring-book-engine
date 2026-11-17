<?php

declare(strict_types=1);

/*
 * The cover step of a coloring book (docs/COLORING-BOOK-LAUNCH-SPEC.md 5b). The prompt and the API calls live in
 * src/ai.php; this file decides what the cover says, redoes a cover that fails our own spelling check (free, up to
 * COLORING_FREE_COVER_REDOS times) and saves the result. The last cover is kept even if it still fails: the customer
 * can buy another for COLORING_COVER_CREDITS.
 */

require_once __DIR__ . '/ai.php';
require_once __DIR__ . '/coloring_book.php';

/** @return array{title_lines:array<int,string>,subtitle:string,badge:string,theme:string,background:string} */
function coloring_cover_brief(array $project): array
{
    $plan = json_decode((string) $project['plan_json'], true) ?: [];
    $cover = is_array($plan['cover'] ?? null) ? $plan['cover'] : [];
    $words = coloring_cover_words($project);
    $lines = array_values(array_filter(array_map(fn($l) => strtoupper(trim((string) $l)), (array) ($cover['title_lines'] ?? []))));
    /* D-656: the plan's line breaks are kept only while they spell the book's title, so the cover never shows a different title
       from the page (the plan model sometimes wrote other words, and an edited title never reached the lines). */
    $letters = static fn (string $text): string => (string) preg_replace('/[^A-Z0-9]+/', '', strtoupper($text));
    if (trim((string) ($project['title'] ?? '')) !== '' && $letters(implode(' ', $lines)) !== $letters((string) $project['title'])) $lines = [];
    if ($lines === []) {
        /* No brief in the plan: break the title into at most three lines of about 16 characters. */
        $lines = explode("\n", wordwrap(strtoupper(trim((string) ($project['title'] ?: $project['idea']))), 16, "\n", true));
        $lines = array_slice($lines, 0, 3);
    }

    return [
        'title_lines' => array_slice($lines, 0, 3),
        'subtitle' => $words['subtitle'],
        'badge' => $words['badge'],
        'theme' => trim((string) ($cover['theme'] ?? '')) ?: (string) $project['idea'],
        'background' => trim((string) ($cover['background'] ?? '')) ?: 'colours and a setting that suit the book idea',
        'audience' => (string) ($project['audience'] ?? 'kids'),
        'style' => (string) ($project['style'] ?? ''),
        'trim' => coloring_trim_key((string) ($project['trim'] ?? 'portrait')),
        /* Resolve 'auto' to a concrete key here, so the cover prompt sees a real choice (D-493). */
        'cover_template' => (function () use ($project) {
            $t = coloring_cover_template_key((string) ($project['cover_template'] ?? 'auto'));
            return $t === 'auto' ? coloring_cover_template_auto_pick((string) ($project['audience'] ?? 'kids'),
                (string) ($project['style'] ?? '')) : $t;
        })(),
        /* The customer's own words, so the cover's subjects and colours follow them (D-496). */
        'idea' => trim((string) ($project['idea'] ?? '')),
        'palette_chosen' => coloring_palette_key((string) ($project['palette'] ?? 'auto')) !== 'auto',
        'palette' => (function () use ($project) {
            $p = coloring_palette_key((string) ($project['palette'] ?? 'auto'));
            return $p === 'auto' ? coloring_palette_auto_pick((string) ($project['audience'] ?? 'kids'),
                (string) ($project['style'] ?? '')) : $p;
        })(),
    ];
}

/** Draw, check and save the cover. $extra is 0 for the book's own cover and n for the nth bought replacement. */
function coloring_generate_cover(array $project, int $extra = 0): void
{
    $id = (int) $project['id'];
    $brief = coloring_cover_brief($project);
    /* The title and subtitle are what must be spelt right. The sticker ("AGES 4-8", "24 DESIGNS") is short, stylised text that the readers
       often skip, and a skipped sticker is not a misspelling worth a $0.14 redraw. */
    $expected = array_merge($brief['title_lines'], [$brief['subtitle']]);
    $draw = $GLOBALS['coloring_cover_providers']['draw'] ?? 'generate_coloring_cover';
    $check = $GLOBALS['coloring_cover_providers']['check'] ?? 'coloring_cover_check';
    $context = fn(string $op, int $try) => ['source' => $op, 'user_id' => 0,
        'coloring_project_id' => $id, 'operation' => $op,
        'action_key' => "$op:$id:$extra:$try"];

    $log = [];
    $jpeg = '';
    for ($try = 1; $try <= 1 + COLORING_FREE_COVER_REDOS; $try++) {
        /* D-609: the claim this run holds, never the token a re-read row may now show for a successor. */
        $leaseToken = (string) ($project['lease_token'] ?? $project['generating_token'] ?? '');
        if ($try > 1 && function_exists('coloring_worker_touch') && $leaseToken !== '') {
            coloring_worker_touch($id, $leaseToken);
        }
        $started = hrtime(true);
        $jpeg = $draw($brief, $context('coloring_cover', $try));
        $drawn = hrtime(true);
        $result = $check($jpeg, $expected, $context('coloring_cover_check', $try));
        /* Step timings ride along in cover_check_json (D-645), so a slow cover can be read off the row without a migration. */
        $log[] = $result + ['draw_ms' => intdiv($drawn - $started, 1000000), 'check_ms' => intdiv(hrtime(true) - $drawn, 1000000)];
        if ($result['ok']) break;
    }

    $directory = application_data_directory() . '/coloring';
    if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
        throw new RuntimeException('Unable to create the coloring directory.');
    }
    $path = 'coloring/cover-' . bin2hex(random_bytes(12)) . '.jpg';
    if (file_put_contents(application_data_directory() . '/' . $path, $jpeg) === false) {
        throw new RuntimeException('Unable to save the cover.');
    }
    /* A replacement is published as a pair, so a failed edit leaves the previous book intact. */
    $outline = null;
    try {
        $isReplacement = ($project['status'] ?? '') === 'ready';
        if ($isReplacement) $outline = coloring_save_cover_outline($jpeg, $project);
        $sql = "UPDATE coloring_projects SET cover_path = ?, cover_outline_path = ?, kdp_metadata_json = NULL,
            cover_status = 'done', cover_attempts = ?, cover_check_json = ?, updated_at = datetime('now') WHERE id = ?";
        $params = [$path, $outline, count($log), json_encode($log), $id];
        if ($isReplacement) {
            /* MCP custom-cover replacement does not hold this worker lease. Compare-and-swap the
               source path so whichever replacement publishes first prevents the other overwriting it. */
            $sql .= " AND status = 'ready' AND cover_path = ?";
            $params[] = (string) ($project['cover_path'] ?? '');
        }
        $sql .= coloring_lease_clause($project, $params);
        $update = db()->prepare($sql);
        $update->execute($params);
        if ($update->rowCount() !== 1) throw new RuntimeException('The cover changed while its replacement was being prepared.');
    } catch (Throwable $exception) {
        @unlink(application_data_directory() . '/' . $path);
        if ($outline !== null) @unlink(coloring_outline_data_directory() . '/' . $outline);
        throw $exception;
    }
}

function coloring_outline_data_directory(): string
{
    return $GLOBALS['mcp_coloring_data_directory'] ?? application_data_directory();
}

/** Save an immutable derivative; the caller publishes its path together with its source cover. */
function coloring_save_cover_outline(string $jpeg, array $project): string
{
    $provider = $GLOBALS['coloring_cover_providers']['outline'] ?? 'generate_coloring_cover_outline';
    $png = $provider($jpeg, $project, [
        'source' => 'coloring_cover_outline', 'operation' => 'coloring_cover_outline',
        'user_id' => 0, 'coloring_project_id' => (int) $project['id'],
        'action_key' => 'coloring-cover-outline:' . (int) $project['id'],
    ]);
    $directory = coloring_outline_data_directory() . '/coloring';
    if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
        throw new RuntimeException('Unable to create the coloring directory.');
    }
    $path = 'coloring/' . bin2hex(random_bytes(16)) . '.png';
    if (file_put_contents(coloring_outline_data_directory() . '/' . $path, $png, LOCK_EX) !== strlen($png)) {
        throw new RuntimeException('Unable to save the cover coloring page.');
    }
    return $path;
}

/** Separate worker stage: retrying this conversion never pays to regenerate the colour cover. */
function coloring_generate_cover_outline(array $project): void
{
    if (coloring_has_cover_outline($project)) return;
    $source = coloring_outline_data_directory() . '/' . (string) ($project['cover_path'] ?? '');
    $jpeg = is_file($source) ? file_get_contents($source) : false;
    if (!is_string($jpeg) || $jpeg === '') throw new RuntimeException('The cover is not available yet.');
    $path = coloring_save_cover_outline($jpeg, $project);
    /* SQLite treats `col = NULL` as false, so match an empty snapshot via COALESCE (review2 F7). */
    $params = [$path, (int) $project['id'], $project['cover_path']];
    $update = db()->prepare("UPDATE coloring_projects SET cover_outline_path = ?, kdp_metadata_json = NULL,
        updated_at = datetime('now') WHERE id = ? AND COALESCE(cover_path, '') = COALESCE(?, '')
        AND (cover_outline_path IS NULL OR cover_outline_path = '')" . coloring_lease_clause($project, $params));
    $update->execute($params);
    if ($update->rowCount() !== 1) {
        @unlink(coloring_outline_data_directory() . '/' . $path);
        throw new RuntimeException('The cover changed while its coloring page was being prepared.');
    }
}

/** Amazon's spine width for white paper: 0.002252 inch a page (cream is 0.0025). Spine text is not allowed under 79 pages. */
const COLORING_SPINE_IN_PER_PAGE = 0.002252;
const COLORING_COVER_WRAP_RENDER_DPI = 300;

/**
 * Wrap-around cover size in inches for a book of `$drawings` drawings. The back and front panels
 * are each the book's trim width; the overall height is the trim height plus 0.125 bleed top and
 * bottom. The spine depends on the page count.
 */
function coloring_cover_wrap_size(int $drawings, bool $belongsPage = false, string $trim = 'portrait', bool $coverOutline = false): array
{
    $spine = coloring_print_page_count($drawings, $belongsPage, $coverOutline) * COLORING_SPINE_IN_PER_PAGE;
    require_once __DIR__ . '/coloring_book.php';
    $t = coloring_trims()[coloring_trim_key($trim)];

    return ['width' => 0.125 + $t['page_w'] + $spine + $t['page_w'] + 0.125,
        'height' => $t['page_h'] + 0.25, 'spine' => $spine];
}

/**
 * Preserve front-cover proportions and clip overflow to the KDP front-panel aspect ratio.
 * The source artwork is included once; it is never blurred, tiled, or used as a background.
 * @return array{bytes:string,width:int,height:int,content_width:int,content_height:int,source_width:int,source_height:int}
 */
function coloring_cover_wrap_fit_front(string $jpeg, float $targetRatio, int $targetWidth = 0, int $targetHeight = 0): array
{
    if (!is_finite($targetRatio) || $targetRatio <= 0) throw new InvalidArgumentException('Invalid cover panel ratio.');
    if (($targetWidth === 0) !== ($targetHeight === 0) || $targetWidth < 0 || $targetHeight < 0) {
        throw new InvalidArgumentException('Invalid print cover pixel dimensions.');
    }
    $source = @imagecreatefromstring($jpeg);
    if ($source === false) throw new RuntimeException('This book has no usable cover image.');
    $sourceWidth = imagesx($source);
    $sourceHeight = imagesy($source);
    if ($sourceWidth < 1 || $sourceHeight < 1) {
        throw new RuntimeException('This book has no usable cover image.');
    }

    $sourceRatio = $sourceWidth / $sourceHeight;
    $cropX = 0;
    $cropY = 0;
    $cropWidth = $sourceWidth;
    $cropHeight = $sourceHeight;
    if ($sourceRatio < $targetRatio) {
        $cropHeight = max(1, min($sourceHeight, (int) round($sourceWidth / $targetRatio)));
        /* Cover titles sit near the top. Put ratio-driven trim loss at the bottom bleed, not through the title. */
        $cropY = 0;
    } elseif ($sourceRatio > $targetRatio) {
        $cropWidth = max(1, min($sourceWidth, (int) round($sourceHeight * $targetRatio)));
        $cropX = (int) floor(($sourceWidth - $cropWidth) / 2);
    }
    $sourceCropWidth = $cropWidth;
    $sourceCropHeight = $cropHeight;
    $canvasWidth = $targetWidth > 0 ? $targetWidth : $cropWidth;
    $canvasHeight = $targetHeight > 0 ? $targetHeight : $cropHeight;

    $canvas = imagecreatetruecolor($canvasWidth, $canvasHeight);
    if ($canvas === false) {
        throw new RuntimeException('The print cover could not be prepared.');
    }
    imagecopyresampled($canvas, $source, 0, 0, $cropX, $cropY,
        $canvasWidth, $canvasHeight, $cropWidth, $cropHeight);
    ob_start();
    $saved = imagejpeg($canvas, null, 95);
    $bytes = ob_get_clean();
    unset($canvas, $source);
    if ($saved !== true || !is_string($bytes) || $bytes === '') {
        throw new RuntimeException('The print cover could not be encoded.');
    }
    return ['bytes' => $bytes, 'width' => $canvasWidth, 'height' => $canvasHeight,
        'content_width' => $canvasWidth, 'content_height' => $canvasHeight,
        'source_width' => $sourceCropWidth, 'source_height' => $sourceCropHeight];
}

/**
 * The full cover for Amazon KDP as one PDF page: front is the generated cover, back and spine are one blend of the cover's top and bottom colours
 * so there is no seam to see. The back's lower right is left empty for Amazon's barcode.
 */
function coloring_cover_wrap_pdf(array $project): string
{
    $file = application_data_directory() . '/' . (string) $project['cover_path'];
    if ((string) $project['cover_path'] === '' || !is_file($file)) throw new RuntimeException('This book has no cover yet.');
    $jpeg = file_get_contents($file);
    if (!is_string($jpeg) || $jpeg === '') throw new RuntimeException('This book has no usable cover image.');
    return coloring_cover_wrap_pdf_from_bytes($project, $jpeg);
}

/** Render a wrap from an immutable, hash-checked artifact snapshot. */
function coloring_cover_wrap_pdf_from_bytes(array $project, string $jpeg): string
{
    if ($jpeg === '') throw new RuntimeException('This book has no usable cover image.');
    require_once dirname(__DIR__) . '/vendor/autoload.php';
    $plan = json_decode((string) $project['plan_json'], true) ?: [];
    $size = coloring_cover_wrap_size(count($plan['scenes'] ?? []) ?: coloring_project_pages($project),
        coloring_has_belongs_page($project), (string) ($project['trim'] ?? 'portrait'), coloring_has_cover_outline($project));
    /* The back and spine blend from the average colour of the front's top strip to that of its bottom strip.
       Rasterize at the print grid size so KDP does not see a one-pixel image stretched over the panel. */
    $image = @imagecreatefromstring($jpeg);
    if ($image === false) throw new RuntimeException('This book has no usable cover image.');
    $edge = '';
    {
        $average = function (int $top) use ($image): array {
            $strip = imagecreatetruecolor(1, 1);
            imagecopyresampled($strip, $image, 0, 0, 0, $top, 1, 1, imagesx($image), max(1, (int) (imagesy($image) * 0.04)));
            $rgb = imagecolorat($strip, 0, 0);
            unset($strip);
            return [($rgb >> 16) & 255, ($rgb >> 8) & 255, $rgb & 255];
        };
        $from = $average(0);
        $to = $average((int) (imagesy($image) * 0.96));
        /* Front panel = trim width plus the 0.125in outer wrap bleed; 8.5in trim resolves to 8.625 (review2 F5). */
        $trim = coloring_trims()[coloring_trim_key((string) ($project['trim'] ?? 'portrait'))];
        $front = $trim['page_w'] + 0.125;
        $backWidth = $size['width'] - $front + 0.02;
        $backgroundWidthPixels = (int) ceil($backWidth * COLORING_COVER_WRAP_RENDER_DPI);
        $backgroundHeightPixels = (int) round($size['height'] * COLORING_COVER_WRAP_RENDER_DPI);
        $column = imagecreatetruecolor($backgroundWidthPixels, $backgroundHeightPixels);
        if ($column === false) { unset($image); throw new RuntimeException('The print cover background could not be prepared.'); }
        for ($y = 0; $y < $backgroundHeightPixels; $y++) {
            $t = $backgroundHeightPixels > 1 ? $y / ($backgroundHeightPixels - 1) : 0;
            $colour = imagecolorallocate($column, (int) round($from[0] + ($to[0] - $from[0]) * $t),
                (int) round($from[1] + ($to[1] - $from[1]) * $t), (int) round($from[2] + ($to[2] - $from[2]) * $t));
            imagefilledrectangle($column, 0, $y, $backgroundWidthPixels - 1, $y, $colour);
        }
        ob_start();
        $saved = imagejpeg($column, null, 95);
        $edge = '<img class="b" src="data:image/jpeg;base64,' . base64_encode((string) ob_get_clean()) . '">';
        unset($column);
        if ($saved !== true) { unset($image); throw new RuntimeException('The print cover background could not be encoded.'); }
    }
    $frontWidthPixels = (int) round($front * COLORING_COVER_WRAP_RENDER_DPI);
    $frontHeightPixels = (int) round($size['height'] * COLORING_COVER_WRAP_RENDER_DPI);
    $fittedCover = coloring_cover_wrap_fit_front($jpeg, $front / $size['height'], $frontWidthPixels, $frontHeightPixels);
    $html = '<style>@page{margin:0}body{margin:0}.w{position:relative;width:' . $size['width'] . 'in;height:' . $size['height'] . 'in;background:#222244;overflow:hidden}'
        . '.b{position:absolute;top:0;left:0;width:' . ($size['width'] - $front + 0.02) . 'in;height:' . $size['height'] . 'in}'
        . '.f{position:absolute;top:0;left:' . ($size['width'] - $front) . 'in;width:' . $front . 'in;height:' . $size['height'] . 'in}</style>'
        . '<div class="w">' . $edge . '<img class="f" src="data:image/jpeg;base64,' . base64_encode($fittedCover['bytes']) . '"></div>';
    unset($image);
    $dompdf = new \Dompdf\Dompdf(['isRemoteEnabled' => false]);
    $dompdf->loadHtml($html);
    $dompdf->setPaper([0, 0, $size['width'] * 72, $size['height'] * 72]);
    $dompdf->render();

    return $dompdf->output();
}
