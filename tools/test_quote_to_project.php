<?php
/**
 * Test fuer "Angebot zu Projekt".
 * Aufruf: php tools/test_quote_to_project.php
 *
 * Es gab "Angebot zu Rechnung", aber nicht "Angebot zu Projekt" - wer
 * den Auftrag bekam, tippte die Positionen, die er gerade beschrieben
 * hatte, ein zweites Mal ab.
 *
 * Der Fall, an dem es still schiefginge, ist das ZWEITE Projekt: ein
 * Doppelklick, eine zurueckgeblaetterte Seite, und dieselbe Arbeit steht
 * doppelt in der Liste - beide Haelften halb gepflegt, und es faellt
 * erst auf, wenn jemand den falschen Meilenstein abhakt.
 */

require_once __DIR__ . '/lib_sqlite_mirror.php';
require_once __DIR__ . '/../includes/quote_to_project.php';

$wurzel = dirname(__DIR__);
$pdo = new SqliteSpiegelPDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('PRAGMA foreign_keys = ON');
foreach (nach_sqlite(file_get_contents($wurzel . '/install/schema.sql')) as $anweisung) {
    $pdo->exec($anweisung);
}

$checks = [];

// =====================================================================
// Der Projekttitel
// =====================================================================
// Der Betreff des Angebots, nicht der Kundenname: ein Kunde kann
// mehrere Projekte haben, und derselbe Name dreimal in der Liste hilft
// niemandem.
$checks['Betreff wird der Titel']
    = projekt_titel_aus_angebot(['subject' => 'Relaunch Website', 'quote_number' => 'ANG-2026-001'])
      === 'Relaunch Website';
$checks['ohne Betreff die Nummer']
    = projekt_titel_aus_angebot(['subject' => '', 'quote_number' => 'ANG-2026-001'])
      === 'Projekt zu ANG-2026-001';
$checks['nur Leerzeichen zaehlt als leer']
    = projekt_titel_aus_angebot(['subject' => '   ', 'quote_number' => 'ANG-2026-002'])
      === 'Projekt zu ANG-2026-002';

// =====================================================================
// Positionen zu Meilensteinen
// =====================================================================
$positionen = [
    ['desc' => 'Konzept und Entwurf',  'qty' => 1,   'price' => 900, 'unit' => 'Pauschale'],
    ['desc' => 'Schulung',             'qty' => 3,   'price' => 400, 'unit' => 'Tage'],
    ['desc' => 'Betreuung',            'qty' => 2.5, 'price' => 100, 'unit' => 'Std'],
    ['desc' => 'Ohne Einheit',         'qty' => 4,   'price' => 50,  'unit' => ''],
];
$ms = meilensteine_aus_positionen($positionen);

// Menge 1 kommt nicht in den Titel - "Konzept (1 Pauschale)" liest sich
// wie ein Formularfehler.
$checks['Menge 1 bleibt weg']       = $ms[0] === 'Konzept und Entwurf';
// Alles andere schon: "Schulung" und "Schulung (3 Tage)" sind
// verschiedene Zusagen, und beim Abhaken will man wissen, welche.
$checks['ganze Menge ohne Komma']   = $ms[1] === 'Schulung (3 Tage)';
$checks['gebrochene Menge deutsch'] = $ms[2] === 'Betreuung (2,50 Std)';
$checks['Menge ohne Einheit']       = $ms[3] === 'Ohne Einheit (4)';

// Leere Beschreibungen fallen heraus - das Formular haelt immer eine
// leere Zeile zum Weitertippen bereit.
$checks['leere Position faellt weg']
    = count(meilensteine_aus_positionen([['desc' => '', 'qty' => 1], ['desc' => '  ', 'qty' => 1]])) === 0;

// Mehrzeiliges wird auf die erste Zeile gekuerzt: das Feld ist ein
// Titel, kein Textblock. Die vollstaendige Fassung bleibt im Angebot.
$mehrzeilig = meilensteine_aus_positionen([
    ['desc' => "Erste Zeile\nZweite Zeile\nDritte", 'qty' => 1],
]);
$checks['nur die erste Zeile'] = $mehrzeilig[0] === 'Erste Zeile';

// Und ueberlange Titel werden gekappt, statt die Spalte zu sprengen.
$lang = meilensteine_aus_positionen([['desc' => str_repeat('A', 400), 'qty' => 1]]);
$checks['Titel wird auf 255 gekappt'] = mb_strlen($lang[0]) === 255;

$checks['ohne Positionen keine Meilensteine'] = meilensteine_aus_positionen([]) === [];

// =====================================================================
// Die Umwandlung
// =====================================================================
$pdo->exec("INSERT INTO contacts (name, contact_type, email) VALUES ('Anna Beispiel', 'Kunde', 'anna@example.com')");
$anna = (int) $pdo->lastInsertId();

$items = json_encode([
    ['desc' => 'Konzept', 'qty' => 1, 'price' => 900, 'unit' => 'Pauschale'],
    ['desc' => 'Umsetzung', 'qty' => 1, 'price' => 2400, 'unit' => 'Pauschale'],
    ['desc' => 'Schulung', 'qty' => 2, 'price' => 400, 'unit' => 'Tage'],
]);

$pdo->prepare(
    "INSERT INTO quotes (quote_number, subject, intro_text, contact_id, status, tax_type, items, notes, total_amount)
     VALUES ('ANG-2026-001', 'Relaunch Website', 'Wir freuen uns auf die Zusammenarbeit.', ?, 'Angenommen', 'regel', ?, 'Zahlbar in drei Raten.', 3700.00)"
)->execute([$anna, $items]);
$angebot = (int) $pdo->lastInsertId();

$checks['vorher gibt es kein Projekt'] = angebot_projekt($pdo, $angebot) === null;

$task = projekt_aus_angebot($pdo, $angebot);
$checks['die Umwandlung liefert eine Kennung'] = is_int($task) && $task > 0;

$zeile = $pdo->query("SELECT * FROM tasks WHERE id = $task")->fetch(PDO::FETCH_ASSOC);
$checks['der Betreff wurde der Titel']  = $zeile['title'] === 'Relaunch Website';
$checks['der Kunde wandert mit']        = (int) $zeile['contact_id'] === $anna;
$checks['der Status ist offen']         = $zeile['status'] === 'Offen';
// Der Einleitungstext des Angebots wird die Beschreibung - man hat ihn
// dem Kunden ohnehin geschrieben.
$checks['der Einleitungstext wandert mit'] = strpos((string) $zeile['description'], 'Wir freuen uns') !== false;
// Und die Herkunft steht dabei, damit spaeter nachvollziehbar ist,
// woher das Projekt kommt.
$checks['die Herkunft steht dabei']     = strpos((string) $zeile['description'], 'ANG-2026-001') !== false;

// --- Die Meilensteine --------------------------------------------------
$meilensteine = $pdo->query(
    "SELECT title FROM task_milestones WHERE task_id = $task ORDER BY id"
)->fetchAll(PDO::FETCH_COLUMN);

$checks['drei Positionen, drei Meilensteine'] = count($meilensteine) === 3;
$checks['Reihenfolge bleibt']                 = $meilensteine[0] === 'Konzept';
$checks['die Menge kommt mit']                = $meilensteine[2] === 'Schulung (2 Tage)';

// --- Die Mitgliedschaft ------------------------------------------------
// Ohne Eintrag in task_contacts sieht der Kunde sein eigenes Projekt im
// Portal nicht - seit Migration 5 fuellt es seine Listen darueber.
$mitglied = $pdo->query(
    "SELECT role FROM task_contacts WHERE task_id = $task AND contact_id = $anna"
)->fetchColumn();
$checks['der Kunde wird Mitglied']    = $mitglied !== false;
$checks['und zwar als Eigentuemer']   = $mitglied === 'owner';

// --- Die Verknuepfung zurueck ------------------------------------------
$checks['das Angebot kennt sein Projekt'] = angebot_projekt($pdo, $angebot) === $task;

// --- Der Status des Angebots bleibt unberuehrt -------------------------
// Ein Angebot kann angenommen sein, ohne dass ein Projekt dazugehoert,
// und Arbeit faengt manchmal an, bevor die Zusage schriftlich vorliegt.
$status = $pdo->query("SELECT status FROM quotes WHERE id = $angebot")->fetchColumn();
$checks['der Angebotsstatus bleibt'] = $status === 'Angenommen';

// =====================================================================
// Ein Angebot ohne Positionen und ohne Kontakt
// =====================================================================
$pdo->exec(
    "INSERT INTO quotes (quote_number, subject, contact_id, status, tax_type, items, total_amount)
     VALUES ('ANG-2026-002', '', NULL, 'Gesendet', 'kleinunternehmer', '[]', 0.00)"
);
$leer = (int) $pdo->lastInsertId();
$task2 = projekt_aus_angebot($pdo, $leer);

$checks['auch ohne Positionen entsteht ein Projekt'] = is_int($task2) && $task2 > 0;
$checks['dann ohne Meilensteine']
    = (int) $pdo->query("SELECT COUNT(*) FROM task_milestones WHERE task_id = $task2")->fetchColumn() === 0;
$checks['und ohne Mitglied']
    = (int) $pdo->query("SELECT COUNT(*) FROM task_contacts WHERE task_id = $task2")->fetchColumn() === 0;
$zeile2 = $pdo->query("SELECT title, contact_id FROM tasks WHERE id = $task2")->fetch(PDO::FETCH_ASSOC);
$checks['der Titel faellt auf die Nummer zurueck'] = $zeile2['title'] === 'Projekt zu ANG-2026-002';
$checks['ohne Kontakt bleibt das Feld leer']       = $zeile2['contact_id'] === null;

// =====================================================================
// Ein Angebot, das es nicht gibt
// =====================================================================
$checks['unbekanntes Angebot gibt null'] = projekt_aus_angebot($pdo, 99999) === null;
$checks['und hat kein Projekt']          = angebot_projekt($pdo, 99999) === null;

// Ein Angebot im Papierkorb ebenso.
$pdo->exec("UPDATE quotes SET deleted_at = '2026-01-01' WHERE id = $leer");
$checks['Angebot im Papierkorb gibt null'] = projekt_aus_angebot($pdo, $leer) === null;

// =====================================================================
// Das geloeschte Projekt gibt das Angebot wieder frei
// =====================================================================
// ON DELETE SET NULL: wer das Projekt wegwirft, soll das Angebot erneut
// umwandeln koennen - sonst haengt das Angebot an etwas, das es nicht
// mehr gibt.
$pdo->exec("UPDATE tasks SET deleted_at = '2026-02-01' WHERE id = $task");
$checks['nach dem Loeschen ist das Angebot wieder frei'] = angebot_projekt($pdo, $angebot) === null;

// =====================================================================
// Ergebnis
// =====================================================================
$fehler = 0;
foreach ($checks as $name => $ok) {
    if (!$ok) {
        echo "FEHLER: $name\n";
        $fehler++;
    }
}

if ($fehler === 0) {
    echo 'OK: ' . count($checks) . " Pruefungen bestanden.\n";
    exit(0);
}
echo "\nFEHLGESCHLAGEN: $fehler von " . count($checks) . " Pruefungen.\n";
exit(1);
