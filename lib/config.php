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

/** The ten buckets we actually work in. Raw service strings are too fragmented
 *  to filter on, so each lead also carries one of these. */
const INDUSTRIES = [
    'beauty'       => 'Beauty & personal care',
    'health'       => 'Health & care',
    'cleaning'     => 'Cleaning & laundry',
    'food'         => 'Food & drink',
    'retail'       => 'Retail',
    'auto'         => 'Auto',
    'trades'       => 'Trades & home services',
    'professional' => 'Professional services',
    'community'    => 'Community, sports & leisure',
    'solar'        => 'Solar & energy',
    'other'        => 'Other',
];

/* Raw import types (OSM-style tags) -> industry. Anything unmapped is 'other'. */
const SERVICE_INDUSTRY = [
    'hairdresser' => 'beauty', 'beauty' => 'beauty', 'massage' => 'beauty', 'tattoo' => 'beauty',
    'dentist' => 'health', 'clinic' => 'health', 'doctor' => 'health', 'psychotherapist' => 'health',
    'autism care' => 'health', 'alternative' => 'health', 'healthcare' => 'health',
    'rehabilitation' => 'health', 'optician' => 'health', 'childcare' => 'health', 'veterinary' => 'health',
    'cleaning' => 'cleaning', 'dry cleaning' => 'cleaning', 'laundry' => 'cleaning',
    'restaurant' => 'food', 'cafe' => 'food', 'fast food' => 'food',
    'antiques' => 'retail', 'clothes' => 'retail', 'gift' => 'retail', 'furniture' => 'retail',
    'jewelry' => 'retail', 'second hand' => 'retail', 'toys' => 'retail', 'convenience' => 'retail',
    'doityourself' => 'retail', 'frame' => 'retail', 'mobile phone' => 'retail', 'pet' => 'retail',
    'photo' => 'retail', 'shoemaker' => 'retail', 'sports' => 'retail',
    'car repair' => 'auto', 'driving school' => 'auto',
    'construction' => 'trades', 'handyman' => 'trades', 'trade' => 'trades', 'storage rental' => 'trades',
    'accountant' => 'professional', 'lawyer' => 'professional', 'financial' => 'professional',
    'estate agent' => 'professional', 'company' => 'professional',
    'masjid' => 'community', 'youth sports' => 'community', 'sports centre' => 'community',
    'amusement arcade' => 'community',
    'solar installer' => 'solar', 'solar' => 'solar', 'solar energy company' => 'solar',
    'solar energy contractor' => 'solar', 'electrician' => 'trades',
];

function industry_for_service(string $service): string {
    return SERVICE_INDUSTRY[strtolower(trim($service))] ?? 'other';
}

function json_out($data, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}
