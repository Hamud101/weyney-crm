<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

/**
 * Google Calendar, over raw cURL — no composer on this host and the surface we
 * need is three endpoints. Tokens live in the tokens table, not in secrets.php,
 * because they rotate and secrets.php is hand-managed.
 */

const GOOGLE_SCOPE = 'https://www.googleapis.com/auth/calendar.events';

function g_tokens_init(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS tokens (
        k TEXT PRIMARY KEY, v TEXT NOT NULL, updated_at INTEGER NOT NULL)");
}
function g_tok_get(string $k): ?string {
    $pdo = db(); g_tokens_init($pdo);
    $s = $pdo->prepare("SELECT v FROM tokens WHERE k=?"); $s->execute([$k]);
    $v = $s->fetchColumn();
    return $v === false ? null : (string)$v;
}
function g_tok_set(string $k, string $v): void {
    $pdo = db(); g_tokens_init($pdo);
    $pdo->prepare("INSERT INTO tokens (k,v,updated_at) VALUES (?,?,?)
                   ON CONFLICT(k) DO UPDATE SET v=excluded.v, updated_at=excluded.updated_at")
        ->execute([$k, $v, time()]);
}

function g_connected(): bool { return g_tok_get('google_refresh') !== null; }

/** Step 1: where to send the browser. */
function g_auth_url(string $state): string {
    return 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query([
        'client_id'     => cfg('google_client_id'),
        'redirect_uri'  => cfg('google_redirect_uri'),
        'response_type' => 'code',
        'scope'         => GOOGLE_SCOPE,
        'access_type'   => 'offline',
        'prompt'        => 'consent',   // force a refresh_token even on re-auth
        'state'         => $state,
    ]);
}

function g_post(string $url, array $fields): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($fields),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$code, json_decode((string)$body, true) ?: []];
}

/** Step 2: swap the one-time code for tokens. */
function g_exchange_code(string $code): array {
    [$status, $r] = g_post('https://oauth2.googleapis.com/token', [
        'code'          => $code,
        'client_id'     => cfg('google_client_id'),
        'client_secret' => cfg('google_client_secret'),
        'redirect_uri'  => cfg('google_redirect_uri'),
        'grant_type'    => 'authorization_code',
    ]);
    if ($status === 200 && !empty($r['access_token'])) {
        if (!empty($r['refresh_token'])) g_tok_set('google_refresh', $r['refresh_token']);
        g_tok_set('google_access', $r['access_token']);
        g_tok_set('google_expires', (string)(time() + (int)($r['expires_in'] ?? 3600) - 60));
    }
    return [$status, $r];
}

/** A valid access token, refreshing if needed. */
function g_access_token(): ?string {
    $exp = (int)(g_tok_get('google_expires') ?? 0);
    $tok = g_tok_get('google_access');
    if ($tok && $exp > time()) return $tok;

    $refresh = g_tok_get('google_refresh');
    if (!$refresh) return null;

    [$status, $r] = g_post('https://oauth2.googleapis.com/token', [
        'refresh_token' => $refresh,
        'client_id'     => cfg('google_client_id'),
        'client_secret' => cfg('google_client_secret'),
        'grant_type'    => 'refresh_token',
    ]);
    if ($status !== 200 || empty($r['access_token'])) {
        error_log('google refresh failed: ' . json_encode($r));
        return null;
    }
    g_tok_set('google_access', $r['access_token']);
    g_tok_set('google_expires', (string)(time() + (int)($r['expires_in'] ?? 3600) - 60));
    return $r['access_token'];
}

function g_api(string $method, string $path, ?array $payload = null, array $query = []): array {
    $tok = g_access_token();
    if (!$tok) return [0, ['error' => 'not connected']];
    $url = 'https://www.googleapis.com/calendar/v3' . $path;
    if ($query) $url .= (strpos($path, '?') === false ? '?' : '&') . http_build_query($query);
    $ch = curl_init($url);
    $headers = ['Authorization: Bearer ' . $tok];
    $opts = [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20,
             CURLOPT_CUSTOMREQUEST => $method];
    if ($payload !== null) {
        $headers[] = 'Content-Type: application/json';
        $opts[CURLOPT_POSTFIELDS] = json_encode($payload);
    }
    $opts[CURLOPT_HTTPHEADER] = $headers;
    curl_setopt_array($ch, $opts);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$code, json_decode((string)$body, true) ?: []];
}

/**
 * Push one event to Google. Creates on first sync, PATCHes afterwards — that's
 * what gcal_event_id is for, so moving a demo moves the entry instead of
 * leaving a duplicate.
 *
 * A demo can carry a Meet link and invite the lead. That needs
 * conferenceDataVersion=1 on the request, and sendUpdates=all so Google emails
 * them — without it the attendee is added silently and nobody is told.
 */
function g_sync_event(array $ev, array $lead): array {
    $tz = cfg('timezone', 'America/Chicago');
    $start = (new DateTime('@' . $ev['starts_at']))->setTimezone(new DateTimeZone($tz));
    $end   = (clone $start)->modify('+' . max(15, (int)$ev['duration_min']) . ' minutes');

    $desc = trim(
        ($lead['phone'] ? "Phone: {$lead['phone']}\n" : '') .
        ($lead['city']  ? "City: {$lead['city']}\n"   : '') .
        ($ev['notes']   ? "\n{$ev['notes']}\n"        : '') .
        "\nOpen in CRM: https://apps.weyney.com/crm/"
    );

    $payload = [
        'summary'     => $ev['title'],
        'description' => $desc,
        'start'       => ['dateTime' => $start->format('c'), 'timeZone' => $tz],
        'end'         => ['dateTime' => $end->format('c'),   'timeZone' => $tz],
        'reminders'   => ['useDefault' => false, 'overrides' => [
            ['method' => 'popup', 'minutes' => 15],
        ]],
    ];

    $query = [];
    $invite = !empty($ev['invite_email']) && filter_var($ev['invite_email'], FILTER_VALIDATE_EMAIL);

    if ($ev['kind'] === 'demo' && $invite) {
        $payload['attendees'] = [['email' => $ev['invite_email']]];
        $payload['conferenceData'] = ['createRequest' => [
            // Google dedupes on requestId, so it must be stable per event —
            // otherwise a reschedule mints a second Meet link.
            'requestId' => substr(sha1('weyney-' . $ev['ics_uid']), 0, 32),
            'conferenceSolutionKey' => ['type' => 'hangoutsMeet'],
        ]];
        $query['conferenceDataVersion'] = 1;
        $query['sendUpdates'] = 'all';
    }

    $calId = rawurlencode(cfg('google_calendar_id', 'primary'));

    if (!empty($ev['gcal_event_id'])) {
        [$code, $r] = g_api('PATCH', "/calendars/$calId/events/" . rawurlencode($ev['gcal_event_id']), $payload, $query);
        if ($code === 404) {   // deleted on Google's side; recreate
            [$code, $r] = g_api('POST', "/calendars/$calId/events", $payload, $query);
        }
    } else {
        [$code, $r] = g_api('POST', "/calendars/$calId/events", $payload, $query);
    }

    if ($code >= 200 && $code < 300 && !empty($r['id'])) {
        $meet = $r['hangoutLink'] ?? null;
        db()->prepare("UPDATE events SET gcal_event_id=?, gcal_synced_at=?, meet_link=? WHERE id=?")
            ->execute([$r['id'], time(), $meet, $ev['id']]);
        return [true, $r['id'], $meet];
    }
    error_log('gcal sync failed ' . $code . ' ' . json_encode($r));
    return [false, $r['error']['message'] ?? ('HTTP ' . $code)];
}
