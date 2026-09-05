<?php
/**
 * Prueft includes/demo.php: Riegel, Rueckleitung und AJAX-Erkennung.
 *
 * demo_reject() beendet die Anfrage mit exit(). Die Faelle, die dorthin
 * fuehren, laufen deshalb als eigener Prozess - diese Datei ruft sich
 * dafuer selbst mit --fall=... auf.
 *
 * Aufruf: php tools/test_demo.php
 */

$wurzel = dirname(__DIR__);
require_once $wurzel . '/includes/env.php';

// Vor jeder Ausgabe: session_start() scheitert sonst auch auf der
// Kommandozeile, sobald etwas geschrieben wurde. Pruefung 7 braucht
// eine laufende Sitzung.
ini_set('session.save_path', sys_get_temp_dir());
@session_start();

// ─── Teilprozess: einen einzelnen Fall ausfuehren ────────────────────
$fall = null;
foreach ($argv as $arg) {
    if (strpos($arg, '--fall=') === 0) $fall = substr($arg, 7);
}

if ($fall !== null) {
    // Der Cookie-Name haengt an einer Konstanten, laesst sich im selben
    // Prozess also nicht zweimal verschieden pruefen.
    if ($fall === 'sessionname-an' || $fall === 'sessionname-aus') {
        define('DEMO_MODE', $fall === 'sessionname-an');
        require_once $wurzel . '/includes/session.php';
        echo app_session_name();
        exit(0);
    }

    define('DEMO_MODE', true);
    require_once $wurzel . '/includes/demo.php';

    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_SERVER['SCRIPT_NAME']    = '/contacts.php';
    $_SERVER['QUERY_STRING']   = '';

    if ($fall === 'ajax') {
        $_SERVER['HTTP_X_REQUESTED_WITH'] = 'XMLHttpRequest';
        $_POST['action'] = 'update_status';
    } elseif ($fall === 'formular') {
        $_POST['action'] = 'add_contact';
    } elseif ($fall === 'erlaubt') {
        $_POST['action'] = 'verify_portal_pin';
    } elseif ($fall === 'get') {
        $_SERVER['REQUEST_METHOD'] = 'GET';
    }

    demo_guard();
    echo 'DURCHGELASSEN';   // nur erreichbar, wenn der Riegel nicht griff
    exit(0);
}

// ─── Haupttest ───────────────────────────────────────────────────────
define('DEMO_MODE', true);
require_once $wurzel . '/includes/demo.php';

$fehler = [];
$n = 0;

function pruefe(string $was, bool $ok, string $detail = ''): void
{
    global $fehler, $n;
    $n++;
    if (!$ok) $fehler[] = $was . ($detail !== '' ? " ($detail)" : '');
}

/** Ruft diese Datei als eigenen Prozess mit einem Fall auf. */
function fall_ausfuehren(string $fall): array
{
    $ausgabe = [];
    $code = 0;
    exec('php ' . escapeshellarg(__FILE__) . ' --fall=' . escapeshellarg($fall) . ' 2>&1',
         $ausgabe, $code);
    return [implode("\n", $ausgabe), $code];
}

echo "=== Pruefung 1: der Riegel greift nur, wo er soll ===\n";

[$aus, $code] = fall_ausfuehren('get');
pruefe('GET wird durchgelassen', strpos($aus, 'DURCHGELASSEN') !== false, $aus);

[$aus, $code] = fall_ausfuehren('erlaubt');
pruefe('Die PIN-Pruefung wird durchgelassen', strpos($aus, 'DURCHGELASSEN') !== false, $aus);

[$aus_f, $code] = fall_ausfuehren('formular');
pruefe('Ein gewoehnliches Formular wird abgewiesen',
       strpos($aus_f, 'DURCHGELASSEN') === false, $aus_f);

[$aus_a, $code] = fall_ausfuehren('ajax');
pruefe('Eine AJAX-Sendung wird abgewiesen',
       strpos($aus_a, 'DURCHGELASSEN') === false, $aus_a);
echo "OK: GET und PIN-Pruefung passieren, Formular und AJAX nicht.\n\n";

echo "=== Pruefung 2: die Antwort passt zur Anfrageart ===\n";
// Formular: keine Ausgabe, nur eine Weiterleitung (im CLI unsichtbar).
pruefe('Formularabweisung gibt keinen Rumpf aus', trim($aus_f) === '', $aus_f);

// AJAX: JSON, das die vorhandenen Aufrufer verstehen.
$json = json_decode(trim($aus_a), true);
pruefe('AJAX-Abweisung liefert gueltiges JSON', is_array($json), $aus_a);
if (is_array($json)) {
    // Die Aufrufer pruefen mal 'ok', mal 'success' - beide muessen falsch sein.
    pruefe("JSON enthaelt ok=false",      array_key_exists('ok', $json) && $json['ok'] === false);
    pruefe("JSON enthaelt success=false", array_key_exists('success', $json) && $json['success'] === false);
    pruefe("JSON enthaelt demo=true",     ($json['demo'] ?? null) === true);
    pruefe('JSON traegt einen Hinweistext', !empty($json['error']));
}
echo "OK: Formular bekommt eine Weiterleitung, AJAX bekommt JSON.\n\n";

echo "=== Pruefung 3: die Rueckleitung fuehrt nie nach draussen ===\n";
// demo_reject() baut das Ziel aus SCRIPT_NAME und QUERY_STRING neu auf.
// SCRIPT_NAME, nicht PHP_SELF: PHP_SELF traegt die PATH_INFO mit, und
// die stammt aus der Anfrage.
// Beides steht unter fremdem Einfluss, deshalb hier die Grenzfaelle.
$faelle = [
    ['/contacts.php', '',                              'contacts?demo=blocked'],
    ['/portal.php',   'token=abc123',                  'portal?token=abc123&demo=blocked'],
    ['/tasks.php',    'filter=offen&q=web',            'tasks?filter=offen&q=web&demo=blocked'],
    // Ein bereits gesetztes demo= darf sich nicht verdoppeln.
    ['/tasks.php',    'demo=blocked',                  'tasks?demo=blocked'],
];
foreach ($faelle as [$self, $qs, $erwartet]) {
    $_SERVER['SCRIPT_NAME']  = $self;
    $_SERVER['QUERY_STRING'] = $qs;
    $ist = demo_ruecksprung();
    pruefe("Rueckleitung fuer $self?$qs", $ist === $erwartet, "ergab '$ist', erwartet '$erwartet'");
}

// Fremde Ziele: weder ueber SCRIPT_NAME noch ueber die Abfrage.
$angriffe = [
    ['//evil.example/x.php',            'a=1'],
    ['/../../etc/passwd',               'a=1'],
    ['/contacts.php',                   'next=https://evil.example'],
    ['/contacts.php',                   'a=1&b=' . str_repeat('x', 900)],
];
foreach ($angriffe as [$self, $qs]) {
    $_SERVER['SCRIPT_NAME']  = $self;
    $_SERVER['QUERY_STRING'] = $qs;
    $ziel = demo_ruecksprung();
    pruefe("Kein fremdes Ziel aus SCRIPT_NAME='$self'",
           !preg_match('#^(https?:)?//#i', $ziel) && strpos($ziel, '..') === false,
           "ergab '$ziel'");
    pruefe('Rueckleitung bleibt kurz', strlen($ziel) <= 560, 'Laenge ' . strlen($ziel));
}
echo "OK: " . (count($faelle) + count($angriffe)) . " Faelle, kein Ziel verlaesst die eigene Seite.\n\n";

echo "=== Pruefung 4: AJAX-Erkennung ===\n";
unset($_SERVER['HTTP_X_REQUESTED_WITH']);
pruefe('Ohne Header keine AJAX-Anfrage', demo_ist_ajax() === false);
$_SERVER['HTTP_X_REQUESTED_WITH'] = 'XMLHttpRequest';
pruefe('Mit Header eine AJAX-Anfrage', demo_ist_ajax() === true);
$_SERVER['HTTP_X_REQUESTED_WITH'] = 'xmlhttprequest';
pruefe('Gross- und Kleinschreibung egal', demo_ist_ajax() === true);
unset($_SERVER['HTTP_X_REQUESTED_WITH']);
echo "OK: der Header entscheidet, nicht eine Liste von Aktionsnamen.\n\n";

echo "=== Pruefung 5: alle Aufrufer senden den Header ===\n";
// Die Erkennung traegt nur, solange jeder AJAX-Aufrufer sich zu erkennen
// gibt. Sonst bekaeme das JavaScript eine Weiterleitung statt JSON.
$aufrufer = [];
foreach (['board.php', 'portal.php'] as $datei) {
    $code = file_get_contents($wurzel . '/' . $datei);
    // Nur echte AJAX-Aufrufe zaehlen: fetch('...') und xhr.open('...').
    // Das naheliegende /fetch\s*\(/ trifft auch PDOs $stmt->fetch() und
    // meldete in portal.php elf Aufrufe, die keine sind.
    preg_match_all('/(?<!->)\bfetch\s*\(\s*[\'"]|\.open\s*\(\s*[\'"]/', $code, $m);
    $anzahl_aufrufe = count($m[0]);
    $anzahl_header  = substr_count($code, 'X-Requested-With');
    $aufrufer[$datei] = [$anzahl_aufrufe, $anzahl_header];
    pruefe("$datei kennzeichnet jeden AJAX-Aufruf",
           $anzahl_header >= $anzahl_aufrufe,
           "$anzahl_aufrufe Aufruf(e), $anzahl_header Kennzeichnung(en)");
}
foreach ($aufrufer as $d => [$a, $h]) echo "  $d: $a Aufruf(e), $h Kennzeichnung(en)\n";
echo "\n";

echo "=== Pruefung 6: die Demo teilt sich keine Sitzung mit dem Echtbetrieb ===\n";
// Das ist die Grenze, an der eine Demo im Unterverzeichnis derselben
// Adresse gefaehrlich wuerde: gleicher Cookie-Name bedeutet, dass die im
// Demo-Modus gesetzte Anmeldung auch fuer die echte Installation gilt.
[$name_aus, ] = fall_ausfuehren('sessionname-aus');
[$name_an,  ] = fall_ausfuehren('sessionname-an');
$name_aus = trim($name_aus);
$name_an  = trim($name_an);

pruefe('Der Echtbetrieb behaelt seinen bisherigen Cookie-Namen',
       $name_aus === 'ADMINPANELSESS', "ergab '$name_aus'");
pruefe('Die Demo bekommt einen anderen Cookie-Namen',
       $name_an !== $name_aus && $name_an !== '', "ergab '$name_an'");
echo "  Echtbetrieb: $name_aus\n  Demo:        $name_an\n";

// Und der Cookie-Pfad bleibt auf dem eigenen Verzeichnis.
require_once $wurzel . '/includes/session.php';
$pfade = [
    '/index.php'        => '/',
    '/tasks.php'        => '/',
    '/demo/index.php'   => '/demo/',
    '/demo/portal.php'  => '/demo/',
    '/kunden/demo/x.php' => '/kunden/demo/',
];
foreach ($pfade as $skript => $erwartet) {
    $_SERVER['SCRIPT_NAME'] = $skript;
    $ist = app_session_path();
    pruefe("Cookie-Pfad fuer $skript", $ist === $erwartet, "ergab '$ist', erwartet '$erwartet'");
}
echo '  ' . count($pfade) . " Pfade geprueft, Cookie bleibt im eigenen Verzeichnis.\n\n";

echo "=== Pruefung 7: die Wahl eines Besuchers bleibt bei ihm ===\n";
// Sprache und Farben darf ein Demo-Besucher aendern. Landeten sie in der
// Datenbank, saehe der naechste Besucher die Einstellung des vorigen - und
// eine unglueckliche Farbwahl bliebe fuer alle stehen.
$_SESSION = [];

pruefe('Ohne Wahl gilt der Standard',
       demo_einstellung('color_primary', '#149ddd') === '#149ddd');

demo_einstellung_setzen('color_primary', '#ff0000');
pruefe('Die eigene Wahl wird zurueckgegeben',
       demo_einstellung('color_primary', '#149ddd') === '#ff0000');
pruefe('Sie liegt in der Sitzung, nicht anderswo',
       ($_SESSION['demo_color_primary'] ?? null) === '#ff0000');

// Eine neue Sitzung ist eine leere Sitzung - genau das trennt die Besucher.
$_SESSION = [];
pruefe('Eine frische Sitzung sieht wieder den Standard',
       demo_einstellung('color_primary', '#149ddd') === '#149ddd');

// Nur die vorgesehenen Schluessel, sonst waere es ein Schlupfloch.
demo_einstellung_setzen('company_name', 'Fremdfirma');
pruefe('Ein nicht vorgesehener Schluessel wird abgewiesen',
       ($_SESSION['demo_company_name'] ?? null) === null);
pruefe('Und liefert weiter den Standard',
       demo_einstellung('company_name', 'Musterwerk') === 'Musterwerk');

// Die Anordnung der Startseiten-Widgets geht denselben Weg wie Sprache und
// Farben: in die Sitzung. Waere es die Datenbank, saehe der naechste
// Besucher die verschobenen Kacheln des vorherigen - und der SELECT-only-
// Benutzer der Demo scheiterte ohnehin beim Schreiben.
$_SESSION = [];
$anordnung = '{"v":1,"items":{"leads":{"x":0,"y":0,"w":6,"h":5}},"hidden":[]}';
demo_einstellung_setzen('dashboard_layout', $anordnung);
pruefe('Die Anordnung der Widgets landet in der Sitzung',
       ($_SESSION['demo_dashboard_layout'] ?? null) === $anordnung);
pruefe('Und wird dem Besucher zurueckgegeben',
       demo_einstellung('dashboard_layout', '') === $anordnung);
$_SESSION = [];
pruefe('Der naechste Besucher sieht wieder den Standard',
       demo_einstellung('dashboard_layout', 'standard') === 'standard');

// Die Liste ist ein Waechter: waechst sie unbemerkt, waechst die Flaeche,
// die den Schreibschutz umgeht. Deshalb steht sie hier ausgeschrieben und
// nicht bloss als Anzahl.
pruefe('Nur die vorgesehenen Einstellungen sind freigegeben',
       DEMO_EIGENE_EINSTELLUNGEN === ['ui_language', 'color_primary', 'color_sidebar', 'dashboard_layout'],
       implode(', ', DEMO_EIGENE_EINSTELLUNGEN));

$_SESSION = [];
echo '  OK: ' . count(DEMO_EIGENE_EINSTELLUNGEN)
   . " Einstellungen, sitzungsgebunden, ohne Datenbankzugriff.\n\n";

echo "=== Zusammenfassung ===\n";
if ($fehler === []) {
    echo "OK: $n Pruefungen bestanden.\n";
    exit(0);
}
echo 'FEHLGESCHLAGEN: ' . count($fehler) . " von $n Pruefungen.\n";
foreach ($fehler as $f) echo '  - ' . $f . "\n";
exit(1);
