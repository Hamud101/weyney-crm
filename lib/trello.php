<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

/**
 * Trello mirror for scheduled events.
 *
 * The calendar is the thing Hamud looks at hour to hour; Trello is where he
 * sees what's coming as *tasks*. So this creates a due-dated card per event
 * and keeps it in step — it does not try to be a second source of truth.
 */

function t_req(string $method, string $path, array $params = []): array {
    $params['key']   = cfg('trello_key');
    $params['token'] = cfg('trello_token');
    $url = 'https://api.trello.com/1' . $path;
    $ch  = curl_init();
    $opts = [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_CUSTOMREQUEST => $method];
    if ($method === 'GET') {
        $url .= '?' . http_build_query($params);
    } else {
        $opts[CURLOPT_POSTFIELDS] = http_build_query($params);
    }
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt_array($ch, $opts);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$code, json_decode((string)$body, true)];
}

/**
 * Push one event to Trello. Creates on first sync, updates thereafter via the
 * stored card id — so moving a callback moves the card instead of leaving a
 * stale duplicate on the board.
 */
function t_sync_event(array $ev): array {
    $due = (new DateTime('@' . $ev['starts_at']))->format('c');
    $name = ($ev['kind'] === 'demo' ? '🎥 Demo — ' : '☎️ Call back — ') . $ev['name'];
    $desc = trim(
        ($ev['phone'] ? "**{$ev['phone']}**\n" : '') .
        ($ev['city']  ? "{$ev['city']}\n"      : '') .
        ($ev['notes'] ? "\n{$ev['notes']}\n"   : '') .
        "\nFrom the CRM: https://apps.weyney.com/crm/"
    );

    if (!empty($ev['trello_card_id'])) {
        [$code, $r] = t_req('PUT', '/cards/' . $ev['trello_card_id'], [
            'name' => $name, 'desc' => $desc, 'due' => $due, 'dueComplete' => 'false',
        ]);
        if ($code === 404) { $ev['trello_card_id'] = null; }   // deleted on the board
        elseif ($code >= 200 && $code < 300) {
            db()->prepare("UPDATE events SET trello_synced_at=? WHERE id=?")->execute([time(), $ev['id']]);
            return [true, $ev['trello_card_id']];
        } else {
            return [false, 'HTTP ' . $code];
        }
    }

    [$code, $r] = t_req('POST', '/cards', [
        'idList' => cfg('trello_list_today'),
        'name'   => $name,
        'desc'   => $desc,
        'due'    => $due,
        'pos'    => 'top',
    ]);
    if ($code >= 200 && $code < 300 && !empty($r['id'])) {
        db()->prepare("UPDATE events SET trello_card_id=?, trello_synced_at=? WHERE id=?")
            ->execute([$r['id'], time(), $ev['id']]);
        return [true, $r['id']];
    }
    return [false, 'HTTP ' . $code . ' ' . json_encode($r)];
}

/** A cancelled event's card is archived off the board. 404 means it is already
 *  gone, which is fine. */
function t_cancel_event(string $cardId): bool {
    [$code] = t_req('PUT', '/cards/' . $cardId, ['closed' => 'true']);
    return ($code >= 200 && $code < 300) || $code === 404;
}

/**
 * Sync everything scheduled within the window. Run from cron.
 * Only touches events that are new or changed since their last push, so
 * re-running is cheap and idempotent.
 */
function t_sync_due(int $daysAhead = 14): array {
    $pdo = db();
    $rows = $pdo->prepare("
        SELECT e.*, l.name, l.phone, l.city
        FROM events e JOIN leads l ON l.id = e.lead_id
        WHERE e.status = 'scheduled'
          AND e.starts_at <= ?
          AND (e.trello_synced_at IS NULL OR e.updated_at > e.trello_synced_at)
        ORDER BY e.starts_at ASC");
    $rows->execute([time() + $daysAhead * 86400]);

    $done = 0; $failed = [];
    foreach ($rows->fetchAll() as $ev) {
        [$ok, $info] = t_sync_event($ev);
        if ($ok) $done++; else $failed[] = $ev['id'] . ': ' . $info;
    }
    return [$done, $failed];
}
