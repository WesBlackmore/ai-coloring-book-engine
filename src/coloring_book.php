<?php

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/ai.php';
require_once __DIR__ . '/coloring_categories.php';
require_once __DIR__ . '/shims.php';

/* 3 in production until the Stage 1 quality test passes (D-367, D-386). The variable exists so that test
   can make a full 24-page book without changing the default. */
define('COLORING_PAGE_COUNT', max(1, min(24, (int) (getenv('COLORING_PAGE_COUNT') ?: 24))));

$coloringStyles = [
    'kawaii-chibi' => 'Kawaii Chibi',
    'mandala' => 'Mandala & Patterns',
    'anime' => 'Anime',
    'bold-easy' => 'Bold & Easy',
    'florals' => 'Florals & Botanicals',
    'cute-animals' => 'Cute Animals',
    'cozy-corner' => 'Cozy Corner Style',
    'detailed-realistic' => 'Detailed & Realistic',
    /* New styles for D-493. Each maps to a rising Amazon KDP sub-category. */
    'cozy-scenes' => 'Cozy Scenes',
    'cityscapes-architecture' => 'Cityscapes & Architecture',
    'fantasy-whimsical' => 'Fantasy & Whimsical',
    'food-and-drink' => 'Food & Drink',
    'vehicles-machines' => 'Vehicles & Machines',
];

/* Styles offered to some audiences only (D-438, extended D-493). The style key => the audiences
   it may be used for. Omit a style to allow it for every audience. */
$coloringStyleAudiences = [
    'cozy-corner' => ['adults'],
    'cozy-scenes' => ['adults'],
    'cityscapes-architecture' => ['adults'],
    'fantasy-whimsical' => ['adults', 'teens'],
    'food-and-drink' => ['adults'],
    'vehicles-machines' => ['kids'],
];

/* Styles that exist but are not offered. Mandala is held back (owner, 2026-09-29): its dense fine lines are
   ragged at `low` quality and only `medium` (about $2.84 a book) is clean. Remove a key here to offer it. */
$coloringHeldStyles = ['mandala'];

/** The styles a person may choose. */
function coloring_styles(): array
{
    global $coloringStyles, $coloringHeldStyles;
    return array_diff_key($coloringStyles, array_flip($coloringHeldStyles));
}

/** May this style be used for this audience? A style with no entry is open to everyone. */
function coloring_style_allowed(string $style, string $audience): bool
{
    global $coloringStyleAudiences;
    return !isset($coloringStyleAudiences[$style]) || in_array($audience, $coloringStyleAudiences[$style], true);
}

/** The audiences a style is limited to, or null when it is open to everyone (the create screen hides it for the others). */
function coloring_style_audiences(string $style): ?array
{
    global $coloringStyleAudiences;
    return $coloringStyleAudiences[$style] ?? null;
}

/** A label for any style, held or not, so an existing project never shows a raw key. */
function coloring_style_label(string $key): string
{
    global $coloringStyles;
    return $coloringStyles[$key] ?? $key;
}

/* Book trim choices (D-492). Portrait is the historical default; square matches many Amazon
   KDP coloring-book bestsellers. The trim is picked at creation and baked into the generated
   artwork, so a project cannot switch later. */
function coloring_trims(): array
{
    return [
        'portrait' => ['label' => 'Portrait', 'note' => '8.5 x 11 in, the classic coloring-book shape',
            'page_w' => 8.5, 'page_h' => 11.0, 'area_w' => 7.8, 'area_h' => 10.4,
            'image_size' => '2400x3200', 'image_w' => 2400, 'image_h' => 3200,
            'area_px_w' => 2340, 'area_px_h' => 3120, 'orientation' => 'Portrait',
            'dompdf' => ['letter', 'portrait']],
        'square' => ['label' => 'Square', 'note' => '8.5 x 8.5 in, modern and popular on Amazon',
            'page_w' => 8.5, 'page_h' => 8.5, 'area_w' => 7.8, 'area_h' => 7.8,
            'image_size' => '2400x2400', 'image_w' => 2400, 'image_h' => 2400,
            'area_px_w' => 2340, 'area_px_h' => 2340, 'orientation' => 'Square',
            'dompdf' => [[0, 0, 612, 612], 'portrait']],
    ];
}

/** Pick a safe trim from an input string. Unknown values fall back to the default. */
function coloring_trim_key(?string $value): string
{
    $value = strtolower(trim((string) $value));
    return isset(coloring_trims()[$value]) ? $value : 'portrait';
}

/** The trim settings for a project row. Missing or legacy rows default to portrait. */
function coloring_project_trim(array $project): array
{
    $key = coloring_trim_key((string) ($project['trim'] ?? 'portrait'));
    return coloring_trims()[$key] + ['key' => $key];
}

/* Cover templates (D-493). Each key maps to a short clause that replaces the LAYOUT RULE in
   coloring_cover_prompt(). 'auto' picks a sensible default per audience. */
function coloring_cover_templates(): array
{
    return [
        'auto' => ['label' => 'Auto', 'note' => 'Pick for me'],
        'badge' => ['label' => 'Centre badge', 'note' => 'Title in a dark rounded badge, a round sticker top-right'],
        'hero' => ['label' => 'Hero scene', 'note' => 'Full-colour scene fills the cover; title sits on a strip at the top'],
        'grid' => ['label' => 'Grid of four', 'note' => 'Title on top, four coloured scenes in a grid below'],
        'frame' => ['label' => 'Vintage frame', 'note' => 'An ornate frame around the art; good for fantasy and florals'],
        'minimalist' => ['label' => 'Minimalist flat', 'note' => 'One central motif on a flat, solid colour'],
        'bubble' => ['label' => 'Cute bubble', 'note' => 'Black-outlined bubble title over a flat, cute animal scene'],
        /* Kids only (D-495): the two best-selling children's cover styles. */
        'bigtitle' => ['label' => 'Big title', 'note' => 'A huge stacked title with one big character below',
            'only_for' => ['kids']],
        'oval' => ['label' => 'Oval medley', 'note' => 'Title in a centre oval, lots of little drawings around it',
            'only_for' => ['kids']],
    ];
}

function coloring_cover_template_key(?string $value): string
{
    $value = strtolower(trim((string) $value));
    return isset(coloring_cover_templates()[$value]) ? $value : 'auto';
}

/* Palette presets (D-493). A 4-colour swatch injected into the cover prompt. */
function coloring_palettes(): array
{
    return [
        'auto' => ['label' => 'Auto', 'note' => 'Pick for me', 'colors' => []],
        'soft-pastel' => ['label' => 'Soft pastel', 'note' => 'Kids, cute, cozy',
            'colors' => ['pale pink', 'buttermilk yellow', 'mint green', 'baby blue']],
        'deep-jewel' => ['label' => 'Deep jewel', 'note' => 'Fantasy, mandala, witchy',
            'colors' => ['deep emerald', 'royal purple', 'burnt gold', 'midnight blue']],
        /* Dropped `warm brown` as a palette colour (D-539): the model used it as the title panel
           and produced brown-on-brown covers. Deep forest reads cleanly behind cream or white text. */
        'warm-earthy' => ['label' => 'Warm earthy', 'note' => 'Cozy scenes, botanicals, cottagecore',
            'colors' => ['terracotta', 'cream', 'sage green', 'deep forest']],
        'mono-plus-accent' => ['label' => 'Mono + accent', 'note' => 'Minimalist, modern mandala',
            'colors' => ['ink black', 'bright white', 'cool grey', 'bright coral']],
        'vibrant-primary' => ['label' => 'Vibrant primary', 'note' => 'Bold, vehicles, kids 3-6',
            'colors' => ['bright red', 'sunflower yellow', 'sky blue', 'grass green']],
    ];
}

function coloring_palette_key(?string $value): string
{
    $value = strtolower(trim((string) $value));
    return isset(coloring_palettes()[$value]) ? $value : 'auto';
}

/* Resolve the 'auto' palette into a concrete key, based on audience and style. */
function coloring_palette_auto_pick(string $audience, string $style): string
{
    if ($audience === 'kids') {
        return $style === 'vehicles-machines' ? 'vibrant-primary' : 'soft-pastel';
    }
    return match ($style) {
        'mandala', 'fantasy-whimsical' => 'deep-jewel',
        'cozy-scenes', 'florals', 'cozy-corner' => 'warm-earthy',
        'cityscapes-architecture', 'detailed-realistic' => 'mono-plus-accent',
        default => 'warm-earthy',
    };
}

/* Resolve the 'auto' cover template into a concrete key, based on audience and style. */
function coloring_cover_template_auto_pick(string $audience, string $style): string
{
    if ($audience === 'kids') {
        return in_array($style, ['kawaii-chibi', 'cute-animals', 'cozy-corner'], true) ? 'bubble' : 'badge';
    }
    return match ($style) {
        'mandala' => 'minimalist',
        'fantasy-whimsical' => 'frame',
        'cozy-corner' => 'bubble',
        'cozy-scenes', 'cityscapes-architecture', 'florals', 'food-and-drink' => 'hero',
        default => 'grid',
    };
}

const COLORING_IDEA_MAX = 1000;

function coloring_validate(array $source, int $defaultPages = COLORING_PAGE_COUNT): array
{
    $idea = trim((string) ($source['idea'] ?? ''));
    if (mb_strlen($idea) < 20 || mb_strlen($idea) > COLORING_IDEA_MAX) {
        throw new InvalidArgumentException('Describe the book in 20 to ' . COLORING_IDEA_MAX . ' characters.');
    }
    $style = (string) ($source['style'] ?? '');
    if ($style !== '' && !isset(coloring_styles()[$style])) {
        throw new InvalidArgumentException('Select a style.');
    }
    $categories = coloring_validate_categories($source);
    if (!coloring_style_allowed($style, $categories['audience'])) {
        throw new InvalidArgumentException('That style is only for adult books. Choose another style.');
    }
    $recurring = !empty($source['recurring']);
    $characterDesc = '';
    if ($recurring) {
        $characterDesc = trim((string) ($source['character_desc'] ?? ''));
        if (mb_strlen($characterDesc) > 200) {
            throw new InvalidArgumentException('Keep the character description under 200 characters.');
        }
    }
    $captions = !empty($source['captions']);
    if (!$captions && preg_match('/\b(name|fact|caption|text below|text under|description below|label)\b/i', $idea)) $captions = true;
    $trim = coloring_trim_key((string) ($source['trim'] ?? 'portrait'));
    $template = coloring_cover_template_key((string) ($source['cover_template'] ?? 'auto'));
    $templateOnly = coloring_cover_templates()[$template]['only_for'] ?? null;
    if ($templateOnly !== null && !in_array($categories['audience'], $templateOnly, true)) $template = 'auto';
    $palette = coloring_palette_key((string) ($source['palette'] ?? 'auto'));
    return ['idea' => $idea, 'style' => $style, 'recurring' => $recurring, 'characterDesc' => $characterDesc,
        'page_count' => coloring_validate_pages($source, $defaultPages), 'captions' => $captions, 'trim' => $trim,
        'cover_template' => $template, 'palette' => $palette] + $categories;
}

function coloring_create_project(?string $ownerRef, array $values): int
{
    $pdo = db();
    $stmt = $pdo->prepare(
        'INSERT INTO coloring_projects (owner_ref, status, idea, style, audience, age_band, mood, occasion, page_count, character_desc, title, captions, trim, cover_template, palette)
         VALUES (:owner_ref, :status, :idea, :style, :audience, :age_band, :mood, :occasion, :page_count, :character_desc, :title, :captions, :trim, :cover_template, :palette)'
    );
    $stmt->execute([
        'owner_ref' => $ownerRef,
        'status' => 'draft',
        'idea' => $values['idea'],
        'style' => $values['style'],
        'audience' => $values['audience'],
        'age_band' => $values['age_band'] ?? null,
        'mood' => $values['mood'] ?? null,
        'occasion' => $values['occasion'] ?? null,
        'page_count' => $values['page_count'] ?? COLORING_PAGE_COUNT,
        'character_desc' => $values['characterDesc'],
        'title' => '',
        'captions' => !empty($values['captions']) ? 1 : 0,
        'trim' => coloring_trim_key((string) ($values['trim'] ?? 'portrait')),
        'cover_template' => coloring_cover_template_key((string) ($values['cover_template'] ?? 'auto')),
        'palette' => coloring_palette_key((string) ($values['palette'] ?? 'auto')),
    ]);
    return (int) $pdo->lastInsertId();
}

/** `$userId` is kept so call sites match the host app; the engine has no users and ignores it. */
function coloring_project(int $id, int $userId = 0): ?array
{
    $stmt = db()->prepare('SELECT * FROM coloring_projects WHERE id = ?');
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
}

/**
 * D-609 lease fence. A worker stamps `lease_token` on the row it works from; every write it makes after a provider
 * returns carries the token in its WHERE clause, so a run whose lease was taken over changes nothing. Rows without
 * the key (routes, MCP) are unfenced, as before.
 */
function coloring_lease_clause(array $project, array &$params): string
{
    $token = (string) ($project['lease_token'] ?? '');
    if ($token === '') return '';
    $params[] = $token;

    return ' AND generating_token = ?';
}

function coloring_lease_assert(array $project, PDOStatement $update): void
{
    if ((string) ($project['lease_token'] ?? '') !== '' && $update->rowCount() !== 1) {
        throw new RuntimeException('This coloring step lost its work lease and was discarded.');
    }
}

function coloring_generate_plan(array $project): ?array
{
    $style = coloring_style_label($project['style']);
    $audience = coloring_audience_phrase($project);
    $guidance = coloring_page_guidance($project);
    $charLine = $project['character_desc'] !== ''
        ? "\nRecurring character: {$project['character_desc']}" : '';
    /* The feel and the occasion shape the scenes as well as the pages (D-435). */
    if (($project['mood'] ?? '') !== '' && isset(coloring_moods()[$project['mood']])) $charLine .= "\nFeel: " . coloring_moods()[$project['mood']];
    if ($guidance['occasion'] !== '') $charLine .= "\nOccasion: " . $guidance['occasion'];
    /* An artist-inspired style is a feeling, never a name in the book (D-438). */
    if ($project['style'] === 'cozy-corner') $charLine .= "\nNever mention any artist's name in the title, the cover text or any scene.";
    $count = coloring_project_pages($project);

    $hasCaptions = !empty($project['captions']);
    $sceneFields = $hasCaptions
        ? '{"id":1,"main":"The one main subject, a short phrase","objects":"2 to 4 supporting objects, comma separated","setting":"A simple place with few elements","description":"One sentence that puts the three together","name":"The person or character name","role":"Their role or occupation, a few words","caption":"One sentence about this person, interesting and age-appropriate"}'
        : '{"id":1,"main":"The one main subject, a short phrase","objects":"2 to 4 supporting objects, comma separated","setting":"A simple place with few elements","description":"One sentence that puts the three together"}';
    $captionReqs = $hasCaptions
        ? "\n- Every scene must include name, role and caption fields\n- Captions are printed below the drawing, so the drawing itself must contain no text" : '';

    $prompt = <<<PROMPT
Create a plan for a coloring book.

Theme: {$project['idea']}
Style: {$style}
Audience: {$audience}{$charLine}

Return strict JSON:
{"title":"A descriptive coloring book title","cover":{"title_lines":["2 or 3 short lines of the title, each under 16 characters"],"theme":"the main characters and objects for the cover, comma separated","background":"a colour and setting for the cover background"},
 "style_sheet":"One sentence that describes how the recurring characters look (species, build, face, clothes), by shape and features and not by colour, so they are drawn the same on every page. Empty string if there are none.",
 "scenes":[
  {$sceneFields},
  ... ({$count} scenes total)
]}

Requirements:
- Exactly {$count} unique scenes
- Each scene must be visually distinct
- Scenes should progress or vary naturally through the theme
- Each scene is one clear main subject with a few objects, never a crowd
- No text or words should appear in any scene
- If no recurring character is given above, do not invent one. Describe what the viewer sees, not what a character does
- Never use real people's names in main, objects, setting or description. Describe the person by appearance, clothing and era instead (e.g. "a man in a powdered wig and colonial coat" not "George Washington"). The image generator cannot draw named real people and will omit them{$captionReqs}
PROMPT;

    /* A stable action key per project (D-558). Without one the usage row is keyed on the request payload and
       `INSERT OR IGNORE` drops a repeat of the same request, so retries would cost money and leave no
       trace in `ai_usage`. */
    $text = generate_text($prompt, 2048, [
        'source' => 'coloring_plan',
        'user_id' => 0,
        'coloring_project_id' => (int) $project['id'],
        'operation' => 'coloring_plan',
        'action_key' => 'coloring-plan:' . (int) $project['id'],
    ], true);

    $plan = json_decode($text, true);
    if (!is_array($plan) || !isset($plan['title']) || !is_array($plan['scenes'] ?? null)) {
        throw new RuntimeException('The plan response was not valid JSON.');
    }
    if (count($plan['scenes']) !== $count) {
        throw new RuntimeException("Expected {$count} scenes, received " . count($plan['scenes']) . '.');
    }
    if (count(array_unique(array_map(fn($s) => (string) ($s['id'] ?? ''), $plan['scenes']))) !== $count) {
        throw new RuntimeException('The plan response had duplicate or missing scene ids.');
    }

    /* The screen edits `description`, so every scene has one even when the model returned only the parts. */
    foreach ($plan['scenes'] as &$scene) {
        if (trim((string) ($scene['description'] ?? '')) === '') {
            $scene['description'] = trim(($scene['main'] ?? '') . ' ' . ($scene['setting'] ?? ''));
        }
    }
    unset($scene);

    $params = [json_encode($plan), $plan['title'], $project['id']];
    /* D-656 review 2: the route (no lease) writes a plan only while the book is a draft or planned, in the same statement, so a
       second plan post (Back, two tabs) cannot rewrite a book that started drawing meanwhile. The worker's plan stage holds the
       lease and keeps its queued or generating status. */
    $fence = (string) ($project['lease_token'] ?? '') === '' ? " AND status IN ('draft', 'planned')" : '';
    $update = db()->prepare(
        'UPDATE coloring_projects SET plan_json = ?, title = ?, updated_at = datetime(\'now\'),
         status = CASE WHEN status IN (\'queued\', \'generating\') THEN status ELSE \'planned\' END
         WHERE id = ?' . $fence . coloring_lease_clause($project, $params)
    );
    $update->execute($params);
    coloring_lease_assert($project, $update);

    /* Null: the write was refused (drawing had started), and the book is as it was. */
    return $update->rowCount() === 1 ? $plan : null;
}

/** D-656 review 2: the heading of a book whose plan has not given it a title yet. Never "Untitled". */
const COLORING_TITLE_FALLBACK = 'Your coloring book';

function coloring_display_title(array $project): string
{
    $title = trim((string) ($project['title'] ?? ''));

    return $title !== '' ? $title : COLORING_TITLE_FALLBACK;
}

function coloring_update_scene_description(int $projectId, int $sceneId, string $description): void
{
    $project = coloring_project($projectId);
    if ($project === null) return;
    $plan = json_decode((string) $project['plan_json'], true) ?: [];
    /* No plan yet, no scene to edit (review2 F1). Iterating a missing key is a TypeError. */
    if (!is_array($plan['scenes'] ?? null)) return;
    foreach ($plan['scenes'] as &$scene) {
        if ((int) $scene['id'] === $sceneId) {
            $scene['description'] = $description;
            unset($scene['main'], $scene['objects'], $scene['setting']);
            break;
        }
    }
    unset($scene);
    db()->prepare('UPDATE coloring_projects SET plan_json = ?, updated_at = datetime(\'now\') WHERE id = ?')
        ->execute([json_encode($plan), $projectId]);
}

function coloring_save_plan(array $project, array $plan): void
{
    /* What the customer edits wins over what the plan call wrote: an edited scene loses its subject, objects and setting,
       and an edited title loses the cover's title lines, so the edit is what gets drawn. */
    $old = json_decode((string) $project['plan_json'], true) ?: [];
    $oldScenes = [];
    foreach ($old['scenes'] ?? [] as $scene) $oldScenes[(int) $scene['id']] = $scene;
    foreach ($plan['scenes'] as &$scene) {
        $before = $oldScenes[(int) $scene['id']]['description'] ?? $scene['description'];
        if ($before !== $scene['description']) unset($scene['main'], $scene['objects'], $scene['setting']);
    }
    unset($scene);
    if (isset($old['title']) && $old['title'] !== $plan['title']) unset($plan['cover']['title_lines']);
    $pdo = db();
    $pdo->prepare(
        'UPDATE coloring_projects SET plan_json = ?, title = ?, updated_at = datetime(\'now\') WHERE id = ?'
    )->execute([json_encode($plan), $plan['title'], $project['id']]);
}

function coloring_generate_one_page(array $project, int $sceneId): string
{
    $plan = json_decode((string) $project['plan_json'], true);
    /* Report this as a plan-stage problem, not a page-stage one (review2 F4). A page draw cannot
       recover from a missing plan; the worker's failure log should name the real stage. */
    if (!is_array($plan) || !is_array($plan['scenes'] ?? null)) {
        throw new RuntimeException('The plan is missing or malformed; rerun the plan stage before drawing pages.');
    }
    $scene = null;
    foreach ($plan['scenes'] as $s) {
        if ((int) $s['id'] === $sceneId) { $scene = $s; break; }
    }
    if ($scene === null) throw new InvalidArgumentException('Scene not found.');

    $style = coloring_style_label($project['style']);
    $audience = coloring_audience_phrase($project);
    $guidance = coloring_page_guidance($project);

    /* Draw count per scene lives on the page entry, so a paid redraw gets its own usage row (D-559). */
    $pages = json_decode($project['pages_json'] ?: '[]', true) ?: [];
    $try = 1;
    foreach ($pages as $p) {
        if ((int) $p['scene_id'] === $sceneId) $try = (int) ($p['tries'] ?? 1) + 1;
    }

    $started = hrtime(true);
    $path = generate_coloring_page($style, $audience, $scene['description'],
        $project['character_desc'], [
            'source' => 'coloring_page',
            'user_id' => 0,
            'coloring_project_id' => (int) $project['id'],
            'operation' => 'coloring_page',
            'action_key' => 'coloring-page:' . (int) $project['id'] . ':' . $sceneId . ':' . $try,
        ], ['main' => $scene['main'] ?? '', 'objects' => $scene['objects'] ?? '', 'setting' => $scene['setting'] ?? '',
            'style_sheet' => $plan['style_sheet'] ?? '', 'who' => $guidance['who'], 'kids' => $guidance['kids'],
            'level' => $guidance['level'], 'occasion' => $guidance['occasion'],
            'captions' => !empty($project['captions']),
            'trim' => coloring_trim_key((string) ($project['trim'] ?? 'portrait'))]);
    $timing = ['ms' => intdiv(hrtime(true) - $started, 1000000), 'done_at' => gmdate('Y-m-d H:i:s')];

    /* Merge into the pages as saved now, not as they were when this draw began: in the parallel pool (D-645) other
       pages of the book were saved while this one was being drawn. Nothing waits between this read and the write. */
    $current = db()->prepare('SELECT pages_json FROM coloring_projects WHERE id = ?');
    $current->execute([(int) $project['id']]);
    $pages = json_decode((string) ($current->fetchColumn() ?: '[]'), true) ?: [];
    $current->closeCursor();
    $found = false;
    foreach ($pages as &$p) {
        if ((int) $p['scene_id'] === $sceneId) {
            $p = ['path' => $path, 'status' => 'done', 'tries' => $try] + $timing + $p;
            $found = true;
            break;
        }
    }
    unset($p);
    if (!$found) {
        $pages[] = ['scene_id' => $sceneId, 'path' => $path, 'status' => 'done', 'tries' => $try] + $timing;
        /* Pages finish out of order in the pool; the PDF and the MCP export print pages_json in its saved order, so keep
           it in plan order. A serial run appends in plan order already, so this changes nothing there. */
        $order = array_flip(array_map(fn($s) => (int) $s['id'], $plan['scenes']));
        usort($pages, fn($a, $b) => ($order[(int) $a['scene_id']] ?? PHP_INT_MAX) <=> ($order[(int) $b['scene_id']] ?? PHP_INT_MAX));
    }

    /* The status belongs to the worker: finishing means a cover and a captured payment, not 24 pages. */
    $params = [json_encode($pages), $project['id']];
    $update = db()->prepare(
        'UPDATE coloring_projects SET pages_json = ?, updated_at = datetime(\'now\') WHERE id = ?'
        . coloring_lease_clause($project, $params)
    );
    $update->execute($params);
    coloring_lease_assert($project, $update);

    return $path;
}

/** Amazon KDP's minimum for a paperback is 24 pages, which a book of this layout reaches at 12 drawings (2 x 12). */
const COLORING_KDP_MIN_PAGES = 24;

/**
 * Interior order: the optional cover-derived line-art page and its blank back; consecutive overview sheets; an optional blank
 * parity pad after an odd number of overview sheets; the optional kids' "This book belongs to" page and its blank back; then each
 * full-size drawing on a right-hand page followed by its blank back. The full-colour cover is a separate file. Drawings sit on odd
 * pages, which is why the gutter margin is on their left. Overview sheets are 4 × 4 for the first 16 drawings, then 4 × 5 for each
 * next group of 20. These pages count toward the spine and KDP's 24-page minimum; blank backs remain the usual coloring-book layout.
 */
function coloring_print_page_count(int $drawings, bool $belongsPage = false, bool $coverOutline = false): int
{
    $overview = coloring_overview_page_count($drawings);
    // The outline sits on the opening recto with no blank reverse of its own (D-540); the overview
    // sheets follow immediately. One blank page after the outline-plus-overview stack keeps the
    // belongs-to spread or the first full-size drawing on a recto.
    $pre = ($coverOutline ? 1 : 0) + $overview;
    return 2 * max(0, $drawings) + ($belongsPage ? 2 : 0) + $pre + ($pre % 2);
}

function coloring_overview_page_count(int $drawings): int
{
    return $drawings < 1 ? 0 : 1 + (int) ceil(max(0, $drawings - 16) / 20);
}

/** First sheet is 4 x 4; continuation sheets are 4 x 5, in the saved drawing order. */
function coloring_overview_groups(array $pages): array
{
    if ($pages === []) return [];
    return array_merge([array_slice($pages, 0, 16)], array_chunk(array_slice($pages, 16), 20));
}

/** Print overview sheets reuse the original artwork, at a smaller size, without provider calls. */
function coloring_overview_sheets(array $project, array $pages): array
{
    $trim = coloring_project_trim($project);
    $dataDir = $GLOBALS['mcp_coloring_data_directory'] ?? application_data_directory();
    $sheets = [];
    foreach (coloring_overview_groups($pages) as $index => $group) {
        $rows = $index === 0 ? 4 : 5;
        $top = $index === 0 ? 1.0 : COLORING_MARGIN_OUTSIDE_IN;
        $width = $trim['area_w'];
        $height = $trim['page_h'] - $top - COLORING_MARGIN_OUTSIDE_IN;
        $cellW = $width / 4;
        $cellH = $height / $rows;
        $gap = 0.15;
        $sheet = $index === 0 ? '<div class="centre overview-heading">'
            . count($pages) . ' IMAGES TO COLOR</div>' : '';
        /* Collect only the drawings that actually exist, so a missing file does not consume a slot
           and leave a visible gap or an off-centre partial row (review2 F6). */
        $present = [];
        foreach ($group as $page) {
            $file = $dataDir . '/' . ($page['path'] ?? '');
            $info = is_file($file) ? @getimagesize($file) : false;
            if (!is_array($info)) { error_log('coloring overview: missing drawing ' . ($page['path'] ?? '')); continue; }
            $present[] = ['file' => $file, 'info' => $info];
        }
        foreach ($present as $i => $entry) {
            $file = $entry['file']; $info = $entry['info'];
            $scale = min(($cellW - $gap) / $info[0], ($cellH - $gap) / $info[1]);
            $w = $info[0] * $scale; $h = $info[1] * $scale;
            $row = intdiv($i, 4);
            $inRow = min(4, count($present) - $row * 4);
            $x = COLORING_MARGIN_GUTTER_IN + (4 - $inRow) * $cellW / 2 + ($i % 4) * $cellW + ($cellW - $w) / 2;
            $y = $top + $row * $cellH + ($cellH - $h) / 2;
            $sheet .= '<img style="position:absolute;left:' . $x . 'in;top:' . $y . 'in;width:' . $w . 'in;height:' . $h
                . 'in" src="' . coloring_file_src($file) . '">';
        }
        $sheets[] = $sheet;
    }
    return $sheets;
}

/** A local image reference for dompdf (the chroot in coloring_export_pdf allows data/coloring), instead of base64 in the HTML. */
function coloring_file_src(string $file): string
{
    if (!is_file($file)) throw new RuntimeException('Coloring image missing: ' . basename($file));
    return 'file://' . (realpath($file) ?: $file);
}

function coloring_has_cover_outline(array $project): bool
{
    return trim((string) ($project['cover_outline_path'] ?? '')) !== '';
}

/** The "This book belongs to" charm is for kids' books only (D-422). */
function coloring_has_belongs_page(array $project): bool
{
    return (string) ($project['audience'] ?? '') === 'kids';
}

/**
 * The print HTML: one `.sheet` per printed page, 8.5 x 11 inches, no bleed. A drawing is placed in the 7.8 x 10.4 inch area
 * (0.4 inch gutter on the left, 0.3 inch on the other three sides, Amazon's minimums plus 0.05), pixel for pixel at 300 DPI.
 *
 * @param array<int,array<string,mixed>>|null $pages Defaults to the project's pages.
 */
function coloring_pdf_html(array $project, ?array $pages = null, bool $includeCover = false): string
{
    $pages ??= json_decode($project['pages_json'] ?: '[]', true) ?: [];
    $trim = coloring_project_trim($project);
    $pageW = $trim['page_w']; $pageH = $trim['page_h'];
    $sheetH = $pageH - 0.05;
    $areaWidth = $trim['area_px_w'] / 300;
    $areaHeight = $trim['area_px_h'] / 300;
    $hasCaptions = !empty($project['captions']);
    $captionArtHeight = round($areaHeight * 2 / 3, 4);
    $captionTop = round(COLORING_MARGIN_OUTSIDE_IN + $captionArtHeight + 0.15, 4);
    $belongsTop = max(0.5, round($pageH / 2 - 1.3, 4));
    $bricolageHeading = 'file://' . dirname(__DIR__) . '/public/fonts/BricolageGrotesque-650.ttf';
    $css = '@font-face { font-family:"Bricolage Grotesque"; font-style:normal; font-weight:650;
            src:url("' . $bricolageHeading . '") format("truetype"); }
        @page { size: ' . $pageW . 'in ' . $pageH . 'in; margin: 0; }
        body { font-family: Helvetica, sans-serif; margin: 0; color: #222; }
        .sheet { position: relative; width: ' . $pageW . 'in; height: ' . $sheetH . 'in; overflow: hidden; page-break-after: always; }
        .sheet-cover, .sheet-cover-outline { height: ' . $pageH . 'in; }
        .sheet-last { page-break-after: auto; }
        .centre { position: absolute; left: 0; width: ' . $pageW . 'in; text-align: center; }
        .overview-heading { top:0.35in; font-family:"Bricolage Grotesque",sans-serif; font-size:25pt;
            font-style:normal; font-weight:650; line-height:1.02; letter-spacing:-.045em; color:#000; text-transform:none; }
        .belongs { top: ' . $belongsTop . 'in; font-size: 18pt; } .rule { margin: 0.5in auto 0; width: 4.5in; border-bottom: 1.5pt solid #222; height: 0; }
        .cover-art, .cover-outline-art { position: absolute; }
        .art { position: absolute; left: ' . COLORING_MARGIN_GUTTER_IN . 'in; top: ' . COLORING_MARGIN_OUTSIDE_IN . 'in;
               width: ' . $areaWidth . 'in; height: ' . ($hasCaptions ? $captionArtHeight : $areaHeight) . 'in; }'
        . ($hasCaptions ? '
        .caption { position: absolute; left: ' . COLORING_MARGIN_GUTTER_IN . 'in; top: ' . $captionTop . 'in;
               width: ' . $areaWidth . 'in; text-align: center; }
        .caption-name { font-size: 14pt; font-weight: bold; margin: 0; }
        .caption-role { font-size: 11pt; font-style: italic; color: #555; margin: 2pt 0 6pt; }
        .caption-text { font-size: 10pt; line-height: 1.4; margin: 0; }' : '');
    /* A kids' book opens with the charm page (right-hand, page 1) and its blank back; drawings follow on right-hand pages. */
    $sheets = [];
    $fullBleedIndexes = [];
    if ($includeCover) {
        $dataDir = isset($GLOBALS['mcp_coloring_data_directory']) && is_string($GLOBALS['mcp_coloring_data_directory'])
            ? $GLOBALS['mcp_coloring_data_directory'] : application_data_directory();
        $coverPath = $dataDir . '/' . (string) ($project['cover_path'] ?? '');
        $coverInfo = is_file($coverPath) ? @getimagesize($coverPath) : false;
        $coverBytes = is_array($coverInfo) ? @file_get_contents($coverPath) : false;
        if (!is_string($coverBytes) || !is_array($coverInfo) || !in_array($coverInfo['mime'] ?? '', ['image/jpeg', 'image/png'], true)) {
            throw new RuntimeException('This coloring book has no usable cover.');
        }
        if ((int) $coverInfo[0] < 1 || (int) $coverInfo[1] < 1) throw new RuntimeException('This coloring book cover has invalid dimensions.');
        /* Crop proportionally to the trim and fill the complete first PDF page. This may resample a
           low-resolution source for display; it does not restore detail or change source-quality reporting. */
        require_once __DIR__ . '/coloring_cover.php';
        $fittedCover = coloring_cover_wrap_fit_front($coverBytes, $pageW / $pageH);
        $coverStyle = 'left:0;top:0;width:' . $pageW . 'in;height:' . $pageH . 'in';
        $fullBleedIndexes[] = count($sheets);
        $sheets[] = '<img class="cover-art" style="' . $coverStyle . '" src="data:image/jpeg;base64,'
            . base64_encode($fittedCover['bytes']) . '">';
    }
    /* This is the first interior page, before the optional belongs-to page. Never add captions. */
    if (coloring_has_cover_outline($project)) {
        $dataDir = $GLOBALS['mcp_coloring_data_directory'] ?? application_data_directory();
        $outlineFile = $dataDir . '/' . $project['cover_outline_path'];
        $outlineBytes = is_file($outlineFile) ? @file_get_contents($outlineFile) : false;
        if (!is_string($outlineBytes) || $outlineBytes === '') throw new RuntimeException('The cover coloring page is unavailable.');
        /* Match the color-cover placement: crop to the trim ratio and fill the page. The saved
           line art already contains its white background; only the ordinary drawing margin is removed. */
        require_once __DIR__ . '/coloring_cover.php';
        $fittedOutline = coloring_cover_wrap_fit_front($outlineBytes, $pageW / $pageH);
        $outlineStyle = 'left:0;top:0;width:' . $pageW . 'in;height:' . $pageH . 'in';
        $fullBleedIndexes[] = count($sheets);
        $sheets[] = '<img class="cover-outline-art" style="' . $outlineStyle . '" src="data:image/jpeg;base64,'
            . base64_encode($fittedOutline['bytes']) . '">';
        /* The outline has no blank reverse (D-540): overview follows on the next physical page. */
    }
    $overviewSheets = coloring_overview_sheets($project, $pages);
    $sheets = array_merge($sheets, $overviewSheets);
    /* Pad to a parity that keeps the belongs-to spread or first drawing on a recto. The cover sheet
       is not part of the interior page count that coloring_print_page_count uses for the spine, so
       exclude it here too (review2 F10) or the combined PDF carries one more interior page than the
       spine width was computed for. */
    $preCount = count($sheets) - ($includeCover ? 1 : 0);
    if ($preCount % 2 === 1) $sheets[] = '';
    /* The kids' belongs-to spread follows the opening art and overviews, keeping later drawings on rectos. */
    $sheets = array_merge($sheets, coloring_has_belongs_page($project)
        ? ['<div class="centre belongs">This book belongs to<div class="rule"></div></div>', ''] : []);
    $dataDir = (isset($GLOBALS['mcp_coloring_data_directory']) && is_string($GLOBALS['mcp_coloring_data_directory'])
        ? $GLOBALS['mcp_coloring_data_directory'] : application_data_directory()) . '/';
    $sceneCaptions = [];
    if ($hasCaptions) {
        $plan = json_decode($project['plan_json'] ?: '[]', true) ?: [];
        foreach (($plan['scenes'] ?? []) as $s) {
            $sceneCaptions[(int) $s['id']] = $s;
        }
    }
    foreach ($pages as $page) {
        $filePath = $dataDir . ($page['path'] ?? '');
        if (!is_file($filePath)) continue;
        $sheet = '<img class="art" src="' . coloring_file_src($filePath) . '">';
        if ($hasCaptions) {
            $sc = $sceneCaptions[(int) ($page['scene_id'] ?? 0)] ?? [];
            $name = htmlspecialchars(trim((string) ($sc['name'] ?? '')), ENT_QUOTES, 'UTF-8');
            $role = htmlspecialchars(trim((string) ($sc['role'] ?? '')), ENT_QUOTES, 'UTF-8');
            $caption = htmlspecialchars(trim((string) ($sc['caption'] ?? '')), ENT_QUOTES, 'UTF-8');
            if ($name !== '') {
                $sheet .= '<div class="caption">'
                    . '<p class="caption-name">' . $name . '</p>'
                    . ($role !== '' ? '<p class="caption-role">' . $role . '</p>' : '')
                    . ($caption !== '' ? '<p class="caption-text">' . $caption . '</p>' : '')
                    . '</div>';
            }
        }
        $sheets[] = $sheet;
        $sheets[] = '';   // the back of the drawing is blank
    }
    $html = '<style>' . $css . '</style>';
    foreach ($sheets as $index => $sheet) {
        $fullBleedClass = $includeCover && $index === 0 ? ' sheet-cover'
            : (in_array($index, $fullBleedIndexes, true) ? ' sheet-cover-outline' : '');
        $html .= '<div class="sheet' . $fullBleedClass
            . ($index === count($sheets) - 1 ? ' sheet-last' : '') . '">' . $sheet . '</div><!-- sheet -->' . "\n";
    }

    return $html;
}

function coloring_export_pdf(array $project, bool $includeCover = false): string
{
    require_once dirname(__DIR__) . '/vendor/autoload.php';
    $options = new \Dompdf\Options();
    $options->set('isRemoteEnabled', false);
    /* Local PDF assets are limited to the packaged UI font and Dompdf's bundled fonts. */
    $root = dirname(__DIR__);
    $dataDir = isset($GLOBALS['mcp_coloring_data_directory']) && is_string($GLOBALS['mcp_coloring_data_directory'])
        ? $GLOBALS['mcp_coloring_data_directory'] : application_data_directory();
    $scratch = sys_get_temp_dir() . '/coloring-pdf-' . getmypid() . '-' . bin2hex(random_bytes(6));
    $copies = [];
    /* dompdf reads a palette PNG (how every drawing is saved, 1 bit) as transparent and splits its alpha pixel by pixel
       in PHP: about 2 s a page, 26 to 40 s a book. A white-backed truecolor copy takes dompdf's GD path instead, about
       0.3 s a page, with the same pixels. The copies live for this render only. */
    $html = preg_replace_callback('#src="file://([^"]+\.png)"#', static function (array $m) use (&$copies, $scratch): string {
        $copies[$m[1]] ??= coloring_pdf_plain_png($m[1], $scratch);
        return 'src="file://' . $copies[$m[1]] . '"';
    }, coloring_pdf_html($project, null, $includeCover));
    try {
        $options->setChroot([$root . '/public/fonts', $root . '/vendor/dompdf/dompdf', $dataDir . '/coloring', $scratch]);
        $dompdf = new \Dompdf\Dompdf($options);
        $dompdf->loadHtml($html);
        $trim = coloring_project_trim($project);
        /* Pass explicit point dimensions to dompdf. 'letter' is portrait only; the custom array
           works for the square trim too. 72 points per inch. */
        $pts = [0.0, 0.0, $trim['page_w'] * 72, $trim['page_h'] * 72];
        $dompdf->setPaper($pts, 'portrait');
        $dompdf->render();
        return $dompdf->output();
    } finally {
        foreach (glob($scratch . '/*.png') ?: [] as $copy) @unlink($copy);
        @rmdir($scratch);
    }
}

/** The file dompdf should read for a PNG: a white-backed truecolor copy for the palette PNGs dompdf treats as alpha, else the file itself. */
function coloring_pdf_plain_png(string $file, string $scratch): string
{
    $header = @file_get_contents($file, false, null, 24, 2);
    if (!is_string($header) || strlen($header) !== 2) return $file;
    [, $bitDepth, $colorType] = unpack('C2', $header) + [0, 0, 0];
    /* dompdf's own test (Cpdf::addPngFromFile): colour types 4 and 6, and palette PNGs that are not 4 bit, take the slow path. */
    if (!in_array($colorType, [4, 6], true) && !($colorType === 3 && $bitDepth !== 4)) return $file;
    $source = function_exists('imagecreatefrompng') ? @imagecreatefrompng($file) : false;
    if ($source === false || (!is_dir($scratch) && !@mkdir($scratch, 0700, true))) return $file;
    $copy = $scratch . '/' . md5($file) . '.png';
    /* Fast road: the same palette written at 4 bits (GD picks the depth from the palette size, 5 to 16 entries), which
       dompdf composites on white itself. About 0.03 s against 0.25 s for a truecolor copy. Not for a palette with a
       transparent index, which dompdf would fill with that colour instead of white. */
    if (!imageistruecolor($source) && imagecolortransparent($source) < 0) {
        while (imagecolorstotal($source) < 5) imagecolorallocate($source, 255, 255, 255);
        $depth = imagecolorstotal($source) <= 16 && imagepng($source, $copy, 1)
            ? unpack('C', (string) @file_get_contents($copy, false, null, 24, 1))[1] ?? 0 : 0;
        if ($depth === 4) return $copy;
    }
    $plain = imagecreatetruecolor(imagesx($source), imagesy($source));
    imagefill($plain, 0, 0, imagecolorallocate($plain, 255, 255, 255));
    imagealphablending($plain, true);
    imagecopy($plain, $source, 0, 0, 0, 0, imagesx($source), imagesy($source));
    /* Level 1: this file is read once, by dompdf, which re-encodes it anyway. */
    return imagepng($plain, $copy, 1) ? $copy : $file;
}

function coloring_delete_project(int $id): bool
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $project = coloring_project($id);
        if ($project === null) {
            $pdo->rollBack();
            return false;
        }
        /* A queued or generating book has a hold and may have a provider call in flight, so it cannot be deleted. The
           there is no free hold in the standalone engine. */
        if (in_array((string) $project['status'], ['queued', 'generating'], true)) {
            throw new RuntimeException('This book is being drawn. You can delete it once it is ready or has stopped.');
        }
        $pdo->prepare('DELETE FROM coloring_projects WHERE id = ?')->execute([$id]);
        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $exception;
    }
    $pages = json_decode($project['pages_json'] ?: '[]', true) ?: [];
    $dataDir = application_data_directory() . '/';
    foreach (array_merge($pages, [['path' => $project['cover_outline_path'] ?? ''], ['path' => $project['cover_path'] ?? '']]) as $page) {
        $filePath = $dataDir . ($page['path'] ?? '');
        if (is_file($filePath)) @unlink($filePath);
        /* The resized copies the image routes made (`<size>_<name>`), so nothing of a deleted book stays on disk. */
        $name = basename((string) ($page['path'] ?? ''));
        if (preg_match('/^(cover-)?[a-f0-9]+\.(png|jpg)$/', $name) === 1) {
            foreach (glob($dataDir . 'coloring/thumbs/*_' . $name) ?: [] as $thumb) @unlink($thumb);
        }
    }
    require_once __DIR__ . '/coloring_pdf_cache.php';
    coloring_pdf_cache_forget($id);
    return true;
}

function coloring_projects_table_ready(): bool
{
    try {
        db()->query('SELECT 1 FROM coloring_projects LIMIT 0');
        return true;
    } catch (\Throwable) {
        return false;
    }
}
