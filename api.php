<?php
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/google.php';
require_once __DIR__ . '/lib/mailer.php';
require_once __DIR__ . '/lib/trello.php';
require_auth();

$a = $_GET['a'] ?? '';
$isWrite = $_SERVER['REQUEST_METHOD'] === 'POST';
if ($isWrite) csrf_check();

$in = $isWrite ? (json_decode(file_get_contents('php://input'), true) ?: []) : $_GET;
$pdo = db();
$now = time();

function lead_row(PDO $pdo, string $id): ?array {
    $s = $pdo->prepare("SELECT * FROM leads WHERE id=?");
    $s->execute([$id]);
    return $s->fetch() ?: null;
}

function touch_lead(PDO $pdo, string $id): void {
    $pdo->prepare("UPDATE leads SET updated_at=? WHERE id=?")->execute([time(), $id]);
}

/**
 * Pull contact surfaces out of the imported research note.
 * The notes follow "<problem> — <opener> | <detail> | site: <domain>", so the
 * site is reliably at the end; 128 of 130 carry one. Facebook is mentioned in
 * prose rather than as a URL, so it becomes a search link, not a fake profile.
 */
/** An email hiding in the notes is better than no email. Surfaced as a
 *  suggestion, never written silently — the operator confirms it. */
function email_from_notes(array $acts): ?string {
    foreach ($acts as $a) {
        if (preg_match('~[\w.+-]+@[\w-]+\.[\w.]{2,}~', (string)$a['body'], $m)) {
            $e = rtrim($m[0], '.,;');
            if (filter_var($e, FILTER_VALIDATE_EMAIL)) return $e;
        }
    }
    return null;
}

function lead_links(array $lead, array $acts): array {
    /* Only surface a link we have grounds for. A speculative "Instagram" chip
       on a business that has no Instagram is worse than no chip — it looks
       like data. Email is excluded too; it already shows as a contact fact. */
    $blob = $lead['reason'] . ' ' . implode(' ', array_column($acts, 'body'));
    $out  = [];
    $q    = trim($lead['name'] . ' ' . $lead['city']);

    // Website: the explicit field first, then whatever the research note carried.
    $site = trim((string)($lead['website'] ?? ''));
    if ($site === '') {
        if (preg_match('~site:\s*([a-z0-9.-]+\.[a-z]{2,})~i', $blob, $m)
            && stripos($m[1], 'facebook') === false) {
            $site = $m[1];
        } elseif (preg_match('~https?://([a-z0-9.-]+\.[a-z]{2,})~i', $blob, $m)
                  && !preg_match('~facebook|instagram|linkedin|yelp~i', $m[1])) {
            // A social profile scraped out of the notes is not a website — it
            // belongs to the socials chip, not the Website one.
            $site = $m[1];
        }
    }
    $site = preg_replace('~^https?://~i', '', rtrim(strtolower($site), '/. '));
    if ($site !== '') {
        $out[] = ['kind' => 'web', 'label' => $site, 'url' => 'https://' . $site];
    }

    /* Socials: only where there's evidence. Either a URL saved on the lead, or
       the platform named in the research. Otherwise nothing. */
    $known = array_filter(array_map('trim', explode(',', (string)($lead['socials'] ?? ''))));
    foreach ($known as $u) {
        $host = strtolower(parse_url(strpos($u, '//') === false ? 'https://' . $u : $u, PHP_URL_HOST) ?? '');
        $kind = strpos($host, 'facebook') !== false ? 'facebook'
              : (strpos($host, 'instagram') !== false ? 'instagram'
              : (strpos($host, 'linkedin') !== false ? 'linkedin'
              : (strpos($host, 'yelp') !== false ? 'yelp' : 'web')));
        $out[] = ['kind' => $kind, 'label' => $kind === 'web' ? 'Web' : ucfirst($kind),
                  'url' => strpos($u, '//') === false ? 'https://' . $u : $u];
    }
    foreach ([['facebook','Facebook','facebook.com'],
              ['instagram','Instagram','instagram.com'],
              ['linkedin','LinkedIn','linkedin.com']] as [$kind, $label, $host]) {
        if (in_array($kind, array_column($out, 'kind'), true)) continue;   // already have a real one
        if (stripos($blob, $kind) === false) continue;                     // no evidence — skip
        $out[] = ['kind' => $kind, 'label' => $label . ' (search)',
                  'url'  => 'https://www.google.com/search?q=' .
                            rawurlencode('site:' . $host . ' ' . $q)];
    }

    if (trim((string)$lead['address']) !== '') {
        $out[] = ['kind' => 'map', 'label' => 'Map',
                  'url' => 'https://www.google.com/maps/search/?api=1&query=' .
                           rawurlencode(trim($lead['address'] . ' ' . $lead['city']))];
    }
    $out[] = ['kind' => 'search', 'label' => 'Search lead on Google',
              'url' => 'https://www.google.com/search?q=' . rawurlencode($q)];
    return $out;
}

function log_act(PDO $pdo, string $leadId, string $type, string $body): void {
    // A double-submit shouldn't read as two things happening.
    $last = $pdo->prepare("SELECT body FROM activities WHERE lead_id=? ORDER BY ts DESC, id DESC LIMIT 1");
    $last->execute([$leadId]);
    if ((string)$last->fetchColumn() === $body) return;
    $pdo->prepare("INSERT INTO activities (lead_id,ts,type,body) VALUES (?,?,?,?)")
        ->execute([$leadId, time(), $type, $body]);
}

switch ($a) {

/* Everything the UI needs on first paint, in one round trip. */
case 'bootstrap': {
    $counts = $pdo->query("SELECT stage, COUNT(*) c FROM leads GROUP BY stage")
                  ->fetchAll(PDO::FETCH_KEY_PAIR);
    $stages = [];
    foreach (STAGES as $k => $v) $stages[$k] = ['label' => $v['label'], 'count' => (int)($counts[$k] ?? 0)];

    // Due today or overdue, and what's coming up.
    $dueNow = $pdo->prepare("
        SELECT e.*, l.name, l.phone, l.city FROM events e JOIN leads l ON l.id=e.lead_id
        WHERE e.status='scheduled' AND e.starts_at <= ? ORDER BY e.starts_at ASC");
    $dueNow->execute([strtotime('tomorrow') - 1]);

    $upcoming = $pdo->prepare("
        SELECT e.*, l.name, l.phone, l.city FROM events e JOIN leads l ON l.id=e.lead_id
        WHERE e.status='scheduled' AND e.starts_at > ? ORDER BY e.starts_at ASC LIMIT 25");
    $upcoming->execute([strtotime('tomorrow') - 1]);

    json_out([
        'stages'   => $stages,
        'total'    => (int)$pdo->query("SELECT COUNT(*) FROM leads")->fetchColumn(),
        'due'      => $dueNow->fetchAll(),
        'upcoming' => $upcoming->fetchAll(),
        'csrf'     => csrf_token(),
        'calendar_connected' => g_connected(),
        'email_ready'        => cfg('smtp_pass', '') !== '',
    ]);
}

/* The call queue: who to ring next, in order. Untouched leads first by seq,
   then anything that has gone quiet. */
case 'queue': {
    $stage = $in['stage'] ?? 'new';
    /* The page size is an implementation detail; the header must show how many
       leads are actually in the stage or "1 of 50" reads as a cap on the list. */
    $cnt = $pdo->prepare("SELECT COUNT(*) FROM leads WHERE stage = ?");
    $cnt->execute([$stage]);
    $stageTotal = (int)$cnt->fetchColumn();

    $s = $pdo->prepare("
        SELECT * FROM leads WHERE stage = ?
        ORDER BY (last_call_at = 0) DESC, last_call_at ASC, seq ASC LIMIT 200");
    $s->execute([$stage]);
    $rows = $s->fetchAll();
    $act = $pdo->prepare("SELECT ts,type,body FROM activities WHERE lead_id=? ORDER BY ts DESC LIMIT 6");
    foreach ($rows as &$r) {
        $act->execute([$r['id']]);
        $r['acts']  = $act->fetchAll();
        $r['links'] = lead_links($r, $r['acts']);
        $r['email_guess'] = $r['email'] === '' ? email_from_notes($r['acts']) : null;
    }
    unset($r);

    // A short look-ahead so nothing lands unannounced mid-queue.
    $up = $pdo->prepare("SELECT e.starts_at, e.kind, l.name FROM events e JOIN leads l ON l.id=e.lead_id
                         WHERE e.status='scheduled' AND e.starts_at >= ?
                         ORDER BY e.starts_at ASC LIMIT 3");
    $up->execute([time() - 3600]);

    json_out(['leads' => $rows, 'next_up' => $up->fetchAll(), 'stage_total' => $stageTotal]);
}

case 'lead': {
    $l = lead_row($pdo, (string)($in['id'] ?? ''));
    if (!$l) json_out(['error' => 'not found'], 404);
    $act = $pdo->prepare("SELECT ts,type,body FROM activities WHERE lead_id=? ORDER BY ts DESC");
    $act->execute([$l['id']]);
    $l['acts'] = $act->fetchAll();
    $ev = $pdo->prepare("SELECT * FROM events WHERE lead_id=? ORDER BY starts_at DESC");
    $ev->execute([$l['id']]);
    $l['events'] = $ev->fetchAll();
    $l['links']  = lead_links($l, $l['acts']);
    $l['email_guess'] = $l['email'] === '' ? email_from_notes($l['acts']) : null;
    json_out($l);
}

/* Record a call outcome. One call = one activity row + a stage move. */
case 'disposition': {
    $id      = (string)($in['id'] ?? '');
    $outcome = (string)($in['outcome'] ?? '');
    $note    = trim((string)($in['note'] ?? ''));
    $lead = lead_row($pdo, $id);
    if (!$lead) json_out(['error' => 'not found'], 404);

    $map = [
        'no_pickup'  => 'attempting',
        'no_answer'  => 'attempting',   // legacy alias
        'voicemail'  => 'voicemail',
        'contacted'  => 'contacted',
        'booked'     => 'demo_set',
        'not_interested' => 'lost',
        'nurture'    => 'nurture',
    ];
    if (!isset($map[$outcome])) json_out(['error' => 'bad outcome'], 400);
    $stage = $map[$outcome];

    $pdo->beginTransaction();
    $pdo->prepare("UPDATE leads SET stage=?, attempts=attempts+1, last_call_at=?,
                   vm_count = vm_count + ?, first_vm_at = CASE WHEN ?>0 AND first_vm_at=0 THEN ? ELSE first_vm_at END,
                   updated_at=? WHERE id=?")
        ->execute([$stage, $now, $outcome === 'voicemail' ? 1 : 0,
                   $outcome === 'voicemail' ? 1 : 0, $now, $now, $id]);
    log_act($pdo, $id, 'call', 'Outcome: ' . str_replace('_', ' ', $outcome) . ($note ? ' — ' . $note : ''));
    $pdo->commit();

    json_out(['ok' => true, 'stage' => $stage]);
}

/* Schedule a callback / booked call. This is the row the calendar and Trello
   sync read — nothing else feeds them. */
case 'schedule': {
    $id    = (string)($in['id'] ?? '');
    $kind  = (string)($in['kind'] ?? 'callback');
    $when  = (string)($in['when'] ?? '');       // 'YYYY-MM-DD HH:MM' local
    $mins  = (int)($in['duration'] ?? 30);
    $notes = trim((string)($in['notes'] ?? ''));
    $inviteEmail = trim((string)($in['invite_email'] ?? ''));
    if ($inviteEmail !== '' && !filter_var($inviteEmail, FILTER_VALIDATE_EMAIL)) {
        json_out(['error' => 'that email address is not valid'], 400);
    }
    $lead  = lead_row($pdo, $id);
    if (!$lead) json_out(['error' => 'not found'], 404);

    $dt = DateTime::createFromFormat('Y-m-d H:i', $when, new DateTimeZone(cfg('timezone')));
    if (!$dt) json_out(['error' => 'bad datetime, expected Y-m-d H:i'], 400);
    $ts = $dt->getTimestamp();

    $title = ($kind === 'demo' ? 'Demo — ' : 'Call back — ') . $lead['name'];
    $uid = $kind . '-' . $id . '-' . $ts . '@weyney.com';

    $pdo->prepare("INSERT OR REPLACE INTO events
        (lead_id,kind,title,notes,starts_at,duration_min,status,created_at,updated_at,ics_uid,invite_email)
        VALUES (?,?,?,?,?,?,'scheduled',?,?,?,?)")
        ->execute([$id, $kind, $title, $notes, $ts, $mins, $now, $now, $uid,
                   $inviteEmail !== '' ? $inviteEmail : null]);

    // Learn the email if this is the first time we've been given one.
    if ($inviteEmail !== '' && $inviteEmail !== $lead['email']) {
        $pdo->prepare("UPDATE leads SET email=? WHERE id=?")->execute([$inviteEmail, $id]);
    }

    /* Scheduling implies contact was made, so it moves the lead on. A demo is
       further along than a follow-up; don't let a follow-up drag a booked lead
       backwards. */
    if ($kind === 'demo') {
        $pdo->prepare("UPDATE leads SET stage='demo_set', updated_at=? WHERE id=?")->execute([$now, $id]);
    } elseif (in_array($lead['stage'], ['new','attempting','voicemail'], true)) {
        $pdo->prepare("UPDATE leads SET stage='contacted', updated_at=? WHERE id=?")->execute([$now, $id]);
    }
    log_act($pdo, $id, 'schedule', ucfirst($kind) . ' set for ' . $dt->format('D j M, g:ia') . ($notes ? ' — ' . $notes : ''));
    touch_lead($pdo, $id);

    /* Push straight to Google. Done inline rather than by cron so a callback
       you just booked is on the phone before you hang up. A failure here must
       not lose the CRM record, so it only affects the response flag. */
    $synced = false; $syncErr = null;
    if (g_connected()) {
        $row = $pdo->prepare("SELECT e.*, l.name, l.phone, l.city FROM events e
                              JOIN leads l ON l.id = e.lead_id WHERE e.ics_uid = ?");
        $row->execute([$uid]);
        if ($ev = $row->fetch()) {
            [$synced, $info, $meet] = array_pad(g_sync_event($ev, $ev), 3, null);
            if (!$synced) $syncErr = $info;
        }
    }
    /* Trello inline too. Cron on this host needs hPanel setup, so relying on it
       would mean cards appearing minutes late or not at all. Cron stays as the
       retry net for anything that fails here. */
    $carded = false;
    if (cfg('trello_key') && cfg('trello_token')) {
        $row = $pdo->prepare("SELECT e.*, l.name, l.phone, l.city FROM events e
                              JOIN leads l ON l.id = e.lead_id WHERE e.ics_uid = ?");
        $row->execute([$uid]);
        if ($ev2 = $row->fetch()) { [$carded] = t_sync_event($ev2); }
    }

    json_out(['ok' => true, 'starts_at' => $ts, 'uid' => $uid,
              'calendar_synced' => $synced, 'calendar_error' => $syncErr,
              'trello_carded' => $carded, 'invited' => $inviteEmail !== '',
              'meet_link' => $meet ?? null]);
}

/* A note can also carry who you spoke to. Capturing the contact at the moment
   you learn it is the only time it reliably gets recorded. */
case 'note': {
    $id      = (string)($in['id'] ?? '');
    $body    = trim((string)($in['body'] ?? ''));
    $contact = trim((string)($in['contact'] ?? ''));
    $email   = trim((string)($in['email'] ?? ''));
    $phone   = trim((string)($in['phone'] ?? ''));
    $lead = lead_row($pdo, $id);
    if (!$lead) json_out(['error' => 'not found'], 404);
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        json_out(['error' => 'that email address is not valid'], 400);
    }
    if ($body === '' && $contact === '' && $email === '' && $phone === '')
        json_out(['error' => 'nothing to save'], 400);

    $changed = [];
    if ($contact !== '' && $contact !== $lead['contact']) {
        $pdo->prepare("UPDATE leads SET contact=? WHERE id=?")->execute([$contact, $id]);
        $changed[] = 'contact → ' . $contact;
    }
    if ($email !== '' && $email !== $lead['email']) {
        $pdo->prepare("UPDATE leads SET email=? WHERE id=?")->execute([$email, $id]);
        $changed[] = 'email → ' . $email;
    }
    if ($phone !== '' && $phone !== $lead['phone']) {
        $pdo->prepare("UPDATE leads SET phone=? WHERE id=?")->execute([$phone, $id]);
        $changed[] = 'phone → ' . $phone;
    }
    /* One save is one entry. A field change and the note explaining it are the
       same event — logging them separately made the phone number show up twice. */
    if ($changed) {
        log_act($pdo, $id, 'detail',
                implode(', ', $changed) . ($body !== '' ? ' — ' . $body : ''));
    } elseif ($body !== '') {
        log_act($pdo, $id, 'note', $body);
    }
    touch_lead($pdo, $id);
    json_out(['ok' => true, 'contact' => $contact ?: $lead['contact'],
              'email' => $email ?: $lead['email'], 'phone' => $phone ?: $lead['phone']]);
}

case 'complete_event': {
    $eid = (int)($in['event_id'] ?? 0);
    $ev = $pdo->prepare("SELECT lead_id, kind, status FROM events WHERE id=?");
    $ev->execute([$eid]);
    $row = $ev->fetch();
    if (!$row) json_out(['error' => 'not found'], 404);
    // Idempotent: a double-click shouldn't log the same thing twice.
    if ($row['status'] !== 'done') {
        $pdo->prepare("UPDATE events SET status='done', updated_at=? WHERE id=?")->execute([$now, $eid]);
        log_act($pdo, $row['lead_id'], 'done',
                ($row['kind'] === 'demo' ? 'Demo' : 'Callback') . ' completed');
        touch_lead($pdo, $row['lead_id']);
    }
    json_out(['ok' => true, 'lead_id' => $row['lead_id']]);
}

/* Send one email to a lead. Logged as an activity so the history shows it. */
case 'email': {
    $id      = (string)($in['id'] ?? '');
    $subject = trim((string)($in['subject'] ?? ''));
    $body    = rtrim((string)($in['body'] ?? ''));
    $lead = lead_row($pdo, $id);
    if (!$lead) json_out(['error' => 'not found'], 404);
    if (!filter_var($lead['email'], FILTER_VALIDATE_EMAIL)) {
        json_out(['error' => 'no valid email on this lead'], 400);
    }
    if ($subject === '' || $body === '') json_out(['error' => 'subject and body required'], 400);

    [$sent, $info] = send_mail($lead['email'], $subject, $body, cfg('smtp_from'));
    if (!$sent) json_out(['error' => 'send failed: ' . $info], 502);

    log_act($pdo, $id, 'email', 'Emailed ' . $lead['email'] . ' — ' . $subject);
    touch_lead($pdo, $id);
    json_out(['ok' => true, 'to' => $lead['email']]);
}

/* Dashboard numbers. One query set, computed server-side so the client stays
   a renderer. Funnel counts are CUMULATIVE — a lead that reached Demo also
   passed through Contacted, otherwise the funnel reads as if people skipped
   stages. */
case 'stats': {
    $today = strtotime('today');
    $week  = strtotime('-7 days');

    $byStage = $pdo->query("SELECT stage, COUNT(*) c FROM leads GROUP BY stage")
                   ->fetchAll(PDO::FETCH_KEY_PAIR);
    $get = function ($k) use ($byStage) { return (int)($byStage[$k] ?? 0); };
    $total = array_sum(array_map('intval', $byStage));

    // Depth reached, for cumulative funnel maths.
    $order = ['new'=>0,'attempting'=>1,'voicemail'=>1,'contacted'=>2,
              'demo_set'=>3,'demo_noshow'=>3,'demo_done'=>4,'proposal'=>5,
              'won'=>6,'nurture'=>2,'lost'=>-1];
    $atLeast = function (int $depth) use ($byStage, $order) {
        $n = 0;
        foreach ($byStage as $st => $c) {
            if (($order[$st] ?? 0) >= $depth) $n += (int)$c;
        }
        return $n;
    };

    $calls      = (int)$pdo->query("SELECT COUNT(*) FROM activities WHERE type='call'")->fetchColumn();
    $callsToday = (int)$pdo->prepare("SELECT COUNT(*) FROM activities WHERE type='call' AND ts>=?")
                           ->execute([$today]) ?: 0;
    $st = $pdo->prepare("SELECT COUNT(*) FROM activities WHERE type='call' AND ts>=?");
    $st->execute([$today]);  $callsToday = (int)$st->fetchColumn();
    $st->execute([$week]);   $callsWeek  = (int)$st->fetchColumn();

    $worked = $total - $get('new');
    $reached = $atLeast(2);

    $funnel = [
        ['label' => 'All leads',    'n' => $total],
        ['label' => 'Attempted',    'n' => $worked],
        ['label' => 'Contacted',    'n' => $reached],
        ['label' => 'Demo booked',  'n' => $atLeast(3)],
        ['label' => 'Demo held',    'n' => $atLeast(4)],
        ['label' => 'Won',          'n' => $get('won')],
    ];

    /* What actually happens when you dial. This is the number that tells you
       whether the list or the pitch is the problem — city counts never did. */
    $outRows = $pdo->query("SELECT body FROM activities WHERE type='call'")->fetchAll(PDO::FETCH_COLUMN);
    $buckets = ['Reached someone'=>0, 'Voicemail'=>0, 'No pick-up'=>0];
    foreach ($outRows as $b) {
        if (stripos($b, 'voicemail') !== false)       $buckets['Voicemail']++;
        elseif (stripos($b, 'no pickup') !== false
             || stripos($b, 'no answer') !== false)   $buckets['No pick-up']++;
        else                                          $buckets['Reached someone']++;
    }
    $outcomes = [];
    foreach ($buckets as $k => $v) $outcomes[] = ['k' => $k, 'c' => $v];

    /* How many dials it takes. Tells you when to stop chasing a number. */
    $att = $pdo->query("SELECT attempts a, COUNT(*) c FROM leads
                        WHERE attempts > 0 GROUP BY attempts ORDER BY attempts")->fetchAll();

    /* Dials per day for the last fortnight, against the 100/day target. This is
       the one that answers "am I actually doing the work". */
    $daily = [];
    $q = $pdo->prepare("SELECT COUNT(*) FROM activities WHERE type='call' AND ts>=? AND ts<?");
    for ($i = 13; $i >= 0; $i--) {
        $s0 = strtotime("-$i days", strtotime('today'));
        $q->execute([$s0, $s0 + 86400]);
        $daily[] = ['d' => date('D j', $s0), 'c' => (int)$q->fetchColumn(),
                    'today' => $i === 0];
    }

    /* Connect rate by hour — when is it worth picking up the phone. */
    $hours = [];
    $hr = $pdo->query("SELECT strftime('%H', ts, 'unixepoch', 'localtime') h,
                              COUNT(*) total,
                              SUM(CASE WHEN body LIKE '%no pickup%' OR body LIKE '%no answer%'
                                        OR body LIKE '%voicemail%' THEN 0 ELSE 1 END) reached
                       FROM activities WHERE type='call' GROUP BY h ORDER BY h")->fetchAll();
    foreach ($hr as $r) {
        $hours[] = ['h' => (int)$r['h'], 'total' => (int)$r['total'],
                    'rate' => $r['total'] > 0 ? round($r['reached'] / $r['total'] * 100) : 0];
    }

    $upcoming = (int)$pdo->prepare("SELECT COUNT(*) FROM events WHERE status='scheduled'")
                         ->execute() ?: 0;
    $u = $pdo->query("SELECT COUNT(*) FROM events WHERE status='scheduled'"); $upcoming = (int)$u->fetchColumn();

    json_out([
        'total'      => $total,
        'worked'     => $worked,
        'untouched'  => $get('new'),
        'reached'    => $reached,
        'demos'      => $atLeast(3),
        'won'        => $get('won'),
        'lost'       => $get('lost'),
        'calls'      => $calls,
        'calls_today'=> $callsToday,
        'calls_week' => $callsWeek,
        'scheduled'  => $upcoming,
        'connect_rate' => $worked > 0 ? round($reached / $worked * 100) : 0,
        'demo_rate'    => $reached > 0 ? round($atLeast(3) / $reached * 100) : 0,
        'funnel'     => $funnel,
        'outcomes'   => $outcomes,
        'attempts'   => $att,
        'stale'      => $pdo->query("
             SELECT l.name, l.phone, l.stage,
                    (SELECT MAX(ts) FROM activities a WHERE a.lead_id=l.id) last_ts
             FROM leads l
             WHERE l.stage IN ('contacted','demo_set','demo_noshow','demo_done','proposal','nurture')
             ORDER BY last_ts ASC LIMIT 6")->fetchAll(),
        'today_calls' => $pdo->query("
             SELECT e.id, e.starts_at, e.kind, e.meet_link, l.name, l.phone, l.id lead_id
             FROM events e JOIN leads l ON l.id = e.lead_id
             WHERE e.status='scheduled' AND e.starts_at < " . strtotime('tomorrow') . "
             ORDER BY e.starts_at ASC")->fetchAll(),
        /* Future demos, so their Meet link is always one click away on the
           dashboard — not buried in the calendar. */
        'upcoming_demos' => $pdo->query("
             SELECT e.id, e.starts_at, e.meet_link, l.name, l.id lead_id
             FROM events e JOIN leads l ON l.id = e.lead_id
             WHERE e.status='scheduled' AND e.kind='demo' AND e.starts_at >= " . strtotime('tomorrow') . "
             ORDER BY e.starts_at ASC LIMIT 12")->fetchAll(),
        'daily'      => $daily,
        'by_hour'    => $hours,
        'target'     => 100,
    ]);
}

/* Anyone worth treating as a relationship rather than a queue entry. */
/* Everything scheduled, for the calendar view. Grouped client-side by day. */
case 'calendar': {
    $from = (int)($in['from'] ?? strtotime('today'));
    $rows = $pdo->prepare("
        SELECT e.id, e.starts_at, e.duration_min, e.kind, e.notes, e.status,
               e.gcal_event_id, e.meet_link, l.id lead_id, l.name, l.phone, l.city
        FROM events e JOIN leads l ON l.id = e.lead_id
        WHERE e.starts_at >= ? ORDER BY e.starts_at ASC LIMIT 200");
    $rows->execute([$from - 30 * 86400]);
    json_out(['events' => $rows->fetchAll()]);
}

case 'clients': {
    $rows = $pdo->query("
        SELECT l.*, (SELECT COUNT(*) FROM activities a WHERE a.lead_id=l.id) acts,
               (SELECT MAX(ts) FROM activities a WHERE a.lead_id=l.id) last_ts,
               (SELECT MIN(starts_at) FROM events e WHERE e.lead_id=l.id AND e.status='scheduled') next_at
        FROM leads l
        WHERE l.stage IN ('contacted','demo_set','demo_noshow','demo_done','proposal','won','nurture')
        ORDER BY CASE l.stage WHEN 'won' THEN 0 WHEN 'proposal' THEN 1 WHEN 'demo_done' THEN 2
                              WHEN 'demo_set' THEN 3 ELSE 4 END, l.updated_at DESC")->fetchAll();
    json_out(['clients' => $rows]);
}

/* Add a lead by hand. The import covers the cold list; inbound and referrals
   arrive some other way and still need to live here. */
case 'new_lead': {
    $name = trim((string)($in['name'] ?? ''));
    if ($name === '') json_out(['error' => 'a business name is required'], 400);
    $email = trim((string)($in['email'] ?? ''));
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        json_out(['error' => 'that email address is not valid'], 400);
    }

    // Don't silently create a duplicate of someone already on the list.
    $dupe = $pdo->prepare("SELECT id,name,stage FROM leads WHERE lower(name)=lower(?) LIMIT 1");
    $dupe->execute([$name]);
    if ($existing = $dupe->fetch()) {
        json_out(['error' => 'already exists', 'lead' => $existing], 409);
    }

    $site = preg_replace('~^https?://~i', '', rtrim(trim((string)($in['website'] ?? '')), '/. '));

    $id = 'l_' . substr(bin2hex(random_bytes(6)), 0, 8);
    $pdo->prepare("INSERT INTO leads
        (id,name,phone,email,contact,service,city,address,stage,reason,next_action,
         owner,value,attempts,vm_count,seq,last_call_at,first_vm_at,called_back_at,
         passed_at,created_at,updated_at,website,socials)
        VALUES (?,?,?,?,?,?,?,?,?,?,'','',0,0,0,0,0,0,0,0,?,?,?,?)")
        ->execute([$id, $name,
                   trim((string)($in['phone'] ?? '')), $email,
                   trim((string)($in['contact'] ?? '')),
                   trim((string)($in['service'] ?? '')),
                   trim((string)($in['city'] ?? '')),
                   trim((string)($in['address'] ?? '')),
                   (string)($in['stage'] ?? 'new'),
                   trim((string)($in['reason'] ?? '')),
                   $now, $now, $site,
                   trim((string)($in['socials'] ?? ''))]);

    $src = trim((string)($in['source'] ?? ''));
    log_act($pdo, $id, 'note', 'Added manually' . ($src !== '' ? ' — ' . $src : ''));
    json_out(['ok' => true, 'id' => $id]);
}

/* Move a lead along the pipeline by hand. Every move is logged, so the history
   shows who decided what and when — not just the current state. */
/* The business profile — pain points and where we can help. Separate from the
   activity log on purpose. */
case 'profile': {
    $id = (string)($in['id'] ?? '');
    if (!lead_row($pdo, $id)) json_out(['error' => 'not found'], 404);
    $site = preg_replace('~^https?://~i', '', rtrim(trim((string)($in['website'] ?? '')), '/. '));
    $pdo->prepare("UPDATE leads SET pain_points=?, opportunity=?, website=?, socials=?, updated_at=? WHERE id=?")
        ->execute([trim((string)($in['pain_points'] ?? '')),
                   trim((string)($in['opportunity'] ?? '')),
                   $site, trim((string)($in['socials'] ?? '')), $now, $id]);
    json_out(['ok' => true]);
}

case 'set_stage': {
    $id    = (string)($in['id'] ?? '');
    $stage = (string)($in['stage'] ?? '');
    $lead  = lead_row($pdo, $id);
    if (!$lead) json_out(['error' => 'not found'], 404);
    if (!isset(STAGES[$stage])) json_out(['error' => 'unknown stage'], 400);
    if ($stage === $lead['stage']) json_out(['ok' => true, 'stage' => $stage]);

    $pdo->prepare("UPDATE leads SET stage=?, updated_at=? WHERE id=?")->execute([$stage, $now, $id]);
    log_act($pdo, $id, 'stage',
        'Moved ' . STAGES[$lead['stage']]['label'] . ' → ' . STAGES[$stage]['label']);
    json_out(['ok' => true, 'stage' => $stage]);
}

case 'search': {
    $q = '%' . trim((string)($in['q'] ?? '')) . '%';
    $s = $pdo->prepare("SELECT id,name,phone,city,stage FROM leads
                        WHERE name LIKE ? OR phone LIKE ? OR city LIKE ? ORDER BY name LIMIT 40");
    $s->execute([$q, $q, $q]);
    json_out(['leads' => $s->fetchAll()]);
}

default:
    json_out(['error' => 'unknown action'], 404);
}
