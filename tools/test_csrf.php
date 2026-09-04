<?php
// Test für den CSRF-Schutz. Aufruf: php tools/test_csrf.php
//
// csrf_check() beendet den Prozess bei einem ungültigen Token, lässt sich
// also nicht direkt aufrufen. Jeder Fall läuft deshalb in einem eigenen
// PHP-Prozess; geprüft wird, ob der Aufruf durchkommt oder abbricht.

$csrf = realpath(__DIR__ . '/../includes/csrf.php');
$php  = PHP_BINARY;

/** Führt csrf_check() mit gesetzter Session und POST-Daten aus. */
function run(array $session, array $post): bool {
    global $csrf, $php;
    $code = '<?php $_SESSION = ' . var_export($session, true) . ';'
          . ' $_POST = ' . var_export($post, true) . ';'
          . ' require ' . var_export($csrf, true) . ';'
          . ' csrf_check(); echo "DURCH";';

    $tmp = tempnam(sys_get_temp_dir(), 'csrf');
    file_put_contents($tmp, $code);
    $out = shell_exec(escapeshellarg($php) . ' ' . escapeshellarg($tmp) . ' 2>&1');
    unlink($tmp);
    return strpos((string)$out, 'DURCH') !== false;
}

$gut  = str_repeat('a', 64);
$boes = str_repeat('b', 64);

$checks = [
    // Der Normalfall.
    'gültiges Token kommt durch'
        => run(['csrf_token' => $gut], ['csrf_token' => $gut]) === true,

    'falsches Token wird abgewiesen'
        => run(['csrf_token' => $gut], ['csrf_token' => $boes]) === false,

    'fehlendes POST-Token wird abgewiesen'
        => run(['csrf_token' => $gut], []) === false,

    // Der eigentliche Grund für die Leerheitsprüfung in csrf.php:
    // hash_equals('', '') ist true. Eine Anfrage ohne Cookie startet eine
    // frische, leere Session - ohne diese Prüfung käme sie durch.
    'beide leer wird abgewiesen'
        => run([], ['csrf_token' => '']) === false,

    'leere Session, gefülltes Token wird abgewiesen'
        => run([], ['csrf_token' => $gut]) === false,

    'gefüllte Session, leeres Token wird abgewiesen'
        => run(['csrf_token' => $gut], ['csrf_token' => '']) === false,

    // Ein Präfix des richtigen Tokens darf nicht genügen.
    'Präfix genügt nicht'
        => run(['csrf_token' => $gut], ['csrf_token' => substr($gut, 0, 32)]) === false,
];

// csrf_field() ist ohne Prozessgrenze prüfbar.
session_start();
require_once __DIR__ . '/../includes/csrf.php';
$feld = csrf_field();
$checks['csrf_field liefert ein Hidden-Feld']
    = strpos($feld, '<input type="hidden" name="csrf_token"') === 0;
$checks['csrf_token ist 64 Zeichen hex']
    = (bool)preg_match('/^[0-9a-f]{64}$/', csrf_token());
$checks['csrf_token bleibt in der Session gleich']
    = csrf_token() === csrf_token();

$fail = 0;
foreach ($checks as $name => $ok) {
    echo ($ok ? 'PASS' : 'FAIL') . "  $name\n";
    if (!$ok) $fail = 1;
}
exit($fail);
