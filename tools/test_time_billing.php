<?php
/**
 * Test fuer die Abrechnung erfasster Zeiten.
 * Aufruf: php tools/test_time_billing.php
 *
 * Der Timer in tasks.php erfasst Zeit seit Langem, aber sie endete nie
 * auf einer Rechnung: time_entries kannte weder einen Stundensatz noch
 * ein Kennzeichen "abgerechnet". Wer abrechnen wollte, zaehlte die
 * Minuten von Hand zusammen und tippte eine Position.
 *
 * Der gefaehrlichste Fall ist hier nicht die Rechnung selbst, sondern
 * die doppelte: wird eine Zeit nicht als abgerechnet vermerkt, taucht
 * sie beim naechsten Mal wieder auf und der Kunde zahlt zweimal.
 */

require_once __DIR__ . '/lib_sqlite_mirror.php';
require_once __DIR__ . '/../includes/time_billing.php';

$wurzel = dirname(__DIR__);
$pdo = new SqliteSpiegelPDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('PRAGMA foreign_keys = ON');
foreach (nach_sqlite(file_get_contents($wurzel . '/install/schema.sql')) as $anweisung) {
    $pdo->exec($anweisung);
}

// --- Ausgangslage -----------------------------------------------------
$pdo->exec("INSERT INTO contacts (name, contact_type, hourly_rate) VALUES ('Anna', 'Kunde', 95.00)");
$anna = (int) $pdo->lastInsertId();
$pdo->exec("INSERT INTO contacts (name, contact_type) VALUES ('Bruno', 'Kunde')");
$bruno = (int) $pdo->lastInsertId();

$pdo->prepare("INSERT INTO tasks (title, status, contact_id, hourly_rate) VALUES ('Mit Satz', 'Offen', ?, 120.00)")
    ->execute([$anna]);
$mit_satz = (int) $pdo->lastInsertId();

$pdo->prepare("INSERT INTO tasks (title, status, contact_id) VALUES ('Ohne Satz', 'Offen', ?)")
    ->execute([$anna]);
$ohne_satz = (int) $pdo->lastInsertId();

$pdo->prepare("INSERT INTO tasks (title, status, contact_id) VALUES ('Kunde ohne Satz', 'Offen', ?)")
    ->execute([$bruno]);
$ganz_ohne = (int) $pdo->lastInsertId();

$checks = [];

// --- Welcher Stundensatz gilt? ----------------------------------------
// Das Projekt schlaegt den Kunden, der Kunde schlaegt die Voreinstellung.
// Ohne diese Reihenfolge muesste bei jedem Sonderpreis der Kundensatz
// geaendert werden - und danach waeren alle anderen Projekte falsch.
$checks['Projektsatz hat Vorrang'] = stundensatz($pdo, $mit_satz, 80.0) === 120.0;
$checks['sonst gilt der Kundensatz'] = stundensatz($pdo, $ohne_satz, 80.0) === 95.0;
$checks['sonst die Voreinstellung'] = stundensatz($pdo, $ganz_ohne, 80.0) === 80.0;
$checks['unbekanntes Projekt gibt die Voreinstellung'] = stundensatz($pdo, 99999, 80.0) === 80.0;

// --- Offene Zeiten sammeln --------------------------------------------
$te = $pdo->prepare("INSERT INTO time_entries (task_id, duration_minutes, note) VALUES (?, ?, ?)");
$te->execute([$mit_satz, 90, 'Konzept']);
$e1 = (int) $pdo->lastInsertId();
$te->execute([$mit_satz, 30, 'Timer']);
$e2 = (int) $pdo->lastInsertId();
$te->execute([$ohne_satz, 60, 'Fremd']);

$offen = offene_zeiten($pdo, $mit_satz);
$checks['nur die Zeiten dieses Projekts'] = count($offen) === 2;
$checks['Eintraege tragen ihre Kennung'] = $offen[0]['id'] === $e1;
$checks['Minuten kommen mit'] = $offen[0]['duration_minutes'] === 90;

$checks['Minutensumme stimmt'] = zeiten_minuten($offen) === 120;

// --- Als Position formen ----------------------------------------------
$pos = zeiten_als_position($offen, 120.0, 'Mit Satz');
$checks['eine Position je Abrechnung'] = is_array($pos) && isset($pos['desc']);
$checks['Menge sind Stunden, nicht Minuten'] = $pos['qty'] === 2.0;
$checks['Preis ist der Stundensatz'] = $pos['price'] === 120.0;
$checks['Einheit ist Stunden'] = $pos['unit'] === 'Std';
$checks['Projektname steht in der Beschreibung'] = strpos($pos['desc'], 'Mit Satz') !== false;

// Krumme Minuten: 95 Minuten sind 1,58 Stunden - auf zwei Stellen, sonst
// steht auf der Rechnung eine Menge, die sich nicht nachrechnen laesst.
$krumm = zeiten_als_position([['id' => 1, 'duration_minutes' => 95, 'note' => '']], 100.0, 'X');
$checks['krumme Minuten werden auf zwei Stellen gerundet'] = $krumm['qty'] === 1.58;

$checks['ohne Zeiten gibt es keine Position'] = zeiten_als_position([], 100.0, 'X') === null;

// --- Abrechnen und nicht doppelt abrechnen ----------------------------
$pdo->prepare("INSERT INTO finances (type, title, contact_id, amount, invoice_number) VALUES ('INCOME', 'RE-2026-001', ?, 240, 'RE-2026-001')")
    ->execute([$anna]);
$rechnung = (int) $pdo->lastInsertId();

zeiten_abrechnen($pdo, [$e1, $e2], $rechnung);

$checks['abgerechnete Zeiten sind weg aus der offenen Liste']
    = offene_zeiten($pdo, $mit_satz) === [];
$checks['die Zeiten zeigen auf die Rechnung']
    = (int) $pdo->query("SELECT invoice_id FROM time_entries WHERE id = $e1")->fetchColumn() === $rechnung;
$checks['der Abrechnungszeitpunkt steht fest']
    = $pdo->query("SELECT billed_at FROM time_entries WHERE id = $e1")->fetchColumn() !== null;

// Der zweite Lauf darf nichts mehr finden - sonst zahlt der Kunde zweimal.
$te->execute([$mit_satz, 45, 'Nachtrag']);
$nachher = offene_zeiten($pdo, $mit_satz);
$checks['neue Zeit nach der Abrechnung taucht auf'] = count($nachher) === 1;
$checks['und nur sie'] = $nachher[0]['duration_minutes'] === 45;

// Eine leere Liste abzurechnen darf nichts anfassen.
$vorher_summe = (int) $pdo->query("SELECT COUNT(*) FROM time_entries WHERE billed_at IS NOT NULL")->fetchColumn();
zeiten_abrechnen($pdo, [], $rechnung);
$nachher_summe = (int) $pdo->query("SELECT COUNT(*) FROM time_entries WHERE billed_at IS NOT NULL")->fetchColumn();
$checks['leere Abrechnung aendert nichts'] = $vorher_summe === $nachher_summe;

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
