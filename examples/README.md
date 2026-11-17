# Examples

Run everything from the repo root. You need PHP 8.2+ with GD and SQLite, and `composer install` done.

| Example | Costs money? | Needs a key? |
|---|---|---|
| `examples/offline-demo.php` | No, free | No |
| `bin/create --spec ...` (queue a book) | No | No (the key is only needed at draw time) |
| `examples/generate-real-book.php` | **Yes**, about 0.40 to 0.65 USD per book | Yes, `OPENAI_API_KEY` |

## 1. Offline demo (free)

```bash
php examples/offline-demo.php
```

Runs the whole pipeline (plan, parallel pages, cover, cover outline, finish, interior PDF) with the same fake providers as `tools/smoke-selfcheck.php`. It uses no key, makes no network calls and spends nothing. The art is placeholder shapes (a black ring on white), not real coloring pages. It exists to prove the pipeline runs.

Expected output, with your own absolute path:

```
PDF: /path/to/coloring-engine/examples/output/offline-demo.pdf
Size: 51813 bytes
```

The PDF lands in `examples/output/`, which is gitignored. A temporary database and data folder are used and removed on exit.

## 2. Example specs

`examples/specs/*.json` are inputs for `bin/create --spec`. Each has a `_comment` key saying what it shows (unknown keys are ignored).

| File | Shows |
|---|---|
| `kids-cute-animals.json` | kids, age band 4-8, cute-animals, recurring character, portrait, soft-pastel |
| `adults-florals.json` | adults, relaxation mood, florals, 24 pages, hero cover, warm-earthy |
| `teens-fantasy.json` | teens, fantasy-whimsical, frame cover, deep-jewel |
| `square-food-captions.json` | square 8.5 x 8.5 trim, food-and-drink, captions on |

Queue one without drawing it (free, prints the project id):

```bash
bin/create --spec examples/specs/adults-florals.json --no-start
```

Drawing it later with `bin/worker` calls OpenAI and costs money (see below).

## 3. Real book (costs money)

```bash
export OPENAI_API_KEY=sk-...
php examples/generate-real-book.php                                  # kids-cute-animals.json
php examples/generate-real-book.php examples/specs/teens-fantasy.json
```

Roughly 0.40 to 0.65 USD per book at the default low image quality, and it takes several minutes. The key is read from the environment only. Cost controls:

- `OPENAI_IMAGE_MODEL`: image model, default `gpt-image-2`.
- `OPENAI_IMAGE_QUALITY`: `low` (default, cheapest), `medium` or `high`. Higher is much more expensive.
- `COLORING_PAGE_COUNT`: default page count (1 to 24) when a spec has no `page_count`. The spec's own `page_count` (16, 24 or 30) wins, and fewer pages cost less.

Output: `examples/output/real-book.pdf` (path and size are printed). Page art and the database go to `examples/output/real-data/`. All of `examples/output/` is gitignored.
