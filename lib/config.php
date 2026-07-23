<?php
/** Secrets live outside the web root; this is the only thing that reads them. */
require_once __DIR__ . '/paths.php';

function cfg(string $key, $default = null) {
    static $c = null;
    if ($c === null) {
        $path = crm_data_dir() . '/secrets.php';
        $c = is_readable($path) ? require $path : [];
    }
    return $c[$key] ?? $default;
}

date_default_timezone_set(cfg('timezone', 'America/Chicago'));

/** Stages, in pipeline order. Mirrors the original CRM so nothing is lost. */
const STAGES = [
    'new'         => ['label' => 'New',          'open' => true],
    'attempting'  => ['label' => 'No answer',    'open' => true],
    'voicemail'   => ['label' => 'Voicemail',    'open' => true],
    'contacted'   => ['label' => 'Contacted',    'open' => true],
    'demo_set'    => ['label' => 'Demo scheduled', 'open' => true],
    'demo_noshow' => ['label' => 'Demo no-show',   'open' => true],
    'demo_done'   => ['label' => 'Demo held',      'open' => true],
    'proposal'    => ['label' => 'Proposal',     'open' => true],
    'won'         => ['label' => 'Won',          'open' => false],
    'nurture'     => ['label' => 'Nurture',      'open' => true],
    'lost'        => ['label' => 'Lost',         'open' => false],
];

function json_out($data, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}
