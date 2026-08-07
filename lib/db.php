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

    if ($cur < 6) {
        /* Per-lead documents — the signed agreement, an invoice, anything that
           belongs to ONE client rather than to every prospect. lib/templates.php
           stays what it is: a registry of generic collateral. A proposal is not
           collateral; it carries a company name, agreed prices and a date, and
           putting one in the shared attachments folder would both misfile it and
           publish it under the web root.

           Only the row lives here. The bytes sit in crm_docs_dir(), named by
           digest so nothing user-supplied ever reaches the filesystem. */
        $pdo->exec("
        CREATE TABLE documents (
            id         TEXT PRIMARY KEY,
            lead_id    TEXT NOT NULL REFERENCES leads(id) ON DELETE CASCADE,
            name       TEXT NOT NULL,             -- original filename, for display
            send_name  TEXT NOT NULL,             -- ASCII filename used on the wire
            stored     TEXT NOT NULL,             -- <sha256>.<ext>, relative to docs dir
            mime       TEXT NOT NULL DEFAULT 'application/pdf',
            size       INTEGER NOT NULL,
            sha256     TEXT NOT NULL,
            created_at INTEGER NOT NULL,
            sent_at    INTEGER DEFAULT NULL       -- last time it went out by email
        )");
        $pdo->exec("CREATE INDEX idx_docs_lead ON documents(lead_id, created_at DESC)");
        $pdo->exec("INSERT OR REPLACE INTO schema_meta (k,v) VALUES ('version','6')");
        $cur = 6;
    }

    if ($cur < 7) {
        /* What this client actually agreed to: tier, setup fee, monthly, term,
           add-ons. JSON in one column because there is exactly one live proposal
           per lead and nothing queries inside it — a table would buy joins we
           would never use.

           Amounts are stored, not looked up. The catalogue lists Foundation at
           $75/month; Caring Hands agreed $40. The tier picks the starting
           numbers and then every one of them is editable, because the deal is
           whatever was actually said on the call. */
        $pdo->exec("ALTER TABLE leads ADD COLUMN proposal TEXT NOT NULL DEFAULT ''");
        $pdo->exec("INSERT OR REPLACE INTO schema_meta (k,v) VALUES ('version','7')");
        $cur = 7;
    }

    if ($cur < 8) {
        /* Proposal rendering has to happen outside the web request.
           LiteSpeed runs PHP under a HARD 4 GB address-space limit (verified:
           `ulimit -v` refuses to rise, hard == soft), and Chrome reserves more
           than that for V8's cage before it draws anything — it dies with
           SIGTRAP every time. From the CLI the limit is unlimited and the same
           render takes 0.36s. So the web app queues, and a cron worker renders.

           Keeping this as a table rather than a flag on leads means a failure
           has somewhere to put its reason. */
        $pdo->exec("
        CREATE TABLE proposal_jobs (
            id         INTEGER PRIMARY KEY AUTOINCREMENT,
            lead_id    TEXT NOT NULL REFERENCES leads(id) ON DELETE CASCADE,
            status     TEXT NOT NULL DEFAULT 'pending',  -- pending|running|done|failed
            error      TEXT NOT NULL DEFAULT '',
            doc_id     TEXT NOT NULL DEFAULT '',
            created_at INTEGER NOT NULL,
            started_at INTEGER,
            done_at    INTEGER
        )");
        $pdo->exec("CREATE INDEX idx_pjob_status ON proposal_jobs(status, id)");
        $pdo->exec("CREATE INDEX idx_pjob_lead ON proposal_jobs(lead_id, id DESC)");
        $pdo->exec("INSERT OR REPLACE INTO schema_meta (k,v) VALUES ('version','8')");
        $cur = 8;
    }
}
