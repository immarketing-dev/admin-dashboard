<?php
/**
 * Test für „Angebot zu Rechnung". Aufruf: php tools/test_quote_to_invoice.php
 *
 * Die Umwandlung gab es schon; geprüft war sie nie, und ihr fehlten zwei
 * Dinge, die die Schwesterfunktion (Angebot zu Projekt) hat:
 *
 *   - ein Riegel gegen die zweite Sendung. Eine zweite Rechnung über
 *     dieselbe Leistung, mit eigener Nummer, merkt man, wenn der Kunde
 *     zweimal zahlen soll.
 *   - einen Weg für den üblichen Ablauf. Der Knopf zeigte sich nur,
 *     solange das Angebot nicht angenommen war - sagte der Kunde im
 *     Portal zu, war er weg.
 *
 * Beides hängt an quotes.converted_invoice_id, und darum kreisen die
 * Prüfungen hier.
 */

require_once __DIR__ . '/lib_sqlite_mirror.php';
require_once __DIR__ . '/../includes/quote_to_invoice.php';

$wurzel = dirname(__DIR__);
$checks = [];

$pdo = new SqliteSpiegelPDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('PRAGMA foreign_keys = ON');
foreach (nach_sqlite(file_get_contents($wurzel . '/install/schema.sql')) as $a) {
    $pdo->exec($a);
}

$pdo->exec("INSERT INTO contacts (id, name) VALUES (1, 'Hofmann & Partner')");

$positionen = json_encode([
    ['desc' => 'Konzept',     'qty' => 1,  'price' => 800.00, 'unit' => 'Pauschale'],
    ['desc' => 'Umsetzung',   'qty' => 10, 'price' => 90.00,  'unit' => 'Std'],
]);

/** Legt ein Angebot an. */
function angebot(PDO $pdo, string $nummer, string $status, ?int $kontakt, ?string $frei,
                 string $items, float $summe, string $steuerart = 'regel'): int
{
    $pdo->prepare(
        "INSERT INTO quotes (quote_number, subject, contact_id, custom_name, status,
                             tax_type, items, notes, total_amount)
         VALUES (?, 'Relaunch', ?, ?, ?, ?, ?, 'Zahlbar ohne Abzug.', ?)"
    )->execute([$nummer, $kontakt, $frei, $status, $steuerart, $items, $summe]);
    return (int) $pdo->lastInsertId();
}

// =====================================================================
// Der Normalfall
// =====================================================================
// total_amount ist der Bruttobetrag: 1.700 netto plus 19 Prozent.
$q1 = angebot($pdo, 'AN-2026-001', 'Gesendet', 1, null, $positionen, 2023.00);

$checks['vorher keine Rechnung'] = angebot_rechnung($pdo, $q1) === null;

$r1 = rechnung_aus_angebot($pdo, $q1, 'RE-2026-001', 'uploads/re-1.pdf', '2026-03-02');
$checks['die Rechnung entsteht'] = is_int($r1) && $r1 > 0;

$rech = $pdo->query("SELECT * FROM finances WHERE id = $r1")->fetch(PDO::FETCH_ASSOC);
$checks['als Einnahme angelegt']  = $rech['type'] === 'INCOME' && $rech['status'] === 'Offen';
$checks['Nummer steht drin']      = $rech['invoice_number'] === 'RE-2026-001';
$checks['Kontakt wandert mit']    = (int) $rech['contact_id'] === 1;
$checks['Betrag wandert mit']     = abs((float) $rech['amount'] - 2023.00) < 0.005;
$checks['Notiz wandert mit']      = $rech['notes'] === 'Zahlbar ohne Abzug.';
$checks['PDF-Pfad wandert mit']   = $rech['invoice_pdf_path'] === 'uploads/re-1.pdf';
$checks['Steuerart wandert mit']  = $rech['tax_type'] === 'regel';

// Die Positionen wandern mit. Bis Schemaversion 8 ging nur der
// Gesamtbetrag ueber, und die Rechnung liess sich danach nicht mehr
// aendern, ohne sie neu zu tippen.
$pos = json_decode((string) $rech['items'], true);
$checks['Positionen wandern mit'] = is_array($pos) && count($pos) === 2
                                 && $pos[0]['desc'] === 'Konzept';

// Netto und Steuer werden neu gerechnet, nicht uebernommen.
$checks['Netto ist gerechnet']  = abs((float) $rech['net_amount'] - 1700.00) < 0.02;
$checks['Steuer ist gerechnet'] = abs((float) $rech['tax_amount'] - 323.00) < 0.02;

// Datumsrechnung in PHP, damit sie auf beiden Datenbanken gleich ist.
$checks['Rechnungsdatum gesetzt'] = $rech['record_date'] === '2026-03-02';
$checks['Faellig in 14 Tagen']    = $rech['due_date'] === '2026-03-16';

// Das Angebot weiss davon.
$checks['Angebot ist angenommen'] = $pdo->query("SELECT status FROM quotes WHERE id = $q1")->fetchColumn() === 'Angenommen';
$checks['Angebot kennt die Rechnung'] = angebot_rechnung($pdo, $q1) === $r1;
$checks['und ihre Nummer'] = angebot_rechnung_zeile($pdo, $q1)['invoice_number'] === 'RE-2026-001';

// =====================================================================
// Der Riegel
// =====================================================================
// Ein zweites Absenden darf keine zweite Rechnung anlegen. Geprueft wird
// hier die Frage, die finances.php vor dem Anlegen stellt.
$checks['zweites Absenden wird erkannt'] = angebot_rechnung($pdo, $q1) !== null;

// =====================================================================
// Der uebliche Ablauf
// =====================================================================
// Der Kunde sagt im Portal zu - das Angebot steht auf "Angenommen", eine
// Rechnung gibt es aber nicht. Genau dann will man sie schreiben.
$q2 = angebot($pdo, 'AN-2026-002', 'Angenommen', 1, null, $positionen, 2023.00);
$checks['angenommen und ohne Rechnung'] = angebot_rechnung($pdo, $q2) === null;

$r2 = rechnung_aus_angebot($pdo, $q2, 'RE-2026-002', '', '2026-03-05');
$checks['auch daraus wird eine Rechnung'] = is_int($r2) && $r2 > 0;
$checks['ohne PDF bleibt der Pfad leer']
    = $pdo->query("SELECT invoice_pdf_path FROM finances WHERE id = $r2")->fetchColumn() === null;

// =====================================================================
// Eine geloeschte Rechnung zaehlt nicht
// =====================================================================
// Sonst bliebe das Angebot fuer immer gesperrt, obwohl die Rechnung im
// Papierkorb liegt.
$pdo->exec("UPDATE finances SET deleted_at = '2026-04-01' WHERE id = $r2");
$checks['geloeschte Rechnung gibt frei'] = angebot_rechnung($pdo, $q2) === null;

// =====================================================================
// Der Empfaenger
// =====================================================================
// Der freie Name geht vor - steht er im Angebot, war das Absicht.
$q3 = angebot($pdo, 'AN-2026-003', 'Gesendet', 1, 'Barverkauf', $positionen, 500.00);
$r3 = rechnung_aus_angebot($pdo, $q3, 'RE-2026-003');
$checks['freier Name geht vor']
    = $pdo->query("SELECT custom_name FROM finances WHERE id = $r3")->fetchColumn() === 'Barverkauf';

// Ohne beides bleibt das Feld leer. Ein "Unbekannt" landete sonst auf
// einer Rechnung, und dort hat es nichts zu suchen.
$q4 = angebot($pdo, 'AN-2026-004', 'Gesendet', null, null, $positionen, 500.00);
$r4 = rechnung_aus_angebot($pdo, $q4, 'RE-2026-004');
$checks['ohne Namen bleibt es leer']
    = $pdo->query("SELECT custom_name FROM finances WHERE id = $r4")->fetchColumn() === null;

$checks['Empfaenger aus dem Kontakt']
    = rechnungsempfaenger_aus_angebot(['custom_name' => '', 'c_name' => 'Hofmann']) === 'Hofmann';
$checks['Empfaenger leer bleibt null']
    = rechnungsempfaenger_aus_angebot(['custom_name' => '  ', 'c_name' => '']) === null;

// =====================================================================
// Was es nicht gibt
// =====================================================================
$checks['unbekanntes Angebot']  = rechnung_aus_angebot($pdo, 999999, 'RE-X') === null;

$pdo->exec("UPDATE quotes SET deleted_at = '2026-04-01' WHERE id = $q4");
$checks['geloeschtes Angebot']  = rechnung_aus_angebot($pdo, $q4, 'RE-Y') === null;
$checks['und es kennt nichts']  = angebot_rechnung($pdo, $q4) === null;

// =====================================================================
$fail = 0;
foreach ($checks as $name => $ok) {
    echo ($ok ? 'PASS' : 'FAIL') . "  $name\n";
    if (!$ok) $fail = 1;
}
exit($fail);
