<?php
/**
 * Google Calendar connect + OAuth callback.
 *
 * Google redirects to /crm/oauth/google/callback (extensionless), which
 * .htaccess rewrites here. Same file starts the flow when hit with ?start=1.
 */
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/google.php';
require_auth();

$msg = ''; $ok = null;

/* Start: bounce to Google with a state token tied to the session. */
if (isset($_GET['start'])) {
    $state = substr(hash_hmac('sha256', 'gstate', ($_COOKIE[SESSION_COOKIE] ?? '')), 0, 32);
    g_tok_set('google_state', $state);
    header('Location: ' . g_auth_url($state));
    exit;
}

/* Disconnect. */
if (isset($_GET['disconnect'])) {
    foreach (['google_refresh', 'google_access', 'google_expires'] as $k) {
        db()->prepare("DELETE FROM tokens WHERE k=?")->execute([$k]);
    }
    $ok = true; $msg = 'Disconnected from Google Calendar.';
}

/* Callback. */
if (isset($_GET['code'])) {
    $expect = g_tok_get('google_state');
    if (!$expect || !hash_equals($expect, (string)($_GET['state'] ?? ''))) {
        $ok = false; $msg = 'State mismatch — start the connection again from the CRM.';
    } else {
        [$status, $r] = g_exchange_code((string)$_GET['code']);
        if ($status === 200 && !empty($r['access_token'])) {
            if (empty($r['refresh_token']) && !g_tok_get('google_refresh')) {
                $ok = false;
                $msg = 'Google returned no refresh token. Revoke access at myaccount.google.com/permissions, then connect again.';
            } else {
                $ok = true; $msg = 'Google Calendar connected.';
                // Backfill anything already scheduled.
                $rows = db()->query("SELECT e.*, l.name, l.phone, l.city FROM events e
                                     JOIN leads l ON l.id=e.lead_id
                                     WHERE e.status='scheduled' AND e.gcal_event_id IS NULL")->fetchAll();
                $n = 0;
                foreach ($rows as $ev) { [$good] = g_sync_event($ev, $ev); if ($good) $n++; }
                if ($n) $msg .= " Synced $n existing event" . ($n > 1 ? 's' : '') . '.';
            }
        } else {
            $ok = false;
            $msg = 'Token exchange failed: ' . ($r['error_description'] ?? $r['error'] ?? "HTTP $status");
        }
    }
} elseif (isset($_GET['error'])) {
    $ok = false; $msg = 'Google returned: ' . htmlspecialchars((string)$_GET['error']);
}

$connected = g_connected();

/* A successful connect has nothing left to say — go straight back to the
   Calendar rather than parking on a confirmation page. */
if ($ok === true && isset($_GET['code'])) {
    header('Location: /crm/#cal');
    exit;
}
?><!doctype html>
<html lang="en"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Calendar — Weyney CRM</title>
<link rel="stylesheet" href="/crm/assets/app.css">
</head><body class="gate">
<div class="login" style="width:min(460px,92vw);text-align:left">
  <h1 style="font-size:24px">Google Calendar</h1>
  <p class="sub" style="margin-bottom:20px">
    <?= $connected ? 'Connected — scheduled callbacks appear on your calendar.'
                   : 'Not connected. Callbacks live only in the CRM until you connect.' ?>
  </p>

  <?php if ($msg): ?>
    <div style="padding:11px 14px;border-radius:9px;margin-bottom:16px;
      background:<?= $ok ? '#12291d' : '#2a1717' ?>;
      border:1px solid <?= $ok ? '#2c5c42' : '#5c2c2c' ?>;
      color:<?= $ok ? 'var(--good)' : 'var(--bad)' ?>;font-size:14px">
      <?= htmlspecialchars($msg) ?>
    </div>
  <?php endif; ?>

  <?php if (!$connected): ?>
    <a href="/crm/oauth_google.php?start=1"
       style="display:block;text-align:center;padding:13px;border-radius:10px;
              background:var(--accent);color:#fff;font-weight:600;text-decoration:none">
       Connect Google Calendar</a>
    <p style="color:var(--faint);font-size:13px;margin-top:14px">
      You'll see a “Google hasn't verified this app” warning — that's expected for a
      private app. Choose <b>Advanced</b> → <b>Go to Weyney CRM</b>. Sign in as
      <b>hamud@weyney.com</b>, not a personal account.
    </p>
  <?php else: ?>
    <a href="/crm/oauth_google.php?disconnect=1"
       style="display:block;text-align:center;padding:12px;border-radius:10px;
              background:var(--panel2);border:1px solid var(--line);
              color:var(--dim);text-decoration:none">Disconnect</a>
  <?php endif; ?>

  <p style="margin-top:22px"><a href="/crm/#cal" style="color:var(--accent);text-decoration:none">← Back to Calendar</a></p>
</div>
</body></html>
