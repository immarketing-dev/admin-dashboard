<?php
/**
 * Test fuer die Belege zu Ausgaben.
 * Aufruf: php tools/test_receipts.php
 *
 * finances kannte genau ein Dateifeld - invoice_pdf_path, die selbst
 * erzeugte Ausgangsrechnung. An einer Ausgabe hing nichts, und zur
 * Steuer wurde jeder Beleg wieder aus fuenf Postfaechern zusammengesucht.
 *
 * Die heikle Stelle ist hier nicht das Hochladen, sondern das Loeschen:
 * beleg_loeschen() bekommt einen Pfad aus der Datenbank und entfernt
 * damit eine Datei. Waere die Schranke nicht da, genuegte ein "../.env"
 * in der Spalte.
 *
 * Nicht geprueft: das Archiv selbst. Es braucht die Erweiterung zip, die
 * auf dieser Maschine fehlt - beleg_zip_moeglich() faengt genau das ab,
 * und der Test unten prueft, dass die Funktion dann sauber null liefert
 * statt zu scheitern.
 */

require_once __DIR__ . '/lib_sqlite_mirror.php';
require_once __DIR__ . '/../includes/receipts.php';

$wurzel = dirname(__DIR__);
$pdo = new SqliteSpiegelPDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('PRAGMA foreign_keys = ON');
foreach (nach_sqlite(file_get_contents($wurzel . '/install/schema.sql')) as $anweisung) {
    $pdo->exec($anweisung);
}

$checks = [];

// =====================================================================
// Der Name im Archiv
// =====================================================================
// Datum voran, damit die Dateien im Archiv in derselben Reihenfolge
// stehen wie in der Liste; die Kennung dahinter, damit zwei Ausgaben mit
// gleichem Datum und gleichem Titel sich nicht ueberschreiben.
$name = beleg_archivname([
    'id' => 42, 'record_date' => '2026-03-15', 'title' => 'Serverkosten',
    'receipt_path' => 'uploads/receipts/1780000000_rechnung.pdf',
]);
$checks['Archivname beginnt mit dem Datum'] = strpos($name, '2026-03-15_') === 0;
$checks['Archivname traegt die Kennung']    = strpos($name, '_42_') !== false;
$checks['Archivname behaelt die Endung']    = substr($name, -4) === '.pdf';

// Sonderzeichen im Titel duerfen keinen Pfad und keinen unbrauchbaren
// Dateinamen ergeben.
$name = beleg_archivname([
    'id' => 7, 'record_date' => '2026-01-02', 'title' => '../etc/passwd & Co. GmbH',
    'receipt_path' => 'uploads/receipts/x.jpg',
]);
$checks['kein Pfad im Archivnamen']      = strpos($name, '/') === false && strpos($name, '\\') === false;
$checks['keine Punkte aus dem Titel']    = substr_count($name, '.') === 1;
$checks['Endung bleibt erhalten']        = substr($name, -4) === '.jpg';

// Ein Beleg ohne Endung und eine Ausgabe ohne Datum kippen nichts.
$name = beleg_archivname(['id' => 1, 'record_date' => null, 'title' => '', 'receipt_path' => 'uploads/receipts/x']);
$checks['ohne Datum ein Ersatzdatum'] = strpos($name, '0000-00-00_') === 0;
$checks['ohne Titel ein Ersatzname']  = strpos($name, 'Beleg') !== false;

// =====================================================================
// Die Uebersicht
// =====================================================================
$pdo->exec("INSERT INTO contacts (name, contact_type) VALUES ('Hoster AG', 'Lieferant')");
$hoster = (int) $pdo->lastInsertId();

$ins = $pdo->prepare(
    "INSERT INTO finances (type, title, contact_id, custom_name, amount, status, record_date, notes, receipt_path)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
);
$ins->execute(['EXPENSE', 'Serverkosten', $hoster, null, 29.90, 'Bezahlt', '2026-03-15', 'Monatlich', 'uploads/receipts/a.pdf']);
$ins->execute(['EXPENSE', 'Bahnticket',   null, 'DB Vertrieb', 49.00, 'Bezahlt', '2026-04-02', null, 'uploads/receipts/b.pdf']);
// Ohne Beleg - kommt in die Liste, aber nicht ins Archiv.
$ins->execute(['EXPENSE', 'Kaffee',       null, null, 3.50, 'Bezahlt', '2026-04-03', null, null]);
// Nicht mitzaehlen: Einnahme, Vorjahr, Papierkorb.
$ins->execute(['INCOME',  'Rechnung',     $hoster, null, 500.00, 'Bezahlt', '2026-03-20', null, null]);
$ins->execute(['EXPENSE', 'Altlast',      null, null, 10.00, 'Bezahlt', '2025-12-01', null, 'uploads/receipts/alt.pdf']);
$pdo->exec(
    "INSERT INTO finances (type, title, amount, status, record_date, deleted_at, receipt_path)
     VALUES ('EXPENSE', 'Geloescht', 5.00, 'Bezahlt', '2026-05-01', '2026-06-01', 'uploads/receipts/weg.pdf')"
);

$ausgaben = belege_des_jahres($pdo, 2026);
$titel = array_column($ausgaben, 'title');

$checks['Ausgaben des Jahres sind dabei']   = in_array('Serverkosten', $titel, true)
                                           && in_array('Bahnticket', $titel, true);
$checks['auch ohne Beleg']                  = in_array('Kaffee', $titel, true);
$checks['Einnahmen nicht']                  = !in_array('Rechnung', $titel, true);
$checks['Vorjahr nicht']                    = !in_array('Altlast', $titel, true);
$checks['Papierkorb nicht']                 = !in_array('Geloescht', $titel, true);
$checks['nach Datum sortiert']              = $titel === ['Serverkosten', 'Bahnticket', 'Kaffee'];
$checks['Kontaktname wird aufgeloest']      = $ausgaben[0]['empfaenger'] === 'Hoster AG';
$checks['freier Name ebenso']               = $ausgaben[1]['empfaenger'] === 'DB Vertrieb';

// --- CSV ---------------------------------------------------------------
$csv = belege_csv($ausgaben);

$checks['CSV traegt die Byte-Order-Mark'] = strpos($csv, chr(0xEF) . chr(0xBB) . chr(0xBF)) === 0;
$checks['CSV hat eine Kopfzeile']         = strpos($csv, 'Datum;Bezeichnung;Empfaenger') !== false;
$checks['CSV nennt die Ausgabe']          = strpos($csv, 'Serverkosten') !== false;
// Deutsches Dezimalkomma - dieselbe Schreibweise wie im vorhandenen
// Finanz-Export, sonst liest Excel 29.90 als Datum.
$checks['CSV schreibt das Komma']         = strpos($csv, '29,9') !== false;
$checks['CSV nennt den Archivnamen']      = strpos($csv, '2026-03-15_') !== false;
// Die Zeile ohne Beleg steht drin, aber ohne Dateinamen.
$checks['Zeile ohne Beleg bleibt leer']   = strpos($csv, 'Kaffee') !== false;

// =====================================================================
// Loeschen: die Pfadschranke
// =====================================================================
// Der Wert kommt aus der Datenbank - aber ein Pfad, der aus uploads/
// herausfuehrt, waere auch dann falsch, wenn ihn niemand angegriffen hat.
// config.php und .env liegen ein Verzeichnis darueber.
$checks['leerer Pfad loescht nichts']       = beleg_loeschen('', $wurzel) === false;
$checks['null loescht nichts']              = beleg_loeschen(null, $wurzel) === false;
$checks['Pfad ausserhalb uploads/ abgelehnt'] = beleg_loeschen('../.env', $wurzel) === false;
$checks['Rueckschritt im Pfad abgelehnt']   = beleg_loeschen('uploads/receipts/../../.env', $wurzel) === false;
$checks['fremdes Verzeichnis abgelehnt']    = beleg_loeschen('includes/config.php', $wurzel) === false;

// Und der richtige Fall: eine echte Datei unter uploads/receipts/ geht weg.
$ordner = $wurzel . '/uploads/receipts';
if (!is_dir($ordner)) mkdir($ordner, 0755, true);
$probe = $ordner . '/pruefdatei_' . getmypid() . '.txt';
file_put_contents($probe, 'Testinhalt');

$rel = 'uploads/receipts/' . basename($probe);
$checks['vorhandene Belegdatei wird entfernt'] = beleg_loeschen($rel, $wurzel) === true;
$checks['danach ist sie weg']                  = !is_file($probe);
$checks['zweiter Aufruf meldet false']         = beleg_loeschen($rel, $wurzel) === false;

// =====================================================================
// Das Archiv
// =====================================================================
// Ohne die Erweiterung zip darf die Funktion nicht scheitern, sondern
// muss null melden - die Oberflaeche blendet den Knopf dann aus, und der
// Handler faellt auf die CSV zurueck.
$vermisst = [];
$archiv   = belege_archiv($ausgaben, $wurzel, 2026, $vermisst);

if (beleg_zip_moeglich()) {
    $checks['Archiv wird erzeugt'] = is_string($archiv) && is_file($archiv);
    // Die beiden Belegdateien liegen hier nicht auf der Platte - der Lauf
    // ueberspringt sie und meldet sie, statt abzubrechen.
    $checks['fehlende Belege werden gemeldet'] = count($vermisst) === 2;
    if (is_string($archiv)) @unlink($archiv);
} else {
    $checks['ohne zip meldet das Archiv null'] = $archiv === null;
    echo "HINWEIS: Erweiterung zip fehlt - der Archivinhalt wurde nicht geprueft.\n";
}

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
