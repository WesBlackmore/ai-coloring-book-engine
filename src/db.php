<?php

declare(strict_types=1);

/*
 * Standalone PDO SQLite connection and the engine's own schema. Shape copied from the host app's src/db.php;
 * the host's maintenance mode, schema enforcement and legacy bootstrap are gone.
 */

function application_database_path(): string
{
    $configured = trim((string) getenv('COLORING_DATABASE_PATH'));

    return $configured !== '' ? $configured : application_data_directory() . '/coloring.db';
}

/** The data directory: `data/` in this repo, or COLORING_DATA_DIR. Book images, exports and signal files live under it. */
function application_data_directory(): string
{
    $configured = trim((string) getenv('COLORING_DATA_DIR'));

    return $configured !== '' ? rtrim($configured, '/') : dirname(__DIR__) . '/data';
}

/**
 * A second connection to the same database file, holding no read snapshot (D-393).
 * In WAL mode a SELECT whose statement is still open keeps its connection on an old snapshot, and the next write on it
 * fails at once with "database is locked". A fresh connection starts current. Null for an in-memory database.
 */
function db_fresh_connection(PDO $pdo): ?PDO
{
    $file = '';
    foreach ($pdo->query('PRAGMA database_list')->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if (($row['name'] ?? '') === 'main') $file = (string) ($row['file'] ?? '');
    }
    if ($file === '') return null;
    $fresh = new PDO('sqlite:' . $file, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    $fresh->exec('PRAGMA foreign_keys = ON');
    $fresh->exec('PRAGMA busy_timeout = ' . max(1000, min(120000, (int) (getenv('DB_BUSY_TIMEOUT_MS') ?: 30000))));
    apply_connection_pragmas($fresh);

    return $fresh;
}

/** The long-running worker loop calls this between passes so each pass starts on a fresh connection. */
function db_reset(): PDO
{
    return db(null, true);
}

function apply_connection_pragmas(PDO $pdo): void
{
    $pdo->exec('PRAGMA journal_mode = WAL');
    $pdo->exec('PRAGMA synchronous = NORMAL');
    $pdo->exec('PRAGMA cache_size = -20000');
    $pdo->exec('PRAGMA temp_store = MEMORY');
}

function db(?PDO $override = null, bool $reset = false): PDO
{
    static $pdo = null;
    static $overridden = false;

    /* Only db_reset() passes $reset: drop the cached handle so the next statement runs on a fresh connection with no open
       cursor or WAL snapshot (D-639). An override or in-memory handle is kept; a transaction left open is rolled back. */
    if ($reset && $pdo instanceof PDO) {
        $memory = true;
        foreach ($pdo->query('PRAGMA database_list')->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (($row['name'] ?? '') === 'main' && ($row['file'] ?? '') !== '') $memory = false;
        }
        if ($overridden || $memory) return $pdo;
        if ($pdo->inTransaction()) {
            error_log('db_reset: rolling back a transaction left open');
            $pdo->rollBack();
        }
        $pdo = null;
    }

    if ($override instanceof PDO) {
        $pdo = $override;
        $overridden = true;

        return $pdo;
    }
    if ($pdo instanceof PDO) return $pdo;

    $dataDir = application_data_directory();
    if (!is_dir($dataDir) && !mkdir($dataDir, 0775, true) && !is_dir($dataDir)) {
        throw new RuntimeException('Unable to create the data directory.');
    }
    $pdo = new PDO('sqlite:' . application_database_path(), null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->exec('PRAGMA busy_timeout = ' . max(1000, min(120000, (int) (getenv('DB_BUSY_TIMEOUT_MS') ?: 30000))));
    apply_connection_pragmas($pdo);
    bootstrap_schema($pdo);

    return $pdo;
}

/**
 * The engine's whole schema, idempotent. Columns match what src/coloring_*.php reads and writes. `owner_ref` is the
 * caller's own correlation string (a user id, an order id); the engine never interprets it. Most columns are nullable
 * on purpose: the code reads them with (int) / (string) casts, exactly as against the host's migrated table.
 */
function bootstrap_schema(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS schema_migrations (
        version TEXT PRIMARY KEY,
        checksum TEXT NOT NULL,
        application_release TEXT NOT NULL,
        applied_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS coloring_projects (
        id INTEGER PRIMARY KEY,
        owner_ref TEXT,
        status TEXT NOT NULL DEFAULT 'draft',
        idea TEXT NOT NULL,
        style TEXT NOT NULL,
        audience TEXT NOT NULL,
        age_band TEXT,
        mood TEXT,
        occasion TEXT,
        page_count INTEGER,
        character_desc TEXT NOT NULL,
        title TEXT NOT NULL,
        captions INTEGER NOT NULL DEFAULT 0,
        trim TEXT,
        cover_template TEXT,
        palette TEXT,
        plan_json TEXT,
        pages_json TEXT,
        cover_status TEXT,
        cover_path TEXT,
        cover_check_json TEXT,
        cover_attempts INTEGER,
        cover_extra_count INTEGER,
        cover_outline_path TEXT,
        pdf_path TEXT,
        kdp_metadata_json TEXT,
        unlocked INTEGER,
        reservation_key TEXT,
        credits_charged INTEGER,
        generating_until TEXT,
        generating_token TEXT,
        failure_count INTEGER,
        failure_kind TEXT,
        failure_at TEXT,
        last_activity_at TEXT,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
    )");
    $pdo->exec('CREATE INDEX IF NOT EXISTS coloring_projects_owner ON coloring_projects (owner_ref)');
}
