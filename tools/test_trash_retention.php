<?php
/**
 * Test fuer den Verfall im Papierkorb.
 * Aufruf: php tools/test_trash_retention.php
 *
 * Die Frist stand bisher nur auf der Seite und wurde auch nur dort
 * durchgesetzt - beim Oeffnen. Wer den Papierkorb nie aufschlug, behielt
 * das Geloeschte fuer immer, samt der Dateien, deren Name Kunde und
 * Betrag verraet. Seit cron_papierkorb() laeuft es nachts.
 *
 * Zwei Dinge muessen dabei stimmen, und beide sind ohne Test still:
 *
 *   1. Es darf nur treffen, was die Frist ueberschritten hat. Ein Eintrag
 *      von gestern gehoert noch nicht entfernt, ein aktiver ueberhaupt
 *      nicht.
 *   2. Die Datei geht mit - aber nur, wenn ihr Pfad unter uploads/ bleibt.
 *      Der Wert kommt aus der Datenbank; ein "../.env" darin waere auch
 *      dann falsch, wenn ihn niemand angegriffen hat.
 */

require_once __DIR__ . '/lib_sqlite_mirror.php';
require_once __DIR__ . '/../includes/trash_retention.php';

$wurzel = dirname(__DIR__);
$pdo = new SqliteSpiegelPDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('PRAGMA foreign_keys = ON');
foreach (nach_sqlite(file_get_contents($wurzel . '/install/schema.sql')) as $anweisung) {
    $pdo->exec($anweisung);
}

$checks = [];
$alt    = date('Y-m-d H:i:s', strtotime('-' . (AUFBEWAHRUNG_TAGE + 5) . ' days'));
$frisch = date('Y-m-d H:i:s', strtotime('-3 days'));

// =====================================================================
// Nur was die Frist ueberschritten hat
// =====================================================================
$pdo->exec("INSERT INTO contacts (name, deleted_at) VALUES ('Lange weg', '$alt')");
$pdo->exec("INSERT INTO contacts (name, deleted_at) VALUES ('Kuerzlich weg', '$frisch')");
$pdo->exec("INSERT INTO contacts (name) VALUES ('Aktiv')");
$pdo->exec("INSERT INTO tasks (title, deleted_at) VALUES ('Altes Projekt', '$alt')");
$pdo->exec("INSERT INTO quotes (quote_number, items, total_amount, deleted_at) VALUES ('ANG-1', '[]', 100, '$alt')");

[$zeilen, $dateien] = papierkorb_verfallen($pdo);

$namen = $pdo->query('SELECT name FROM contacts ORDER BY name')->fetchAll(PDO::FETCH_COLUMN);
$checks['abgelaufener Kontakt ist weg']   = !in_array('Lange weg', $namen, true);
$checks['junger Eintrag bleibt']          = in_array('Kuerzlich weg', $namen, true);
$checks['aktiver Eintrag bleibt']         = in_array('Aktiv', $namen, true);
$checks['auch Projekte werden geraeumt']  = (int) $pdo->query('SELECT COUNT(*) FROM tasks')->fetchColumn() === 0;
$checks['auch Angebote werden geraeumt']  = (int) $pdo->query('SELECT COUNT(*) FROM quotes')->fetchColumn() === 0;
$checks['die Zahl stimmt']                = $zeilen === 3;
$checks['ohne Dateien bleibt die Zahl 0'] = $dateien === 0;

// Ein zweiter Lauf findet nichts mehr - und meldet das auch so.
[$zeilen2, ] = papierkorb_verfallen($pdo);
$checks['zweiter Lauf raeumt nichts'] = $zeilen2 === 0;

// =====================================================================
// Die Datei geht mit
// =====================================================================
$ordner = $wurzel . '/uploads/receipts';
if (!is_dir($ordner)) mkdir($ordner, 0755, true);
$probe = $ordner . '/pruefbeleg_' . getmypid() . '.pdf';
file_put_contents($probe, '%PDF-1.4 Testinhalt');
$rel = 'uploads/receipts/' . basename($probe);

$ins = $pdo->prepare("INSERT INTO finances (type, title, amount, status, record_date, deleted_at, receipt_path)
                      VALUES ('EXPENSE', ?, 10, 'Bezahlt', '2026-01-01', ?, ?)");
$ins->execute(['Mit Beleg', $alt, $rel]);

[$zeilen3, $dateien3] = papierkorb_verfallen($pdo);
$checks['Finanzzeile entfernt']    = $zeilen3 === 1;
$checks['Beleg mitgezaehlt']       = $dateien3 === 1;
$checks['Beleg ist von der Platte'] = !is_file($probe);

// =====================================================================
// Die Pfadschranke
// =====================================================================
// Ein Pfad ausserhalb uploads/ darf keine Datei anfassen. Zur Probe eine
// echte Datei ausserhalb, auf die der Datensatz zeigt.
$fremd = $wurzel . '/pruefdatei_ausserhalb_' . getmypid() . '.txt';
file_put_contents($fremd, 'darf bleiben');

$ins->execute(['Boeser Pfad', $alt, '../pruefdatei_ausserhalb_' . getmypid() . '.txt']);
[$zeilen4, $dateien4] = papierkorb_verfallen($pdo);

$checks['Zeile wird trotzdem entfernt'] = $zeilen4 === 1;
$checks['fremde Datei nicht angefasst'] = $dateien4 === 0 && is_file($fremd);
@unlink($fremd);

// Und ein Pfad, der nirgendwohin zeigt, kippt den Lauf nicht.
$ins->execute(['Fehlende Datei', $alt, 'uploads/receipts/gibtesnicht.pdf']);
$abbruch = false;
try {
    [$zeilen5, $dateien5] = papierkorb_verfallen($pdo);
} catch (Throwable $e) {
    $abbruch = true;
}
$checks['fehlende Datei bricht nicht ab'] = !$abbruch && ($zeilen5 ?? 0) === 1;
$checks['sie wird nicht mitgezaehlt']     = ($dateien5 ?? -1) === 0;

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
