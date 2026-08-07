<?php
/**
 * Render queued proposal PDFs. CLI only — run from cron every minute.
 *
 * This exists because of one hard constraint: LiteSpeed serves this site with
 * RLIMIT_AS pinned at 4 GB, hard and unraisable, and Chrome reserves more than
 * that before it renders a pixel. Inside a web request it dies with SIGTRAP
 * every time. From the CLI there is no such limit and the same page renders in
 * about a third of a second. So the app queues and this drains the queue.
 *
 *   php ~/domains/weyney.com/public_html/apps/crm/bin/render-worker.php
 *
 * Safe to run concurrently-ish: jobs are claimed by flipping status to
 * 'running' before any work starts. A crash leaves one stuck job rather than a
 * duplicate render, and the lock below stops overlapping cron ticks piling up.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("this is a command-line worker\n");
}

require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/proposal.php';

/* A render takes under a second, but a cron every minute plus a slow disk
   should never start a second worker on top of the first. */
$lock = fopen(crm_data_dir() . '/render-worker.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
    exit(0);                       // another worker holds it; nothing to say
}

$pdo = db();

/* Anything left 'running' for more than ten minutes was interrupted. Put it
   back rather than leaving the operator watching a spinner forever. */
$pdo->prepare("UPDATE proposal_jobs SET status='pending', started_at=NULL
               WHERE status='running' AND started_at < ?")
    ->execute([time() - 600]);

$lines = prop_run_pending($pdo, 5);

foreach ($lines as $l) {
    fwrite(STDOUT, date('Y-m-d H:i:s') . '  ' . $l . "\n");
}

flock($lock, LOCK_UN);
fclose($lock);
