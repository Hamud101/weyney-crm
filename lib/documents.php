<?php
/**
 * Per-lead documents: store, list, resolve for sending.
 *
 * The distinction that matters here is against lib/templates.php. A template is
 * generic collateral — the same "what I do" PDF goes to every prospect, so it
 * lives in attachments/ and is named by a registry key. A document belongs to
 * ONE lead: the services agreement with their company, their price and their
 * date on it. It cannot be a template, and it must not sit in the web root.
 *
 * So: the row goes in `documents`, the bytes go in crm_docs_dir() (0700, beside
 * the database), and the only way back out is doc.php behind the session check.
 */

require_once __DIR__ . '/paths.php';

/** What may be uploaded. Deliberately short — this is a contract store, not a
 *  file share, and every extra type is another thing the browser might render. */
const DOC_TYPES = [
    'application/pdf' => 'pdf',
    'image/png'       => 'png',
    'image/jpeg'      => 'jpg',
];

const DOC_MAX_BYTES = 20 * 1024 * 1024;

/**
 * An ASCII filename safe to put in a MIME header.
 *
 * lib/mailer.php hand-builds its headers and does not implement RFC 2231, so a
 * non-ASCII filename would go out mangled or break the part. This is not
 * hypothetical: the Caring Hands agreement is named with em dashes
 * ("Weyney Media — Services Agreement — …"), which is exactly the case that
 * would have broken. The stored `name` keeps the original for display; only the
 * wire name is folded down.
 */
function doc_ascii_name(string $name): string {
    $n = strtr($name, [
        "\u{2014}" => '-', "\u{2013}" => '-', "\u{2012}" => '-',  // em/en/figure dash
        "\u{2018}" => "'", "\u{2019}" => "'",
        "\u{201C}" => '"', "\u{201D}" => '"',
        "\u{00A0}" => ' ', "\u{2026}" => '...',
    ]);
    if (function_exists('iconv')) {
        $t = @iconv('UTF-8', 'ASCII//TRANSLIT', $n);
        if (is_string($t) && $t !== '') $n = $t;
    }
    $n = preg_replace('/[^\x20-\x7E]/', '', $n);       // anything left that is not ASCII
    $n = preg_replace('/["\\\\\r\n]/', '', $n);        // would terminate the header
    $n = preg_replace('/\s+/', ' ', trim($n));
    if ($n === '' || $n === '.' || $n === '..') $n = 'document.pdf';
    return substr($n, 0, 120);
}

function doc_dir_for(string $leadId): string {
    // Lead ids are generated as l_<hex>, but never trust one into a path.
    $safe = preg_replace('/[^A-Za-z0-9_-]/', '', $leadId);
    $dir  = crm_docs_dir() . '/' . $safe;
    if (!is_dir($dir)) @mkdir($dir, 0700, true);
    return $dir;
}

/**
 * Take one entry from $_FILES and put it away.
 * Returns [documentRow, null] or [null, 'why not'].
 */
function doc_store(PDO $pdo, string $leadId, array $file): array {
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        $why = [
            UPLOAD_ERR_INI_SIZE   => 'the file is larger than the server allows',
            UPLOAD_ERR_FORM_SIZE  => 'the file is too large',
            UPLOAD_ERR_PARTIAL    => 'the upload was interrupted',
            UPLOAD_ERR_NO_FILE    => 'no file was chosen',
            UPLOAD_ERR_NO_TMP_DIR => 'the server has no temp directory',
            UPLOAD_ERR_CANT_WRITE => 'the server could not write the file',
        ];
        return [null, $why[$file['error'] ?? -1] ?? 'the upload failed'];
    }
    if (!is_uploaded_file($file['tmp_name'])) return [null, 'that was not an upload'];
    if (($file['size'] ?? 0) > DOC_MAX_BYTES)  return [null, 'that file is over 20 MB'];
    if (($file['size'] ?? 0) <= 0)             return [null, 'that file is empty'];

    /* Trust the bytes, not the browser's Content-Type — finfo reads the magic. */
    $mime = 'application/octet-stream';
    if (class_exists('finfo')) {
        $f = new finfo(FILEINFO_MIME_TYPE);
        $mime = (string)$f->file($file['tmp_name']) ?: $mime;
    }
    if (!isset(DOC_TYPES[$mime])) {
        return [null, 'only PDF, PNG and JPEG can be stored (that looked like ' . $mime . ')'];
    }
    $ext = DOC_TYPES[$mime];

    $sha = hash_file('sha256', $file['tmp_name']);
    if (!$sha) return [null, 'could not read the file'];

    $stored = $sha . '.' . $ext;
    $dest   = doc_dir_for($leadId) . '/' . $stored;
    if (!file_exists($dest) && !move_uploaded_file($file['tmp_name'], $dest)) {
        return [null, 'could not save the file'];
    }
    @chmod($dest, 0600);

    $orig = (string)($file['name'] ?? 'document.' . $ext);
    $orig = preg_replace('~[/\\\\]~', '', $orig);   // no directory parts, ever

    /* Same lead, same bytes, already here — re-uploading a file should not
       silently create a second identical row to pick between when sending. */
    $dupe = $pdo->prepare("SELECT id FROM documents WHERE lead_id=? AND sha256=?");
    $dupe->execute([$leadId, $sha]);
    if ($existingId = $dupe->fetchColumn()) {
        $pdo->prepare("UPDATE documents SET name=?, send_name=?, created_at=? WHERE id=?")
            ->execute([$orig, doc_ascii_name($orig), time(), $existingId]);
        return [doc_get($pdo, $existingId), null];
    }

    $id = 'd_' . substr(bin2hex(random_bytes(6)), 0, 8);
    $pdo->prepare("INSERT INTO documents
        (id,lead_id,name,send_name,stored,mime,size,sha256,created_at)
        VALUES (?,?,?,?,?,?,?,?,?)")
        ->execute([$id, $leadId, $orig, doc_ascii_name($orig), $stored,
                   $mime, (int)$file['size'], $sha, time()]);

    return [doc_get($pdo, $id), null];
}

function doc_get(PDO $pdo, string $id): ?array {
    $s = $pdo->prepare("SELECT * FROM documents WHERE id=?");
    $s->execute([$id]);
    return $s->fetch() ?: null;
}

function doc_list(PDO $pdo, string $leadId): array {
    $s = $pdo->prepare("SELECT id,name,send_name,mime,size,created_at,sent_at
                        FROM documents WHERE lead_id=? ORDER BY created_at DESC");
    $s->execute([$leadId]);
    return $s->fetchAll();
}

function doc_path(array $doc): string {
    return doc_dir_for($doc['lead_id']) . '/' . $doc['stored'];
}

/** Shaped like tpl_attachments() so send_mail() takes either without caring. */
function doc_attachments(PDO $pdo, string $id, string $leadId): array {
    $d = doc_get($pdo, $id);
    if (!$d || $d['lead_id'] !== $leadId) return [];   // never another lead's file
    $p = doc_path($d);
    if (!is_file($p)) return [];
    return [['path' => $p, 'name' => $d['send_name'], 'type' => $d['mime']]];
}

/** Remove the row, and the bytes too if no other row still points at them. */
function doc_delete(PDO $pdo, array $doc): void {
    $pdo->prepare("DELETE FROM documents WHERE id=?")->execute([$doc['id']]);
    $still = $pdo->prepare("SELECT COUNT(*) FROM documents WHERE lead_id=? AND stored=?");
    $still->execute([$doc['lead_id'], $doc['stored']]);
    if (!(int)$still->fetchColumn()) @unlink(doc_path($doc));
}
