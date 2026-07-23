<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

/**
 * Session tokens live in SQLite rather than PHP's session files, so logging out
 * everywhere is a DELETE and sessions survive any shared-host session GC.
 */

const SESSION_COOKIE = 'crm_sess';
const SESSION_TTL    = 60 * 60 * 24 * 30;   // 30 days; single trusted user

function auth_login(string $password): bool {
    $hash = cfg('login_password_hash', '');
    if ($hash === '' || !password_verify($password, $hash)) {
        // Constant-ish delay to blunt trivial brute forcing on a single-user box.
        usleep(random_int(150000, 350000));
        return false;
    }
    $token = bin2hex(random_bytes(32));
    $now = time();
    db()->prepare("INSERT INTO sessions (token,created_at,expires_at) VALUES (?,?,?)")
        ->execute([$token, $now, $now + SESSION_TTL]);

    setcookie(SESSION_COOKIE, $token, [
        'expires'  => $now + SESSION_TTL,
        'path'     => '/crm/',
        'secure'   => true,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    return true;
}

function auth_user(): bool {
    $token = $_COOKIE[SESSION_COOKIE] ?? '';
    if ($token === '') return false;
    $row = db()->prepare("SELECT expires_at FROM sessions WHERE token=?");
    $row->execute([$token]);
    $exp = $row->fetchColumn();
    if ($exp === false) return false;
    if ((int)$exp < time()) {
        db()->prepare("DELETE FROM sessions WHERE token=?")->execute([$token]);
        return false;
    }
    return true;
}

function auth_logout(): void {
    $token = $_COOKIE[SESSION_COOKIE] ?? '';
    if ($token !== '') {
        db()->prepare("DELETE FROM sessions WHERE token=?")->execute([$token]);
    }
    setcookie(SESSION_COOKIE, '', ['expires' => 1, 'path' => '/crm/', 'secure' => true, 'httponly' => true]);
}

function require_auth(): void {
    if (!auth_user()) {
        if (str_contains($_SERVER['REQUEST_URI'] ?? '', '/api.php')) {
            json_out(['error' => 'unauthorized'], 401);
        }
        header('Location: /crm/');
        exit;
    }
}

/** CSRF: token tied to the session cookie, checked on every mutating API call. */
function csrf_token(): string {
    return hash_hmac('sha256', 'csrf', ($_COOKIE[SESSION_COOKIE] ?? '') . cfg('login_password_hash', ''));
}
function csrf_check(): void {
    $sent = $_SERVER['HTTP_X_CSRF'] ?? '';
    if (!hash_equals(csrf_token(), $sent)) json_out(['error' => 'bad csrf'], 403);
}
