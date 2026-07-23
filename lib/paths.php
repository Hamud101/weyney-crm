<?php
/**
 * Resolve the account home directory.
 *
 * getenv('HOME') is set under the CLI but NOT under LiteSpeed, which is what
 * serves this site — so anything relying on it works when run over SSH and
 * silently fails over HTTP. Try the environment, then the passwd entry, then
 * walk up from this file, and verify the result actually contains appdata/.
 */
function crm_home(): string {
    static $home = null;
    if ($home !== null) return $home;

    $candidates = [];

    $env = getenv('HOME');
    if (is_string($env) && $env !== '') $candidates[] = $env;

    if (function_exists('posix_geteuid') && function_exists('posix_getpwuid')) {
        $pw = @posix_getpwuid(posix_geteuid());
        if (!empty($pw['dir'])) $candidates[] = $pw['dir'];
    }

    // .../<home>/domains/<site>/public_html/apps/crm/lib  -> up six
    $candidates[] = dirname(__DIR__, 6);
    $candidates[] = dirname(__DIR__, 5);

    foreach ($candidates as $c) {
        if ($c && is_dir($c . '/appdata')) { return $home = rtrim($c, '/'); }
    }
    // Nothing verified — fall back to the most likely so errors are legible.
    return $home = rtrim($candidates[count($candidates) - 2] ?? '/tmp', '/');
}

function crm_data_dir(): string {
    $dir = crm_home() . '/appdata/crm';
    if (!is_dir($dir)) @mkdir($dir, 0700, true);
    return $dir;
}
