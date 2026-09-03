<?php
// Test für den .env-Loader. Aufruf: php tools/test_env.php
require_once __DIR__ . '/../includes/env.php';

$tmp = sys_get_temp_dir() . '/env_test_' . getmypid();
file_put_contents($tmp, <<<ENV
# Kommentar wird ignoriert
DB_HOST=localhost
QUOTED_HASH="pa ss#word"
EMPTY=
QUOTED='single'
SSO_ENABLED=true
export EXPORTED=yes

ENV);

env_load($tmp);
unlink($tmp);

$checks = [
    'einfacher Wert'          => env('DB_HOST') === 'localhost',
    'Anfuehrungszeichen weg'  => env('QUOTED_HASH') === 'pa ss#word',
    'leerer Wert'             => env('EMPTY') === '',
    'einfache Quotes'         => env('QUOTED') === 'single',
    'Default bei Fehlen'      => env('NICHT_DA', 'fallback') === 'fallback',
    'Kommentar ignoriert'     => env('# Kommentar wird ignoriert') === null,
    'bool true'               => env_bool('SSO_ENABLED') === true,
    'bool Default'            => env_bool('NICHT_DA', false) === false,
    'export Praefix entfernt' => env('EXPORTED') === 'yes',
];

$fail = 0;
foreach ($checks as $name => $ok) {
    echo ($ok ? 'PASS' : 'FAIL') . "  $name\n";
    if (!$ok) $fail = 1;
}
exit($fail);
