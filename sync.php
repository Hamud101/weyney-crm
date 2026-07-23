<?php
/**
 * Cron entry point. Pushes scheduled events to Trello, and retries any Google
 * Calendar syncs that failed at the moment of scheduling (network blips etc).
 * CLI only — refuses to run over HTTP so it can't be triggered by a stranger.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("cli only\n"); }

require_once __DIR__ . '/lib/trello.php';
require_once __DIR__ . '/lib/google.php';

$stamp = date('Y-m-d H:i:s');

[$n, $failed] = t_sync_due(14);
echo "[$stamp] trello: synced $n\n";
foreach ($failed as $f) echo "[$stamp] trello FAILED $f\n";

// Catch anything Google missed when it was scheduled.
if (g_connected()) {
    $rows = db()->query("SELECT e.*, l.name, l.phone, l.city FROM events e
                         JOIN leads l ON l.id=e.lead_id
                         WHERE e.status='scheduled' AND e.gcal_event_id IS NULL
                           AND e.starts_at > " . (time() - 86400))->fetchAll();
    $g = 0;
    foreach ($rows as $ev) { [$ok] = g_sync_event($ev, $ev); if ($ok) $g++; }
    echo "[$stamp] gcal: backfilled $g\n";
} else {
    echo "[$stamp] gcal: not connected, skipped\n";
}
