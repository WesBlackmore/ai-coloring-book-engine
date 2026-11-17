<?php

declare(strict_types=1);

/*
 * The coloring slice of the host app's src/ai.php, copied as-is except where noted:
 * generate_text(), the KDP metadata call, the shared provider transport ai_http_json(), and every coloring image function.
 * Removed: the ebook prompts, MCP pilot spend metering and the per-user usage ledger (the engine does not bill).
 * Every AI call goes through ai_http_json(), which runs the rate_limit_ai_call() spend guard first.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/shims.php';

/**
 * Which text model this call uses.
 *
 * The work before the account gate is now done for a visitor nobody knows: a classification, three
 * titles and a whole outline, on every idea anybody types. That is the one part of the product a
 * stranger can spend money on, so it runs on the cheapest model in the family by default
 * (`gpt-5.4-nano` against `gpt-5.4-mini`), and every call after the gate is unchanged (D-177).
 *
 * `OPENAI_GUEST_TEXT_MODEL` overrides the cheap tier, and setting it to the paid model turns the
 * saving off without a deploy.
 *
 * @param array<string, mixed>|null $usageContext
 */
function ai_text_model(?array $usageContext = null): string
{
    $paid = getenv('OPENAI_TEXT_MODEL') ?: 'gpt-5.4-mini';
    $operation = is_array($usageContext) ? (string) ($usageContext['operation'] ?? '') : '';

    /* Extraction runs cheap by default and escalates when it struggles. A caller that has already
       failed on the cheap tier sets `model_tier` to `paid`, so a difficult chapter still gets the
       capable model and only the difficult ones pay for it (D-248). */
    if (in_array($operation, ai_extraction_operations(), true)) {
        $tier = is_array($usageContext) ? (string) ($usageContext['model_tier'] ?? '') : '';
        if ($tier === 'paid') return $paid;

        return getenv('OPENAI_EXTRACTION_MODEL') ?: 'gpt-5.4-nano';
    }

    if (!in_array($operation, ai_pre_account_operations(), true)) return $paid;

    return getenv('OPENAI_GUEST_TEXT_MODEL') ?: 'gpt-5.4-nano';
}


/**
 * The calls that run on the cheap tier before anybody knows who the visitor is.
 *
 * The three small ones: a label, three titles, and a reroll of those titles. The outline is
 * deliberately **not** here. It is the book's skeleton, every chapter is written against it, and a
 * weaker outline is a weaker book for a customer who then pays, a saving of about two cents against
 * the one artefact the whole product is built on (D-177).
 *
 * `OPENAI_GUEST_OUTLINE_CHEAP=1` adds it, for measuring what the difference actually is.
 *
 * @return list<string>
 */
function ai_pre_account_operations(): array
{
    $operations = ['book_type_classification', 'title_generation', 'title_reroll'];
    if (trim((string) getenv('OPENAI_GUEST_OUTLINE_CHEAP')) === '1') {
        $operations[] = 'outline_generation';
    }
    return $operations;
}

/**
 * Work that reads a finished draft and fills in a form, rather than writing anything.
 *
 * This is a different axis from `ai_pre_account_operations()`, which asks who the visitor is. This
 * asks what the job is. Continuity extraction never writes a word the reader sees: it reads the
 * chapter that was just written and records what changed, in a shape the API now guarantees
 * (D-246). Nothing about that needs the model that writes the prose, and at 49.5% of all spend it
 * was the most expensive thing in the product (D-248).
 *
 * @return list<string>
 */
function ai_extraction_operations(): array
{
    return ['continuity_state_update', 'coloring_brief_suggestion', 'coloring_intent_classification',
        /* The KDP metadata panel on the preview page: summarise a finished book into the Amazon
           KDP upload fields. Reading job, not a writing job, so it rides the cheap tier (D-491). */
        'kdp_metadata_generation'];
}

/**
 * @param array|null $jsonSchema A strict JSON schema. When given it replaces plain JSON mode, and
 *                               the provider then cannot return a shape the parser will reject.
 */
function generate_text(
    string $prompt,
    int $maxTokens = 4096,
    ?array $usageContext = null,
    bool $jsonObject = false,
    ?array $jsonSchema = null
): string
{
    $apiKey = getenv('OPENAI_API_KEY');
    if (!$apiKey) {
        throw new RuntimeException('OPENAI_API_KEY is not configured.');
    }

    $model = ai_text_model($usageContext);
    $payload = [
        'model' => $model,
        /* The GPT-5 family takes max_completion_tokens, and that budget also covers the
           model's reasoning tokens, so short ceilings can return an empty message. */
        'max_completion_tokens' => $maxTokens,
        'messages' => [['role' => 'user', 'content' => $prompt]],
    ];
    if ($jsonSchema !== null) {
        /* Chat Completions nests the schema under `json_schema`; the Responses API spells this
           differently, so do not copy the shape from the Structured Outputs guide. */
        $payload['response_format'] = ['type' => 'json_schema', 'json_schema' => [
            'name' => 'continuity_update', 'strict' => true, 'schema' => $jsonSchema]];
    } elseif ($jsonObject) {
        $payload['response_format'] = ['type' => 'json_object'];
    }

    $response = ai_http_json(
        'https://api.openai.com/v1/chat/completions',
        ['authorization: Bearer ' . $apiKey],
        $payload,
        $usageContext
    );

    $text = trim((string) ($response['choices'][0]['message']['content'] ?? ''));
    if ($text === '') {
        throw new RuntimeException('The text model returned an empty response.');
    }

    return $text;
}


/**
 * Coloring-book sibling of generate_kdp_metadata(). Same return shape so the view can reuse the
 * same rendering code. The prompt knows coloring-book conventions: Amazon shoppers search for
 * phrases like "coloring book for kids ages 4-8", the categories live under GAMES & ACTIVITIES /
 * JUVENILE NONFICTION, and the description leads with page count + art style + what is inside.
 *
 * @return array{display_title:string, subtitle:string, description:string, keywords:list<string>,
 *               categories:list<string>, primary_audience:string, reading_age:string,
 *               author_bio:string}
 */
function generate_kdp_metadata_coloring(array $project): array
{
    $title = (string) ($project['title'] ?? '');
    $idea = (string) ($project['idea'] ?? '');
    $audience = (string) ($project['audience'] ?? '');
    $ageBand = (string) ($project['age_band'] ?? '');
    $style = (string) ($project['style'] ?? '');
    $character = (string) ($project['character_desc'] ?? '');
    $mood = (string) ($project['mood'] ?? '');
    $occasion = (string) ($project['occasion'] ?? '');
    require_once __DIR__ . '/coloring_categories.php';
    require_once __DIR__ . '/coloring_book.php';
    $drawings = coloring_project_pages($project);
    $pageCount = coloring_print_page_count($drawings, coloring_has_belongs_page($project), coloring_has_cover_outline($project));
    $isKids = in_array(strtolower($audience), ['kids', 'children'], true)
        || preg_match('/\d/', $ageBand) === 1;

    $prompt = "You are preparing an Amazon KDP paperback upload metadata package for a COLORING BOOK.\n\n"
        . 'Title: ' . $title . "\n"
        . 'Idea: ' . $idea . "\n"
        . 'Audience: ' . $audience . "\n"
        . ($ageBand !== '' ? 'Age band: ' . $ageBand . "\n" : '')
        . 'Art style: ' . $style . "\n"
        . ($character !== '' ? 'Recurring character: ' . $character . "\n" : '')
        . ($mood !== '' ? 'Mood: ' . $mood . "\n" : '')
        . ($occasion !== '' ? 'Occasion: ' . $occasion . "\n" : '')
        . ($drawings > 0 ? 'Drawings in the book: ' . $drawings . "\n" : '')
        . ($pageCount > 0 ? 'Total printed pages: ' . $pageCount . "\n" : '');

    $prompt .= "\nReturn ONLY a JSON object with these keys:\n"
        . "- display_title: an SEO-rich alternative title the author can use on Amazon KDP\n"
        . "  INSTEAD of their short brand title. Follow the pattern coloring-book bestsellers use:\n"
        . "  the subject plus the audience and a benefit. If you include a page or drawing count\n"
        . "  you MUST use the exact numbers given above (Drawings in the book / Total printed\n"
        . "  pages). Never invent a different count. If no count is given above, do not mention\n"
        . "  one. Keep it under 80 characters, title-case, no quotes or trailing punctuation.\n"
        . "- subtitle: 100-180 characters, keyword-rich, in the style of Amazon coloring bestsellers:\n"
        . "  name the audience, the art style, the page size, and the benefit (relaxation,\n"
        . "  learning, fun). If you include a drawing or page count, use the exact numbers above\n"
        . "  (" . ($drawings > 0 ? $drawings . ' drawings / ' . $pageCount . ' total pages' : 'none given') . ").\n"
        . "  Never invent a count. No HTML, no quotes.\n"
        . "- description: a 150-220 word Amazon product-page sales description, 2-3 short paragraphs,\n"
        . "  under 4000 characters. Plain text, no HTML. Must obey the WRITING RULES below AND the\n"
        . "  SEO RULES below. The first 150 characters must land the hook on mobile: audience +\n"
        . "  art style + what is inside.\n"
        . "- keywords: an array of exactly 7 Amazon search phrases, each under 50 characters,\n"
        . "  the kind a shopper actually types. For a kids book include age ranges ('coloring book\n"
        . "  ages 4-8'); for adults include stress-relief phrasing. No commas or pipes inside a phrase.\n"
        . "- categories: an array of exactly 3 full BISAC categories in Amazon's slash-separated\n"
        . "  format. Pick from the real coloring-book categories, e.g. 'JUVENILE NONFICTION /\n"
        . "  Activity Books / Coloring Books', 'GAMES & ACTIVITIES / Coloring Books', 'CRAFTS &\n"
        . "  HOBBIES / Coloring Books'.\n"
        . "- primary_audience: 'Children' for a kids book, 'Adult' otherwise.\n"
        . "- reading_age: for a kids book, an age range like '4-8 years'; otherwise 'Not applicable'.\n"
        . "- author_bio: 2-3 third-person sentences for the Amazon author page.\n"
        . "\n"
        . "WRITING RULES for description and author_bio (strict):\n"
        . "- No em dashes.\n"
        . "- Banned words: delve, foster, leverage, utilize, facilitate, empower, streamline,\n"
        . "  robust, cutting-edge, paradigm, game changer, tapestry, realm, beacon, multifaceted,\n"
        . "  meticulous, intricate, paramount, transformative, elevate, embark, supercharge,\n"
        . "  harness, ever-evolving, ultimately, in conclusion, overall.\n"
        . "- Banned phrases: 'it is worth noting', 'at the end of the day', 'at its core',\n"
        . "  'in the realm of', 'stands as a testament'.\n"
        . "- No binary contrasts of the form 'not X, it is Y'.\n"
        . "- No throat-clearing openers, no faux-insight framings, no colon drama.\n"
        . "- No fake-profound closing metaphor.\n"
        . "- Active voice. Concrete and specific. Do NOT invent facts, stats or endorsements.\n"
        . "\n"
        . "SEO RULES for description (strict, in addition to the WRITING RULES):\n"
        . "- First 150 characters are the Amazon mobile 'above the fold' zone.\n"
        . "- Weave 3-5 of the returned keywords into the body naturally.\n"
        . "- Name the target reader (audience) early. If you state the page or drawing count,\n"
        . "  it MUST match the numbers given above; otherwise do not state a count at all.\n"
        . "- Do not use the words 'SEO' or 'optimised' or 'optimized' in the description itself.\n";

    $json = generate_text($prompt, 2000, [
        'operation' => 'kdp_metadata_generation',
        'book_id' => (int) ($project['id'] ?? 0),
    ], true);
    $parsed = json_decode($json, true);
    if (!is_array($parsed)) {
        throw new RuntimeException('KDP metadata (coloring) response was not valid JSON.');
    }

    $keywords = array_values(array_filter(array_map(
        static fn ($v) => mb_substr(trim((string) $v), 0, 50),
        (array) ($parsed['keywords'] ?? [])), static fn ($v) => $v !== ''));
    $categories = array_values(array_filter(array_map(
        static fn ($v) => trim((string) $v),
        (array) ($parsed['categories'] ?? [])), static fn ($v) => $v !== ''));

    /* The model sometimes returns a nested object for an author_bio or a subtitle (eg
       {"short":"...","long":"..."}). A bare (string) cast of an array yields the literal
       "Array", which then landed in the KDP UI (D-517). Coerce by joining string leaves. */
    $toText = static function ($value): string {
        if (is_string($value)) return $value;
        if (is_scalar($value)) return (string) $value;
        if (is_array($value)) {
            $parts = [];
            array_walk_recursive($value, static function ($v) use (&$parts): void {
                if (is_string($v) && trim($v) !== '') $parts[] = trim($v);
                elseif (is_scalar($v)) $parts[] = (string) $v;
            });
            return implode('. ', $parts);
        }
        return '';
    };
    return [
        'display_title' => mb_substr(trim($toText($parsed['display_title'] ?? '')), 0, 200),
        'subtitle' => mb_substr(trim($toText($parsed['subtitle'] ?? '')), 0, 200),
        'description' => mb_substr(trim($toText($parsed['description'] ?? '')), 0, 4000),
        'keywords' => array_slice($keywords, 0, 7),
        'categories' => array_slice($categories, 0, 3),
        'primary_audience' => trim($toText($parsed['primary_audience'] ?? ($isKids ? 'Children' : 'Adult'))),
        'reading_age' => trim($toText($parsed['reading_age'] ?? ($isKids ? ($ageBand !== '' ? $ageBand : '4-8 years') : 'Not applicable'))),
        'author_bio' => mb_substr(trim($toText($parsed['author_bio'] ?? '')), 0, 1000),
    ];
}

/** How long one provider call may take. curl and PHP's own limit must agree on this. */
const AI_CALL_TIMEOUT = 180;

function ai_http_json(string $url, array $headers, array $payload, ?array $usageContext = null): array
{
    /* Every provider call, for text and for images, arrives here. One guard therefore covers
       chapters, outlines, titles and covers, and no future caller can route around it. See D-082. */
    rate_limit_ai_call();

    /**
     * PHP's max_execution_time is 30 seconds in most default configurations, including `php -S`
     * and stock php-fpm, while a cover takes 14-22 seconds and stage 9 makes several in one
     * request. Without this the process is killed mid-call: no exception, no unwinding, so the
     * generation lock leaks and the charge is never explained. set_time_limit() restarts the
     * counter, so the limit applies per provider call and matches CURLOPT_TIMEOUT rather than
     * capping the whole request. See D-038.
     */
    /* A visitor is waiting on the form for these two, so they fail fast instead of stalling it. */
    $isColoringSuggestion = in_array($usageContext['operation'] ?? '',
        ['coloring_brief_suggestion', 'coloring_intent_classification'], true);
    $callTimeout = $isColoringSuggestion ? 8 : AI_CALL_TIMEOUT;
    $concurrencyWaitSeconds = $isColoringSuggestion ? 1 : 30;
    if (function_exists('set_time_limit')) {
        @set_time_limit($callTimeout + $concurrencyWaitSeconds + 5);
    }

    /* Cover warm-up is intentionally concurrent with writing. Do not fail a chapter merely
       because both bounded provider slots are briefly occupied; wait for one to be released. */
    $concurrencyHandle = null;
    $waitUntil = microtime(true) + $concurrencyWaitSeconds;
    $inPool = ai_http_in_pool();
    while ($concurrencyHandle === null) {
        try {
            $concurrencyHandle = ai_concurrency_acquire(ai_concurrency_pool($usageContext));
        } catch (RuntimeException $exception) {
            if (!str_contains($exception->getMessage(), 'AI generation is busy')) {
                throw $exception;
            }
            if (microtime(true) >= $waitUntil) {
                throw $exception;
            }
            /* In the parallel page pool a wait must not stall the requests already in flight (D-645). */
            if ($inPool) Fiber::suspend(['wait' => 0.25]); else usleep(250000);
        }
    }

    try {
        $request = ['url' => $url, 'timeout' => $callTimeout,
            'headers' => array_merge(['content-type: application/json'], $headers),
            'body' => json_encode($payload, JSON_THROW_ON_ERROR)];
        /* Everything above is the request's preparation and everything below its settlement. In the pool this fiber waits
           here while curl_multi carries several requests at once; anywhere else the call is sent and waited for in place. */
        [$body, $status, $curlError] = $inPool ? Fiber::suspend(['request' => $request]) : ai_http_send($request);
        unset($request);
        if (is_resource($body)) {
            /* The pool hands over the temporary file its response landed in. */
            rewind($body);
            $stream = $body;
            $body = stream_get_contents($stream);
            fclose($stream);
        }

        if ($body === false) throw new RuntimeException('AI request failed: ' . $curlError);
        $decoded = json_decode($body, true);
        /* A drawing's response is several MB of base64; do not hold it twice. */
        unset($body);
        if ($status < 200 || $status >= 300) {
            /* The status is in the text so a worker can tell a busy provider (429, 5xx) from a refusal (D-645). */
            $message = $decoded['error']['message'] ?? 'no message';
            throw new RuntimeException('AI request failed (HTTP ' . $status . '): ' . $message);
        }
        if (!is_array($decoded)) throw new RuntimeException('AI provider returned an invalid response.');
        return $decoded;
    } finally {
        ai_concurrency_release($concurrencyHandle);
    }
}

/** True inside a fiber the parallel page pool runs (src/coloring_parallel.php); its provider calls suspend instead of block. */
function ai_http_in_pool(): bool
{
    $fiber = Fiber::getCurrent();

    return $fiber !== null && isset($GLOBALS['ai_http_pool_fibers']) && isset($GLOBALS['ai_http_pool_fibers'][$fiber]);
}

/** The curl handle for one prepared request. One place, so the serial call and the pool send exactly the same thing. */
function ai_http_handle(array $request): CurlHandle
{
    /* Selfchecks point the real providers at a local fake server. Honoured only on the command line, so no web request can
       redirect a call that carries the real API key (D-646). */
    $url = PHP_SAPI === 'cli' && isset($GLOBALS['ai_http_test_base'])
        ? preg_replace('#^https://api\.openai\.com#', (string) $GLOBALS['ai_http_test_base'], $request['url']) : $request['url'];
    $handle = curl_init($url);
    curl_setopt_array($handle, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => min(3, $request['timeout']),
        CURLOPT_TIMEOUT => $request['timeout'],
        CURLOPT_HTTPHEADER => $request['headers'],
        CURLOPT_POSTFIELDS => $request['body']]);

    return $handle;
}

/** Send one prepared request and wait for it. @return array{0:string|false,1:int,2:string} body, HTTP status, curl error */
function ai_http_send(array $request): array
{
    $handle = ai_http_handle($request);
    $body = curl_exec($handle);

    return [$body, (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE), curl_error($handle)];
}

/** A page counts as cut off when this share of any one edge is ink. Measured on 72 pages (D-390). */
const COLORING_EDGE_INK_LIMIT = 2.0;

/**
 * The largest share of one image edge that is ink, in percent, over an outer ring 6 pixels deep.
 *
 * Ink on the edge means the model drew past its own canvas, so the picture is cut off before any PDF
 * margin exists (Stage 1 first called this harmless, which was wrong). Reads the flattened page.
 * Returns 0 when GD is missing or the image cannot be read, so a page is never refused for that.
 */
function coloring_edge_ink(string $png): float
{
    if (!function_exists('imagecreatefromstring')) return 0.0;
    $image = @imagecreatefromstring($png);
    if ($image === false) return 0.0;
    $width = imagesx($image);
    $height = imagesy($image);
    $ring = 6;
    /* A 1-bit PNG loads as a palette image, where imagecolorat() gives an index, not a colour. */
    $palette = !imageistruecolor($image);
    $dark = [];
    if ($palette) {
        for ($index = 0, $total = imagecolorstotal($image); $index < $total; $index++) {
            if (imagecolorsforindex($image, $index)['red'] < 128) $dark[$index] = true;
        }
    }
    $strips = [[0, 0, $width, $ring], [0, $height - $ring, $width, $height],
        [0, 0, $ring, $height], [$width - $ring, 0, $width, $height]];
    $worst = 0.0;
    foreach ($strips as [$x0, $y0, $x1, $y1]) {
        $ink = 0;
        for ($y = $y0; $y < $y1; $y++) {
            for ($x = $x0; $x < $x1; $x++) {
                $value = imagecolorat($image, $x, $y);
                if ($palette ? isset($dark[$value]) : ($value & 0xFF) < 128) $ink++;
            }
        }
        $worst = max($worst, $ink / (($x1 - $x0) * ($y1 - $y0)) * 100);
    }

    return $worst;
}

/*
 * Print layout for an 8.5 x 11 inch page, no bleed (D-418). Amazon's minimums for 24 to 150 pages are 0.25 inch outside, top
 * and bottom and 0.375 inch inside; we add 0.05 inch. Drawings sit on right-hand pages, so the gutter is on the left. The
 * drawing area is 7.8 x 10.4 inches, exactly 3:4, which is 2340 x 3120 pixels at 300 DPI. The model draws at 2400 x 3200, so
 * a drawing placed pixel for pixel is 300 DPI and is never enlarged (a 3% upscale would drop it to about 290).
 */
const COLORING_DRAW_SIZE = '2400x3200';
const COLORING_AREA_WIDTH_PX = 2340;
const COLORING_AREA_HEIGHT_PX = 3120;
const COLORING_MARGIN_GUTTER_IN = 0.4;
const COLORING_MARGIN_OUTSIDE_IN = 0.3;
/*
 * D-656: line art comes back as JPEG at this quality, not PNG. The 2400 x 3200 PNG was about 3.8 MB, 5.1 MB as base64 inside the
 * JSON, and 97% of it was thrown away: every page is flattened to a 1-bit PNG of about 100 KB before it is saved. The pixel size
 * (300 PPI on the trim) is unchanged; only the transfer format is. Measured on real pages: the flattened 1-bit result of the JPEG
 * differs from the PNG's only on line edges, with no stray specks (tools/coloring-parallel-selfcheck.php, D-656).
 */
const COLORING_LINE_ART_JPEG_QUALITY = 90;

/** Reject a model response that cannot provide at least one source pixel per pixel in the 300-PPI print grid. */
function coloring_page_has_print_resolution(string $png, int $areaWidth = COLORING_AREA_WIDTH_PX, int $areaHeight = COLORING_AREA_HEIGHT_PX): bool
{
    $info = @getimagesizefromstring($png);
    return is_array($info) && (int) ($info[0] ?? 0) >= $areaWidth
        && (int) ($info[1] ?? 0) >= $areaHeight;
}

/**
 * Crop a flattened page to its ink and fit it into the print area: centred, never enlarged, reduced only if it is too big.
 *
 * The model leaves uneven margins of 1% to 9% whatever the prompt says (measured, D-417), and its top, bottom, left and right
 * differ, so the drawing sat off-centre. This makes the margins even and exact for free. It cannot repair a shape the model
 * cut off itself. Returns the input unchanged when GD is missing, the image cannot be read, or the page is blank.
 */
function coloring_fit_to_print_area(string $png, int $areaWidth = COLORING_AREA_WIDTH_PX, int $areaHeight = COLORING_AREA_HEIGHT_PX): string
{
    if (!function_exists('imagecreatefromstring') || !function_exists('imagepng')) return $png;
    $image = @imagecreatefromstring($png);
    if ($image === false) return $png;
    $width = imagesx($image);
    $height = imagesy($image);
    $palette = !imageistruecolor($image);
    $dark = [];
    if ($palette) {
        for ($index = 0, $total = imagecolorstotal($image); $index < $total; $index++) {
            if (imagecolorsforindex($image, $index)['red'] < 128) $dark[$index] = true;
        }
    }
    $minX = $width; $maxX = -1; $minY = $height; $maxY = -1;
    for ($y = 0; $y < $height; $y += 2) {
        for ($x = 0; $x < $width; $x += 2) {
            $value = imagecolorat($image, $x, $y);
            if ($palette ? !isset($dark[$value]) : ($value & 0xFF) >= 128) continue;
            if ($x < $minX) $minX = $x;
            if ($x > $maxX) $maxX = $x;
            if ($y < $minY) $minY = $y;
            if ($y > $maxY) $maxY = $y;
        }
    }
    if ($maxX < $minX) return $png;
    /* The scan samples every second pixel, so widen the box by two to keep the last stroke whole. */
    $minX = max(0, $minX - 2); $minY = max(0, $minY - 2);
    $maxX = min($width - 1, $maxX + 2); $maxY = min($height - 1, $maxY + 2);
    $boxWidth = $maxX - $minX + 1;
    $boxHeight = $maxY - $minY + 1;
    $scale = min(1.0, $areaWidth / $boxWidth, $areaHeight / $boxHeight);
    $targetWidth = max(1, (int) round($boxWidth * $scale));
    $targetHeight = max(1, (int) round($boxHeight * $scale));
    $canvas = imagecreatetruecolor($areaWidth, $areaHeight);
    imagefill($canvas, 0, 0, imagecolorallocate($canvas, 255, 255, 255));
    imagecopyresampled($canvas, $image, intdiv($areaWidth - $targetWidth, 2),
        intdiv($areaHeight - $targetHeight, 2), $minX, $minY, $targetWidth, $targetHeight, $boxWidth, $boxHeight);
    ob_start();
    imagepng($canvas);
    $fitted = (string) ob_get_clean();

    return $fitted === '' ? $png : coloring_flatten_line_art($fitted);
}

/**
 * The page prompt, written like the cover prompt that worked (D-417, launch spec 5e): numbered contents, an explicit layout
 * rule, a positive line style. Tested for Bold & Easy only (4 of 4 pages first time); the other styles' descriptions are
 * untested guesses. The scene is a main subject, objects and setting when the plan has them, else its one sentence.
 */
function coloring_page_prompt(string $style, string $audience, string $scene, string $characterDesc = '', array $detail = []): string
{
    $kids = $detail['kids'] ?? (stripos($audience, 'kid') !== false);
    $who = trim((string) ($detail['who'] ?? '')) ?: ($kids ? 'children aged 4 to 8' : 'adults');
    $lines = [
        'cozy corner style' => 'cozy, charming, bold-outline designs that are a pleasure to color, in an original style of our own: never reproduce any specific artwork, character or composition by any existing artist; bold, even, clean outlines; closed shapes; large friendly areas to color; no hatching, no texture',
        'bold & easy' => 'thick, even, clean outlines of the same weight everywhere; simple rounded closed shapes; big friendly faces; large open areas that small hands can color; no tiny details, no hatching, no texture',
        'kawaii chibi' => 'kawaii chibi: big round heads, huge sparkling eyes, tiny soft bodies; thick, even, clean outlines; simple rounded closed shapes; large open areas to color; no hatching, no texture',
        'cute animals' => 'friendly cartoon animals with big eyes and simple rounded bodies; thick, even, clean outlines; closed shapes; large open areas to color; no hatching, no texture',
        'anime' => 'anime style: clean confident line art, expressive eyes, even outlines; closed shapes; no shading, no hatching',
        'florals & botanicals' => 'flowers and leaves as clean closed shapes with even medium outlines and moderate detail; no shading, no hatching',
        'mandala & patterns' => 'balanced, symmetrical pattern with even medium outlines and clear fine detail; closed shapes; no shading',
        'detailed & realistic' => 'realistic proportions and precise architectural or natural detail; clean even outlines of medium weight; closed shapes; fine interior lines for texture and structure; no shading, no hatching, no solid fills',
        'cozy scenes' => 'cozy indoor scenes with warm hand-drawn charm: a reading nook, a coffee shop corner, a cottage interior, a windowsill with plants; soft confident outlines of medium weight; closed shapes; fine props like books, mugs and lamps rendered as clean line art; no hatching, no shading, no texture',
        'cityscapes & architecture' => 'detailed architectural line art of a city street or landmark: windows, awnings, doorways, cars, signage, and simple figures; clean even outlines of medium weight; precise straight lines; closed shapes; no shading, no hatching',
        'fantasy & whimsical' => 'whimsical fantasy subjects: dragons, mushroom houses, witches, moon phases, celestial motifs; medium weight outlines, closed shapes, decorative detail at the edges; no shading, no hatching',
        'food & drink' => 'cozy food and drink subjects: lattes, pastries, ramen bowls, charcuterie, cocktails; clean rounded outlines of medium weight; closed shapes; small decorative detail like steam swirls and sprinkles; no shading, no hatching',
        'vehicles & machines' => 'friendly cartoon vehicles and machines for young kids: trucks, excavators, trains, rockets, cement mixers; thick, even, clean outlines; simple geometric shapes; big friendly windows and wheels; no small details, no hatching, no texture',
    ];
    $line = $lines[strtolower($style)] ?? 'thick, even, clean outlines; simple closed shapes; large open areas to color; no hatching, no texture';
    /* The plan gives a main subject, supporting objects and a setting per scene; an older plan, or a scene the customer
       edited, has only the one sentence. */
    $main = trim((string) ($detail['main'] ?? ''));
    $objects = trim((string) ($detail['objects'] ?? ''));
    $setting = trim((string) ($detail['setting'] ?? ''));
    if ($main !== '') {
        $contents = "1. The main subject, drawn large: {$main}.\n";
        if ($objects !== '') $contents .= "2. Also in the picture, and nothing else: {$objects}.\n";
        if ($setting !== '') $contents .= '3. The setting, kept simple with few elements: ' . $setting . ".\n";
    } else {
        $contents = "1. The scene: {$scene}.\n";
    }
    if ($characterDesc !== '') $contents .= "A recurring character, drawn the same way on every page: {$characterDesc}.\n";
    $sheet = trim((string) ($detail['style_sheet'] ?? ''));
    if ($sheet !== '') $contents .= "Characters look the same on every page of this book: {$sheet}.\n";

    $captions = !empty($detail['captions']);
    $layout = $captions
        ? "LAYOUT RULE: The drawing fills the top two-thirds of the page. Leave the bottom third completely blank and white, text will be printed there. "
        . "About 8% blank margin on the top and sides. The subject is large and fills most of the drawing area. "
        . "Every object is complete and fully visible: nothing is cut off. Nothing touches or crosses the blank margin. "
        . "Do not draw any frame, border, box, ground line, or row of dashes or marks along any edge of the page."
        : "LAYOUT RULE: The whole drawing sits in the middle of the page with about 8% of blank white page on every side. "
        . "The subject is large and fills most of that middle area. Every object is complete and fully visible: nothing is cut off. "
        . "Nothing touches or crosses the blank margin. Do not draw any frame, border, box, ground line, or row of dashes or marks "
        . "along any edge of the page.";

    $orientation = (string) ($detail['orientation'] ?? 'Portrait');
    return 'Create a black-and-white coloring page for ' . $who . '. ' . $orientation . ".\n\n"
        . "FORMAT: Pure black outlines on a pure white background. Every shape is empty and unfilled, ready to be colored. "
        . "No grey, no shading, no solid black areas (small round eyes may be solid black). No text, letters or numbers.\n\n"
        . "THE PAGE MUST CONTAIN:\n{$contents}\n"
        . "{$layout}\n\n"
        . "LINE STYLE ({$style}): {$line}.\n\n"
        . (trim((string) ($detail['level'] ?? '')) !== '' ? "DETAIL LEVEL: {$detail['level']}\n\n" : '')
        . (trim((string) ($detail['occasion'] ?? '')) !== '' ? "THEME: {$detail['occasion']}\n\n" : '')
        . 'Professional coloring book illustration for ' . $who . '.';
}

/**
 * Turn the model's grey-scale drawing into pure black and white.
 *
 * Two reasons, both measured on 2026-09-29. The raw 2304x3072 PNG is 4.5 MB, so 24 of them cannot
 * be inlined into one PDF within PHP's memory limit; flattened it is about 80 KB. And the model
 * sometimes draws a stroke that fades to light grey: a hard 128 cut turns the fade into a gap in the
 * outline, while cutting at 235 keeps it as a closed line and leaves the background clean.
 *
 * Returns the input unchanged when GD is missing or the image cannot be read, so a page is never lost.
 */
function coloring_flatten_line_art(string $png): string
{
    if (!function_exists('imagecreatefromstring') || !function_exists('imagepng')) return $png;
    $image = @imagecreatefromstring($png);
    if ($image === false) return $png;
    imagefilter($image, IMG_FILTER_GRAYSCALE);
    imagetruecolortopalette($image, false, 256);
    for ($index = 0, $total = imagecolorstotal($image); $index < $total; $index++) {
        $shade = imagecolorsforindex($image, $index)['red'] >= 235 ? 255 : 0;
        imagecolorset($image, $index, $shade, $shade, $shade);
    }
    /* Two colours make GD write a 1-bit PNG, about 80 KB instead of 590 KB. */
    imagepalettetotruecolor($image);
    imagetruecolortopalette($image, false, 2);
    for ($index = 0, $total = imagecolorstotal($image); $index < $total; $index++) {
        $shade = imagecolorsforindex($image, $index)['red'] >= 128 ? 255 : 0;
        imagecolorset($image, $index, $shade, $shade, $shade);
    }
    ob_start();
    imagepng($image, null, 9);
    $flat = (string) ob_get_clean();

    return $flat !== '' ? $flat : $png;
}

/** Reject empty or almost-solid output while allowing very sparse outlines after downsampling. */
function coloring_line_art_has_ink(string $png): bool
{
    if (!function_exists('imagecreatefromstring') || !function_exists('imagecopyresampled')) return true;
    $source = @imagecreatefromstring($png);
    if ($source === false) return false;
    $sample = imagecreatetruecolor(64, 64);
    imagecopyresampled($sample, $source, 0, 0, 0, 0, 64, 64, imagesx($source), imagesy($source));
    $ink = 0.0;
    for ($y = 0; $y < 64; $y++) {
        for ($x = 0; $x < 64; $x++) {
            $pixel = imagecolorat($sample, $x, $y);
            $red = ($pixel >> 16) & 255; $green = ($pixel >> 8) & 255; $blue = $pixel & 255;
            $luminance = 0.299 * $red + 0.587 * $green + 0.114 * $blue;
            $ink += (255 - $luminance) / 255;
        }
    }
    $coverage = $ink / (64 * 64);
    return $coverage > 0.00005 && $coverage < 0.8;
}

/** Convert the actual selected cover, rather than drawing a second interpretation of its brief. */
function generate_coloring_cover_outline(string $jpeg, array $project, ?array $usageContext = null): string
{
    $apiKey = getenv('OPENAI_API_KEY');
    if (!$apiKey) throw new RuntimeException('OPENAI_API_KEY is not configured.');
    $trim = coloring_project_trim($project);
    $response = ai_http_json('https://api.openai.com/v1/images/edits',
        ['authorization: Bearer ' . $apiKey], [
            'model' => getenv('OPENAI_IMAGE_MODEL') ?: 'gpt-image-2',
            'images' => [['image_url' => 'data:image/jpeg;base64,' . base64_encode($jpeg)]],
            'prompt' => 'Convert this exact book cover into its uncoloured coloring-book page. '
                . 'Trace and preserve the original composition, every subject, pose, object, border and decoration in the same position and proportions. '
                . 'Keep all title lettering and other text exactly as spelled and positioned; turn filled letters into hollow black outlines with white interiors. '
                . 'Remove ALL colour fills, dark background fills, shading, shadows and gradients. Replace them with pure white. '
                . 'Every contour is ONE single confident black stroke of uniform width. No sketch marks, no doubled lines, no parallel ghost strokes, no cross-hatching, no stipple, no feathering, no pencil-style multi-pass construction lines. '
                . 'Keep crisp, clean black contour lines and small essential details such as pupils. '
                . 'Every large area must be white and ready to colour, including backgrounds and title panels. '
                . 'Do not redesign, simplify, add, remove or rearrange anything. Preserve the complete artwork without cropping. '
                . 'Flat black line art on pure white, no grayscale, no photograph or mockup.',
            'size' => $trim['image_size'],
            'quality' => getenv('OPENAI_IMAGE_QUALITY') ?: 'low',
            /* D-656: as a page, JPEG for the transfer; it is flattened to a 1-bit PNG below. */
            'output_format' => 'jpeg',
            'output_compression' => COLORING_LINE_ART_JPEG_QUALITY,
        ], $usageContext);
    $encoded = $response['data'][0]['b64_json'] ?? null;
    $png = is_string($encoded) ? base64_decode($encoded, true) : false;
    if ($png === false || !coloring_page_has_print_resolution($png, $trim['area_px_w'], $trim['area_px_h'])) {
        throw new RuntimeException('The cover coloring page did not meet the print resolution requirement.');
    }
    $flat = coloring_flatten_line_art($png);
    if (!coloring_line_art_has_ink($flat)) {
        throw new RuntimeException('The cover coloring page did not contain usable line art.');
    }
    return coloring_require_png(coloring_fit_to_print_area($flat, $trim['area_px_w'], $trim['area_px_h']));
}

/**
 * D-656 review 2: the drawings arrive as JPEG, and the flatten and fit helpers hand their input back when GD cannot read it, so a
 * raw JPEG would be saved as coloring/<hex>.png and break the image route and the PDF. A page that is not a PNG by now fails, and
 * the worker retries it as any failed page.
 */
function coloring_require_png(string $bytes): string
{
    if (!str_starts_with($bytes, "\x89PNG\r\n\x1a\n")) throw new RuntimeException('The coloring page could not be converted to a PNG.');

    return $bytes;
}

function generate_coloring_page(string $style, string $audience, string $scene,
    string $characterDesc = '', ?array $usageContext = null, array $detail = []): string
{
    $apiKey = getenv('OPENAI_API_KEY');
    if (!$apiKey) throw new RuntimeException('OPENAI_API_KEY is not configured.');
    /* Trim-aware size and target area (D-492). The default stays the historical portrait values. */
    $trimKey = (string) ($detail['trim'] ?? 'portrait');
    $isSquare = $trimKey === 'square';
    $imageSize = $isSquare ? '2400x2400' : COLORING_DRAW_SIZE;
    $areaW = COLORING_AREA_WIDTH_PX;
    $areaH = $isSquare ? COLORING_AREA_WIDTH_PX : COLORING_AREA_HEIGHT_PX;
    $detail['orientation'] = $isSquare ? 'Square (equal width and height)' : 'Portrait';
    $prompt = coloring_page_prompt($style, $audience, $scene, $characterDesc, $detail);

    /* One redo at most, and only when the first page runs off the edge. Both attempts are billed and
       recorded (a distinct action key each), and the page with less ink on its edge is kept. */
    $image = false;
    $edgeInk = 100.0;
    for ($attempt = 1; $attempt <= 2; $attempt++) {
        $context = $usageContext;
        if ($context !== null && isset($context['action_key'])) $context['action_key'] .= ':try' . $attempt;
        $response = ai_http_json(
            'https://api.openai.com/v1/images/generations',
            ['authorization: Bearer ' . $apiKey],
            [
                'model' => getenv('OPENAI_IMAGE_MODEL') ?: 'gpt-image-2',
                'prompt' => $prompt,
                /* 2400x3200 (3:4) is 300 DPI pixel for pixel in the 7.8 x 10.4 inch print area (D-418). The MVP's
                   1024x1024 was about 137 DPI. */
                'size' => $imageSize,
                'quality' => getenv('OPENAI_IMAGE_QUALITY') ?: 'low',
                /* D-656: JPEG for the transfer only; the page is flattened to a 1-bit PNG below, as before. */
                'output_format' => 'jpeg',
                'output_compression' => COLORING_LINE_ART_JPEG_QUALITY,
            ],
            $context
        );
        $encoded = $response['data'][0]['b64_json'] ?? null;
        $candidate = is_string($encoded) ? base64_decode($encoded, true) : false;
        /* Several pages are in flight at once in the parallel pool (D-645): keep one copy of a drawing, not three. */
        unset($response, $encoded);
        if ($candidate === false) {
            if ($image === false) throw new RuntimeException('The image model returned no usable page.');
            break;
        }
        if (!coloring_page_has_print_resolution($candidate, $areaW, $areaH)) {
            $info = @getimagesizefromstring($candidate);
            $actual = is_array($info) ? ((int) ($info[0] ?? 0) . ' × ' . (int) ($info[1] ?? 0)) : 'unknown dimensions';
            throw new RuntimeException('The image model returned ' . $actual
                . "; coloring-book pages require at least {$areaW} × {$areaH} source pixels for the 300-PPI print area.");
        }
        $candidate = coloring_flatten_line_art($candidate);
        $candidateInk = coloring_edge_ink($candidate);
        if ($image === false || $candidateInk < $edgeInk) {
            $image = $candidate;
            $edgeInk = $candidateInk;
        }
        if ($edgeInk < COLORING_EDGE_INK_LIMIT) break;
    }
    if ($edgeInk >= COLORING_EDGE_INK_LIMIT) {
        error_log(sprintf('coloring-engine: coloring page still runs off the edge after a redo (%.1f%% of one edge is ink)',
            $edgeInk));
    }
    $image = coloring_require_png(coloring_fit_to_print_area($image, $areaW, $areaH));
    $directory = application_data_directory() . '/coloring';
    if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
        throw new RuntimeException('Unable to create the coloring directory.');
    }
    $relativePath = 'coloring/' . bin2hex(random_bytes(16)) . '.png';
    if (file_put_contents(application_data_directory() . '/' . $relativePath, $image) === false) {
        throw new RuntimeException('Unable to save the coloring page.');
    }
    return $relativePath;
}

/** Cover size: 2512 x 3280 is the largest 3:4 the image API accepts (2592 x 3392 is refused), 291 DPI across the 8.625 inch front panel. */
const COLORING_COVER_SIZE = '2512x3280';

/**
 * The cover prompt, tested in Stage 1 (9 of 9 covers spelled right): the exact words and the layout are written into the
 * prompt so the model draws the title, and every character is arranged around it. Drawing the title on afterwards covered the
 * characters. The badge sits well inside the frame so Amazon's trimming never touches it.
 *
 * @param array{title_lines:array<int,string>,subtitle:string,badge:string,theme:string,background:string} $brief
 */
function coloring_cover_prompt(array $brief): string
{
    $template = (string) ($brief['cover_template'] ?? 'badge');
    $isBubble = $template === 'bubble';
    $isOval = $template === 'oval';
    /* White and yellow letters cannot be read on a yellow or gold ground, so that ground gets red and blue letters. */
    $yellowGround = (bool) preg_match('/yellow|gold|mustard|lemon|amber/i', (string) ($brief['background'] ?? ''));
    $colors = match (true) {
        $isBubble => ['white', 'white', 'white'],
        $yellowGround => ['bright red', 'royal blue', 'bright red'],
        default => ['white', 'bright yellow', 'white'],
    };
    $lines = [];
    foreach (array_values($brief['title_lines']) as $i => $text) {
        $lines[] = 'line ' . ($i + 1) . ' "' . $text . '" in ' . $colors[$i % 3];
    }
    $audience = $brief['audience'] ?? 'kids';
    $style = $brief['style'] ?? '';
    $isAdult = $audience === 'adults';
    $who = $isAdult ? 'an adult coloring book' : "a children's coloring book (ages 4-8)";
    $artDirection = $isAdult
        ? 'Elegant, sophisticated art with realistic proportions. No cartoon faces, no googly eyes, no anthropomorphised objects. '
          . 'Clean detailed illustration, rich colours that suit the idea, professional cover quality.'
        : "Bold clean outlines, bright colours that suit the idea, friendly cartoon faces, professional children's book cover quality.";
    if ($isBubble) {
        /* The cute & comfy look (D-494): the cover looks like a coloured-in page of the book itself. */
        $artDirection = 'Flat 2D hand-drawn illustration that looks exactly like a neatly coloured-in page of a cute coloring '
            . 'book: every shape has the same thick, smooth, dark brown outline, filled with flat soft colour. Simple '
            . 'round chubby characters with tiny dot eyes, small blush cheeks and small smiles. No gradients, no glossy '
            . 'highlights, no 3D shading, no texture, no glow, no clutter.';
    } elseif ($isOval) {
        /* The busy kids medley (D-495): the drawings around the oval look like the pages inside. */
        $artDirection = 'Clean, bold black-outline cartoon line art like the pages of a kids coloring book. Most of the small '
            . 'drawings are left white inside their outlines, a few have flat bright colour. Friendly cartoon faces. No '
            . 'gradients, no 3D shading, no photo detail.';
    } elseif ($style === 'detailed-realistic') {
        $artDirection = 'Photorealistic style with precise architectural detail and realistic proportions. No cartoon elements, '
            . 'no cartoon faces, no googly eyes. Clean, sophisticated illustration with rich saturated colors, professional cover quality.';
    }
    $orientation = (string) ($brief['orientation'] ?? 'Portrait');
    /* The cover template varies the layout; the palette tints the whole cover (D-493). */
    $palette = (string) ($brief['palette'] ?? '');
    require_once __DIR__ . '/coloring_book.php';
    $paletteInfo = coloring_palettes()[$palette] ?? null;
    $paletteColors = ($paletteInfo && !empty($paletteInfo['colors'])) ? implode(', ', $paletteInfo['colors']) : '';
    /* A palette the customer chose is a rule. An auto palette is only a fallback: the idea's own colours win (D-496). */
    if ($paletteColors !== '' && !empty($brief['palette_chosen'])) {
        $paletteLine = 'Dominant palette: ' . $paletteColors . '. Every colour on the cover uses these shades or near shades. ';
    } elseif ($paletteColors !== '') {
        $paletteLine = 'Colours: use the colours the book idea and the background describe. Only if they name none, lean '
            . 'towards ' . $paletteColors . '. ';
    } else {
        $paletteLine = 'Colours: use the colours the book idea and the background describe. ';
    }
    $idea = trim(preg_replace('/\s+/', ' ', (string) ($brief['idea'] ?? '')));
    $ideaLine = $idea === '' ? '' : "THE CUSTOMER'S BOOK IDEA (subjects, setting, mood and colours only; never copy its words "
        . 'onto the cover): "' . str_replace('"', "'", $idea) . "\"\n"
        . 'The layout rule sets where things go and how they are drawn. The subjects, scene and colours come from the '
        . "customer's idea and the theme, not from the layout rule's examples.\n\n";

    $titleWords = 'THE COVER MUST CONTAIN EXACTLY THESE WORDS, spelled exactly as written, in clean bold readable letters, and no other text anywhere:' . "\n"
        . '1. The main title, on ' . count($lines) . ' lines: ' . implode(', ', $lines)
            . '. Title/panel contrast must meet WCAG 4.5:1 or stronger (D-539): pick ONE of two combinations only, '
            . 'and use the same combination for every title line and the subtitle. '
            . 'EITHER (a) a very light panel (near-white, cream, pale pastel) with dark title letters '
            . '(near-black, charcoal, deep navy, deep maroon) and a thin dark outline; '
            . 'OR (b) a deep saturated panel (navy, black, deep indigo, deep teal, deep burgundy) with white or cream title letters '
            . 'and a thin dark outline. '
            . 'FORBIDDEN panel colours: any brown, tan, taupe, khaki, olive, mustard, muddy green, muddy orange, or any medium-tone '
            . 'muted colour; and never a panel whose hue is similar to the dominant hero colour behind it. '
            . 'FORBIDDEN title colour pairings: red on green, blue on brown, blue on green, red on brown, any saturated colour on '
            . 'another saturated colour of similar lightness.' . "\n"
        . '2. A smaller subtitle line directly under the title: "' . $brief['subtitle'] . "\".\n"
        . ($isBubble
            ? '3. The words "' . $brief['badge'] . "\" once, small, in neat dark handwritten script directly above the title, with no sticker or shape around them.\n"
            : '3. A short sticker containing the words "' . $brief['badge'] . "\" placed where the template below says.\n");

    $layoutRule = match ($template) {
        'hero' => 'LAYOUT RULE (hero scene): The whole cover is one rich full-colour scene that bleeds to every edge. '
            . 'The title sits on a clean strip across the top 22% of the cover; the subtitle sits on a thin strip across '
            . 'the bottom 10% of the cover; the sticker sits in the bottom-right corner of the top strip. The scene '
            . 'behind the top and bottom strips is tinted dark enough that the text reads clearly. Nothing crops the '
            . 'main subject. No white margins.',
        'grid' => 'LAYOUT RULE (grid of four): The top 18% of the cover is a title strip with the title and the sticker. '
            . 'The lower 72% is divided into a 2 by 2 grid of four complete coloured drawings, each a different scene '
            . 'from the book. The bottom 10% is a subtitle strip. The grid has a small gap between tiles. No white '
            . 'margins outside the grid.',
        'frame' => 'LAYOUT RULE (vintage frame): A hand-drawn ornate decorative frame runs around the whole cover, '
            . 'about 8% of the cover width in from every edge. Inside the frame, a single rich coloured scene fills the '
            . 'inner panel. The title sits in a banner across the top of the inner panel; the sticker sits in a small '
            . 'medallion on the frame itself in the top-right; the subtitle sits in a small banner at the bottom of the '
            . 'inner panel.',
        'minimalist' => 'LAYOUT RULE (minimalist flat): The cover is mostly flat solid background colour from the palette. '
            . 'A small central motif from the theme sits in the upper-middle. The title sits directly under the motif in '
            . 'large readable letters; the subtitle sits one line under the title; the sticker sits in the lower-middle. '
            . 'Lots of empty space. No background scene. No gradients.',
        'bubble' => 'LAYOUT RULE (cute bubble): A flat soft background colour, plain or with faint vertical stripes, and '
            . 'only a few tiny decorations from the theme near the top. The title sits across the top quarter '
            . 'in very large, chunky, rounded bubble capitals: white fill with one thick solid BLACK outline around every '
            . 'letter, no shadow, no 3D. The subtitle sits directly under the title in plain bold black capitals, smaller, '
            . 'with no outline. The lower two-thirds is one cosy scene: three or four cute round characters from the theme '
            . 'close together, sharing one moment, with small objects from the theme in the front, the scene running off '
            . 'the bottom and side edges. No sticker, no badge, no frame, no white margins.',
        'bigtitle' => 'LAYOUT RULE (big title): The title is the main feature. It fills the top half of the cover, one '
            . 'word or short line per row, in huge chunky bold capitals as wide as the cover allows, every letter with a '
            . 'thick black outline and a small dark drop shadow. The subtitle sits at the end of the title block in '
            . 'smaller bold capitals, set to the right. One big friendly cartoon character from the theme fills the lower '
            . 'half, overlapping the bottom of the title a little, in front of a simple matching scene. The sticker words '
            . 'sit small, in plain white letters, in the bottom-left corner. A deep background colour that suits the theme, '
            . 'dark enough that the letters stand out. No frame, no white margins.',
        'oval' => 'LAYOUT RULE (oval medley): A large upright oval panel sits in the centre, about 55% of the cover width, '
            . 'filled with one solid colour and edged with a thin decorative border. The title sits inside the oval in big '
            . 'chunky hand-drawn capitals with tiny doodle stars, dashes and dots around the words. The subtitle sits on a '
            . 'coloured ribbon band across the lower part of the oval. The sticker words sit inside a small white cloud '
            . 'shape in the top-right corner. Around the oval, the whole cover is filled edge to edge with 12 to 18 '
            . 'separate small drawings from the theme on one solid saturated background colour. The drawings touch the '
            . 'oval but never cover it or any text. No white margins.',
        default => 'LAYOUT RULE (centre badge): The title sits in the centre of the cover inside a dark rounded badge '
            . 'that is about 64% of the cover width. The subtitle sits inside the same badge, directly under the title. '
            . 'A round sticker in a bright contrasting colour containing the sticker words sits at the top-right corner of the title badge, close '
            . 'to the middle of the cover and far from the edges. Every character and object is arranged AROUND the '
            . 'badge, at the edges and corners; nothing overlaps, touches or hides the badge or any text. Every element '
            . 'is fully visible and never cut off by the frame.',
    };

    return 'Create a full-color front cover for ' . $who . '. ' . $orientation . ".\n\n"
        . $ideaLine
        . $titleWords . "\n"
        . $layoutRule . "\n\n"
        . 'Theme: ' . $brief['theme'] . '. Background: ' . $brief['background'] . '. '
        . $paletteLine
        . ($isBubble || $isOval ? 'Fill the entire frame edge to edge; ' : 'Fill the entire frame edge to edge with colourful art; ')
        . "no white margins, no borders, no logos, "
        . "no watermarks. " . $artDirection;
}

/**
 * Draw one cover and return it as JPEG bytes (about 1 MB; the PNG is 8 MB and the cover is shown and printed, never edited).
 * D-656: the model sends the JPEG itself (it used to send the 8 MB PNG, 11 MB as base64, for this function to convert), so the
 * JPEG it returns is kept as it came; anything else is converted here as before.
 */
function generate_coloring_cover(array $brief, ?array $usageContext = null): string
{
    $apiKey = getenv('OPENAI_API_KEY');
    if (!$apiKey) throw new RuntimeException('OPENAI_API_KEY is not configured.');
    $isSquareCover = (string) ($brief['trim'] ?? 'portrait') === 'square';
    $size = $isSquareCover ? '2800x2800' : COLORING_COVER_SIZE;
    if ($isSquareCover) {
        $brief['orientation'] = 'Square (equal width and height)';
    }
    $response = ai_http_json('https://api.openai.com/v1/images/generations', ['authorization: Bearer ' . $apiKey], [
        'model' => getenv('OPENAI_IMAGE_MODEL') ?: 'gpt-image-2',
        'prompt' => coloring_cover_prompt($brief),
        'size' => $size,
        'quality' => 'medium',
        'output_format' => 'jpeg',
        'output_compression' => 92,
    ], $usageContext);
    $encoded = $response['data'][0]['b64_json'] ?? null;
    unset($response);

    return coloring_cover_jpeg(is_string($encoded) ? base64_decode($encoded, true) : false);
}

/**
 * The cover bytes as JPEG. D-656 review 2: a JPEG is recognised from its header and kept as it came, never decoded (the 2512 x 3280
 * cover is about 33 MB once decoded with the bundled GD); anything else is decoded and converted.
 */
function coloring_cover_jpeg(string|false $bytes): string
{
    $info = $bytes === false ? false : @getimagesizefromstring($bytes);
    if (is_array($info) && ($info['mime'] ?? '') === 'image/jpeg') return $bytes;
    $image = $bytes === false ? false : @imagecreatefromstring($bytes);
    if ($image === false) throw new RuntimeException('The image model returned no usable cover.');
    ob_start();
    imagejpeg($image, null, 92);
    return (string) ob_get_clean();
}

/**
 * Read the words off a cover and compare them with the words we asked for. A cheap net, not a proof: in Stage 1 it caught 3 of
 * 4 deliberate misspellings and missed a transposition.
 *
 * The first read is the cheap model, plain (about $0.0004). The first live book showed it misread a correct CHIBI as CHUBI three
 * times running and paid for two extra covers, so a failed read is confirmed by a stronger model that spells each word letter by
 * letter (about $0.003) before the caller pays for another draw. Only a failure of both counts.
 *
 * @return array{ok:bool,missing:array<int,string>,extra:array<int,string>,confirmed:bool}
 */
function coloring_cover_check(string $jpeg, array $expectedTexts, ?array $usageContext = null): array
{
    $words = function (string $text): array {
        $text = strtoupper((string) preg_replace('/[^\p{L}\p{N}&]+/u', ' ', $text));
        return array_values(array_filter(explode(' ', trim($text)), fn($w) => $w !== ''));
    };
    $want = $words(implode(' ', $expectedTexts));
    $compare = function (array $got) use ($want): array {
        /* Pass on `missing` alone. A misspelt title word is always a missing word, while an extra word is usually a sign in the art
           (the first real run failed a good cover for reading POPCORN off a popcorn stand and paid for a second draw). */
        $missing = array_values(array_diff($want, $got));
        return ['ok' => $missing === [], 'missing' => $missing, 'extra' => array_values(array_diff($got, $want))];
    };
    $first = $compare($words(coloring_cover_read($jpeg, getenv('OPENAI_COVER_CHECK_MODEL') ?: 'gpt-5.4-nano', false, $usageContext)));
    if ($first['ok']) return $first + ['confirmed' => false];
    $context = $usageContext;
    if ($context !== null && isset($context['action_key'])) $context['action_key'] .= ':confirm';
    $second = $compare($words(coloring_cover_read($jpeg, getenv('OPENAI_COVER_CONFIRM_MODEL') ?: 'gpt-5.4-mini', true, $context)));

    return $second + ['confirmed' => true];
}

/** One vision read of a cover. `$letters` asks for each word spelt one character at a time, so the model cannot auto-correct. */
function coloring_cover_read(string $jpeg, string $model, bool $letters, ?array $usageContext): string
{
    $apiKey = getenv('OPENAI_API_KEY');
    if (!$apiKey) throw new RuntimeException('OPENAI_API_KEY is not configured.');
    $instruction = $letters
        ? 'Read ALL the text printed on this book cover. Do NOT use your knowledge of how words are normally spelt: report only the letters you can see. '
            . 'For every printed line, give the line spelled ONE CHARACTER AT A TIME, with a single space between characters and " | " between words. '
            . 'Misspelt words must stay misspelt. Return JSON: {"lines": ["D I N O | F R I E N D S"]} with one entry per printed line, in reading order.'
        : 'Read ALL the text printed on this book cover. Copy every word EXACTLY as printed, letter by letter. If a word is misspelt, copy the '
            . 'misspelling; never correct, complete or guess. Return JSON: {"lines": ["..."]} with one entry per printed line of text, in reading order.';
    $response = ai_http_json('https://api.openai.com/v1/chat/completions', ['authorization: Bearer ' . $apiKey], [
        'model' => $model,
        'max_completion_tokens' => 700,
        'response_format' => ['type' => 'json_object'],
        'messages' => [['role' => 'user', 'content' => [
            ['type' => 'text', 'text' => $instruction],
            ['type' => 'image_url', 'image_url' => ['url' => 'data:image/jpeg;base64,' . base64_encode($jpeg), 'detail' => 'high']],
        ]]],
    ], $usageContext);
    $decoded = json_decode((string) ($response['choices'][0]['message']['content'] ?? ''), true);
    $lines = is_array($decoded['lines'] ?? null) ? $decoded['lines'] : [];
    if ($letters) {
        /* "W O O D | L A N D" -> "WOOD LAND": drop the spaces inside a word, keep the bars between words. */
        $lines = array_map(fn($line) => implode(' ', array_map(fn($w) => str_replace(' ', '', trim($w)), explode('|', (string) $line))), $lines);
    }

    return implode(' ', array_map('strval', $lines));
}
