<?php
/**
 * Test für das Zahlungsjournal. Aufruf: php tools/test_invoice_payments.php
 *
 * Der teure Fehler ist hier nicht die vergessene Teilzahlung, sondern
 * der Status, der nicht mehr zum Journal passt: eine Rechnung steht auf
 * „Bezahlt", obwohl noch etwas offen ist, die Mahnung bleibt aus, und
 * auffallen wird es beim Jahresabschluss. Die Prüfungen kreisen deshalb
 * um die Übergänge - jede Änderung am Journal muss den Status
 * nachziehen, in beide Richtungen.
 *
 * Mitgeprüft wird die Nachfüllanweisung aus Migration 20. Sie läuft
 * genau einmal, auf einer Datenbank mit echten Zahlen darin, und wenn
 * sie danebengeht, sieht die Auswertung über Nacht anders aus als am
 * Vortag.
 */

require_once __DIR__ . '/lib_sqlite_mirror.php';
require_once __DIR__ . '/../includes/invoice_payments.php';
require_once __DIR__ . '/../includes/migrations.php';

$wurzel = dirname(__DIR__);
$checks = [];

$pdo = new SqliteSpiegelPDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('PRAGMA foreign_keys = ON');
foreach (nach_sqlite(file_get_contents($wurzel . '/install/schema.sql')) as $anweisung) {
    $pdo->exec($anweisung);
}

$heute  = date('Y-m-d');
$morgen = date('Y-m-d', strtotime('+14 days'));
$frueher = date('Y-m-d', strtotime('-20 days'));

/** Legt eine Ausgangsrechnung an und gibt ihre Nummer zurück. */
function rechnung(PDO $pdo, float $betrag, ?string $faellig, string $status = 'Offen'): int
{
    $pdo->prepare(
        "INSERT INTO finances (type, title, amount, status, record_date, due_date)
         VALUES ('INCOME', 'Testrechnung', ?, ?, ?, ?)"
    )->execute([$betrag, $status, date('Y-m-d'), $faellig]);
    return (int) $pdo->lastInsertId();
}

function status_von(PDO $pdo, int $id): string
{
    $s = $pdo->prepare('SELECT status FROM finances WHERE id = ?');
    $s->execute([$id]);
    return (string) $s->fetchColumn();
}

// =====================================================================
// Rechnen
// =====================================================================
$checks['offener Rest']            = rechnung_offen(1240.00, 900.00) === 340.00;
$checks['Rest null statt Cent']    = rechnung_offen(1240.00, 1239.999) === 0.0;
$checks['Rest bei Ueberzahlung']   = rechnung_offen(100.00, 120.00) === 0.0;
$checks['beglichen ab dem Betrag'] = rechnung_beglichen(100.00, 100.00) === true;
$checks['nicht beglichen davor']   = rechnung_beglichen(100.00, 99.98) === false;

// =====================================================================
// Eine Zahlung zieht den Status nach
// =====================================================================
$r1 = rechnung($pdo, 1240.00, $morgen);

zahlung_erfassen($pdo, $r1, 400.00, $heute, 'Anzahlung');
$checks['Teilzahlung laesst offen']  = status_von($pdo, $r1) === 'Offen';
$checks['Summe nach Teilzahlung']    = zahlung_summe($pdo, $r1) === 400.00;
$checks['Rest nach Teilzahlung']     = rechnung_offen(1240.00, zahlung_summe($pdo, $r1)) === 840.00;

zahlung_erfassen($pdo, $r1, 840.00, $heute, 'Restzahlung');
$checks['Restzahlung schliesst ab']  = status_von($pdo, $r1) === 'Bezahlt';
$checks['zwei Zeilen im Journal']    = count(zahlungen_einer_rechnung($pdo, $r1)) === 2;

// Drei krumme Raten, die zusammen aufgehen. Ohne die Cent-Grenze bliebe
// die Rechnung hier auf "Offen" stehen.
$r2 = rechnung($pdo, 1240.00, $morgen);
foreach ([413.33, 413.33, 413.34] as $rate) {
    zahlung_erfassen($pdo, $r2, $rate, $heute, 'Rate');
}
$checks['drei Raten gehen auf'] = status_von($pdo, $r2) === 'Bezahlt';

// =====================================================================
// Und zurueck
// =====================================================================
$zeilen = zahlungen_einer_rechnung($pdo, $r1);
zahlung_entfernen($pdo, (int) $zeilen[0]['id']);
$checks['entfernte Zahlung oeffnet wieder'] = status_von($pdo, $r1) === 'Offen';

// Bei ueberschrittener Faelligkeit nicht "Offen", sondern "Ueberfaellig".
$r3 = rechnung($pdo, 500.00, $frueher);
$z3 = zahlung_erfassen($pdo, $r3, 500.00, $heute);
$checks['bezahlt trotz Faelligkeit'] = status_von($pdo, $r3) === 'Bezahlt';
zahlung_entfernen($pdo, $z3);
$checks['danach ueberfaellig']       = status_von($pdo, $r3) === 'Überfällig';

// =====================================================================
// Was nicht gebucht werden darf
// =====================================================================
$checks['kein Betrag unter einem Cent'] = zahlung_erfassen($pdo, $r3, 0.00, $heute) === 0;
$checks['kein negativer Betrag']        = zahlung_erfassen($pdo, $r3, -50.00, $heute) === 0;
$checks['nicht auf eine Unbekannte']    = zahlung_erfassen($pdo, 999999, 10.00, $heute) === 0;

// Eine stornierte Rechnung ist keine offene - und wird durch eine
// Zahlung auch keine bezahlte.
$r4 = rechnung($pdo, 300.00, $morgen, 'Storniert');
$checks['nichts auf eine stornierte']   = zahlung_erfassen($pdo, $r4, 300.00, $heute) === 0;
$checks['storniert bleibt storniert']   = status_von($pdo, $r4) === 'Storniert';
$checks['Ableiten laesst sie in Ruhe']  = rechnung_status_ableiten($pdo, $r4) === '';

// Eine Ausgabe hat kein Journal.
$pdo->prepare("INSERT INTO finances (type, title, amount, status, record_date)
               VALUES ('EXPENSE', 'Bueromaterial', 34.50, 'Offen', ?)")->execute([$heute]);
$ausgabe = (int) $pdo->lastInsertId();
$checks['nichts auf eine Ausgabe'] = zahlung_erfassen($pdo, $ausgabe, 34.50, $heute) === 0;

// Ueberzahlung wird gebucht, nicht verschwiegen.
$r5 = rechnung($pdo, 100.00, $morgen);
zahlung_erfassen($pdo, $r5, 120.00, $heute, 'zu viel ueberwiesen');
$checks['Ueberzahlung wird gebucht'] = zahlung_summe($pdo, $r5) === 120.00
                                    && status_von($pdo, $r5) === 'Bezahlt';

// =====================================================================
// Der Schalter in der Liste
// =====================================================================
// Er schreibt ins Journal statt am Status zu drehen - und nimmt nur
// zurueck, was er selbst geschrieben hat.
$r6 = rechnung($pdo, 1000.00, $morgen);
zahlung_erfassen($pdo, $r6, 300.00, $heute, 'Anzahlung', 'manual');

rechnung_status_setzen($pdo, $r6, 'Bezahlt');
$checks['Schalter schliesst die Luecke'] = zahlung_summe($pdo, $r6) === 1000.00
                                        && status_von($pdo, $r6) === 'Bezahlt';

rechnung_status_setzen($pdo, $r6, 'Offen');
$checks['Schalter nimmt nur seine Zeile'] = zahlung_summe($pdo, $r6) === 300.00
                                         && status_von($pdo, $r6) === 'Offen';
$checks['die erfasste Zahlung bleibt']
    = count(zahlungen_einer_rechnung($pdo, $r6)) === 1
   && zahlungen_einer_rechnung($pdo, $r6)[0]['source'] === 'manual';

// Stornieren und zurueck.
rechnung_status_setzen($pdo, $r6, 'Storniert');
$checks['Stornieren geht direkt'] = status_von($pdo, $r6) === 'Storniert';
rechnung_status_setzen($pdo, $r6, 'Offen');
$checks['danach wieder aus dem Journal'] = status_von($pdo, $r6) === 'Offen'
                                        && zahlung_summe($pdo, $r6) === 300.00;

// =====================================================================
// Summen fuer eine Liste
// =====================================================================
$summen = zahlung_summen($pdo, [$r1, $r2, $r6, 999999]);
$checks['Summen in einer Abfrage'] = ($summen[$r2] ?? null) === 1240.00
                                  && ($summen[$r6] ?? null) === 300.00
                                  && !isset($summen[999999]);
$checks['leere Liste, leere Antwort'] = zahlung_summen($pdo, []) === [];

// Der SQL-Ausdruck, an dem Mahnwesen und offene Posten haengen.
$offen = $pdo->query(
    'SELECT ' . RECHNUNG_OFFEN_SQL . ' AS offen FROM finances f WHERE f.id = ' . $r6
)->fetchColumn();
$checks['SQL-Ausdruck rechnet mit'] = abs((float) $offen - 700.00) < 0.005;

// =====================================================================
// Das Journal haengt an der Rechnung
// =====================================================================
$pdo->prepare('DELETE FROM finances WHERE id = ?')->execute([$r2]);
$checks['endgueltiges Loeschen raeumt mit'] = zahlungen_einer_rechnung($pdo, $r2) === [];

// =====================================================================
// Die Nachfuellanweisung aus Migration 20
// =====================================================================
// Sie laeuft genau einmal, auf einer Datenbank mit echten Zahlen. Ohne
// sie stuende der ganze Bestand als "nichts eingegangen" da.
$m = new SqliteSpiegelPDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$m->exec('PRAGMA foreign_keys = ON');
foreach (nach_sqlite(file_get_contents($wurzel . '/install/schema.sql')) as $a) {
    $m->exec($a);
}
$bezahlt1 = rechnung($m, 1190.00, $frueher, 'Bezahlt');
$bezahlt2 = rechnung($m, 850.00,  $frueher, 'Bezahlt');
$offen1   = rechnung($m, 600.00,  $morgen,  'Offen');
$storno   = rechnung($m, 400.00,  $frueher, 'Storniert');

// Die zweite Anweisung von Migration 20 - der echte Text, nicht eine
// Abschrift davon.
$nachfuellen = migrations()[20][1];
$m->exec($nachfuellen);

$checks['Bestand nachgefuellt']
    = zahlung_summe($m, $bezahlt1) === 1190.00 && zahlung_summe($m, $bezahlt2) === 850.00;
$checks['offene bleiben offen']  = zahlung_summe($m, $offen1) === 0.0;
$checks['stornierte bleiben leer'] = zahlung_summe($m, $storno) === 0.0;

$m->exec($nachfuellen);
$checks['ein zweiter Lauf fuegt nichts hinzu'] = zahlung_summe($m, $bezahlt1) === 1190.00;

// Der Status stimmt danach mit dem Journal ueberein - sonst haette die
// Nachfuellung die Zahlen verschoben statt sie zu erhalten.
$checks['Status nach dem Nachfuellen unveraendert']
    = rechnung_status_ableiten($m, $bezahlt1) === 'Bezahlt'
   && rechnung_status_ableiten($m, $offen1)   === 'Offen';

// =====================================================================
$fail = 0;
foreach ($checks as $name => $ok) {
    echo ($ok ? 'PASS' : 'FAIL') . "  $name\n";
    if (!$ok) $fail = 1;
}
exit($fail);
