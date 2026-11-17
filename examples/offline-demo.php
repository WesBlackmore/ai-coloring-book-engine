<?php

declare(strict_types=1);

/*
 * Offline demo: runs the FULL pipeline (plan, parallel pages, cover, cover outline, finish, interior PDF) for free.
 *
 * Every AI provider is a fake (the same $GLOBALS['coloring_worker_providers'] seam tools/smoke-selfcheck.php uses),
 * OPENAI_API_KEY is removed, and no network call is made. It costs nothing and needs no key.
 *
 * IMPORTANT: the "art" is placeholder shapes (a black ring on white), NOT real coloring pages. This exists to prove
 * the pipeline runs end to end. For real artwork see examples/generate-real-book.php (that one costs money).
 *
 *   php examples/offline-demo.php
 *
 * Writes examples/output/offline-demo.pdf and prints its absolute path and byte size. examples/output/ is gitignored.
 */

if (PHP_SAPI !== 'cli') exit(1);

$root = dirname(__DIR__);
$out = __DIR__ . '/output';
$work = sys_get_temp_dir() . '/coloring-demo-' . bin2hex(random_bytes(4));
mkdir($work, 0775, true);
if (!is_dir($out)) mkdir($out, 0775, true);

putenv('COLORING_DATA_DIR=' . $work);
putenv('COLORING_DATABASE_PATH=' . $work . '/demo.db');
putenv('OPENAI_API_KEY');
putenv('COLORING_READY_WEBHOOK');
putenv('COLORING_WORKER_SPAWN=0');
putenv('COLORING_PAGE_PARALLEL=4');
register_shutdown_function(static function () use ($work): void {
    exec('rm -rf ' . escapeshellarg($work));
});

require_once $root . '/src/coloring_worker.php';
require_once $root . '/src/coloring_pdf_cache.php';

/** A small valid image: white with a black ring. Placeholder only. */
function demo_image(string $format, int $width, int $height): string
{
    $image = imagecreatetruecolor($width, $height);
    imagefill($image, 0, 0, imagecolorallocate($image, 255, 255, 255));
    imagesetthickness($image, 3);
    imageellipse($image, intdiv($width, 2), intdiv($height, 2), intdiv($width, 2), intdiv($height, 2), imagecolorallocate($image, 0, 0, 0));
    ob_start();
    $format === 'png' ? imagepng($image) : imagejpeg($image, null, 85);

    return (string) ob_get_clean();
}

function demo_save(string $relative, string $bytes): void
{
    $path = application_data_directory() . '/' . $relative;
    if (!is_dir(dirname($path))) mkdir(dirname($path), 0775, true);
    file_put_contents($path, $bytes);
}

$GLOBALS['coloring_worker_providers'] = [
    'plan' => function (array $project): void {
        $scenes = [];
        for ($i = 1; $i <= coloring_project_pages($project); $i++) {
            $scenes[] = ['id' => $i, 'main' => "subject $i", 'objects' => 'a, b', 'setting' => 'a field', 'description' => "Scene $i"];
        }
        $plan = ['title' => 'Offline Demo Dinosaurs', 'cover' => ['title_lines' => ['OFFLINE DEMO', 'DINOSAURS'], 'theme' => 'dinosaurs',
            'background' => 'green'], 'style_sheet' => '', 'scenes' => $scenes];
        db()->prepare('UPDATE coloring_projects SET plan_json = ?, title = ? WHERE id = ?')
            ->execute([json_encode($plan), $plan['title'], $project['id']]);
    },
    'page' => function (array $project, int $sceneId): void {
        $path = 'coloring/' . bin2hex(random_bytes(8)) . '.png';
        demo_save($path, demo_image('png', 240, 320));
        $row = db()->prepare('SELECT pages_json FROM coloring_projects WHERE id = ?');
        $row->execute([$project['id']]);
        $pages = json_decode((string) ($row->fetchColumn() ?: '[]'), true) ?: [];
        $row->closeCursor();
        $pages[] = ['scene_id' => $sceneId, 'path' => $path, 'status' => 'done', 'tries' => 1, 'ms' => 5, 'done_at' => gmdate('Y-m-d H:i:s')];
        db()->prepare('UPDATE coloring_projects SET pages_json = ?, updated_at = datetime(\'now\') WHERE id = ?')
            ->execute([json_encode($pages), $project['id']]);
    },
    'cover' => function (array $project): void {
        $path = 'coloring/cover-' . bin2hex(random_bytes(12)) . '.jpg';
        demo_save($path, demo_image('jpg', 300, 400));
        db()->prepare("UPDATE coloring_projects SET cover_path = ?, cover_status = 'done', updated_at = datetime('now') WHERE id = ?")
            ->execute([$path, $project['id']]);
    },
    'cover_outline' => function (array $project): void {
        $path = 'coloring/' . bin2hex(random_bytes(8)) . '.png';
        demo_save($path, demo_image('png', 300, 400));
        db()->prepare("UPDATE coloring_projects SET cover_outline_path = ?, updated_at = datetime('now') WHERE id = ?")
            ->execute([$path, $project['id']]);
    },
    'kdp' => fn (array $project): array => ['display_title' => 'Offline Demo Dinosaurs Coloring Book', 'subtitle' => 'A fake subtitle',
        'description' => 'A fake description.', 'keywords' => ['dinosaur coloring book'], 'categories' => ['GAMES & ACTIVITIES / Coloring Books'],
        'primary_audience' => 'Children', 'reading_age' => '4-8 years', 'author_bio' => 'A fake bio.'],
];

$spec = json_decode((string) file_get_contents(__DIR__ . '/specs/kids-cute-animals.json'), true);
$id = coloring_create_project('offline-demo', coloring_validate($spec));
coloring_worker_start($id);

$summary = ['ready' => 0];
for ($pass = 0; $pass < 10 && $summary['ready'] === 0; $pass++) $summary = coloring_worker_run(5);
if ($summary['ready'] !== 1) {
    fwrite(STDERR, "offline-demo: the book did not finish\n");
    exit(1);
}
if (coloring_pdf_prerender_run(1) !== 1) {
    fwrite(STDERR, "offline-demo: the interior PDF was not rendered\n");
    exit(1);
}

$cached = coloring_pdf_cache_path($id);
$target = $out . '/offline-demo.pdf';
if (!is_file($cached) || !copy($cached, $target) || !str_starts_with((string) file_get_contents($target, false, null, 0, 5), '%PDF-')) {
    fwrite(STDERR, "offline-demo: no valid PDF produced\n");
    exit(1);
}
echo "PDF: ", realpath($target), "\n", "Size: ", filesize($target), " bytes\n";
