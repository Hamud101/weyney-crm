# Weyney CRM

The sales system I run Weyney Media on. It tracks leads from the first cold call
through to a signed agreement, and it is the tool I actually use, not a demo.

Live at `apps.weyney.com/crm`.

## Why it exists

I was running the sales side out of spreadsheets and a mail client. The parts that
kept breaking were the handoffs: a call would get logged but the follow-up never
got scheduled, or a proposal would go out with last month's pricing in it. So the
CRM is built around those handoffs rather than around storing contacts.

Three things it does that a generic CRM did not do for me:

**Proposals are generated, not written.** You tick the services a lead agreed to
and the system renders a signable PDF from the current price list. The package
that was agreed on gets recorded against the lead, so the proposal and the call
log cannot drift apart.

**Sent mail goes into the real Sent folder.** Email is sent over SMTP and then
filed into IMAP, so the thread reads normally in a regular mail client. Anything
sent from the CRM is visible from a phone without the CRM.

**Attachments are built, not hand-made.** The service one-pagers are generated
from the HTML sources in `attachments/src/` by `build.py`, which inlines the
fonts and drives headless Chrome. Changing a price means editing the source and
rebuilding, not hunting for the newest PDF on a desktop. The built PDFs are not
committed, since they carry the price list and this repo is public. `deploy.sh`
sends them to the server by name.

## How it is put together

Plain PHP with no framework, SQLite for storage, and vanilla JavaScript on the
front end. That is a deliberate choice. It deploys to shared LiteSpeed hosting
over scp, which rules out anything needing a build step or a long-running process.

```
index.php        entry point and router
api.php          the API surface the front end talks to
lib/
  auth.php       session handling
  db.php         SQLite access
  google.php     OAuth for calendar and contacts
  mailer.php     SMTP send, then IMAP file into Sent
  proposal.php   builds the signable proposal
  documents.php  attachment handling
  templates.php  HTML template rendering
  trello.php     pushes won deals onto the delivery board
bin/render-worker.php   PDF rendering, run from cron
deploy.sh        scp deploy, with a backup taken first
```

**PDF rendering runs outside the web request.** Proposals render through headless
Chrome, and the host caps a web request at 4 GB, which Chrome exceeds on a cold
start. So the web request queues a render and `bin/render-worker.php` picks it up
from cron. Chrome also needs a writable `HOME` and its own profile directory under
LiteSpeed, which is not the default.

**Secrets and data are not in the web root.** `secrets.php` and `crm.sqlite` live
in `~/appdata/crm` on the server, outside anything the web server will serve. The
gitignore blocks them as well in case one ever gets copied in by mistake.

## Running it

You need PHP 8 with the sqlite3, imap, and curl extensions, and Chrome or
Chromium if you want proposal rendering.

```bash
cp lib/secrets.example.php ~/appdata/crm/secrets.php   # fill in SMTP, IMAP, Google OAuth
php -S localhost:8000                                  # the SQLite file is created on first run
```

Deploying is `./deploy.sh`, which takes a dated backup on the server before it
copies anything over.

## What is not here

Client records. The database is not in the repo and never has been. What you are
reading is the system, not the sales history that runs through it.
