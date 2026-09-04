<?php
/**
 * Test fuer das Fehler-Auffangnetz.
 * Aufruf: php tools/test_errors.php
 *
 * Der Handler laeuft genau dann, wenn ohnehin schon etwas schiefging.
 * Ein Fehler in ihm selbst waere deshalb besonders unangenehm: er
 * ersetzte eine leere Seite durch eine andere leere Seite, und die
 * Kennung, mit der man den Vorgang im Protokoll finden soll, gaebe es
 * gar nicht.
 */

require_once __DIR__ . '/../includes/errors.php';

$checks = [];

// --- Kennung ----------------------------------------------------------
$k1 = fehler_kennung();
$k2 = fehler_kennung();
$checks['Kennung hat die erwartete Form']
    = (bool) preg_match('/^\d{6}-\d{4}-[0-9A-F]{4}$/', $k1);
$checks['zwei Kennungen sind verschieden'] = $k1 !== $k2;
$checks['Kennung traegt das heutige Datum']
    = strpos($k1, date('ymd')) === 0;

// --- JSON oder HTML ---------------------------------------------------
// Die Poll- und Suchendpunkte liefern JSON. Bekaemen sie im Fehlerfall
// HTML, scheiterte im Browser das Auswerten der Antwort und die Ursache
// verschwaende hinter einem Syntaxfehler in der Konsole.
$_SERVER['SCRIPT_NAME'] = '/ajax_poll.php';
unset($_SERVER['HTTP_ACCEPT'], $_SERVER['HTTP_X_REQUESTED_WITH']);
$checks['ajax_poll bekommt JSON'] = fehler_will_json() === true;

$_SERVER['SCRIPT_NAME'] = '/ajax_search.php';
$checks['ajax_search bekommt JSON'] = fehler_will_json() === true;

$_SERVER['SCRIPT_NAME'] = '/tasks.php';
$checks['eine gewoehnliche Seite bekommt HTML'] = fehler_will_json() === false;

$_SERVER['SCRIPT_NAME'] = '/tasks.php';
$_SERVER['HTTP_ACCEPT'] = 'application/json, text/plain, */*';
$checks['Accept: application/json entscheidet fuer JSON'] = fehler_will_json() === true;
unset($_SERVER['HTTP_ACCEPT']);

$_SERVER['HTTP_X_REQUESTED_WITH'] = 'XMLHttpRequest';
$checks['XMLHttpRequest entscheidet fuer JSON'] = fehler_will_json() === true;
unset($_SERVER['HTTP_X_REQUESTED_WITH']);

// Eine Seite, deren Name mit "ajax" beginnt, aber nicht mit "ajax_":
// die Praefixpruefung darf nicht auf blosses Vorkommen hereinfallen.
$_SERVER['SCRIPT_NAME'] = '/tasks_ajax.php';
$checks['ajax mitten im Namen zaehlt nicht'] = fehler_will_json() === false;

// --- Ausgabe ----------------------------------------------------------
// Geprueft wird die Erzeugung, nicht die Ausgabe: fehler_ausgeben()
// leert jeden Ausgabepuffer und liesse sich so gar nicht einfangen.
$html = fehler_seite_html('260904-1200-ABCD');
$checks['HTML-Seite nennt die Kennung'] = strpos($html, '260904-1200-ABCD') !== false;
$checks['HTML-Seite verraet keine Einzelheiten']
    = stripos($html, 'stack') === false && stripos($html, '.php:') === false;
$checks['HTML-Seite fuehrt zurueck'] = strpos($html, 'href="index"') !== false;

$json = fehler_seite_json('260904-1200-BEEF');
$daten = json_decode($json, true);
$checks['JSON-Antwort ist gueltiges JSON'] = is_array($daten);
$checks['JSON-Antwort traegt die Kennung'] = ($daten['ref'] ?? '') === '260904-1200-BEEF';
$checks['JSON-Antwort nennt keinen Klartext-Stapel'] = strpos($json, '#0') === false;

// ----------------------------------------------------------------------
$fail = 0;
foreach ($checks as $name => $ok) {
    echo ($ok ? 'PASS' : 'FAIL') . "  $name\n";
    if (!$ok) $fail = 1;
}
echo $fail === 0
    ? 'OK: ' . count($checks) . " Pruefungen bestanden.\n"
    : "FEHLGESCHLAGEN.\n";
exit($fail);
