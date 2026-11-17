<?php

declare(strict_types=1);

/*
 * How a coloring book is categorised (D-435), from what people search for on Amazon:
 *
 *   top level     audience     kids, teens, adults
 *   second level  age band     kids only: ages 1-3, 4-8, 8-12
 *                 feel         adults only, optional: relaxation, stress relieving, funny
 *   beside them   art style    chibi, anime, bold and easy, florals, cute animals (coloring_styles())
 *                 occasion     optional: Halloween, Christmas, another holiday
 *
 * Deliberately not offered: a named franchise or character, or a named artist's style (someone else's work; the model
 * must not be asked to imitate it), and "spiral bound" (a way of printing, which Amazon KDP paperbacks do not offer;
 * it is not a kind of book).
 *
 * This file is data and the wording that follows from it: the labels, the validation, and the lines the plan, page and
 * cover prompts add. The prompts themselves still live in `src/ai.php` and `src/coloring_book.php`.
 */

/** The top level: who the book is for. */
function coloring_audiences(): array
{
    return ['kids' => 'Kids', 'teens' => 'Teens', 'adults' => 'Adults'];
}

/** A short line for each top-level choice, shown on the create screen. */
function coloring_audience_notes(): array
{
    return [
        'kids' => 'Friendly, simple drawings for young colorers',
        'teens' => 'Expressive and detailed, nothing babyish',
        'adults' => 'Calm, intricate or funny pages to unwind with',
    ];
}

/** Second level for kids. The value is the part after "ages ". */
function coloring_age_bands(): array
{
    return ['1-3' => 'Ages 1 to 3', '4-8' => 'Ages 4 to 8', '8-12' => 'Ages 8 to 12'];
}

function coloring_age_band_notes(): array
{
    return [
        '1-3' => 'One big subject, very thick lines',
        '4-8' => 'Bold, friendly scenes',
        '8-12' => 'More detail and fuller scenes',
    ];
}

/** Second level for adults, optional. */
function coloring_moods(): array
{
    return ['relaxation' => 'Relaxation', 'stress-relief' => 'Stress relieving', 'funny' => 'Funny'];
}

/** Optional, for any audience. */
function coloring_occasions(): array
{
    return ['halloween' => 'Halloween', 'christmas' => 'Christmas', 'holiday' => 'Another holiday'];
}

/**
 * Check the category fields of a submitted form and return the three stored values. Whatever does not belong to the
 * chosen audience is dropped, so a stale field from a hidden control never reaches the database.
 *
 * @return array{audience: string, age_band: ?string, mood: ?string, occasion: ?string}
 */
function coloring_validate_categories(array $source): array
{
    $audience = (string) ($source['audience'] ?? '');
    if (!isset(coloring_audiences()[$audience])) {
        throw new InvalidArgumentException('Choose who the book is for.');
    }
    $ageBand = null;
    if ($audience === 'kids') {
        $ageBand = (string) ($source['age_band'] ?? '4-8');
        if (!isset(coloring_age_bands()[$ageBand])) {
            throw new InvalidArgumentException('Choose an age group.');
        }
    }
    $mood = null;
    if ($audience === 'adults') {
        $given = (string) ($source['mood'] ?? '');
        if ($given !== '') {
            if (!isset(coloring_moods()[$given])) throw new InvalidArgumentException('Choose how the book should feel, or leave it open.');
            $mood = $given;
        }
    }
    $occasion = null;
    $givenOccasion = (string) ($source['occasion'] ?? '');
    if ($givenOccasion !== '') {
        if (!isset(coloring_occasions()[$givenOccasion])) throw new InvalidArgumentException('Choose an occasion, or leave it open.');
        $occasion = $givenOccasion;
    }

    return ['audience' => $audience, 'age_band' => $ageBand, 'mood' => $mood, 'occasion' => $occasion];
}

/** "Kids, ages 4–8", "Adults, relaxation, Halloween": the whole category in words, for the project page. */
function coloring_category_label(array $project): string
{
    $audience = (string) ($project['audience'] ?? 'kids');
    $parts = [coloring_audiences()[$audience] ?? ucfirst($audience)];
    $band = (string) ($project['age_band'] ?? '');
    if ($audience === 'kids') $parts[] = coloring_age_bands()[$band !== '' ? $band : '4-8'] ?? 'Ages 4 to 8';
    $mood = (string) ($project['mood'] ?? '');
    if ($mood !== '' && isset(coloring_moods()[$mood])) $parts[] = coloring_moods()[$mood];
    $occasion = (string) ($project['occasion'] ?? '');
    if ($occasion !== '' && isset(coloring_occasions()[$occasion])) $parts[] = coloring_occasions()[$occasion];

    return implode(', ', $parts);
}

/** The audience in one phrase, as the plan prompt reads it. */
function coloring_audience_phrase(array $project): string
{
    $audience = (string) ($project['audience'] ?? 'kids');
    $band = (string) ($project['age_band'] ?? '') ?: '4-8';

    return match ($audience) {
        'teens' => 'Teens',
        'adults' => 'Adults',
        default => $band === '1-3' ? 'Toddlers ages 1-3' : 'Kids ages ' . $band,
    };
}

/**
 * The extra lines the interior page prompt takes for this book, keyed for `coloring_page_prompt()`'s `$detail`.
 *
 * Every audience now gets age-specific drawing guidance (line weight, element count, detail density).
 *
 * @return array{who: string, kids: bool, level: string, occasion: string}
 */
function coloring_page_guidance(array $project): array
{
    $audience = (string) ($project['audience'] ?? 'kids');
    $band = (string) ($project['age_band'] ?? '') ?: '4-8';
    $mood = (string) ($project['mood'] ?? '');
    $occasion = (string) ($project['occasion'] ?? '');

    $who = 'children aged 4 to 8';
    $level = '';
    if ($audience === 'kids') {
        $who = match ($band) { '1-3' => 'toddlers aged 1 to 3', '8-12' => 'children aged 8 to 12', default => 'children aged 4 to 8' };
        $level = match ($band) {
            '1-3' => 'Made for the youngest colorers: one big simple subject, 3 to 5 pt line weight, huge open shapes, all outlines fully closed, simple cartoon face with dot eyes and curved smile, 2 to 3 elements maximum, almost no background, no small details at all.',
            '8-12' => 'For older children: 2 to 3 pt medium-weight outlines, one main subject with a simple scene around it, moderate interior detail like fur texture or brickwork drawn with medium lines not fine ones, background present but not busy, more expressive faces with eyebrows and ear shape, all outlines fully closed, 5 to 10 elements per page.',
            default => 'For young children: 3 to 5 pt thick bold outlines of the same weight everywhere, one large centred subject, all outlines fully closed so colour cannot escape, simple cartoon faces with dot eyes and curved smile, large open regions to colour, minimal background with 2 to 3 simple objects, 3 to 5 elements per page, lots of white space.',
        };
    } elseif ($audience === 'teens') {
        $who = 'teenagers';
        $level = 'For teenagers: 1.5 to 2 pt outlines with some stroke variation, a full scene with foreground and background layers, medium-density patterns in clothing and architecture, realistic proportions on faces and bodies, 8 to 15 elements per page, some open or implied outlines acceptable, smaller colouring regions mixed with larger ones.';
    } else {
        $who = 'adults';
        $level = match ($mood) {
            'relaxation' => 'Calm and flowing: 1 to 1.5 pt fine outlines with deliberate variation, gentle curves, a balanced composition, moderate fine detail, 15 to 20 elements per page, a peaceful mood.',
            'stress-relief' => 'Meditative and detailed: 1 to 1.5 pt fine outlines, repeating patterns and dense intricate linework, tiny regions alongside medium ones, fully detailed background with no empty space, 20 to 30 elements per page.',
            'funny' => 'Humorous: 1.5 to 2 pt clean medium outlines, exaggerated expressions and a visual joke, moderate detail, a playful tone, 10 to 15 elements per page.',
            default => 'For adults: 1 to 1.5 pt fine outlines with deliberate variation for elegance, dense intricate detail across the full page, complex patterns and textures, realistic or highly stylised faces, fully detailed background, 15 to 30 elements per page.',
        };
    }
    $theme = match ($occasion) {
        'halloween' => 'A Halloween theme' . ($audience === 'kids' ? ', friendly and playful, never scary or gory.' : ', spooky-cute and playful, never gory.'),
        'christmas' => 'A Christmas theme: festive and warm.',
        'holiday' => 'A seasonal holiday theme: festive and warm.',
        default => '',
    };

    return ['who' => $who, 'kids' => $audience === 'kids', 'level' => $level, 'occasion' => $theme];
}

/**
 * What the front cover says under the title and on its sticker (D-417): the subtitle is spell-checked, the sticker is not.
 *
 * @return array{subtitle: string, badge: string}
 */
function coloring_cover_words(array $project): array
{
    $audience = (string) ($project['audience'] ?? 'kids');
    $band = (string) ($project['age_band'] ?? '') ?: '4-8';
    $mood = (string) ($project['mood'] ?? '');
    $occasion = (string) ($project['occasion'] ?? '');
    $style = (string) ($project['style'] ?? '');
    $pages = coloring_project_pages($project);

    if ($audience === 'teens') {
        $who = 'Teens';
        $badge = 'FOR TEENS';
    } elseif ($audience === 'adults') {
        $who = 'Adults';
        /* The badge varies by style first, mood second, page count last. The count is always real
           (D-492 / D-493): never say "50 DESIGNS" on a 16-page book. */
        $badge = match ($style) {
            'mandala' => $pages . ' MANDALAS',
            'florals' => $pages . ' BOTANICALS',
            'cozy-scenes' => $pages . ' COZY SCENES',
            'cityscapes-architecture' => $pages . ' CITIES',
            'fantasy-whimsical' => $pages . ' FANTASY SCENES',
            'food-and-drink' => $pages . ' DESIGNS',
            default => match ($mood) {
                'relaxation' => 'RELAXING',
                'stress-relief' => 'STRESS RELIEF',
                'funny' => 'FUNNY',
                default => $pages . ' DESIGNS',
            },
        };
    } else {
        $who = $band === '1-3' ? 'Toddlers' : 'Kids';
        /* Kids badge: for vehicles use a count badge; otherwise age band. */
        $badge = $style === 'vehicles-machines' ? $pages . ' BIG VEHICLES' : 'AGES ' . $band;
    }
    $prefix = match ($occasion) { 'halloween' => 'Halloween ', 'christmas' => 'Christmas ', 'holiday' => 'Holiday ', default => '' };

    return ['subtitle' => $prefix . 'Coloring Book for ' . $who, 'badge' => $badge];
}

/*
 * How many pages, and what they cost (D-437). A book is 16, 24 or 30 coloring pages. The price in credits follows the measured cost, not a guess:
 * each page costs $0.0152 to draw at low quality plus about 4% for the page that is redrawn when it runs off the edge, and each book adds a fixed
 * $0.16 (the plan, the cover with allowance for a redraw, the spelling checks). At the lowest credit value ($0.048, the $99 plan) a price of
 * 8.6 credits plus 1.1 a page keeps the cost to about a third of the income at every size, as the 35 credits owner-approved for 24 pages did.
 *
 *   16 pages: cost about $0.41, 26 credits     24 pages: about $0.54, 35 credits     30 pages: about $0.63, 42 credits
 */
const COLORING_PRICE_FIXED_CREDITS = 8.6;
const COLORING_PRICE_PER_PAGE_CREDITS = 1.1;

/** The sizes a person may choose: pages => credits. */
function coloring_page_options(): array
{
    $options = [];
    foreach ([16, 24, 30] as $pages) $options[$pages] = coloring_credits_for_pages($pages);

    return $options;
}

function coloring_credits_for_pages(int $pages): int
{
    return (int) round(COLORING_PRICE_FIXED_CREDITS + COLORING_PRICE_PER_PAGE_CREDITS * $pages);
}

/** How many books of this size a plan's monthly credits cover. Worked out from the price, never typed. */
function coloring_plan_book_count(int $planCredits, int $pages = 16): int
{
    return intdiv($planCredits, max(1, coloring_credits_for_pages($pages)));
}

/** One line under each size on the create screen: what it prints as. */
function coloring_page_option_notes(): array
{
    return [16 => 'A short, quick book', 24 => 'The standard size', 30 => 'A fuller book'];
}

/** The pages in this book. A project made before the choice existed has none stored, and is the standard size. */
function coloring_project_pages(array $project): int
{
    $stored = (int) ($project['page_count'] ?? 0);

    return isset(coloring_page_options()[$stored]) ? $stored : COLORING_PAGE_COUNT;
}

/** What making this book costs, in credits. */
function coloring_book_price(array $project): int
{
    return coloring_credits_for_pages(coloring_project_pages($project));
}

/**
 * The chosen size from a submitted form: `$default` when none was sent, an error for anything that is not on offer. The browser
 * create route passes 16 for a non subscriber (D-656 review 2): the quiz posts no size, and the free book is exactly 16 pages.
 */
function coloring_validate_pages(array $source, int $default = COLORING_PAGE_COUNT): int
{
    $given = (string) ($source['page_count'] ?? '');
    if ($given === '') return $default;
    if (!isset(coloring_page_options()[(int) $given]) || (string) (int) $given !== $given) {
        throw new InvalidArgumentException('Choose 16, 24 or 30 pages.');
    }

    return (int) $given;
}

/*
 * Ideas that type themselves into the idea box (D-439), a pool for each audience, in American wording. Each is a whole sentence of 20 to 500
 * characters, so a visitor who keeps one gets a valid idea. None names a franchise, a character or an artist (D-435). The adult pool covers
 * the feels people search for (relaxation, stress relieving, funny), chibi and the holidays; the kids pool covers the early ages and
 * the older ones.
 *
 * @return array<string, list<string>>
 */
function coloring_idea_examples(): array
{
    return [
        'kids' => [
            'A big friendly elephant and a tiny mouse sharing a picnic by the river',
            'Little dinosaurs learning to ride bicycles around a sunny park',
            'Farm animals putting on a talent show in the barn',
            'A brave little fire truck and its animal crew rescuing a kitten',
            'Sleepy bears baking a giant birthday cake in the forest',
            'Ocean friends having a party: a happy whale, a crab and a dancing octopus',
            'A smiling rocket and its alien friends exploring a candy planet',
            'Puppies and kittens opening a busy little pet shop on Main Street',
            'Bugs on a rainy day: a ladybug, a snail and a frog sharing a big umbrella',
            'A day at the zoo where all the animals swap places',
        ],
        'teens' => [
            'Streetwear cats exploring a neon city at night',
            'A skateboard crew of foxes, ravens and raccoons in a graffiti-covered alley',
            'Mythical creatures at a music festival, from dragons to unicorns',
            'Cozy cafe scenes with headphones, plants and a sleepy cat',
            'A road trip across the desert in a vintage van, with stickers and snacks',
            'Space station crew life: gadgets, plants and one very curious robot',
            'Cool animals in sneakers and hoodies, each with a different hobby',
            'Underwater ruins with glowing jellyfish, treasure and a friendly sea dragon',
        ],
        'adults' => [
            'Cozy cottages and sleepy cats in a snowy village, for a calm evening',
            'A quiet garden in full bloom with butterflies, birdhouses and winding paths',
            'Funny office cats pretending to work, with tiny coffee mugs and big opinions',
            'Intricate mountain lakes at dawn with pine trees and drifting mist',
            'Chibi forest animals having a tea party under the mushrooms',
            'Stress-relieving patterns of leaves, feathers and flowing waves',
            'Spooky-cute Halloween pumpkins, black cats and friendly ghosts',
            'A Christmas market with lanterns, wreaths and warm winter windows',
            'Seaside cottages, sailboats and seagulls on a slow summer afternoon',
            'Funny dogs in costumes at a very serious talent show',
            'A cozy bookshop on a rainy day, full of shelves, plants and reading cats',
            'Peaceful Japanese gardens with koi ponds, cherry blossoms and lanterns',
        ],
    ];
}
