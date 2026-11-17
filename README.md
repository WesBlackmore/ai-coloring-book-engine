# coloring-engine

The coloring-book generation engine from AI eBook Pro, packaged as a standalone PHP library plus two CLIs. Idea in, KDP-ready
interior PDF, cover and Amazon KDP metadata out. Vanilla PHP 8.2+, PDO + SQLite, one Composer dependency (`dompdf/dompdf`).
No framework, no bundler, no npm. The engine does not bill and knows no users: the consuming project does both.

## Boot

```bash
composer install
export OPENAI_API_KEY=sk-...        # the only required secret
php bin/create --idea "Friendly dinosaurs having a picnic together" --audience kids --pages 16 --owner-ref order-123
php bin/worker --limit=20           # one pass; prints "advanced=.. ready=.. failed=.. stalled=.. pdfs=.."
```

`bin/create` prints the project id and queues the book. `bin/worker` draws it one unit per claim (plan, a run of pages, cover,
cover outline, finish) and renders the interior PDF into `data/exports/coloring-<id>.pdf`. KDP metadata is stored on the
project row (`kdp_metadata_json`). Run `bin/worker --loop-seconds=3300` under cron with `flock -n` to keep it running; a book
queued by `bin/create` touches `data/background-worker.wake`, which a running loop hears within about a second.

`bin/create --spec book.json` takes the keys `idea, audience, age_band, style, page_count (16|24|30), trim (portrait|square),
cover_template, palette, recurring, character_desc, captions, mood, occasion`. See the header of `bin/create`.

## Offline smoke test (no OpenAI calls, no spend)

```bash
php tools/smoke-selfcheck.php
```

Runs two full books (parallel and serial page drawing) with fake providers installed through
`$GLOBALS['coloring_worker_providers']` (`plan`, `page`, `cover`, `cover_outline`, `kdp`) and asserts plan, pages, cover,
finish, KDP JSON and a valid interior PDF. With no overrides set, the real OpenAI path runs.

## Environment

| Variable | Default | Meaning |
|---|---|---|
| `OPENAI_API_KEY` | none | Required for real generation. Unset, the worker records a failure and retries with backoff. |
| `COLORING_DATA_DIR` | `./data` | Database, book images, exports, rate-limit counters, logs, wake files. |
| `COLORING_DATABASE_PATH` | `<data dir>/coloring.db` | SQLite file. |
| `COLORING_READY_WEBHOOK` | unset | When set, a JSON POST `{event, project_id, owner_ref, title}` is sent when a book is ready. Inert when unset. |
| `BOOK_RETRY_LIMIT` | 6 | Failures before a book stalls (backoff is 18 min x attempt squared, capped at 6 h; transient faults retry in 2 min for the first 3). |
| `AI_RATE_ACTOR_HOUR` | 150 | Provider calls per hour. Over it the call throws. |
| `AI_RATE_GLOBAL_DAY` | 3000 | Provider calls per day. The last stop before a surprise bill. |
| `AI_CONCURRENT_LIMIT` / `AI_IMAGE_CONCURRENT_LIMIT` | 2 / pages+1 | Concurrent provider calls (text / image). |
| `COLORING_PAGE_PARALLEL` | 4 | Pages drawn at once per worker unit (1 to 8; 1 is serial). |
| `COLORING_WORKER_LIMIT` | 12 | Pages one serial unit draws before handing over. |
| `COLORING_PAGE_COUNT` | 24 | Default page count when a spec gives none. |
| `OPENAI_IMAGE_MODEL`, `OPENAI_IMAGE_QUALITY`, `OPENAI_TEXT_MODEL`, `OPENAI_EXTRACTION_MODEL`, `OPENAI_COVER_CHECK_MODEL`, `OPENAI_COVER_CONFIRM_MODEL` | `gpt-image-2`, `low`, `gpt-5.4-mini`, `gpt-5.4-nano`, `gpt-5.4-nano`, `gpt-5.4-mini` | Model overrides. |
| `COLORING_WORKER_WAKE`, `COLORING_WORKER_SPAWN`, `COLORING_WORKER_PHP`, `BACKGROUND_WORKER_LOCK_FILE`, `COLORING_WORKER_ENV_FILE` | on / off in CLI | Wake file and the detached single-pass spawn when no loop is alive (`COLORING_WORKER_SPAWN=1` to enable from a web request). |

## Layout

- `src/coloring_*.php`: the engine, copied from the host app (book model, cover, categories, PDF, worker, parallel pool).
- `src/ai.php`: the coloring slice of the host's `ai.php` (OpenAI text, image, edit, cover check, KDP metadata, transport).
- `src/shims.php`, `src/db.php`, `src/ratelimit.php`: standalone replacements for host helpers (no-op credits, SQLite handle and
  schema, retry policy, spend guard, ready webhook).
- `src/background_book_worker_loop.php`, `src/background_worker_wake.php`: the worker loop and wake/spawn signals.
- `bin/create`, `bin/worker`, `tools/smoke-selfcheck.php`, `public/fonts/` (Bricolage Grotesque, OFL).

Schema: `coloring_projects` (with a nullable `owner_ref` instead of the host's `user_id`) and `schema_migrations`, created
idempotently on first `db()` call by `bootstrap_schema()`.

## TODO (deliberately not built yet)

- MCP server (the coloring tool subset).
- HTTP API beyond the CLI create path (create, status, image and PDF download).
- AI back-cover feature.
