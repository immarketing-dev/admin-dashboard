<?php
/**
 * Rendert reports.php wirklich - gegen den SQLite-Spiegel.
 * Aufruf: php tools/test_reports_render.php
 *
 * tools/test_reports.php prueft die Abfragen und das Rechnen.
 * check_includes.php prueft, dass jede aufgerufene Funktion geladen ist.
 * Beides zusammen sagt noch nicht, dass die Seite auch laeuft: ein
 * falscher Array-Schluessel im Markup, eine Variable, die nur in einem
 * der beiden Reiter gesetzt wird, ein max() auf eine leere Liste - das
 * sieht keiner der beiden, und im Browser ist es ein weisses Fenster.
 *
 * Deshalb wird hier der ECHTE Quelltext der Seite ausgefuehrt. Ersetzt
 * werden nur die beiden Zeilen, die eine MySQL-Verbindung und eine
 * Sitzung braeuchten (config.php und includes/auth.php); alles andere -
 * Abfragen, Schleifen, Markup - ist das, was auch ausgeliefert wird.
 * Wird die Seite umgebaut, laeuft dieser Test ueber den neuen Stand,
 * nicht ueber eine Abschrift.
 */

require_once __DIR__ . '/lib_sqlite_mirror.php';

$wurzel = dirname(__DIR__);
$fehler = 0;

// ── Umgebung, die sonst config.php stellt ────────────────────────────
define('COMPANY_SHORT', 'Testfirma');
define('COMPANY_NAME',  'Testfirma GmbH');
define('COLOR_PRIMARY', '#149ddd');
define('COLOR_SIDEBAR', '#040b14');
define('DEMO_MODE',     false);
define('APP_NAME',      'Testpanel');

$EINSTELLUNGEN = ['default_hourly_rate' => '75'];

function setting(string $key, string $default = ''): string {
    global $EINSTELLUNGEN;
    return $EINSTELLUNGEN[$key] ?? $default;
}
function asset(string $pfad): string { return $pfad; }
function csrf_token(): string { return 'testtoken'; }
function csrf_field(): string { return ''; }

// Nicht nachgebaut, sondern eingebunden: demo.php bringt demo_mode()
// UND demo_einstellung() mit, das i18n.php beim Ermitteln der Sprache
// aufruft. Eine eigene Attrappe fuer demo_mode() allein liess die
// Seite mit "undefined function demo_einstellung()" auflaufen.
require_once $wurzel . '/includes/demo.php';
require_once $wurzel . '/includes/i18n.php';
require_once $wurzel . '/includes/reports.php';

// ── Datenbank ────────────────────────────────────────────────────────
$pdo = new SqliteSpiegelPDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('PRAGMA foreign_keys = ON');
foreach (nach_sqlite(file_get_contents($wurzel . '/install/schema.sql')) as $anweisung) {
    $pdo->exec($anweisung);
}

/**
 * Fuehrt reports.php aus und gibt das erzeugte Markup zurueck.
 *
 * Die drei require-Zeilen am Kopf werden entfernt: config.php braeuchte
 * MySQL, includes/auth.php eine Sitzung, includes/logging.php haengt an
 * config.php. reports.php selbst protokolliert nichts - es liest nur.
 *
 * head.php und layout_start.php bringen die Seitenleiste mit, und die
 * fragt fuenf Zaehler ab. Das laeuft gegen den Spiegel, ist also Teil
 * des Tests - faellt eine dieser Abfragen um, faellt es hier auf.
 */
function seite_rendern(PDO $pdo, array $get): string
{
    $wurzel = dirname(__DIR__);
    $quelle = file_get_contents($wurzel . '/reports.php');

    $quelle = preg_replace(
        '/^require_once .*(config\.php|auth\.php|logging\.php).*$/m',
        '// (im Test ersetzt)',
        $quelle
    );
    // Die eingebundenen Rahmendateien liegen relativ zum Wurzelverzeichnis.
    $quelle = str_replace("require 'includes/", "require '" . $wurzel . "/includes/", $quelle);

    $_GET = $get;
    $_SERVER['PHP_SELF'] = '/reports.php';
    $_SERVER['REQUEST_URI'] = '/reports';
    $_SERVER['QUERY_STRING'] = http_build_query($get);

    // Ueber eine temporaere Datei und include statt eval: so meldet PHP
    // Fehler mit brauchbarer Zeilennummer, und der Quelltext laeuft
    // durch denselben Weg wie im Betrieb. In eine eigene Funktion
    // gekapselt, damit die Seite ihre eigenen Variablen bekommt und
    // nicht die des Testlaufs ueberschreibt.
    $tmp = tempnam(sys_get_temp_dir(), 'rep') . '.php';
    file_put_contents($tmp, $quelle);

    $ausfuehren = static function (string $datei, PDO $pdo): string {
        ob_start();
        try {
            include $datei;
        } catch (Throwable $e) {
            ob_end_clean();
            throw $e;
        }
        return (string) ob_get_clean();
    };

    try {
        return $ausfuehren($tmp, $pdo);
    } finally {
        @unlink($tmp);
    }
}

/** Prueft eine Seite auf Fehlerspuren und liefert das Markup. */
function pruefe(PDO $pdo, array $get, string $name): ?string
{
    global $fehler;

    // Warnungen und Hinweise sind hier keine Nebensache: "Undefined
    // array key" bedeutet, dass an dieser Stelle im Browser nichts oder
    // etwas Falsches steht.
    $gesammelt = [];
    set_error_handler(function ($nr, $text, $datei, $zeile) use (&$gesammelt) {
        $gesammelt[] = "$text (Zeile $zeile)";
        return true;
    });

    try {
        $html = seite_rendern($pdo, $get);
    } catch (Throwable $e) {
        restore_error_handler();
        echo "FEHLER [$name]: " . $e->getMessage() . "\n";
        $fehler++;
        return null;
    }
    restore_error_handler();

    foreach ($gesammelt as $m) {
        echo "FEHLER [$name]: $m\n";
        $fehler++;
    }
    return $html;
}

/** Kleine Behauptung ueber das erzeugte Markup. */
function enthaelt(?string $html, string $text, string $name): void
{
    global $fehler;
    if ($html === null) return;
    if (strpos($html, $text) === false) {
        echo "FEHLER [$name]: erwartet, aber nicht gefunden: $text\n";
        $fehler++;
    }
}

// =====================================================================
// 1. Die leere Installation
// =====================================================================
// Der Fall, den eine frische Installation zuerst sieht - und der Fall,
// in dem max() auf eine leere Liste laeuft, wenn niemand daran gedacht
// hat.
$html = pruefe($pdo, [], 'leer/auswertung');
enthaelt($html, 'Keine offenen Rechnungen', 'leer/auswertung');
enthaelt($html, 'Umsatz je Kunde', 'leer/auswertung');
enthaelt($html, 'Jede erfasste Stunde ist abgerechnet', 'leer/auswertung');

$html = pruefe($pdo, ['tab' => 'timesheet'], 'leer/stundenzettel');
enthaelt($html, 'keine Zeit erfasst', 'leer/stundenzettel');

// =====================================================================
// 2. Mit Daten
// =====================================================================
$pdo->exec("INSERT INTO contacts (name, contact_type, hourly_rate) VALUES ('Anna Beispiel', 'Kunde', 95.00)");
$anna = (int) $pdo->lastInsertId();

$pdo->prepare(
    "INSERT INTO finances (type, title, invoice_number, contact_id, amount, status, record_date, due_date, reminder_count)
     VALUES ('INCOME', 'Rechnung A', 'RE-2026-001', ?, 1500.00, 'Überfällig', '2026-06-01', '2026-06-15', 2)"
)->execute([$anna]);
$pdo->prepare(
    "INSERT INTO finances (type, title, invoice_number, contact_id, amount, status, record_date, due_date)
     VALUES ('INCOME', 'Rechnung B', 'RE-2026-002', ?, 800.00, 'Bezahlt', '2026-07-01', '2026-07-15')"
)->execute([$anna]);

$pdo->prepare("INSERT INTO tasks (title, status, contact_id, hourly_rate) VALUES ('Relaunch', 'In Bearbeitung', ?, 120.00)")
    ->execute([$anna]);
$projekt = (int) $pdo->lastInsertId();

$te = $pdo->prepare("INSERT INTO time_entries (task_id, duration_minutes, note, created_at) VALUES (?, ?, ?, ?)");
$te->execute([$projekt, 150, 'Konzept',   date('Y-m-d') . ' 09:00:00']);
$te->execute([$projekt,  45, 'Abstimmung', date('Y-m-d') . ' 14:30:00']);

$html = pruefe($pdo, [], 'daten/auswertung');
enthaelt($html, 'RE-2026-001',   'daten/auswertung');
enthaelt($html, 'Anna Beispiel', 'daten/auswertung');
enthaelt($html, 'Relaunch',      'daten/auswertung');
// 195 Minuten zu 120,00 EUR sind 3,25 Stunden - der offene Wert.
enthaelt($html, '390,00',        'daten/auswertung');
enthaelt($html, '3:15',          'daten/auswertung');

$html = pruefe($pdo, ['tab' => 'timesheet', 'modus' => 'month'], 'daten/stundenzettel');
enthaelt($html, 'Konzept',   'daten/stundenzettel');
enthaelt($html, 'Relaunch',  'daten/stundenzettel');
enthaelt($html, '3:15',      'daten/stundenzettel');

// =====================================================================
// 3. Die uebrigen Zeitraeume und ein Jahr ohne Daten
// =====================================================================
pruefe($pdo, ['tab' => 'timesheet', 'modus' => 'week'], 'woche');
pruefe($pdo, ['tab' => 'timesheet', 'modus' => 'year'], 'jahr');
pruefe($pdo, ['jahr' => '2019'], 'jahr ohne Daten');

// Unsinn in der Adresszeile darf die Seite nicht kippen.
pruefe($pdo, ['tab' => 'quatsch', 'modus' => 'quatsch', 'anker' => 'kein-datum', 'jahr' => 'abc'], 'unsinnige Parameter');

// =====================================================================
// 4. Auf englischer Oberflaeche
// =====================================================================
// Die Altersstufen kommen als Variable an und laufen ueber datenwert().
// Bliebe das aus, stuende hier weiterhin Deutsch.
// sprache_setzen() statt der Einstellung: lang() merkt sich das
// Ergebnis in $GLOBALS, eine spaeter geaenderte Einstellung wuerde also
// nicht mehr greifen. Genau dafuer gibt es die Funktion - das Portal
// stellt damit auf die Sprache des jeweiligen Kontakts um.
sprache_setzen('en');
$html = pruefe($pdo, [], 'englisch');
enthaelt($html, 'Outstanding invoices by age', 'englisch');
enthaelt($html, 'not yet due', 'englisch');
enthaelt($html, 'over 90 days', 'englisch');
sprache_setzen('de');

// =====================================================================
// Ergebnis
// =====================================================================
if ($fehler === 0) {
    echo "OK: reports.php rendert in allen geprueften Zustaenden ohne Fehler.\n";
    exit(0);
}
echo "\nFEHLGESCHLAGEN: $fehler Beanstandung(en).\n";
exit(1);
