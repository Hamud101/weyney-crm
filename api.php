<?php
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/google.php';
require_once __DIR__ . '/lib/mailer.php';
require_once __DIR__ . '/lib/templates.php';
require_once __DIR__ . '/lib/documents.php';
require_once __DIR__ . '/lib/proposal.php';
require_once __DIR__ . '/lib/trello.php';
require_auth();

$a = $_GET['a'] ?? '';
$isWrite = $_SERVER['REQUEST_METHOD'] === 'POST';
if ($isWrite) csrf_check();

/* Every write is a JSON body except a file upload, which has to be multipart —
 * php://input is empty once PHP has parsed the parts, so read $_POST there. */
$isUpload = $isWrite && str_starts_with($_SERVER['CONTENT_TYPE'] ?? '', 'multipart/form-data');
$in = $isUpload ? $_POST
    : ($isWrite ? (json_decode(file_get_contents('php://input'), true) ?: []) : $_GET);
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
 * One scheduled thing, inserted and pushed to the calendar and Trello.
 * Extracted from schedule() so audit_sent() and set_stage() create their
 * reminders through exactly the same path, with the same sync bookkeeping.
 */
function create_event(PDO $pdo, array $lead, string $kind, int $ts, string $title, string $notes,
                      int $mins = 15, string $inviteEmail = ''): array {
    $id  = $lead['id'];
    $now = time();
    $uid = $kind . '-' . $id . '-' . $ts . '@weyney.com';

    $pdo->prepare("INSERT OR REPLACE INTO events
        (lead_id,kind,title,notes,starts_at,duration_min,status,created_at,updated_at,ics_uid,invite_email)
        VALUES (?,?,?,?,?,?,'scheduled',?,?,?,?)")
        ->execute([$id, $kind, $title, $notes, $ts, $mins, $now, $now, $uid,
                   $inviteEmail !== '' ? $inviteEmail : null]);

    // Learn the email if this is the first time we've been given one.
    if ($inviteEmail !== '' && $inviteEmail !== ($lead['email'] ?? '')) {
        $pdo->prepare("UPDATE leads SET email=? WHERE id=?")->execute([$inviteEmail, $id]);
    }

    $row = $pdo->prepare("SELECT e.*, l.name, l.phone, l.city FROM events e
                          JOIN leads l ON l.id = e.lead_id WHERE e.ics_uid = ?");
    $row->execute([$uid]);
    $ev = $row->fetch();

    $synced = false; $syncErr = null; $meet = null; $carded = false;
    if ($ev) {
        // Push straight to Google, inline so a booking is on the phone at once.
        if (g_connected()) {
            [$synced, $info, $meet] = array_pad(g_sync_event($ev, $ev), 3, null);
            if (!$synced) $syncErr = $info;
        }
        // Trello inline too; cron stays as the retry net.
        if (cfg('trello_key') && cfg('trello_token')) { [$carded] = t_sync_event($ev); }
    }

    return ['uid' => $uid, 'synced' => $synced, 'syncErr' => $syncErr,
            'meet' => $meet, 'carded' => $carded];
}

/** A weekday or two out, at 10:00 local. Used for the audit follow ups. */
function business_days_later(int $days): int {
    $d = new DateTime('now', new DateTimeZone(cfg('timezone')));
    $d->setTime(10, 0, 0);
    $added = 0;
    while ($added < $days) {
        $d->modify('+1 day');
        if ((int)$d->format('N') <= 5) $added++;
    }
    return $d->getTimestamp();
}

/** Some days out, at 10:00 local. Used for the events a win creates. */
function days_later_at_ten(int $days): int {
    $d = new DateTime('now', new DateTimeZone(cfg('timezone')));
    $d->setTime(10, 0, 0);
    $d->modify('+' . $days . ' days');
    return $d->getTimestamp();
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

    $indCounts = $pdo->query("SELECT industry, COUNT(*) c FROM leads GROUP BY industry")
                     ->fetchAll(PDO::FETCH_KEY_PAIR);
    /* Known buckets first in their fixed order, then anything new the leads
       brought with them, alphabetically, and Other last. */
    $industries = [];
    foreach (INDUSTRIES as $k => $label)
        if ($k !== 'other') $industries[$k] = ['label' => $label, 'count' => (int)($indCounts[$k] ?? 0)];
    $extra = array_filter(array_keys($indCounts), function ($k) {
        return $k !== '' && $k !== 'other' && !isset(INDUSTRIES[$k]);
    });
    sort($extra);
    foreach ($extra as $k)
        $industries[$k] = ['label' => industry_label((string)$k), 'count' => (int)$indCounts[$k]];
    $industries['other'] = ['label' => INDUSTRIES['other'],
                            'count' => (int)($indCounts['other'] ?? 0) + (int)($indCounts[''] ?? 0)];

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
        'industries' => $industries,
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
    $industry = industry_key((string)($in['industry'] ?? ''));
    /* The page size is an implementation detail; the header must show how many
       leads are actually in the stage or "1 of 50" reads as a cap on the list. */
    if ($industry !== '') {
        $cnt = $pdo->prepare("SELECT COUNT(*) FROM leads WHERE stage = ? AND industry = ?");
        $cnt->execute([$stage, $industry]);
    } else {
        $cnt = $pdo->prepare("SELECT COUNT(*) FROM leads WHERE stage = ?");
        $cnt->execute([$stage]);
    }
    $stageTotal = (int)$cnt->fetchColumn();

    if ($industry !== '') {
        $s = $pdo->prepare("
            SELECT * FROM leads WHERE stage = ? AND industry = ?
            ORDER BY (last_call_at = 0) DESC, last_call_at ASC, seq ASC LIMIT 200");
        $s->execute([$stage, $industry]);
    } else {
        $s = $pdo->prepare("
            SELECT * FROM leads WHERE stage = ?
            ORDER BY (last_call_at = 0) DESC, last_call_at ASC, seq ASC LIMIT 200");
        $s->execute([$stage]);
    }
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

    // Per-industry counts for this stage only, so the filter bar matches the list.
    $ic = $pdo->prepare("SELECT industry, COUNT(*) FROM leads WHERE stage=? GROUP BY industry");
    $ic->execute([$stage]);
    $industryCounts = [];
    foreach ($ic->fetchAll(PDO::FETCH_KEY_PAIR) as $k => $v) $industryCounts[$k] = (int)$v;

    // And per-stage counts for this industry, so the stage pills match the filter.
    $stageCounts = [];
    if ($industry !== '') {
        $sc = $pdo->prepare("SELECT stage, COUNT(*) FROM leads WHERE industry=? GROUP BY stage");
        $sc->execute([$industry]);
        foreach ($sc->fetchAll(PDO::FETCH_KEY_PAIR) as $k => $v) $stageCounts[$k] = (int)$v;
    }

    json_out(['leads' => $rows, 'next_up' => $up->fetchAll(), 'stage_total' => $stageTotal,
              'industry_counts' => $industryCounts, 'stage_counts' => $stageCounts]);
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
    $l['documents'] = doc_list($pdo, $l['id']);
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

    $tc = $pdo->prepare("SELECT COUNT(*) FROM activities WHERE lead_id=?");
    $tc->execute([$id]);

    json_out(['ok' => true, 'stage' => $stage, 'touches' => (int)$tc->fetchColumn()]);
}
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
    $res = create_event($pdo, $lead, $kind, $ts, $title, $notes, $mins, $inviteEmail);
    $uid = $res['uid'];

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

    json_out(['ok' => true, 'starts_at' => $ts, 'uid' => $uid,
              'calendar_synced' => $res['synced'], 'calendar_error' => $res['syncErr'],
              'trello_carded' => $res['carded'], 'invited' => $inviteEmail !== '',
              'meet_link' => $res['meet'] ?? null]);
}

/* Set the flags the sales rules hang off. Only the keys sent are touched, and
   every real change logs one line so the history shows when it changed. */
case 'set_flags': {
    $id   = (string)($in['id'] ?? '');
    $lead = lead_row($pdo, $id);
    if (!$lead) json_out(['error' => 'not found'], 404);

    $sets = []; $vals = []; $changed = 0;
    if (array_key_exists('may_text', $in)) {
        $v = !empty($in['may_text']) ? 1 : 0;
        if ($v !== (int)$lead['may_text']) {
            $sets[] = 'may_text=?'; $vals[] = $v; $changed++;
            log_act($pdo, $id, 'detail',
                $v ? 'Texting allowed: they replied first' : 'Texting off');
        }
    }
    if (array_key_exists('proof_ok', $in)) {
        $v = !empty($in['proof_ok']) ? 1 : 0;
        if ($v !== (int)$lead['proof_ok']) {
            $sets[] = 'proof_ok=?'; $vals[] = $v; $changed++;
            log_act($pdo, $id, 'detail',
                $v ? 'Permission to describe the work: yes' : 'Permission to describe the work: no');
        }
    }
    if (array_key_exists('baseline', $in)) {
        $v = trim((string)$in['baseline']);
        if ($v !== (string)$lead['baseline']) {
            $sets[] = 'baseline=?'; $vals[] = $v; $changed++;
            log_act($pdo, $id, 'detail', 'Baseline recorded');
        }
    }
    if ($sets) {
        $sets[] = 'updated_at=?'; $vals[] = $now; $vals[] = $id;
        $pdo->prepare("UPDATE leads SET " . implode(',', $sets) . " WHERE id=?")->execute($vals);
        touch_lead($pdo, $id);
    }
    json_out(['ok' => true, 'changed' => $changed]);
}

/* The reply speed test: we submit the contact form under our own name, then
   record when a human answers it. The gap is the number the plan cares about. */
case 'form_test': {
    $id   = (string)($in['id'] ?? '');
    $step = (string)($in['step'] ?? '');
    $lead = lead_row($pdo, $id);
    if (!$lead) json_out(['error' => 'not found'], 404);

    if ($step === 'sent') {
        $pdo->prepare("UPDATE leads SET form_test_at=?, updated_at=? WHERE id=?")
            ->execute([$now, $now, $id]);
        log_act($pdo, $id, 'detail', 'Form test sent under our name');
        touch_lead($pdo, $id);
        json_out(['ok' => true, 'form_test_at' => $now]);
    }
    if ($step === 'replied') {
        if ((int)$lead['form_test_at'] === 0) {
            json_out(['error' => 'send the form test first'], 400);
        }
        $pdo->prepare("UPDATE leads SET form_reply_at=?, updated_at=? WHERE id=?")
            ->execute([$now, $now, $id]);
        $mins = (int)floor(max(0, $now - (int)$lead['form_test_at']) / 60);
        $h = intdiv($mins, 60); $m = $mins % 60;
        log_act($pdo, $id, 'detail',
            'Form test answered after ' . $h . ' hours ' . $m . ' minutes');
        touch_lead($pdo, $id);
        json_out(['ok' => true, 'form_reply_at' => $now, 'hours' => $h, 'minutes' => $m]);
    }
    json_out(['error' => 'unknown step'], 400);
}

/* The one page audit went out. That starts two reminders: two business days
   later to ask if it landed, and a week later to send one new fact. Both are
   notes for Hamud, never an automated message. */
case 'audit_sent': {
    $id   = (string)($in['id'] ?? '');
    $lead = lead_row($pdo, $id);
    if (!$lead) json_out(['error' => 'not found'], 404);

    $pdo->prepare("UPDATE leads SET audit_sent_at=?, updated_at=? WHERE id=?")
        ->execute([$now, $now, $id]);
    log_act($pdo, $id, 'note', 'One page audit sent');

    create_event($pdo, $lead, 'followup', business_days_later(2),
        'Call: did the audit land? - ' . $lead['name'],
        'Two days after the audit. Ask what they thought of the two facts.');
    create_event($pdo, $lead, 'followup', days_later_at_ten(7),
        'One new fact - ' . $lead['name'],
        'Text one new fact from their listing ONLY if texting is allowed (they replied). Otherwise email it.');

    // Sending the audit is contact, so it moves the lead forward like a schedule does.
    if (in_array($lead['stage'], ['new','attempting','voicemail'], true)) {
        $pdo->prepare("UPDATE leads SET stage='contacted', updated_at=? WHERE id=?")
            ->execute([$now, $id]);
    }
    touch_lead($pdo, $id);
    json_out(['ok' => true, 'audit_sent_at' => $now,
              'stage' => in_array($lead['stage'], ['new','attempting','voicemail'], true)
                         ? 'contacted' : $lead['stage']]);
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

/* One event's full record, joined to its lead — what the edit sheet loads. */
case 'event': {
    $eid = (int)($in['id'] ?? 0);
    $s = $pdo->prepare("SELECT e.*, l.name, l.email, l.phone, l.city
                        FROM events e JOIN leads l ON l.id = e.lead_id WHERE e.id = ?");
    $s->execute([$eid]);
    $row = $s->fetch();
    if (!$row) json_out(['error' => 'not found'], 404);
    json_out($row);
}

/* Edit a booked event in place. Because the row keeps its id, ics_uid and the
   remote ids, the calendar entry and Trello card MOVE instead of duplicating,
   and a demo keeps its Meet link — the attendee just gets an updated invite. */
case 'update_event': {
    $eid   = (int)($in['event_id'] ?? 0);
    $when  = (string)($in['when'] ?? '');
    $notes = trim((string)($in['notes'] ?? ''));
    $inviteEmail = trim((string)($in['invite_email'] ?? ''));
    // Default to notifying, so an unaware caller never sends silently by accident.
    $notify = array_key_exists('notify', $in) ? (bool)$in['notify'] : true;
    if ($inviteEmail !== '' && !filter_var($inviteEmail, FILTER_VALIDATE_EMAIL)) {
        json_out(['error' => 'that email address is not valid'], 400);
    }
    $s = $pdo->prepare("SELECT * FROM events WHERE id = ?");
    $s->execute([$eid]);
    $ev = $s->fetch();
    if (!$ev) json_out(['error' => 'not found'], 404);
    if ($ev['status'] !== 'scheduled') json_out(['error' => 'this event is no longer scheduled'], 400);

    $dt = DateTime::createFromFormat('Y-m-d H:i', $when, new DateTimeZone(cfg('timezone')));
    if (!$dt) json_out(['error' => 'bad datetime, expected Y-m-d H:i'], 400);
    $ts = $dt->getTimestamp();

    $pdo->prepare("UPDATE events SET starts_at=?, notes=?, invite_email=?, updated_at=? WHERE id=?")
        ->execute([$ts, $notes, $inviteEmail !== '' ? $inviteEmail : null, $now, $eid]);

    $lead = lead_row($pdo, $ev['lead_id']);
    if ($inviteEmail !== '' && $lead && $inviteEmail !== $lead['email']) {
        $pdo->prepare("UPDATE leads SET email=? WHERE id=?")->execute([$inviteEmail, $ev['lead_id']]);
    }
    log_act($pdo, $ev['lead_id'], 'schedule',
            ucfirst($ev['kind']) . ' moved to ' . $dt->format('D j M, g:ia') . ($notes ? ' — ' . $notes : ''));
    touch_lead($pdo, $ev['lead_id']);

    // Re-push to Google and Trello — both PATCH/PUT the existing entries.
    $row = $pdo->prepare("SELECT e.*, l.name, l.phone, l.city FROM events e
                          JOIN leads l ON l.id = e.lead_id WHERE e.id = ?");
    $row->execute([$eid]);
    $full = $row->fetch();

    $synced = false; $syncErr = null; $meet = $full['meet_link'] ?? null;
    if (g_connected()) {
        [$synced, $info, $m] = array_pad(g_sync_event($full, $full, $notify), 3, null);
        if ($synced) { $meet = $m ?? $meet; } else { $syncErr = $info; }
    }
    $carded = false;
    if (cfg('trello_key') && cfg('trello_token')) { [$carded] = t_sync_event($full); }

    json_out(['ok' => true, 'starts_at' => $ts,
              'calendar_synced' => $synced, 'calendar_error' => $syncErr,
              'trello_carded' => $carded, 'invited' => $inviteEmail !== '' && $notify,
              'meet_link' => $meet]);
}

/* Cancel a booked event: pull it off Google (attendees get a cancellation) and
   archive its Trello card. The row stays as a 'cancelled' record. */
case 'cancel_event': {
    $eid = (int)($in['event_id'] ?? 0);
    $s = $pdo->prepare("SELECT * FROM events WHERE id = ?");
    $s->execute([$eid]);
    $ev = $s->fetch();
    if (!$ev) json_out(['error' => 'not found'], 404);
    if ($ev['status'] !== 'scheduled') json_out(['ok' => true, 'lead_id' => $ev['lead_id']]);

    if (g_connected() && !empty($ev['gcal_event_id']))       g_delete_event($ev['gcal_event_id']);
    if (cfg('trello_key') && cfg('trello_token') && !empty($ev['trello_card_id']))
        t_cancel_event($ev['trello_card_id']);

    $pdo->prepare("UPDATE events SET status='cancelled', updated_at=? WHERE id=?")->execute([$now, $eid]);
    log_act($pdo, $ev['lead_id'], 'cancel', ucfirst($ev['kind']) . ' cancelled');
    touch_lead($pdo, $ev['lead_id']);
    json_out(['ok' => true, 'lead_id' => $ev['lead_id']]);
}

/* The email templates, with placeholders already filled from this lead so the
   sheet can drop one straight into the fields. */
case 'templates': {
    $lead = lead_row($pdo, (string)($in['id'] ?? ''));
    if (!$lead) json_out(['error' => 'not found'], 404);
    json_out(['templates' => tpl_render_all($lead)]);
}

/* Send one email to a lead. Logged as an activity so the history shows it.
   A template id only decides what gets attached — the subject and body are
   whatever the sender ended up with after editing. */
case 'email': {
    $id      = (string)($in['id'] ?? '');
    $subject = trim((string)($in['subject'] ?? ''));
    $body    = rtrim((string)($in['body'] ?? ''));
    $tplId   = trim((string)($in['template'] ?? ''));
    $lead = lead_row($pdo, $id);
    if (!$lead) json_out(['error' => 'not found'], 404);
    if (!filter_var($lead['email'], FILTER_VALIDATE_EMAIL)) {
        json_out(['error' => 'no valid email on this lead'], 400);
    }
    if ($subject === '' || $body === '') json_out(['error' => 'subject and body required'], 400);

    /* Two kinds of attachment, and never both. A template is generic collateral
       resolved from the registry; a document belongs to this lead and is
       resolved by id scoped to this lead, so neither can name a path from the
       outside and neither can reach another client's file. */
    $docId = trim((string)($in['document'] ?? ''));
    if ($tplId !== '' && $docId !== '') {
        json_out(['error' => 'attach a template or a document, not both'], 400);
    }
    $attach = [];
    if ($tplId !== '') {
        $attach = tpl_attachments($tplId);
        if (!$attach) json_out(['error' => 'unknown template'], 400);
    } elseif ($docId !== '') {
        $attach = doc_attachments($pdo, $docId, $id);
        if (!$attach) json_out(['error' => 'that document is not on this lead'], 400);
    }

    [$sent, $info, , $filed] = send_mail($lead['email'], $subject, $body, cfg('smtp_from'), $attach);
    if (!$sent) json_out(['error' => 'send failed: ' . $info], 502);

    $note = 'Emailed ' . $lead['email'] . ' — ' . $subject;
    if ($attach) $note .= ' (attached ' . $attach[0]['name'] . ')';
    log_act($pdo, $id, 'email', $note);
    // A document's whole point is knowing when it went out and to whom.
    if ($docId !== '') {
        $pdo->prepare("UPDATE documents SET sent_at=? WHERE id=?")->execute([$now, $docId]);
    }
    touch_lead($pdo, $id);
    /* 'filed' is not success or failure — the email is sent either way. It tells
       the sender whether the Sent folder will show it, so a false makes them
       trust this log rather than a mailbox that is about to look empty. */
    json_out(['ok' => true, 'to' => $lead['email'],
              'attached' => $attach ? $attach[0]['name'] : null,
              'filed' => (bool)$filed]);
}

/* The agreed package for one lead. Written whole — the sheet always sends the
   complete selection, so there is no partial-update case to get wrong. */
case 'save_proposal': {
    $id = (string)($in['id'] ?? '');
    if (!lead_row($pdo, $id)) json_out(['error' => 'not found'], 404);

    $num = function ($v) { return max(0, (int)round((float)$v)); };
    $p = $in['proposal'] ?? [];

    /* One line per ticked service. The label and description are stored rather
       than looked up: the catalogue will be edited over time, and a signed
       agreement has to keep saying what it said the day it was sent. */
    $lines = [];
    foreach ((array)($p['lines'] ?? []) as $l) {
        $label = trim((string)($l['label'] ?? ''));
        if ($label === '') continue;
        $lines[] = [
            'id'     => preg_replace('/[^a-z0-9_-]/', '', (string)($l['id'] ?? '')),
            'label'  => mb_substr($label, 0, 80),
            'amount' => $num($l['amount'] ?? 0),
            'kind'   => ($l['kind'] ?? 'once') === 'monthly' ? 'monthly' : 'once',
            'desc'   => mb_substr(trim((string)($l['desc'] ?? $label)), 0, 400),
        ];
    }

    $date = (string)($p['date'] ?? '');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) $date = date('Y-m-d');

    $clean = [
        'lines'       => $lines,
        'term_months' => max(1, min(60, (int)($p['term_months'] ?? 12))),
        'prepaid'     => !empty($p['prepaid']),
        'change_fee'  => $num($p['change_fee'] ?? 50),
        'date'        => $date,
        'notes'       => mb_substr(trim((string)($p['notes'] ?? '')), 0, 2000),
        'updated_at'  => $now,
    ];

    $pdo->prepare("UPDATE leads SET proposal=?, updated_at=? WHERE id=?")
        ->execute([json_encode($clean, JSON_UNESCAPED_SLASHES), $now, $id]);

    // Same arithmetic as the sheet, so the figure logged is the figure shown.
    $total = 0;
    foreach ($lines as $l) {
        $total += $l['kind'] === 'monthly'
            ? ($clean['prepaid'] ? $l['amount'] * $clean['term_months'] : $l['amount'])
            : $l['amount'];
    }

    log_act($pdo, $id, 'note', 'Proposal set — ' .
        (count($lines) ? implode(', ', array_column($lines, 'label')) : 'nothing selected') .
        ' — due on signing $' . number_format($total));

    json_out(['ok' => true, 'proposal' => $clean, 'total' => $total]);
}

/* Build the signable agreement from the saved selection and put it on the lead.
   Everything the operator chose is baked in; the only fillable fields left are
   the three the client needs to sign. */
case 'generate_proposal': {
    $id = (string)($in['id'] ?? '');
    $lead = lead_row($pdo, $id);
    if (!$lead) json_out(['error' => 'not found'], 404);

    $p = json_decode((string)$lead['proposal'], true);
    if (!is_array($p) || !($p['lines'] ?? [])) {
        json_out(['error' => 'set the packages first'], 400);
    }

    /* Queued, not rendered here. Chrome cannot start inside a LiteSpeed request
       on this host — see the schema v8 note — so bin/render-worker.php does it
       from cron, where the address-space limit does not apply. */
    $job = prop_enqueue($pdo, $id);
    json_out(['ok' => true, 'job' => $job]);
}

/* Poll for a queued render. The sheet asks every couple of seconds until the
   job is done or failed. */
case 'proposal_job': {
    $id = (string)($in['id'] ?? '');
    if (!lead_row($pdo, $id)) json_out(['error' => 'not found'], 404);
    $job = prop_latest_job($pdo, $id);
    $doc = ($job && $job['status'] === 'done' && $job['doc_id'])
         ? doc_get($pdo, $job['doc_id']) : null;
    json_out(['job' => $job, 'document' => $doc,
              'documents' => doc_list($pdo, $id)]);
}

/* Documents held against one lead. The list rides along with the lead record
   too, but the email sheet asks for it directly after an upload. */
case 'docs': {
    $id = (string)($in['id'] ?? '');
    if (!lead_row($pdo, $id)) json_out(['error' => 'not found'], 404);
    json_out(['documents' => doc_list($pdo, $id)]);
}

case 'upload_doc': {
    $id = (string)($in['id'] ?? '');
    if (!lead_row($pdo, $id)) json_out(['error' => 'not found'], 404);
    if (empty($_FILES['file'])) json_out(['error' => 'no file received'], 400);

    [$doc, $err] = doc_store($pdo, $id, $_FILES['file']);
    if ($err) json_out(['error' => $err], 400);

    log_act($pdo, $id, 'note', 'Document added — ' . $doc['name']);
    touch_lead($pdo, $id);
    json_out(['ok' => true, 'document' => $doc, 'documents' => doc_list($pdo, $id)]);
}

case 'delete_doc': {
    $docId = (string)($in['document'] ?? '');
    $doc = doc_get($pdo, $docId);
    if (!$doc) json_out(['error' => 'not found'], 404);
    doc_delete($pdo, $doc);
    log_act($pdo, $doc['lead_id'], 'note', 'Document removed — ' . $doc['name']);
    json_out(['ok' => true, 'documents' => doc_list($pdo, $doc['lead_id'])]);
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

    /* Free audit leads from weyney.com. Someone who started the audit and has
       not been called yet is the hottest thing on this page, so rank them. */
    $auditRows = $pdo->query("
        SELECT l.id, l.name, l.contact, l.phone, l.city, l.stage,
               l.pain_points, l.opportunity, l.next_action, l.created_at,
               COALESCE((SELECT MIN(a.ts) FROM activities a
                          WHERE a.lead_id=l.id AND a.body LIKE 'Free audit started at%'),
                        l.created_at) AS started_ts,
               (SELECT MAX(a.ts) FROM activities a
                 WHERE a.lead_id=l.id AND a.body LIKE 'Free audit answers from the website%') AS answered_ts,
               (SELECT MAX(a.ts) FROM activities a
                 WHERE a.lead_id=l.id AND a.type='call') AS last_call_ts,
               (SELECT COUNT(*) FROM activities a
                 WHERE a.lead_id=l.id AND a.type='call'
                   AND a.ts >= COALESCE((SELECT MIN(b.ts) FROM activities b
                          WHERE b.lead_id=l.id AND b.body LIKE 'Free audit started at%'),
                        l.created_at)) AS calls_since
        FROM leads l
        WHERE l.stage NOT IN ('won','lost')
          AND (l.reason LIKE 'Website: free audit%'
            OR l.next_action LIKE 'Call now: audit%'
            OR EXISTS (SELECT 1 FROM activities a WHERE a.lead_id=l.id
                        AND (a.body LIKE 'Free audit started at%'
                          OR a.body LIKE 'Free audit answers from the website%')))
    ")->fetchAll();

    /* Pull one labelled answer out of the "Label: value" lines the form writes.
       Search both answer fields so the source of each label never matters. */
    $pick = function ($text, $label) {
        foreach (preg_split('/\r\n|\r|\n/', (string)$text) as $line) {
            if (strpos($line, $label) === 0) return trim(substr($line, strlen($label)));
        }
        return '';
    };

    $nowA = time();
    $auditAll = [];
    foreach ($auditRows as $r) {
        $started  = (int)$r['started_ts'];
        $answered = $r['answered_ts'] !== null ? (int)$r['answered_ts'] : null;
        $lastCall = $r['last_call_ts'] !== null ? (int)$r['last_call_ts'] : null;
        $called   = ((int)$r['calls_since']) > 0;

        $answers = (string)$r['pain_points'] . "\n" . (string)$r['opportunity'];
        $volume  = $pick($answers, 'New inquiries a month: ');
        $speed   = $pick($answers, 'Reply speed: ');
        $spend   = $pick($answers, 'Current lead or ad spend: ');
        $fix     = $pick($answers, 'Most wants fixed: ');

        $best = '';
        $pos  = strpos((string)$r['next_action'], 'best time ');
        if ($pos !== false) $best = trim(substr($r['next_action'], $pos + strlen('best time ')));

        $heat = 0;
        if (!$called) $heat += 100;
        if ($answered !== null) $heat += 40;
        if ($spend === '$500 to $2,000 a month' || $spend === 'Over $2,000 a month') $heat += 20;
        elseif ($spend === 'Under $500 a month') $heat += 10;
        if ($volume === '30 to 100' || $volume === 'Over 100') $heat += 15;
        elseif ($volume === '10 to 30') $heat += 5;
        if ($speed === 'Same day' || $speed === 'Next day or later') $heat += 10;

        $recent = max($started, $answered !== null ? $answered : 0);
        $days   = (int)floor(max(0, $nowA - $recent) / 86400);
        if ($days > 30) $days = 30;
        $heat -= $days;

        $auditAll[] = [
            'id'           => (string)$r['id'],
            'name'         => $r['name'],
            'contact'      => $r['contact'],
            'phone'        => $r['phone'],
            'city'         => $r['city'],
            'stage'        => $r['stage'],
            'started_ts'   => $started,
            'answered_ts'  => $answered,
            'last_call_ts' => $lastCall,
            'called'       => $called,
            'volume'       => $volume,
            'speed'        => $speed,
            'spend'        => $spend,
            'fix'          => $fix,
            'best_time'    => $best,
            'heat'         => $heat,
        ];
    }

    usort($auditAll, function ($a, $b) {
        if ($a['heat'] !== $b['heat']) return $b['heat'] - $a['heat'];
        $ra = max($a['answered_ts'] !== null ? $a['answered_ts'] : 0, $a['started_ts']);
        $rb = max($b['answered_ts'] !== null ? $b['answered_ts'] : 0, $b['started_ts']);
        return $rb - $ra;
    });

    $auditLeads = array_slice($auditAll, 0, 12);

    $aWeek = 0; $aAnswered = 0; $aWaiting = 0;
    foreach ($auditAll as $a) {
        if ($a['started_ts'] >= $week) $aWeek++;
        if ($a['answered_ts'] !== null) $aAnswered++;
        if (!$a['called']) $aWaiting++;
    }
    $auditSummary = ['total' => count($auditAll), 'week' => $aWeek,
                     'answered' => $aAnswered, 'waiting' => $aWaiting];

    /* The plan's weekly hypothesis, measured against what actually happened over
       the last 7 days. Dials come straight from the call log. Held demos use the
       stage-change activity, which is the only timestamped record of a move into
       Demo held, so count those in the window. Closes count leads whose won_at
       landed in the window. */
    $swk = $pdo->prepare("SELECT COUNT(*) FROM activities WHERE type='call' AND ts>=?");
    $swk->execute([$week]); $dialsWeek = (int)$swk->fetchColumn();

    $shd = $pdo->prepare("SELECT COUNT(*) FROM activities WHERE type='stage' AND body LIKE ? AND ts>=?");
    $shd->execute(['%→ Demo held%', $week]); $heldWeek = (int)$shd->fetchColumn();

    $scl = $pdo->prepare("SELECT COUNT(*) FROM leads WHERE won_at > 0 AND won_at >= ?");
    $scl->execute([$week]); $closesWeek = (int)$scl->fetchColumn();

    $planDph = 70;   // plan: 70 dials per held demo
    $planHpc = 4;    // plan: 4 held demos per close
    $weekBlock = [
        'dials'               => $dialsWeek,
        'held'                => $heldWeek,
        'closes'              => $closesWeek,
        'plan_dials_per_held' => $planDph,
        'plan_held_per_close' => $planHpc,
        'dials_per_held'      => $heldWeek > 0 ? $dialsWeek / $heldWeek : null,
        'held_per_close'      => $closesWeek > 0 ? $heldWeek / $closesWeek : null,
    ];

    /* Stop loss: six touches, three weeks, no reply. A lead only qualifies if
       its first call is at least 21 days old, so a fresh six-touch lead is not
       swept off the list too early. */
    $slq = $pdo->prepare("
        SELECT l.id, l.name, l.attempts,
               (SELECT MIN(a.ts) FROM activities a
                 WHERE a.lead_id=l.id AND a.type='call') AS first_call
        FROM leads l
        WHERE l.stage IN ('attempting','voicemail') AND l.attempts >= 6
          AND (SELECT MIN(a.ts) FROM activities a
                WHERE a.lead_id=l.id AND a.type='call') <= ?
        ORDER BY first_call ASC");
    $slq->execute([$now - 21 * 86400]);
    $stopLoss = $slq->fetchAll();

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
        'audit_leads'   => $auditLeads,
        'audit_summary' => $auditSummary,
        'daily'      => $daily,
        'by_hour'    => $hours,
        'week'       => $weekBlock,
        'stop_loss'  => $stopLoss,
        'target'     => 100,
    ]);
}

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

/* Officially clients: signed and paying, nobody else. The signature is the
   line — everyone still being sold to sits in 'pipeline' below, however far
   along they are. Keeping "quoted" and "paying" in one list was what made this
   view impossible to use as a book of business. */
case 'clients': {
    $rows = $pdo->query("
        SELECT l.*, (SELECT COUNT(*) FROM activities a WHERE a.lead_id=l.id) acts,
               (SELECT MAX(ts) FROM activities a WHERE a.lead_id=l.id) last_ts,
               (SELECT MIN(starts_at) FROM events e WHERE e.lead_id=l.id AND e.status='scheduled') next_at
        FROM leads l
        WHERE l.stage = 'won'
        ORDER BY l.updated_at DESC")->fetchAll();
    json_out(['clients' => $rows]);
}

/* The Leads view: every prospect who has not signed, at whatever stage they
   have reached. Deliberately includes 'new' — the call queue is where you work
   an untouched lead, but this is the only place that answers "who have we got".
   'won' is the sole exclusion; those are Clients.

   Capped, with the true count alongside, so a big cold list renders as a list
   rather than a hang. Anything past the cap is reachable from the call queue. */
case 'pipeline': {
    $rows = $pdo->query("
        SELECT l.*, (SELECT COUNT(*) FROM activities a WHERE a.lead_id=l.id) acts,
               (SELECT MAX(ts) FROM activities a WHERE a.lead_id=l.id) last_ts,
               (SELECT MIN(starts_at) FROM events e WHERE e.lead_id=l.id AND e.status='scheduled') next_at
        FROM leads l
        WHERE l.stage <> 'won'
        ORDER BY l.updated_at DESC LIMIT 500")->fetchAll();
    json_out([
        'leads' => $rows,
        'total' => (int)$pdo->query("SELECT COUNT(*) FROM leads WHERE stage <> 'won'")->fetchColumn(),
    ]);
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

    /* Trust an explicit industry from the sheet; otherwise derive it from the
       raw service so a hand-added lead is grouped like an imported one. */
    $service  = trim((string)($in['service'] ?? ''));
    $industry = industry_key((string)($in['industry'] ?? ''));
    if ($industry === '') $industry = industry_for_service($service);

    $id = 'l_' . substr(bin2hex(random_bytes(6)), 0, 8);
    $src = trim((string)($in['source'] ?? ''));
    $pdo->prepare("INSERT INTO leads
        (id,name,phone,email,contact,service,industry,city,address,stage,reason,next_action,
         owner,value,attempts,vm_count,seq,last_call_at,first_vm_at,called_back_at,
         passed_at,created_at,updated_at,website,socials,source,defect,pitch,severity)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,'','',0,0,0,0,0,0,0,0,?,?,?,?,?,?,?,?)")
        ->execute([$id, $name,
                   trim((string)($in['phone'] ?? '')), $email,
                   trim((string)($in['contact'] ?? '')),
                   $service, $industry,
                   trim((string)($in['city'] ?? '')),
                   trim((string)($in['address'] ?? '')),
                   (string)($in['stage'] ?? 'new'),
                   trim((string)($in['reason'] ?? '')),
                   $now, $now, $site,
                   trim((string)($in['socials'] ?? '')),
                   $src,
                   trim((string)($in['defect'] ?? '')),
                   trim((string)($in['pitch'] ?? '')),
                   (int)($in['severity'] ?? 0)]);

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
    $set  = "pain_points=?, opportunity=?, website=?, socials=?";
    $vals = [trim((string)($in['pain_points'] ?? '')),
             trim((string)($in['opportunity'] ?? '')),
             $site, trim((string)($in['socials'] ?? ''))];
    // A missing or bad industry leaves the stored value alone rather than blanking it.
    $industry = industry_key((string)($in['industry'] ?? ''));
    if ($industry !== '') { $set .= ", industry=?"; $vals[] = $industry; }
    $set .= ", updated_at=?"; $vals[] = $now; $vals[] = $id;
    $pdo->prepare("UPDATE leads SET $set WHERE id=?")->execute($vals);
    json_out(['ok' => true]);
}

case 'set_stage': {
    $id    = (string)($in['id'] ?? '');
    $stage = (string)($in['stage'] ?? '');
    $lead  = lead_row($pdo, $id);
    if (!$lead) json_out(['error' => 'not found'], 404);
    if (!isset(STAGES[$stage])) json_out(['error' => 'unknown stage'], 400);
    if ($stage === $lead['stage']) json_out(['ok' => true, 'stage' => $stage]);

    // Winning is a date as well as a stage, and it starts the client reminders.
    $wonNow = ($stage === 'won' && (int)$lead['won_at'] === 0);
    if ($wonNow) {
        $pdo->prepare("UPDATE leads SET stage=?, won_at=?, updated_at=? WHERE id=?")
            ->execute([$stage, $now, $now, $id]);
    } else {
        $pdo->prepare("UPDATE leads SET stage=?, updated_at=? WHERE id=?")
            ->execute([$stage, $now, $id]);
    }
    log_act($pdo, $id, 'stage',
        'Moved ' . STAGES[$lead['stage']]['label'] . ' → ' . STAGES[$stage]['label']);

    if ($wonNow) {
        $lead['won_at'] = $now;
        create_event($pdo, $lead, 'followup', days_later_at_ten(7),
            'Record the baseline - ' . $lead['name'],
            'Before folder: dated screenshots of the map block, profile, review count, form timestamp, site on a phone. Fill the Baseline field.');
        create_event($pdo, $lead, 'followup', days_later_at_ten(35),
            'First report: ask for a Weyney review if the numbers moved - ' . $lead['name'],
            'If review count or reply time moved, ask once for a Google review of Weyney Media and one introduction. Direct link.');
        create_event($pdo, $lead, 'followup', days_later_at_ten(80),
            'Day 80 review - ' . $lead['name'],
            'Three reports side by side with the baseline. One question: what should month four focus on.');
        touch_lead($pdo, $id);
    }
    json_out(['ok' => true, 'stage' => $stage]);
}

/* The plan's stop loss: six touches over three weeks with no reply moves a row
   to the January list. Only leads still in the cold stages are moved. */
case 'move_to_nurture': {
    $ids = (array)($in['ids'] ?? []);
    $moved = 0;
    foreach ($ids as $rawId) {
        $id = (string)$rawId;
        $lead = lead_row($pdo, $id);
        if (!$lead) continue;
        if (!in_array($lead['stage'], ['attempting', 'voicemail'], true)) continue;
        $pdo->prepare("UPDATE leads SET stage='nurture', next_action=?, updated_at=? WHERE id=?")
            ->execute(['January list: call again in January', $now, $id]);
        log_act($pdo, $id, 'stage',
            'Stop loss: 6 touches, 3 weeks, no reply. Moved to the January list.');
        touch_lead($pdo, $id);
        $moved++;
    }
    json_out(['ok' => true, 'moved' => $moved]);
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
