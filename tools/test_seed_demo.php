<?php
/**
 * Führt die Demodaten wirklich aus - gegen SQLite im Arbeitsspeicher.
 *
 * Auf der Entwicklungsmaschine steht kein MySQL zur Verfügung, und eine
 * reine Syntaxprüfung sagt über einen Seed wenig: der häufigste Fehler
 * ist ein Spaltenname, den es nicht gibt, und der fällt erst beim
 * Einfügen auf. Deshalb wird install/schema.sql hier nach SQLite
 * übersetzt, der Seed dagegen laufen gelassen und das Ergebnis geprüft.
 *
 * Das prüft in einem Durchgang mit:
 *   - dass jede Spalte, die der Seed befüllt, im Schema existiert
 *   - dass die Reihenfolge der Einfügungen die Fremdschlüssel erfüllt
 *   - dass die erzeugten Verweise tatsächlich auflösen
 *
 * Was es NICHT prüft: MySQL-eigenes Verhalten (ENUM-Prüfung,
 * Zeichensatz, ON DUPLICATE KEY). Dafür bräuchte es einen echten Server.
 *
 * Aufruf: php tools/test_seed_demo.php
 */

$fehler = [];
$pruef  = 0;

function pruefe(string $was, bool $bedingung, string $detail = ''): void
{
    global $fehler, $pruef;
    $pruef++;
    if (!$bedingung) {
        $fehler[] = $was . ($detail !== '' ? ' - ' . $detail : '');
    }
}

// ─────────────────────────────────────────────────────────────────────
// 1. Schema nach SQLite übersetzen
// ─────────────────────────────────────────────────────────────────────

require_once __DIR__ . '/lib_sqlite_mirror.php';


// ─────────────────────────────────────────────────────────────────────
// 2. Datenbank aufbauen
// ─────────────────────────────────────────────────────────────────────
$wurzel = dirname(__DIR__);
$pdo = new SqliteSpiegelPDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('PRAGMA foreign_keys = ON');

$angelegt = 0;
foreach (nach_sqlite(file_get_contents($wurzel . '/install/schema.sql')) as $anweisung) {
    try {
        $pdo->exec($anweisung);
        if (stripos($anweisung, 'CREATE TABLE') !== false) $angelegt++;
    } catch (PDOException $e) {
        $fehler[] = 'Schema liess sich nicht anlegen: ' . $e->getMessage()
                  . "\n    " . substr(preg_replace('/\s+/', ' ', $anweisung), 0, 160);
    }
}
pruefe('Alle 25 Tabellen angelegt', $angelegt === 25, "es wurden $angelegt angelegt");

if ($fehler !== []) {
    echo "FEHLGESCHLAGEN beim Aufbau des Schemas:\n";
    foreach ($fehler as $f) echo '  - ' . $f . "\n";
    exit(1);
}

// ─────────────────────────────────────────────────────────────────────
// 3. Seed ausführen
// ─────────────────────────────────────────────────────────────────────
// Der Datenteil braucht demo_portal_pin() und BASE_URL, aber keine
// Datenbankverbindung aus config.php.
require_once $wurzel . '/includes/env.php';
if (!defined('DEMO_MODE')) define('DEMO_MODE', true);
if (!defined('BASE_URL'))  define('BASE_URL', 'https://demo.example');
require_once $wurzel . '/includes/demo.php';

require $wurzel . '/tools/seed_demo_lib.php';

// Der Seed legt Platzhalterdateien unter uploads/ an. Auf dem Demo-Server
// gehoeren sie dorthin, hier nicht: tools/check.sh meldet zu Recht jede
// Datei in uploads/, damit nie ein echter Kundenupload im Repository
// landet. Deshalb wird gemerkt, was vorher da war, und der Rest am Ende
// wieder entfernt.
$upload_verzeichnisse = [$wurzel . '/uploads/client_assets', $wurzel . '/uploads/wiki'];
$vorher = [];
foreach ($upload_verzeichnisse as $dir) {
    $vorher[$dir] = is_dir($dir) ? array_diff(scandir($dir), ['.', '..']) : null;
}

ob_start();
try {
    require $wurzel . '/tools/seed_demo_data.php';
    $ausgabe = ob_get_clean();
} catch (Throwable $e) {
    ob_end_clean();
    echo "FEHLGESCHLAGEN: der Seed brach ab.\n  " . $e->getMessage() . "\n";
    exit(1);
}

// ─────────────────────────────────────────────────────────────────────
// 4. Ergebnis prüfen
// ─────────────────────────────────────────────────────────────────────
$zaehle = fn(string $t, string $wo = '1=1'): int
    => (int) $pdo->query("SELECT COUNT(*) FROM $t WHERE $wo")->fetchColumn();

echo "=== Pruefung 1: keine Tabelle bleibt leer ===\n";
// settings, users und sso_tokens ausgenommen: die ersten beiden sind
// Konfiguration, sso_tokens fuellt sich nur im Betrieb.
$erwartet_gefuellt = [
    'contacts', 'leads_inbox', 'tasks', 'task_milestones', 'task_contacts',
    'milestone_comments', 'project_comments', 'client_assets', 'time_entries',
    'finances', 'quotes', 'support_tickets', 'ticket_notes', 'wiki_articles',
    'wiki_attachments', 'wiki_client_shares', 'calendar_events', 'event_contacts',
    'monitored_urls', 'logs',
];
$leer = [];
foreach ($erwartet_gefuellt as $t) {
    if ($zaehle($t) === 0) $leer[] = $t;
}
pruefe('Jede inhaltstragende Tabelle hat Zeilen', $leer === [], 'leer: ' . implode(', ', $leer));
echo $leer === []
    ? '  OK: alle ' . count($erwartet_gefuellt) . " inhaltstragenden Tabellen sind gefuellt.\n"
    : '  FEHLER: leer geblieben: ' . implode(', ', $leer) . "\n";

echo "\n=== Pruefung 2: erwartete Mengen ===\n";
$mengen = ['contacts' => 6, 'tasks' => 8, 'quotes' => 6, 'support_tickets' => 5,
           'monitored_urls' => 4, 'users' => 1];
foreach ($mengen as $t => $soll) {
    $ist = $zaehle($t);
    pruefe("Anzahl in $t", $ist === $soll, "$ist statt $soll");
    printf("  %-18s %3d (erwartet %d)%s\n", $t, $ist, $soll, $ist === $soll ? '' : '  <-- ABWEICHUNG');
}
printf("  %-18s %3d\n", 'finances', $zaehle('finances'));
printf("  %-18s %3d\n", 'logs', $zaehle('logs'));
printf("  %-18s %3d\n", 'time_entries', $zaehle('time_entries'));

echo "\n=== Pruefung 3: Verweise loesen auf ===\n";
$verweise = [
    ['tasks', 'contact_id', 'contacts'],
    ['task_milestones', 'task_id', 'tasks'],
    ['task_contacts', 'task_id', 'tasks'],
    ['task_contacts', 'contact_id', 'contacts'],
    ['milestone_comments', 'milestone_id', 'task_milestones'],
    ['project_comments', 'task_id', 'tasks'],
    ['project_comments', 'author_contact_id', 'contacts'],
    ['client_assets', 'task_id', 'tasks'],
    ['client_assets', 'uploaded_by_contact_id', 'contacts'],
    ['time_entries', 'task_id', 'tasks'],
    ['finances', 'contact_id', 'contacts'],
    ['quotes', 'contact_id', 'contacts'],
    ['support_tickets', 'contact_id', 'contacts'],
    ['ticket_notes', 'ticket_id', 'support_tickets'],
    ['wiki_attachments', 'article_id', 'wiki_articles'],
    ['wiki_client_shares', 'article_id', 'wiki_articles'],
    ['wiki_client_shares', 'contact_id', 'contacts'],
    ['event_contacts', 'event_id', 'calendar_events'],
    ['event_contacts', 'contact_id', 'contacts'],
];
$kaputt = [];
foreach ($verweise as [$t, $spalte, $ziel]) {
    $n = (int) $pdo->query(
        "SELECT COUNT(*) FROM $t WHERE $spalte IS NOT NULL
         AND $spalte NOT IN (SELECT id FROM $ziel)")->fetchColumn();
    if ($n > 0) $kaputt[] = "$t.$spalte -> $ziel ($n)";
}
pruefe('Alle Fremdschluessel loesen auf', $kaputt === [], implode(', ', $kaputt));
echo $kaputt === []
    ? '  OK: ' . count($verweise) . " Verweisketten geprueft, alle loesen auf.\n"
    : '  FEHLER: ' . implode("\n         ", $kaputt) . "\n";

echo "\n=== Pruefung 4: Portalzugaenge ===\n";
$tokens = $pdo->query('SELECT name, portal_token FROM contacts WHERE portal_token IS NOT NULL')
              ->fetchAll(PDO::FETCH_KEY_PAIR);
pruefe('Fuenf Kontakte haben einen Portalzugang', count($tokens) === 5, count($tokens) . ' statt 5');
$falsche_laenge = array_filter($tokens, fn($t) => strlen($t) !== 64);
pruefe('Jeder Token hat 64 Zeichen', $falsche_laenge === []);
pruefe('Kein Token doppelt', count(array_unique($tokens)) === count($tokens));
$mit_pin = $zaehle('contacts', 'portal_pin IS NOT NULL');
pruefe('Jeder Portalzugang hat einen Zugangscode', $mit_pin === count($tokens),
       "$mit_pin Zugangscodes bei " . count($tokens) . ' Zugaengen');
$erste = reset($tokens);
pruefe('Der Zugangscode passt zum eingestellten Wert',
       password_verify(demo_portal_pin(),
           $pdo->query('SELECT portal_pin FROM contacts WHERE portal_pin IS NOT NULL LIMIT 1')->fetchColumn()));
echo '  OK: ' . count($tokens) . " Zugaenge, Codes pruefbar, Tokens eindeutig.\n";

echo "\n=== Pruefung 5: Rechnungsnummern ===\n";
$nummern = $pdo->query("SELECT invoice_number FROM finances WHERE invoice_number IS NOT NULL")
               ->fetchAll(PDO::FETCH_COLUMN);
pruefe('Rechnungsnummern sind eindeutig', count(array_unique($nummern)) === count($nummern));
$falsches_format = array_filter($nummern, fn($n) => !preg_match('/^RE-\d{4}-\d{3}$/', $n));
pruefe('Format RE-JJJJ-NNN eingehalten', $falsches_format === [],
       implode(', ', array_slice($falsches_format, 0, 3)));
$einnahmen_ohne = $zaehle('finances', "type = 'INCOME' AND (invoice_number IS NULL OR invoice_number = '')");
pruefe('Jede Einnahme traegt eine Nummer', $einnahmen_ohne === 0, "$einnahmen_ohne ohne Nummer");
echo '  OK: ' . count($nummern) . " Rechnungsnummern, eindeutig und im richtigen Format.\n";

echo "\n=== Pruefung 6: Angebotspositionen ===\n";
$kaputte_items = [];
foreach ($pdo->query('SELECT quote_number, items, total_amount FROM quotes') as $q) {
    $positionen = json_decode($q['items'], true);
    if (!is_array($positionen) || $positionen === []) {
        $kaputte_items[] = $q['quote_number'] . ': kein gueltiges JSON';
        continue;
    }
    $summe = 0.0;
    foreach ($positionen as $pos) {
        if (!isset($pos['desc'], $pos['qty'], $pos['price'])) {
            $kaputte_items[] = $q['quote_number'] . ': Position ohne desc/qty/price';
            continue 2;
        }
        $summe += $pos['qty'] * $pos['price'];
    }
    if (abs($summe - (float) $q['total_amount']) > 0.01) {
        $kaputte_items[] = $q['quote_number'] . ': Summe ' . $q['total_amount'] . ' statt ' . $summe;
    }
}
pruefe('Alle Angebote haben gueltige Positionen', $kaputte_items === [], implode('; ', $kaputte_items));
echo $kaputte_items === []
    ? "  OK: alle Angebote tragen gueltiges JSON, die Summen stimmen.\n"
    : '  FEHLER: ' . implode("\n         ", $kaputte_items) . "\n";

echo "\n=== Pruefung 7: Zeitbezug ist relativ ===\n";
// Ein Seed mit festen Daten sieht nach einem halben Jahr verlassen aus.
$heute      = date('Y-m-d');
$vor_kurzem = $zaehle('logs', "created_at >= '" . date('Y-m-d', strtotime('-7 days')) . "'");
pruefe('Es gibt Protokolleintraege aus den letzten sieben Tagen', $vor_kurzem > 0);
$kuenftig = $zaehle('calendar_events', "event_date > '$heute'");
pruefe('Es gibt kuenftige Termine', $kuenftig > 0);
$vergangen = $zaehle('calendar_events', "event_date < '$heute'");
pruefe('Es gibt vergangene Termine', $vergangen > 0);
$offene_rechnungen = $zaehle('finances', "type = 'INCOME' AND status <> 'Bezahlt'");
pruefe('Es gibt offene Rechnungen', $offene_rechnungen > 0);

// Keine Buchung darf in der Zukunft liegen. Der Monatsversatz schob im
// laufenden Monat ueber heute hinaus, und die Finanzseite zeigte
// Bueromaterial mit einem Kaufdatum in neun Tagen.
$kuenftige_buchungen = $zaehle("finances", "record_date > '$heute'");
pruefe("Keine Buchung liegt in der Zukunft", $kuenftige_buchungen === 0,
       "$kuenftige_buchungen Buchung(en) mit Datum nach heute");

// Und im laufenden Monat muss etwas bezahlt sein, sonst steht die
// Finanzseite in ihrer Standardansicht auf 0,00 EUR Einnahmen.
$bezahlt_diesen_monat = $zaehle("finances",
    "type = 'INCOME' AND status = 'Bezahlt' AND record_date >= '" . date("Y-m-01") . "'");
pruefe("Im laufenden Monat ist eine Rechnung bezahlt", $bezahlt_diesen_monat > 0);
echo "  OK: $vor_kurzem Protokolleintraege der letzten Woche, $kuenftig kuenftige und "
   . "$vergangen vergangene Termine, $offene_rechnungen offene Rechnungen.\n";

echo "\n=== Pruefung 8: hinterlegte Dateien existieren ===\n";
$fehlende = [];
foreach ($pdo->query('SELECT file_name, file_path FROM client_assets') as $a) {
    if (!is_file($wurzel . '/' . $a['file_path'])) $fehlende[] = $a['file_path'];
}
foreach ($pdo->query('SELECT file_name, file_path FROM wiki_attachments') as $a) {
    if (!is_file($wurzel . '/' . $a['file_path'])) $fehlende[] = $a['file_path'];
}
pruefe('Jeder Dateiverweis zeigt auf eine vorhandene Datei', $fehlende === [],
       implode(', ', $fehlende));
echo $fehlende === []
    ? "  OK: alle Dateiverweise zeigen auf vorhandene Platzhalter.\n"
    : '  FEHLER: fehlt: ' . implode(', ', $fehlende) . "\n";

echo "\n=== Pruefung 9: keine echten Adressen ===\n";
// In einer oeffentlichen Demo darf keine erreichbare fremde Domain
// stehen - .example ist laut RFC 2606 dafuer reserviert.
$adressen = [];
foreach ([['contacts', 'email'], ['contacts', 'website'], ['leads_inbox', 'email'],
          ['monitored_urls', 'url_link'], ['calendar_events', 'meeting_url']] as [$t, $s]) {
    foreach ($pdo->query("SELECT $s FROM $t WHERE $s IS NOT NULL AND $s <> ''") as $r) {
        $adressen[] = $r[$s];
    }
}
$echte = array_values(array_filter($adressen,
    fn($a) => !preg_match('/(\.example|\.invalid|\.test|example\.(com|org|net))(\/|$|\?)/i', $a)));
pruefe('Alle Adressen liegen in reservierten Namensraeumen', $echte === [],
       implode(', ', array_slice($echte, 0, 5)));
echo $echte === []
    ? '  OK: ' . count($adressen) . " Adressen geprueft, alle in reservierten Namensraeumen.\n"
    : '  FEHLER: erreichbare Adresse(n): ' . implode(', ', $echte) . "\n";

// ─────────────────────────────────────────────────────────────────────
// 5. Aufraeumen
// ─────────────────────────────────────────────────────────────────────
// Erst jetzt, nach Pruefung 8: dort muessen die Dateien noch da sein.
$entfernt = 0;
foreach ($upload_verzeichnisse as $dir) {
    if (!is_dir($dir)) continue;
    $jetzt = array_diff(scandir($dir), ['.', '..']);
    $neu   = $vorher[$dir] === null ? $jetzt : array_diff($jetzt, $vorher[$dir]);
    foreach ($neu as $datei) {
        if (is_file($dir . '/' . $datei)) { unlink($dir . '/' . $datei); $entfernt++; }
    }
    // Verzeichnis nur entfernen, wenn der Test es selbst angelegt hat.
    if ($vorher[$dir] === null && array_diff(scandir($dir), ['.', '..']) === []) {
        rmdir($dir);
    }
}

// ─────────────────────────────────────────────────────────────────────
// 6. Zusammenfassung
// ─────────────────────────────────────────────────────────────────────
echo "\n=== Zusammenfassung ===\n";
if ($fehler === []) {
    $gesamt = 0;
    foreach ($erwartet_gefuellt as $t) $gesamt += $zaehle($t);
    echo "OK: $pruef Pruefungen bestanden, der Seed erzeugt $gesamt Zeilen in "
       . count($erwartet_gefuellt) . " Tabellen.\n";
    if ($entfernt > 0) echo "    ($entfernt Platzhalterdatei(en) nach dem Test wieder entfernt.)\n";
    exit(0);
}
echo 'FEHLGESCHLAGEN: ' . count($fehler) . " von $pruef Pruefungen.\n";
foreach ($fehler as $f) echo '  - ' . $f . "\n";
exit(1);
