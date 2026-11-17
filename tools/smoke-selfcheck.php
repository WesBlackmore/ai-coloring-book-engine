<?php

declare(strict_types=1);

/*
 * Offline end-to-end smoke test: plan -> pages -> cover -> cover outline -> finish -> KDP metadata -> interior PDF.
 * Every AI provider is a fake (the coloring_worker_providers pattern the host's selfchecks use), OPENAI_API_KEY is removed
 * so any real call would throw, and the data directory and database are temporary. No network, no spend.
 *
 *   php tools/smoke-selfcheck.php
 */

$work = sys_get_temp_dir() . '/coloring-smoke-' . bin2hex(random_bytes(4));
mkdir($work, 0775, true);
putenv('COLORING_DATA_DIR=' . $work);
putenv('COLORING_DATABASE_PATH=' . $work . '/smoke.db');
putenv('OPENAI_API_KEY');
putenv('COLORING_READY_WEBHOOK');
putenv('COLORING_WORKER_SPAWN=0');
register_shutdown_function(static function () use ($work): void {
    exec('rm -rf ' . escapeshellarg($work));
});

require_once dirname(__DIR__) . '/src/coloring_worker.php';
require_once dirname(__DIR__) . '/src/coloring_pdf_cache.php';

$failures = [];
$checks = 0;
function smoke_check(string $label, bool $passed): void
{
    global $checks, $failures;
    $checks++;
    if (!$passed) $failures[] = $label;
}

/** A small valid image: white with a black ring, so it is real line art for the PDF layout. */
function smoke_image(string $format, int $width, int $height): string
{
    $image = imagecreatetruecolor($width, $height);
    imagefill($image, 0, 0, imagecolorallocate($image, 255, 255, 255));
    imagesetthickness($image, 3);
    imageellipse($image, intdiv($width, 2), intdiv($height, 2), intdiv($width, 2), intdiv($height, 2), imagecolorallocate($image, 0, 0, 0));
    ob_start();
    $format === 'png' ? imagepng($image) : imagejpeg($image, null, 85);

    return (string) ob_get_clean();
}

function smoke_save(string $relative, string $bytes): void
{
    $path = application_data_directory() . '/' . $relative;
    if (!is_dir(dirname($path))) mkdir(dirname($path), 0775, true);
    file_put_contents($path, $bytes);
}

$calls = ['plan' => 0, 'page' => 0, 'cover' => 0, 'cover_outline' => 0, 'kdp' => 0];
$GLOBALS['coloring_worker_providers'] = [
    'plan' => function (array $project) use (&$calls): void {
        $calls['plan']++;
        $scenes = [];
        for ($i = 1; $i <= coloring_project_pages($project); $i++) {
            $scenes[] = ['id' => $i, 'main' => "subject $i", 'objects' => 'a, b', 'setting' => 'a field', 'description' => "Scene $i"];
        }
        $plan = ['title' => 'Smoke Test Dinosaurs', 'cover' => ['title_lines' => ['SMOKE TEST', 'DINOSAURS'], 'theme' => 'dinosaurs',
            'background' => 'green'], 'style_sheet' => '', 'scenes' => $scenes];
        db()->prepare('UPDATE coloring_projects SET plan_json = ?, title = ? WHERE id = ?')
            ->execute([json_encode($plan), $plan['title'], $project['id']]);
    },
    'page' => function (array $project, int $sceneId) use (&$calls): void {
        $calls['page']++;
        $path = 'coloring/' . bin2hex(random_bytes(8)) . '.png';
        smoke_save($path, smoke_image('png', 240, 320));
        /* Merge into the pages as saved now, as the real drawing does: pages may land several at once. */
        $row = db()->prepare('SELECT pages_json FROM coloring_projects WHERE id = ?');
        $row->execute([$project['id']]);
        $pages = json_decode((string) ($row->fetchColumn() ?: '[]'), true) ?: [];
        $row->closeCursor();
        $pages[] = ['scene_id' => $sceneId, 'path' => $path, 'status' => 'done', 'tries' => 1, 'ms' => 5, 'done_at' => gmdate('Y-m-d H:i:s')];
        db()->prepare('UPDATE coloring_projects SET pages_json = ?, updated_at = datetime(\'now\') WHERE id = ?')
            ->execute([json_encode($pages), $project['id']]);
    },
    'cover' => function (array $project) use (&$calls): void {
        $calls['cover']++;
        $path = 'coloring/cover-' . bin2hex(random_bytes(12)) . '.jpg';
        smoke_save($path, smoke_image('jpg', 300, 400));
        db()->prepare("UPDATE coloring_projects SET cover_path = ?, cover_status = 'done', updated_at = datetime('now') WHERE id = ?")
            ->execute([$path, $project['id']]);
    },
    'cover_outline' => function (array $project) use (&$calls): void {
        $calls['cover_outline']++;
        $path = 'coloring/' . bin2hex(random_bytes(8)) . '.png';
        smoke_save($path, smoke_image('png', 300, 400));
        db()->prepare("UPDATE coloring_projects SET cover_outline_path = ?, updated_at = datetime('now') WHERE id = ?")
            ->execute([$path, $project['id']]);
    },
    'kdp' => function (array $project) use (&$calls): array {
        $calls['kdp']++;
        return ['display_title' => 'Smoke Test Dinosaurs Coloring Book', 'subtitle' => 'A fake subtitle', 'description' => 'A fake description.',
            'keywords' => ['dinosaur coloring book'], 'categories' => ['GAMES & ACTIVITIES / Coloring Books'],
            'primary_audience' => 'Children', 'reading_age' => '4-8 years', 'author_bio' => 'A fake bio.'];
    },
];

/** Create, start and run one book to the end; returns its final row. */
function smoke_book(int $lanes): array
{
    putenv('COLORING_PAGE_PARALLEL=' . $lanes);
    $values = coloring_validate(['idea' => 'Friendly dinosaurs having a picnic together', 'audience' => 'kids', 'age_band' => '4-8',
        'style' => 'cute-animals', 'page_count' => '16']);
    $id = coloring_create_project('smoke-owner', $values);
    smoke_check("[$lanes] a draft is not picked up", coloring_worker_next_project() === null);
    $queued = coloring_worker_start($id);
    smoke_check("[$lanes] start queues the book", $queued['status'] === 'queued' && $queued['reservation_key'] === coloring_reservation_key($id));
    $summary = ['ready' => 0];
    for ($pass = 0; $pass < 10 && $summary['ready'] === 0; $pass++) $summary = coloring_worker_run(5);
    smoke_check("[$lanes] the worker finished the book", $summary['ready'] === 1);
    $row = db()->prepare('SELECT * FROM coloring_projects WHERE id = ?');
    $row->execute([$id]);

    return $row->fetch();
}

foreach ([4, 1] as $lanes) {
    $before = $calls;
    $row = smoke_book($lanes);
    smoke_check("[$lanes] status is ready", $row['status'] === 'ready');
    smoke_check("[$lanes] every page was drawn", $calls['page'] - $before['page'] === 16 && count(json_decode($row['pages_json'], true)) === 16);
    smoke_check("[$lanes] plan, cover and outline each ran once", $calls['plan'] - $before['plan'] === 1
        && $calls['cover'] - $before['cover'] === 1 && $calls['cover_outline'] - $before['cover_outline'] === 1);
    smoke_check("[$lanes] owner_ref is kept", $row['owner_ref'] === 'smoke-owner');
    smoke_check("[$lanes] the book is unlocked and the lease released", (int) $row['unlocked'] === 1 && $row['generating_token'] === null);
    $kdp = json_decode((string) $row['kdp_metadata_json'], true);
    smoke_check("[$lanes] KDP metadata JSON was stored", is_array($kdp) && ($kdp['subtitle'] ?? '') === 'A fake subtitle' && count($kdp['categories']) === 1);

    $pdf = coloring_pdf_prerender_run(1);
    $file = coloring_pdf_cache_path((int) $row['id']);
    smoke_check("[$lanes] the interior PDF was rendered", $pdf === 1 && is_file($file) && str_starts_with((string) file_get_contents($file, false, null, 0, 5), '%PDF-')
        && filesize($file) > 5000);
    smoke_check("[$lanes] the PDF is served from the cache next time", coloring_pdf_cache_fresh($row) === $file);
    echo sprintf("[%d lanes] project %d: pdf %s (%d bytes), kdp subtitle \"%s\"\n", $lanes, $row['id'], basename($file), (int) @filesize($file), $kdp['subtitle'] ?? '');
}

smoke_check('the real provider path is intact and refuses without a key', (function (): bool {
    unset($GLOBALS['coloring_worker_providers']);
    try {
        coloring_worker_provider('kdp')(['id' => 1, 'title' => 'x']);
    } catch (RuntimeException $exception) {
        return str_contains($exception->getMessage(), 'OPENAI_API_KEY is not configured');
    }
    return false;
})());
smoke_check('the ready webhook is inert when unset', (function (): bool {
    notify_coloring_ready(['id' => 1]);
    return true;
})());

if ($failures !== []) {
    fwrite(STDERR, "FAILED:\n - " . implode("\n - ", $failures) . "\n");
    exit(1);
}
echo "smoke-selfcheck: $checks checks passed\n";
