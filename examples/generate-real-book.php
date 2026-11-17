<?php

declare(strict_types=1);

/*
 * Generate a REAL coloring book with the live OpenAI API.
 *
 * *** THIS COSTS MONEY. *** Roughly 0.40 to 0.65 USD per book at the default low image quality.
 * Do not run it unless you mean to spend that. For a free run use examples/offline-demo.php.
 *
 *   export OPENAI_API_KEY=sk-...        (read from the environment, never stored in this repo)
 *   php examples/generate-real-book.php [examples/specs/kids-cute-animals.json]
 *
 * Environment variables that control cost:
 *   OPENAI_IMAGE_MODEL    image model (default gpt-image-2)
 *   OPENAI_IMAGE_QUALITY  low (default, cheapest), medium or high; higher costs much more per page
 *   COLORING_PAGE_COUNT   default page count (1..24, default 24) when the spec has no "page_count"
 * The spec's own "page_count" (16, 24 or 30) wins over COLORING_PAGE_COUNT; fewer pages cost less.
 *
 * Output: the interior PDF is written to examples/output/real-book.pdf (gitignored). Page art and the database
 * live under examples/output/real-data/ so repeated runs do not touch your own data directory.
 */

if (PHP_SAPI !== 'cli') exit(1);

if (trim((string) getenv('OPENAI_API_KEY')) === '') {
    fwrite(STDERR, "generate-real-book: set OPENAI_API_KEY in the environment first. This run costs money.\n");
    exit(1);
}

$specFile = $argv[1] ?? __DIR__ . '/specs/kids-cute-animals.json';
$spec = json_decode((string) @file_get_contents($specFile), true);
if (!is_array($spec)) {
    fwrite(STDERR, "generate-real-book: cannot read a JSON object from $specFile\n");
    exit(1);
}

$data = __DIR__ . '/output/real-data';
if (!is_dir($data)) mkdir($data, 0775, true);
if (trim((string) getenv('COLORING_DATA_DIR')) === '') putenv('COLORING_DATA_DIR=' . $data);
if (trim((string) getenv('COLORING_DATABASE_PATH')) === '') putenv('COLORING_DATABASE_PATH=' . $data . '/real.db');
putenv('COLORING_WORKER_SPAWN=0');

require_once dirname(__DIR__) . '/src/coloring_worker.php';
require_once dirname(__DIR__) . '/src/coloring_pdf_cache.php';

try {
    $id = coloring_create_project('example-real', coloring_validate($spec));
} catch (InvalidArgumentException $exception) {
    fwrite(STDERR, 'generate-real-book: ' . $exception->getMessage() . "\n");
    exit(1);
}
coloring_worker_start($id);
echo "Project $id queued, drawing (this takes several minutes)...\n";

/* A per-page API hiccup is expected over several minutes of image generation; the worker records it and retries on the next
   pass, so only `ready` ends the loop. The 200-pass cap and the ready check below still catch a book that truly stalls. */
$summary = ['ready' => 0];
for ($pass = 0; $pass < 200 && $summary['ready'] === 0; $pass++) {
    $summary = coloring_worker_run(5);
    echo gmdate('H:i:s'), " advanced={$summary['advanced']} ready={$summary['ready']} failed={$summary['failed']}\n";
}
if ($summary['ready'] !== 1) {
    fwrite(STDERR, "generate-real-book: the book did not finish\n");
    exit(1);
}
coloring_pdf_prerender_run(1);
$cached = coloring_pdf_cache_path($id);
$target = __DIR__ . '/output/real-book.pdf';
if (!is_file($cached) || !copy($cached, $target)) {
    fwrite(STDERR, "generate-real-book: no PDF produced\n");
    exit(1);
}
echo "PDF: ", realpath($target), "\n", "Size: ", filesize($target), " bytes\n";
