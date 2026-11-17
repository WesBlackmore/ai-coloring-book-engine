# AI Coloring Book Engine

A self-contained PHP engine that turns a one-line idea into a Kindle Direct Publishing (KDP) coloring book. It asks OpenAI to
plan the book, draw the line-art pages in parallel and paint a cover, then produces a print-ready interior PDF, a KDP cover wrap
PDF and KDP listing metadata (JSON). Vanilla PHP, SQLite, GD, one Composer dependency (`dompdf/dompdf`). No framework, no npm.

## Requirements

- PHP 8.2 or newer with the extensions `pdo_sqlite`, `curl`, `gd` and `mbstring`
- [Composer](https://getcomposer.org/)
- An OpenAI API key with access to the image and text models below (real generation costs money)

## Install

```bash
git clone https://github.com/WesBlackmore/ai-coloring-book-engine.git
cd ai-coloring-book-engine
composer install
export OPENAI_API_KEY=your-key-here
```

## Configuration

All configuration is environment variables. Only `OPENAI_API_KEY` is required.

| Variable | Default | What it does |
|---|---|---|
| `OPENAI_API_KEY` | none | Required for real generation. Unset, the worker records a failure and retries with backoff. |
| `COLORING_DATA_DIR` | `<repo>/data` | Database, images, exports, temp files, wake file. |
| `COLORING_DATABASE_PATH` | `<data dir>/coloring.db` | SQLite file. |
| `COLORING_READY_WEBHOOK` | unset | If set, a JSON POST `{event, project_id, owner_ref, title}` is sent when a book is ready. |
| `OPENAI_IMAGE_MODEL` | `gpt-image-2` | Model for pages and cover. |
| `OPENAI_IMAGE_QUALITY` | `low` | Image quality setting. |
| `OPENAI_TEXT_MODEL` | `gpt-5.4-mini` | Planning and KDP metadata text model. |
| `OPENAI_EXTRACTION_MODEL` | `gpt-5.4-nano` | Light extraction model. |
| `OPENAI_COVER_CHECK_MODEL` | `gpt-5.4-nano` | Reads the cover to check its lettering. |
| `OPENAI_COVER_CONFIRM_MODEL` | `gpt-5.4-mini` | Second-opinion cover lettering check. |
| `COLORING_PAGE_PARALLEL` | `4` | Pages drawn at once per worker unit (1 to 8; 1 is serial). |
| `COLORING_WORKER_LIMIT` | `12` | Pages one serial unit draws before handing over. |
| `COLORING_PAGE_COUNT` | `24` | Page count when a spec gives none. |
| `BOOK_RETRY_LIMIT` | `6` | Failures before a book stalls. Backoff is 18 min x attempt squared, capped at 6 h. |
| `AI_RATE_ACTOR_HOUR` | `150` | Provider calls per hour; over it the call throws. |
| `AI_RATE_GLOBAL_DAY` | `3000` | Provider calls per day; the last stop before a surprise bill. |
| `AI_CONCURRENT_LIMIT` | `2` | Concurrent text calls. |
| `AI_IMAGE_CONCURRENT_LIMIT` | pages + 1 | Concurrent image calls. |
| `DB_BUSY_TIMEOUT_MS` | `30000` | SQLite busy timeout (1000 to 120000). |
| `COLORING_WORKER_WAKE` | on | Set `0` to disable the wake file. |
| `COLORING_WORKER_SPAWN` | off in CLI | Set `1` to let a web request spawn a detached worker pass. |
| `COLORING_WORKER_PHP`, `COLORING_WORKER_ENV_FILE` | auto | PHP binary and env file used by the spawned pass. |
| `BACKGROUND_WORKER_LOCK_FILE`, `BACKGROUND_WORKER_LOOP_INTERVAL` | auto | Lock file and loop interval for the worker loop. |

## Quickstart

```bash
# 1. Queue a book. Prints the project id.
php bin/create --idea "Friendly dinosaurs having a picnic together" --audience kids --pages 16

# 2. Run the worker. One pass advances up to 20 units of work.
php bin/worker --limit=20
```

Run `bin/worker` again until it reports `ready=1`. A 16-page book takes a handful of passes.

`bin/create` options:

```
--idea TEXT            what the book is about (required unless --spec)
--audience kids|teens|adults
--age-band 4-8         --style cute-animals     --mood TEXT    --occasion TEXT
--pages 16|24|30       --trim portrait|square
--cover-template auto  --palette auto
--character TEXT       keep one recurring character across pages
--captions             add captions to pages
--owner-ref ID         your own reference, echoed in the ready webhook
--no-start             create a draft without queueing it
--spec book.json       read the same fields from JSON (idea, style, audience, age_band, mood, occasion,
                       page_count, recurring, character_desc, captions, trim, cover_template, palette)
```

`bin/worker` options: `--once`, `--limit=1..50`, `--loop-seconds=0..3300`, `--lock-file=PATH`. For continuous running under cron:

```bash
* * * * * flock -n ~/.config/coloring-engine.lock php /path/to/bin/worker --limit=20 --loop-seconds=3300
```

## How it works

1. **Plan.** A text model writes the title and one scene per page.
2. **Pages.** Pages are drawn in parallel by a pool of curl multi handles (`COLORING_PAGE_PARALLEL` lanes), with a circuit breaker for
   rate-limit errors. Each page is black line art on white.
3. **Cover, then cover outline.** A color cover is painted and its lettering is checked by a vision model, then a line-art outline
   version is made for the book's coloring page.
4. **Finish.** The book is marked ready and KDP metadata is generated.
5. **PDF.** The worker renders the interior PDF with dompdf and caches it.

State lives in one SQLite table (`coloring_projects`). The worker is a queue: each claim takes one unit of work under a lease, and
a stale or crashed worker loses the lease so two workers never write the same book. Failures retry with backoff until
`BOOK_RETRY_LIMIT`, then the book stalls.

## Output

Everything lands under the data directory (`./data` by default):

| Path | Contents |
|---|---|
| `data/coloring.db` | SQLite database, including `kdp_metadata_json` per book |
| `data/coloring/` | Page images, cover, cover outline, thumbnails |
| `data/exports/coloring-<id>.pdf` | Print-ready interior PDF |

KDP metadata (JSON on the project row): `display_title`, `subtitle`, `description`, `keywords` (7 phrases), `categories` (3 BISAC
categories), `primary_audience`, `reading_age`, `author_bio`. Read it with
`sqlite3 data/coloring.db "select kdp_metadata_json from coloring_projects where id=1"`.

The KDP cover wrap PDF is produced on demand by `coloring_cover_wrap_pdf($project)` in `src/coloring_cover.php`, which returns PDF
bytes (spine width follows the page count). The worker does not write it to disk for you; call it from your own script.

## Run without spending money

```bash
php tools/smoke-selfcheck.php
```

Runs two full books (parallel and serial) end to end with fake AI providers in a temporary directory and checks the plan, pages,
cover, KDP JSON and a valid PDF. No network, no key needed.

## Known limitations

- The back cover of the wrap is a flat color gradient. There is no designed back-cover art yet.
- This is an engine, not an app. Billing, credits, accounts and auth are not included, and there is no web UI or HTTP API.
- The cover wrap is a library call, not a worker output file (see Output).

## Security

Never commit your API key. It is read from the environment only (`OPENAI_API_KEY`). `.env` and `data/` are git-ignored.

## License

MIT, see `LICENSE`. The bundled font in `public/fonts` (Bricolage Grotesque) is under the SIL OFL, license text alongside it.
`dompdf/dompdf` is a separate LGPL dependency installed by Composer, not bundled.
