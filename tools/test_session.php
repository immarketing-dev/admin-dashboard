<?php
require 'includes/session.php';

// ERST alle Session-Operationen, DANN ausgeben - sonst "headers already sent".
$r = [];
$r['Name ist nicht PHPSESSID']      = APP_SESSION_NAME !== 'PHPSESSID';
$r['Name ist ADMINPANELSESS']       = APP_SESSION_NAME === 'ADMINPANELSESS';

app_session_start();
$r['Session laeuft']                = session_status() === PHP_SESSION_ACTIVE;
$r['Name aktiv uebernommen']        = session_name() === 'ADMINPANELSESS';

$p = session_get_cookie_params();
$r['Cookie-Pfad /']                 = $p['path'] === '/';
$r['Keine Domain (host-only)']      = $p['domain'] === '';
$r['httponly gesetzt']              = $p['httponly'] === true;
$r['samesite Lax']                  = ($p['samesite'] ?? '') === 'Lax';
$r['secure aus ohne HTTPS']         = $p['secure'] === false;

app_session_start();
$r['Mehrfachaufruf unschaedlich']   = session_status() === PHP_SESSION_ACTIVE;

$_SESSION['probe'] = 'wert';
$r['Schreiben und Lesen']           = ($_SESSION['probe'] ?? null) === 'wert';

$fail = 0;
foreach ($r as $name => $ok) { printf("%-30s %s\n", $name, $ok ? 'PASS' : 'FAIL'); $fail += $ok ? 0 : 1; }
printf("\n%d von %d bestanden\n", count($r) - $fail, count($r));
exit($fail ? 1 : 0);
