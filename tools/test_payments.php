<?php
/**
 * Test fuer den Zahlungsabgleich.
 * Aufruf: php tools/test_payments.php
 *
 * Der teure Fehler ist hier nicht die uebersehene Zahlung, sondern die
 * falsch zugeordnete: eine Rechnung steht dann auf "Bezahlt", die
 * Mahnung bleibt aus, und auffallen wird es fruehestens beim
 * Jahresabschluss. Der Test kreist deshalb um die Faelle, in denen
 * KEINE Zuordnung gemacht werden darf.
 */

require_once __DIR__ . '/lib_sqlite_mirror.php';
require_once __DIR__ . '/../includes/payments.php';

$wurzel = dirname(__DIR__);
$checks = [];

// =====================================================================
// Betraege aus Bankexporten
// =====================================================================
$faelle = [
    ['1.234,56', 1234.56],   // deutsch
    ['1,234.56', 1234.56],   // englisch
    ['1234.56',  1234.56],
    ['1234,56',  1234.56],
    ['-45,00',   -45.0],
    ['45,00 EUR', 45.0],
    ['0,99',     0.99],
    ['1,234',    1234.0],    // Komma als Tausendertrenner
    ['',         null],
    ['abc',      null],
];
foreach ($faelle as [$roh, $soll]) {
    $checks['Betrag "' . $roh . '"'] = zahlung_betrag_parsen($roh) === $soll;
}

// =====================================================================
// Datumsformate
// =====================================================================
$checks['deutsches Datum']   = zahlung_datum_parsen('15.01.2026') === '2026-01-15';
$checks['zweistelliges Jahr'] = zahlung_datum_parsen('15.01.26') === '2026-01-15';
$checks['ISO-Datum']         = zahlung_datum_parsen('2026-01-15') === '2026-01-15';
$checks['leeres Datum']      = zahlung_datum_parsen('') === null;

// =====================================================================
// CSV: die Spalten werden ueber die Kopfzeile erkannt
// =====================================================================
// Zwei Banken, zwei Reihenfolgen, dasselbe Ergebnis.
$sparkasse = "Buchungstag;Wertstellung;Verwendungszweck;Beguenstigter/Zahlungspflichtiger;Betrag;Waehrung\n"
           . "15.01.2026;15.01.2026;Rechnung RE-2026-001;Hofmann & Partner;1.190,00;EUR\n"
           . "16.01.2026;16.01.2026;Miete Januar;Vermieter GmbH;-800,00;EUR\n";

$e = zahlungen_aus_csv($sparkasse);
$checks['CSV wird gelesen']        = $e['fehler'] === null;
$checks['nur Eingaenge']           = count($e['zahlungen']) === 1;
$checks['Betrag stimmt']           = ($e['zahlungen'][0]['betrag'] ?? 0) === 1190.0;
$checks['Zweck stimmt']            = ($e['zahlungen'][0]['zweck'] ?? '') === 'Rechnung RE-2026-001';
$checks['Name stimmt']             = ($e['zahlungen'][0]['name'] ?? '') === 'Hofmann & Partner';
$checks['Datum stimmt']            = ($e['zahlungen'][0]['datum'] ?? '') === '2026-01-15';

// Andere Reihenfolge, andere Ueberschriften, Komma als Trenner.
$andere = "Datum,Betrag,Auftraggeber,Buchungstext\n"
        . "2026-02-03,\"250.00\",Weiss Naturkosmetik,\"Anzahlung RE-2026-002\"\n";
$e2 = zahlungen_aus_csv($andere);
$checks['andere Spaltenfolge']  = ($e2['zahlungen'][0]['betrag'] ?? 0) === 250.0;
$checks['Komma als Trenner']    = ($e2['zahlungen'][0]['name'] ?? '') === 'Weiss Naturkosmetik';

// Eine Datei, die kein Kontoauszug ist, muss das sagen.
$e3 = zahlungen_aus_csv("Spalte A;Spalte B\n1;2\n");
$checks['fremde Datei gemeldet'] = $e3['fehler'] !== null && $e3['zahlungen'] === [];

// =====================================================================
// CAMT.053
// =====================================================================
$camt = '<?xml version="1.0" encoding="UTF-8"?>'
      . '<Document xmlns="urn:iso:std:iso:20022:tech:xsd:camt.053.001.02"><BkToCstmrStmt><Stmt>'
      . '<Ntry><Amt Ccy="EUR">1190.00</Amt><CdtDbtInd>CRDT</CdtDbtInd>'
      . '<BookgDt><Dt>2026-01-15</Dt></BookgDt><NtryDtls><TxDtls>'
      . '<RltdPties><Dbtr><Nm>Hofmann und Partner</Nm></Dbtr></RltdPties>'
      . '<RmtInf><Ustrd>Rechnung RE-2026-001</Ustrd></RmtInf>'
      . '</TxDtls></NtryDtls></Ntry>'
      . '<Ntry><Amt Ccy="EUR">800.00</Amt><CdtDbtInd>DBIT</CdtDbtInd>'
      . '<BookgDt><Dt>2026-01-16</Dt></BookgDt></Ntry>'
      . '</Stmt></BkToCstmrStmt></Document>';

$c = zahlungen_aus_camt($camt);
$checks['CAMT wird gelesen']    = $c['fehler'] === null;
$checks['Abbuchung fliegt raus'] = count($c['zahlungen']) === 1;
$checks['CAMT-Betrag']          = ($c['zahlungen'][0]['betrag'] ?? 0) === 1190.0;
$checks['CAMT-Zweck']           = ($c['zahlungen'][0]['zweck'] ?? '') === 'Rechnung RE-2026-001';
$checks['CAMT-Zahler']          = ($c['zahlungen'][0]['name'] ?? '') === 'Hofmann und Partner';

$checks['kaputtes XML gemeldet'] = zahlungen_aus_camt('<Document>')['fehler'] !== null;
$checks['Format wird erkannt']   = zahlungen_lesen($camt)['format'] === 'CAMT.053'
                                && zahlungen_lesen($sparkasse)['format'] === 'CSV';

// =====================================================================
// Die Zuordnung
// =====================================================================
$offene = [
    ['id' => 1, 'invoice_number' => 'RE-2026-001', 'title' => 'Relaunch', 'amount' => 1190.00, 'kunde' => 'Hofmann & Partner'],
    ['id' => 2, 'invoice_number' => 'RE-2026-002', 'title' => 'Shop',     'amount' => 250.00,  'kunde' => 'Weiß Naturkosmetik'],
    ['id' => 3, 'invoice_number' => 'RE-2026-003', 'title' => 'Wartung',  'amount' => 250.00,  'kunde' => 'Brandt Elektro'],
];

// 1. Nummer im Zweck: der sichere Fall.
$v = zahlung_vorschlag(['name' => 'X', 'zweck' => 'Zahlung RE-2026-001', 'betrag' => 1190.00, 'datum' => null], $offene);
$checks['Nummer trifft']        = ($v['treffer']['id'] ?? 0) === 1;
$checks['und gilt als sicher']  = $v['sicherheit'] === ZAHLUNG_SICHER;

// Nummer da, Betrag weicht ab: Treffer ja, sicher nein.
$v = zahlung_vorschlag(['name' => 'X', 'zweck' => 'RE-2026-001', 'betrag' => 900.00, 'datum' => null], $offene);
$checks['Teilzahlung nicht sicher'] = $v['sicherheit'] === ZAHLUNG_MOEGLICH;

// 2. Betrag und Name.
$v = zahlung_vorschlag(['name' => 'WEISS NATURKOSMETIK', 'zweck' => 'ohne Nummer', 'betrag' => 250.00, 'datum' => null], $offene);
$checks['Name entscheidet']      = ($v['treffer']['id'] ?? 0) === 2;
$checks['Name gilt als moeglich'] = $v['sicherheit'] === ZAHLUNG_MOEGLICH;

// 3. Zwei Rechnungen ueber denselben Betrag, kein passender Name:
//    hier darf NICHTS vorgeschlagen werden.
$v = zahlung_vorschlag(['name' => 'Unbekannt AG', 'zweck' => 'ohne Nummer', 'betrag' => 250.00, 'datum' => null], $offene);
$checks['mehrdeutig: kein Treffer'] = $v['treffer'] === null;
$checks['und der Grund sagt es']    = strpos($v['grund'], '2 offene') !== false;

// Ein Betrag, den es nicht gibt.
$v = zahlung_vorschlag(['name' => 'X', 'zweck' => 'Y', 'betrag' => 77.77, 'datum' => null], $offene);
$checks['kein passender Betrag'] = $v['treffer'] === null;

// Ein eindeutiger Betrag ohne Namen: Treffer, aber als unklar markiert.
$v = zahlung_vorschlag(['name' => '', 'zweck' => '', 'betrag' => 1190.00, 'datum' => null], $offene);
$checks['eindeutiger Betrag trifft'] = ($v['treffer']['id'] ?? 0) === 1;
$checks['aber nur unklar']           = $v['sicherheit'] === ZAHLUNG_UNKLAR;

// =====================================================================
// Keine Rechnung zweimal
// =====================================================================
// Zwei Zahlungen ueber 250, eine mit Nummer. Die Nummer muss ihre
// Rechnung bekommen, die andere darf nicht dieselbe treffen.
$zwei = [
    ['name' => '', 'zweck' => '', 'betrag' => 250.00, 'datum' => null],
    ['name' => '', 'zweck' => 'RE-2026-003', 'betrag' => 250.00, 'datum' => null],
];
$vs = zahlungen_vorschlaege($zwei, $offene);
$ids = array_map(fn($v) => $v['treffer']['id'] ?? null, $vs);
$checks['Nummer bekommt ihre Rechnung'] = $ids[1] === 3;
$checks['keine Rechnung doppelt']       = $ids[0] !== $ids[1];
$checks['Reihenfolge bleibt']           = count($vs) === 2;

// =====================================================================
// Buchen
// =====================================================================
$pdo = new SqliteSpiegelPDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
foreach (nach_sqlite(file_get_contents($wurzel . '/install/schema.sql')) as $a) {
    $pdo->exec($a);
}
$pdo->exec("INSERT INTO finances (type, title, invoice_number, amount, status, record_date)
            VALUES ('INCOME', 'Relaunch', 'RE-2026-001', 1190, 'Offen', '2026-01-01')");
$offen_id = (int) $pdo->lastInsertId();
$pdo->exec("INSERT INTO finances (type, title, amount, status, record_date)
            VALUES ('INCOME', 'Schon bezahlt', 100, 'Bezahlt', '2026-01-01')");
$bezahlt_id = (int) $pdo->lastInsertId();
$pdo->exec("INSERT INTO finances (type, title, amount, status, record_date, deleted_at)
            VALUES ('INCOME', 'Im Papierkorb', 100, 'Offen', '2026-01-01', '2026-02-01')");
$weg_id = (int) $pdo->lastInsertId();

$checks['offene Rechnung wird gebucht'] = zahlung_buchen($pdo, $offen_id, 'RE-2026-001', '2026-01-15') === true;
$status = $pdo->query("SELECT status FROM finances WHERE id = $offen_id")->fetchColumn();
$checks['Status ist bezahlt']           = $status === 'Bezahlt';
$notiz = (string) $pdo->query("SELECT notes FROM finances WHERE id = $offen_id")->fetchColumn();
$checks['Vermerk steht in der Notiz']   = strpos($notiz, 'Zahlungseingang') !== false;
$checks['Vermerk nennt das Datum']      = strpos($notiz, '15.01.2026') !== false;

// Der zweite Klick darf nichts tun.
$checks['zweiter Klick bucht nicht']    = zahlung_buchen($pdo, $offen_id, 'x', null) === false;
// Eine bereits bezahlte und eine geloeschte ebensowenig.
$checks['bezahlte bleibt unberuehrt']   = zahlung_buchen($pdo, $bezahlt_id, 'x', null) === false;
$checks['geloeschte bleibt unberuehrt'] = zahlung_buchen($pdo, $weg_id, 'x', null) === false;
$checks['nichts an fremder Kennung']    = zahlung_buchen($pdo, 99999, 'x', null) === false;

// =====================================================================
// Namensvergleich
// =====================================================================
$checks['Rechtsform faellt weg']  = zahlung_name_flach('Brandt Elektro GmbH') === zahlung_name_flach('Brandt Elektro');
$checks['Umlaut wird umschrieben'] = zahlung_name_flach('Weiß') === zahlung_name_flach('Weiss');
$checks['kaufmaennisches Und']     = zahlung_name_flach('Hofmann & Partner') === zahlung_name_flach('Hofmann + Partner');

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
