<?php
/**
 * SQLite connection + schema.
 *
 * The database lives outside the web root (~/appdata/crm/) so it can never be
 * fetched over HTTP, even if a rewrite rule is lost.
 */

require_once __DIR__ . '/paths.php';

function db(): PDO {
    static $pdo = null;
    if ($pdo !== null) return $pdo;

    $pdo = new PDO('sqlite:' . crm_data_dir() . '/crm.sqlite');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    // WAL keeps reads from blocking behind the writer. Single user, but the
    // cron job writes while the UI reads.
    $pdo->exec('PRAGMA journal_mode = WAL');
    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->exec('PRAGMA busy_timeout = 5000');

    migrate($pdo);
    return $pdo;
}

function migrate(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS schema_meta (k TEXT PRIMARY KEY, v TEXT)");
    $cur = (int)($pdo->query("SELECT v FROM schema_meta WHERE k='version'")->fetchColumn() ?: 0);

    if ($cur < 1) {
        $pdo->exec("
        CREATE TABLE leads (
            id            TEXT PRIMARY KEY,
            name          TEXT NOT NULL,
            phone         TEXT NOT NULL DEFAULT '',
            email         TEXT NOT NULL DEFAULT '',
            contact       TEXT NOT NULL DEFAULT '',
            service       TEXT NOT NULL DEFAULT '',
            city          TEXT NOT NULL DEFAULT '',
            address       TEXT NOT NULL DEFAULT '',
            stage         TEXT NOT NULL DEFAULT 'new',
            reason        TEXT NOT NULL DEFAULT '',
            next_action   TEXT NOT NULL DEFAULT '',
            owner         TEXT NOT NULL DEFAULT '',
            value         INTEGER NOT NULL DEFAULT 0,
            attempts      INTEGER NOT NULL DEFAULT 0,
            vm_count      INTEGER NOT NULL DEFAULT 0,
            seq           INTEGER NOT NULL DEFAULT 0,
            last_call_at  INTEGER NOT NULL DEFAULT 0,
            first_vm_at   INTEGER NOT NULL DEFAULT 0,
            called_back_at INTEGER NOT NULL DEFAULT 0,
            passed_at     INTEGER NOT NULL DEFAULT 0,
            created_at    INTEGER NOT NULL,
            updated_at    INTEGER NOT NULL
        )");
        $pdo->exec("CREATE INDEX idx_leads_stage ON leads(stage)");
        $pdo->exec("CREATE INDEX idx_leads_seq   ON leads(seq)");

        // Append-only history. Every call, note, stage change lands here.
        $pdo->exec("
        CREATE TABLE activities (
            id       INTEGER PRIMARY KEY AUTOINCREMENT,
            lead_id  TEXT NOT NULL REFERENCES leads(id) ON DELETE CASCADE,
            ts       INTEGER NOT NULL,
            type     TEXT NOT NULL,
            body     TEXT NOT NULL DEFAULT ''
        )");
        $pdo->exec("CREATE INDEX idx_act_lead ON activities(lead_id, ts DESC)");

        /* Scheduled things: callbacks, booked calls, follow-ups.
           This is the ONLY table the calendar and Trello sync read from —
           keeping it separate from leads means a lead can have several
           scheduled events over its life without overwriting a single field,
           and sync state has somewhere to live. */
        $pdo->exec("
        CREATE TABLE events (
            id          INTEGER PRIMARY KEY AUTOINCREMENT,
            lead_id     TEXT NOT NULL REFERENCES leads(id) ON DELETE CASCADE,
            kind        TEXT NOT NULL,              -- callback | demo | followup
            title       TEXT NOT NULL DEFAULT '',
            notes       TEXT NOT NULL DEFAULT '',
            starts_at   INTEGER NOT NULL,           -- unix seconds, UTC
            duration_min INTEGER NOT NULL DEFAULT 30,
            status      TEXT NOT NULL DEFAULT 'scheduled', -- scheduled|done|cancelled
            created_at  INTEGER NOT NULL,
            updated_at  INTEGER NOT NULL,
            -- sync bookkeeping: remote ids let us UPDATE rather than duplicate
            gcal_event_id  TEXT DEFAULT NULL,
            gcal_synced_at INTEGER DEFAULT NULL,
            trello_card_id TEXT DEFAULT NULL,
            trello_synced_at INTEGER DEFAULT NULL,
            ics_uid     TEXT NOT NULL               -- stable across re-exports
        )");
        $pdo->exec("CREATE INDEX idx_ev_start  ON events(starts_at)");
        $pdo->exec("CREATE INDEX idx_ev_status ON events(status, starts_at)");
        $pdo->exec("CREATE UNIQUE INDEX idx_ev_uid ON events(ics_uid)");

        $pdo->exec("
        CREATE TABLE sessions (
            token      TEXT PRIMARY KEY,
            created_at INTEGER NOT NULL,
            expires_at INTEGER NOT NULL
        )");

        $pdo->exec("INSERT OR REPLACE INTO schema_meta (k,v) VALUES ('version','1')");
        $cur = 1;
    }

    if ($cur < 2) {
        // Meet link and who was invited, so a reschedule keeps both.
        $pdo->exec("ALTER TABLE events ADD COLUMN meet_link TEXT DEFAULT NULL");
        $pdo->exec("ALTER TABLE events ADD COLUMN invite_email TEXT DEFAULT NULL");
        $pdo->exec("INSERT OR REPLACE INTO schema_meta (k,v) VALUES ('version','2')");
        $cur = 2;
    }

    if ($cur < 3) {
        /* Standing facts about the business, kept apart from the activity log.
           "What we know" is a profile you read before dialling; the log is a
           history you scroll after. Mixing them made both unreadable. */
        $pdo->exec("ALTER TABLE leads ADD COLUMN pain_points TEXT NOT NULL DEFAULT ''");
        $pdo->exec("ALTER TABLE leads ADD COLUMN opportunity TEXT NOT NULL DEFAULT ''");
        $pdo->exec("INSERT OR REPLACE INTO schema_meta (k,v) VALUES ('version','3')");
        $cur = 3;
    }

    if ($cur < 4) {
        /* Imported leads carry their site in the note text ("site: domain").
           Manually added ones need somewhere real to put it, plus any social
           profiles we actually know exist. */
        $pdo->exec("ALTER TABLE leads ADD COLUMN website   TEXT NOT NULL DEFAULT ''");
        $pdo->exec("ALTER TABLE leads ADD COLUMN socials   TEXT NOT NULL DEFAULT ''");
        $pdo->exec("INSERT OR REPLACE INTO schema_meta (k,v) VALUES ('version','4')");
        $cur = 4;
    }

    if ($cur < 5) {
        /* The Clients page asks "when is this lead's next event?" once per row.
           Without lead_id in an index that scanned every scheduled event for
           every client — fine at a hundred leads, quadratic at a few thousand. */
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_ev_lead ON events(lead_id, status, starts_at)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_leads_updated ON leads(updated_at DESC)");
        $pdo->exec("INSERT OR REPLACE INTO schema_meta (k,v) VALUES ('version','5')");
        $cur = 5;
    }
}
