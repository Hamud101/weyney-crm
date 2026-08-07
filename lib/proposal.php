<?php
/**
 * Build the signable services agreement for one lead, server-side.
 *
 * The document is the approved print source with a handful of regions swapped
 * out — not a re-implementation. That matters: the agreement's look was signed
 * off, and a second renderer would quietly change it. Chrome draws it, exactly
 * as it does on the laptop, and sign_fields.py stamps the client's signature
 * fields afterwards.
 *
 * Everything the operator chooses is baked in. The only fields left fillable
 * are the three the CLIENT needs in order to sign.
 */

require_once __DIR__ . '/paths.php';
require_once __DIR__ . '/documents.php';

/** Where the headless browser and its two extra libraries live. Installed by
 *  hand — see CLAUDE.md; there is no package manager on this host. */
function chrome_bin(): string { return crm_home() . '/opt/chrome/bin/chrome-headless-shell'; }
function chrome_lib(): string { return crm_home() . '/opt/chrome/lib'; }
function proposal_template(): string { return __DIR__ . '/../templates/services-agreement.html'; }
function sign_fields_script(): string { return __DIR__ . '/../templates/sign_fields.py'; }

function prop_money($n): string { return '$' . number_format((int)$n); }

/** "2026-08-07" -> "7 August 2026", which is how the document writes dates. */
function prop_date(string $iso): string {
    $t = strtotime($iso ?: 'today') ?: time();
    return ltrim(date('j F Y', $t), '0');
}

/**
 * Turn the saved selection into the strings the template needs.
 * Returns [placeholder => replacement].
 */
function prop_fields(array $lead, array $p): array {
    $lines = $p['lines'] ?? [];
    $term  = max(1, (int)($p['term_months'] ?? 12));
    $prepaid = !empty($p['prepaid']);

    // Section 1: one bullet per service, in catalogue order as saved.
    $bullets = '';
    foreach ($lines as $l) {
        $bullets .= '    <li>' . htmlspecialchars((string)($l['desc'] ?? $l['label']),
                     ENT_QUOTES, 'UTF-8') . "</li>\n";
    }
    if ($bullets === '') $bullets = "    <li>As agreed in writing between the parties.</li>\n";

    // Section 3: a fee line per service, then the total actually due on signing.
    $fees = '';
    $total = 0;
    foreach ($lines as $l) {
        $amt  = (int)($l['amount'] ?? 0);
        $mon  = ($l['kind'] ?? 'once') === 'monthly';
        $due  = $mon ? ($prepaid ? $amt * $term : $amt) : $amt;
        $total += $due;

        $label = htmlspecialchars((string)$l['label'], ENT_QUOTES, 'UTF-8');
        if ($mon) {
            $desc = $prepaid
                ? $label . ' for ' . $term . ' months, prepaid'
                : $label . ' — ' . prop_money($amt) . ' per month, first month due on signing';
        } else {
            $desc = $label . ', due on signing';
        }
        $fees .= '  <p><span class="fee">' . prop_money($due) . '</span>' .
                 '<span class="feelabel">' . $desc . "</span></p>\n";
    }
    $fees .= '  <p class="total"><span class="fee">' . prop_money($total) . '</span>' .
             '<span class="feelabel">total due on signing</span></p>' . "\n";

    if (!empty($p['notes'])) {
        $fees .= '  <p>' . nl2br(htmlspecialchars((string)$p['notes'], ENT_QUOTES, 'UTF-8')) . "</p>\n";
    }

    /* The package name. One service is named plainly; several become the
       generic label the agreement already used, because inventing a product
       name for an arbitrary combination would put a phrase in a contract that
       exists nowhere else. */
    $names = array_map(function ($l) { return (string)$l['label']; }, $lines);
    $package = count($names) === 1 ? $names[0]
             : (count($names) ? 'Custom Services' : 'Services');

    return [
        '{{AGREEMENT_DATE}}' => prop_date((string)($p['date'] ?? '')),
        '{{CLIENT_NAME}}'    => htmlspecialchars((string)$lead['name'], ENT_QUOTES, 'UTF-8'),
        '{{PACKAGE_NAME}}'   => htmlspecialchars($package, ENT_QUOTES, 'UTF-8'),
        '{{SERVICE_BULLETS}}'=> $bullets,
        '{{FEE_LINES}}'      => $fees,
        '{{TERM}}'           => $term . ' month' . ($term === 1 ? '' : 's'),
        '{{CHANGE_FEE}}'     => prop_money((int)($p['change_fee'] ?? 50)) . ' per request',
        '{{TOTAL}}'          => prop_money($total),
        '{{SIGN_DATE}}'      => prop_date((string)($p['date'] ?? '')),
    ];
}

/**
 * Render and stamp. Returns [documentRow, null] or [null, 'why not'].
 */
function prop_generate(PDO $pdo, array $lead, array $p): array {
    $tpl = proposal_template();
    if (!is_file($tpl))          return [null, 'the agreement template is missing on the server'];
    if (!is_file(chrome_bin()))  return [null, 'the renderer is not installed (~/opt/chrome)'];

    $html = strtr(file_get_contents($tpl), prop_fields($lead, $p));

    $work = sys_get_temp_dir() . '/weyney-prop-' . bin2hex(random_bytes(6));
    if (!@mkdir($work, 0700)) return [null, 'could not create a work directory'];

    $src = $work . '/agreement.html';
    $pdf = $work . '/agreement.pdf';
    file_put_contents($src, $html);

    /* HOME and --user-data-dir are not optional here. Under the CLI this works
       without them; under LiteSpeed, which serves the site, HOME is unset, so
       Chrome has nowhere to put its profile and dies before rendering — the
       exact difference between "works over SSH" and "fails in the app".

       --no-sandbox because shared hosting has no user namespaces, and
       --disable-dev-shm-usage because /dev/shm here is too small to render
       into. The dbus errors Chrome prints are harmless and unavoidable. */
    $cmd = 'HOME=' . escapeshellarg($work) .
           ' LD_LIBRARY_PATH=' . escapeshellarg(chrome_lib()) . ' ' .
           escapeshellarg(chrome_bin()) .
           ' --headless --no-sandbox --disable-gpu --disable-dev-shm-usage' .
           ' --user-data-dir=' . escapeshellarg($work . '/chrome') .
           ' --crash-dumps-dir=' . escapeshellarg($work) .
           ' --no-pdf-header-footer --print-to-pdf=' . escapeshellarg($pdf) .
           ' ' . escapeshellarg('file://' . $src) . ' 2>&1';
    exec($cmd, $out, $rc);

    if (!is_file($pdf) || filesize($pdf) < 1000) {
        /* Say what actually happened. "The renderer failed:" with nothing after
           it, which is what an empty $out produces, is useless at 11pm. */
        $tail = trim(implode(' | ', array_slice($out, -3)));
        prop_rmdir($work);
        return [null, 'the renderer failed (exit ' . $rc . ')' .
                      ($tail !== '' ? ': ' . $tail : ' with no output')];
    }

    /* Signature fields. If this step fails the PDF is still correct, just not
       fillable — worth sending, so it is reported rather than fatal. */
    $signed = $work . '/signed.pdf';
    $warn = null;
    if (is_file(sign_fields_script())) {
        // pypdf lives in ~/.local for this account; LiteSpeed's HOME is not that.
        exec('HOME=' . escapeshellarg(crm_home()) .
             ' PYTHONPATH=' . escapeshellarg(crm_home() . '/.local/lib/python3.6/site-packages') .
             ' python3 ' . escapeshellarg(sign_fields_script()) . ' ' .
             escapeshellarg($pdf) . ' -o ' . escapeshellarg($signed) . ' -q 2>&1', $so, $rc2);
        if (!is_file($signed)) {
            $warn = 'signature fields could not be added: ' .
                    trim(implode(' | ', array_slice($so, -2)));
            $signed = $pdf;
        }
    } else {
        $warn = 'the field stamper is missing';
        $signed = $pdf;
    }

    /* Hand it to the document store the same way an upload would arrive, so
       there is one code path for "a file belonging to this lead". */
    $name = 'Weyney Media - Services Agreement - ' .
            preg_replace('/[^A-Za-z0-9 &.,-]/', '', $lead['name']) . '.pdf';
    [$doc, $err] = doc_store_local($pdo, $lead['id'], $signed, $name);
    prop_rmdir($work);
    if ($err) return [null, $err];

    return [['doc' => $doc, 'warning' => $warn], null];
}

function prop_rmdir(string $dir): void {
    /* Chrome leaves a profile tree behind, so this has to recurse. */
    foreach (glob($dir . '/*') ?: [] as $f) {
        is_dir($f) ? prop_rmdir($f) : @unlink($f);
    }
    @rmdir($dir);
}

/* ---------- the queue ----------
 *
 * The web app cannot render (see the schema v8 note). It queues here, and
 * bin/render-worker.php — run from cron, where the address-space limit does not
 * apply — does the work.
 */

/** One pending job per lead: clicking Generate twice should not render twice. */
function prop_enqueue(PDO $pdo, string $leadId): array {
    $open = $pdo->prepare("SELECT * FROM proposal_jobs
                           WHERE lead_id=? AND status IN ('pending','running')
                           ORDER BY id DESC LIMIT 1");
    $open->execute([$leadId]);
    if ($job = $open->fetch()) return $job;

    $pdo->prepare("INSERT INTO proposal_jobs (lead_id,status,created_at) VALUES (?,'pending',?)")
        ->execute([$leadId, time()]);
    return prop_job($pdo, (int)$pdo->lastInsertId());
}

function prop_job(PDO $pdo, int $id): ?array {
    $s = $pdo->prepare("SELECT * FROM proposal_jobs WHERE id=?");
    $s->execute([$id]);
    return $s->fetch() ?: null;
}

function prop_latest_job(PDO $pdo, string $leadId): ?array {
    $s = $pdo->prepare("SELECT * FROM proposal_jobs WHERE lead_id=? ORDER BY id DESC LIMIT 1");
    $s->execute([$leadId]);
    return $s->fetch() ?: null;
}

/**
 * Render everything queued. Called only from the CLI worker.
 * Returns a line per job for the log.
 */
function prop_run_pending(PDO $pdo, int $limit = 5): array {
    $out = [];
    $jobs = $pdo->query("SELECT * FROM proposal_jobs WHERE status='pending'
                         ORDER BY id ASC LIMIT " . (int)$limit)->fetchAll();
    foreach ($jobs as $job) {
        $pdo->prepare("UPDATE proposal_jobs SET status='running', started_at=? WHERE id=?")
            ->execute([time(), $job['id']]);

        $lead = null;
        $s = $pdo->prepare("SELECT * FROM leads WHERE id=?");
        $s->execute([$job['lead_id']]);
        $lead = $s->fetch() ?: null;

        $p = $lead ? json_decode((string)$lead['proposal'], true) : null;
        if (!$lead) {
            $err = 'the lead no longer exists';
        } elseif (!is_array($p) || !($p['lines'] ?? [])) {
            $err = 'no packages are selected on this lead';
        } else {
            [$made, $err] = prop_generate($pdo, $lead, $p);
        }

        if (!empty($err)) {
            $pdo->prepare("UPDATE proposal_jobs SET status='failed', error=?, done_at=? WHERE id=?")
                ->execute([$err, time(), $job['id']]);
            $out[] = "job {$job['id']} FAILED: $err";
            continue;
        }
        $pdo->prepare("UPDATE proposal_jobs SET status='done', doc_id=?, done_at=? WHERE id=?")
            ->execute([$made['doc']['id'], time(), $job['id']]);
        $pdo->prepare("INSERT INTO activities (lead_id,ts,type,body) VALUES (?,?,?,?)")
            ->execute([$lead['id'], time(), 'note',
                       'Proposal PDF generated — ' . $made['doc']['name']]);
        $out[] = "job {$job['id']} ok: {$made['doc']['name']}" .
                 ($made['warning'] ? " (warning: {$made['warning']})" : '');
    }
    return $out;
}
