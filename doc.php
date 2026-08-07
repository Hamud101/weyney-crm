<?php
/**
 * Serve one stored document to a signed-in operator.
 *
 * This exists because the files deliberately do NOT live under the web root:
 * they are client agreements, and a URL you can guess is not an access control.
 * Every fetch comes through here so require_auth() runs first.
 *
 *   /crm/doc.php?id=d_1a2b3c4d          view in the browser
 *   /crm/doc.php?id=d_1a2b3c4d&dl=1     download with the original filename
 */

require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/documents.php';
require_auth();

$pdo = db();
$doc = doc_get($pdo, (string)($_GET['id'] ?? ''));
if (!$doc) { http_response_code(404); header('Content-Type: text/plain'); exit("no such document\n"); }

$path = doc_path($doc);
if (!is_file($path)) {
    http_response_code(410);
    header('Content-Type: text/plain');
    exit("the row is here but the file is not — it was moved or removed on disk\n");
}

/* The name is the operator's own upload, but it still reaches a response
   header, so fold it to ASCII and strip anything that could end the header. */
$name = doc_ascii_name($doc['name']);
$disp = !empty($_GET['dl']) ? 'attachment' : 'inline';

header('Content-Type: ' . $doc['mime']);
header('Content-Length: ' . (string)filesize($path));
header('Content-Disposition: ' . $disp . '; filename="' . $name . '"');
// A contract should not sit in a shared cache, and must never be framed.
header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');

readfile($path);
