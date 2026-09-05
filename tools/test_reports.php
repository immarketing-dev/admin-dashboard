<?php
/**
 * Test fuer die Auswertungen.
 * Aufruf: php tools/test_reports.php
 *
 * Seit Schemaversion 9 liegen Stundensatz, erfasste Zeit und
 * abgerechneter Betrag alle vor - ausgewertet wurde davon nichts.
 *
 * Die heiklen Stellen sind hier die Grenzen: eine Rechnung, die genau
 * heute faellig ist, gehoert in keine Mahnstufe; ein Zeiteintrag vom
 * letzten Tag des Monats gehoert noch in den Monat, auch wenn er um
 * 23:50 Uhr entstanden ist; und ein Stundensatz von 0,00 ist eine
 * Aussage und keine fehlende Angabe.
 *
 * Nicht geprueft: umsatz_jahre(). Die Funktion benutzt YEAR(), das
 * SQLite nicht kennt - sie im Spiegel nachzubilden waere mehr Aufwand
 * als die eine Zeile wert ist, die sie enthaelt.
 */

require_once __DIR__ . '/lib_sqlite_mirror.php';
require_once __DIR__ . '/../includes/reports.php';
require_once __DIR__ . '/../includes/migrations.php';

$wurzel = dirname(__DIR__);
$pdo = new SqliteSpiegelPDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('PRAGMA foreign_keys = ON');
foreach (nach_sqlite(file_get_contents($wurzel . '/install/schema.sql')) as $anweisung) {
    $pdo->exec($anweisung);
}

$checks = [];

// =====================================================================
// Offene Posten: die Altersstufen
// =====================================================================
// Eine heute faellige Rechnung ist offen, aber nicht ueberfaellig - sie
// gehoert in keinen der Mahneimer, sondern in "nicht faellig".
$checks['heute faellig ist nicht ueberfaellig'] = op_stufe(0) === -1;
$checks['noch nicht faellig ebenso']            = op_stufe(-5) === -1;
$checks['ein Tag ist die erste Stufe']          = op_stufe(1) === 0;
$checks['30 Tage noch erste Stufe']             = op_stufe(30) === 0;
$checks['31 Tage sind die zweite']              = op_stufe(31) === 1;
$checks['90 Tage sind die dritte']              = op_stufe(90) === 2;
$checks['91 Tage fallen in den Rest']           = op_stufe(91) === 3;
$checks['ein Jahr auch']                        = op_stufe(365) === 3;

$namen = op_stufen_namen();
$checks['fuenf Eimer']            = count($namen) === 5;
$checks['der erste heisst richtig'] = $namen[0] === 'nicht fällig';
$checks['der letzte heisst richtig'] = $namen[4] === 'über 90 Tage';

// --- Verteilen --------------------------------------------------------
$offen = [
    ['due_date' => '2026-09-20', 'amount' => 100.00],  // noch nicht faellig
    ['due_date' => '2026-09-04', 'amount' => 200.00],  // heute faellig
    ['due_date' => '2026-08-25', 'amount' => 300.00],  // 10 Tage
    ['due_date' => '2026-07-20', 'amount' => 400.00],  // 46 Tage
    ['due_date' => '2026-05-01', 'amount' => 500.00],  // 126 Tage
];
$eimer = offene_posten_verteilen($offen, '2026-09-04');

$checks['nicht faellig: zwei Posten']    = $eimer[0]['anzahl'] === 2;
$checks['nicht faellig: 300 Euro']       = abs($eimer[0]['betrag'] - 300.00) < 0.001;
$checks['1-30 Tage: ein Posten']         = $eimer[1]['anzahl'] === 1 && abs($eimer[1]['betrag'] - 300.00) < 0.001;
$checks['31-60 Tage: ein Posten']        = $eimer[2]['anzahl'] === 1 && abs($eimer[2]['betrag'] - 400.00) < 0.001;
$checks['61-90 Tage: leer']              = $eimer[3]['anzahl'] === 0;
$checks['ueber 90 Tage: ein Posten']     = $eimer[4]['anzahl'] === 1 && abs($eimer[4]['betrag'] - 500.00) < 0.001;
$checks['leere Liste ergibt leere Eimer'] = array_sum(array_column(offene_posten_verteilen([], '2026-09-04'), 'anzahl')) === 0;

// =====================================================================
// Stunden
// =====================================================================
$checks['90 Minuten sind 1,5 Stunden'] = stunden(90) === 1.5;
$checks['auf zwei Stellen gerundet']   = stunden(100) === 1.67;
$checks['null bleibt null']            = stunden(0) === 0.0;

$checks['465 Minuten lesbar']    = stunden_lesbar(465) === '7:45';
$checks['glatte Stunde lesbar']  = stunden_lesbar(120) === '2:00';
$checks['unter einer Stunde']    = stunden_lesbar(5) === '0:05';
$checks['negative Zeit']         = stunden_lesbar(-90) === '-1:30';

// =====================================================================
// Zeitraeume
// =====================================================================
// Der 4. September 2026 ist ein Freitag - die Woche laeuft von Montag,
// dem 31. August, bis Sonntag, dem 6. September.
$w = zeitraum_grenzen('week', '2026-09-04');
$checks['Woche beginnt am Montag'] = $w['von'] === '2026-08-31';
$checks['Woche endet am Sonntag']  = $w['bis'] === '2026-09-06';

// Ein Sonntag gehoert zur Woche davor, nicht zur naechsten. Mit
// "sunday this week" ist das richtig; mit "next sunday" waere es das
// nicht, und der Fehler faellt genau einen Tag pro Woche auf.
$w2 = zeitraum_grenzen('week', '2026-09-06');
$checks['Sonntag zaehlt zur laufenden Woche'] = $w2['von'] === '2026-08-31' && $w2['bis'] === '2026-09-06';

$m = zeitraum_grenzen('month', '2026-09-15');
$checks['Monat von Erstem bis Letztem'] = $m['von'] === '2026-09-01' && $m['bis'] === '2026-09-30';

$feb = zeitraum_grenzen('month', '2028-02-10');
$checks['Schaltjahr-Februar hat 29 Tage'] = $feb['bis'] === '2028-02-29';

$j = zeitraum_grenzen('year', '2026-06-01');
$checks['Jahr von Januar bis Dezember'] = $j['von'] === '2026-01-01' && $j['bis'] === '2026-12-31';

$checks['unbekannter Modus wird zum Monat'] = zeitraum_grenzen('quatsch', '2026-09-15')['von'] === '2026-09-01';

// --- Blaettern --------------------------------------------------------
$checks['eine Woche zurueck'] = zeitraum_verschieben('week', '2026-09-04', -1) === '2026-08-28';
$checks['eine Woche vor']     = zeitraum_verschieben('week', '2026-09-04', 1) === '2026-09-11';
$checks['ein Jahr vor']       = zeitraum_verschieben('year', '2026-06-15', 1) === '2027-01-01';

// Der Fallstrick: vom 31. Januar aus ueberspringt "+1 month" den Februar
// und landet im Maerz. Deshalb wird erst auf den Monatsersten gegangen.
$checks['vom 31. aus wird der Februar nicht uebersprungen']
    = zeitraum_verschieben('month', '2026-01-31', 1) === '2026-02-01';
$checks['ein Monat zurueck'] = zeitraum_verschieben('month', '2026-03-15', -1) === '2026-02-01';

// =====================================================================
// Umsatz je Kunde
// =====================================================================
$pdo->exec("INSERT INTO contacts (name, contact_type, hourly_rate) VALUES ('Anna', 'Kunde', 95.00)");
$anna = (int) $pdo->lastInsertId();
$pdo->exec("INSERT INTO contacts (name, contact_type) VALUES ('Bruno', 'Kunde')");
$bruno = (int) $pdo->lastInsertId();

$ins = $pdo->prepare(
    "INSERT INTO finances (type, title, contact_id, custom_name, amount, status, record_date, due_date)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
);
$ins->execute(['INCOME', 'RE-1', $anna,  null, 1000.00, 'Bezahlt',    '2026-03-01', '2026-03-15']);
$ins->execute(['INCOME', 'RE-2', $anna,  null,  500.00, 'Offen',      '2026-04-01', '2026-04-15']);
$ins->execute(['INCOME', 'RE-3', $bruno, null,  200.00, 'Bezahlt',    '2026-05-01', '2026-05-15']);
$ins->execute(['INCOME', 'RE-4', null,   'Barverkauf', 50.00, 'Bezahlt', '2026-06-01', null]);
// Nicht mitzaehlen: Ausgabe, Vorjahr, Papierkorb.
$ins->execute(['EXPENSE', 'Server', $anna, null, 30.00, 'Bezahlt', '2026-03-01', null]);
$ins->execute(['INCOME', 'RE-alt', $anna, null, 9999.00, 'Bezahlt', '2025-03-01', null]);
$pdo->prepare(
    "INSERT INTO finances (type, title, contact_id, amount, status, record_date, deleted_at)
     VALUES ('INCOME', 'RE-weg', ?, 777.00, 'Bezahlt', '2026-03-01', '2026-04-01')"
)->execute([$anna]);

// Seit Migration 20 haengt "bezahlt" am Zahlungsjournal und nicht mehr
// am Status. Auf einer bestehenden Datenbank hat die Migration die
// Zeilen dafuer nachgefuellt - hier tut es dieselbe Anweisung, damit die
// Ausgangslage die eines echten Panels ist und nicht eine, die es so
// nirgends gibt.
$pdo->exec(migration_20_nachfuellen());

// Eine Rechnung mit Anzahlung. Vorher zaehlte sie ganz zu "offen" oder
// ganz zu "bezahlt" - beides war falsch.
$ins->execute(['INCOME', 'RE-5', $bruno, null, 1000.00, 'Offen', '2026-07-01', '2026-07-15']);
$teil_id = (int) $pdo->lastInsertId();
$pdo->prepare("INSERT INTO payments (finance_id, amount, paid_at, source)
               VALUES (?, 400.00, '2026-07-20', 'manual')")->execute([$teil_id]);

$umsatz = umsatz_je_kunde($pdo, 2026);
$nach_kunde = [];
foreach ($umsatz as $u) {
    $nach_kunde[$u['kunde']] = $u;
}

$checks['Anna bezahlt: 1000']           = abs($nach_kunde['Anna']['bezahlt'] - 1000.00) < 0.001;
$checks['Anna offen: 500']              = abs($nach_kunde['Anna']['offen'] - 500.00) < 0.001;
$checks['Anna: zwei Rechnungen']        = $nach_kunde['Anna']['anzahl'] === 2;
// 200 aus der beglichenen Rechnung, 400 als Anzahlung auf die
// naechste - beides ist Geld, das eingegangen ist.
$checks['Bruno bezahlt: 600']           = abs($nach_kunde['Bruno']['bezahlt'] - 600.00) < 0.001;
$checks['Bruno offen: 600']             = abs($nach_kunde['Bruno']['offen'] - 600.00) < 0.001;
// Eine Rechnung ohne Kontakt laeuft unter ihrem freien Namen mit - sie
// ist Umsatz wie jeder andere.
$checks['freier Name zaehlt mit']       = isset($nach_kunde['Barverkauf']);
$checks['Ausgaben zaehlen nicht mit']   = !isset($nach_kunde['Server']);
$checks['Papierkorb bleibt draussen']   = abs($nach_kunde['Anna']['bezahlt'] - 1000.00) < 0.001;
$checks['Vorjahr bleibt draussen']      = count(umsatz_je_kunde($pdo, 2025)) === 1;
$checks['nach Umsatz sortiert']         = $umsatz[0]['kunde'] === 'Anna';

// =====================================================================
// Offene Posten aus der Datenbank
// =====================================================================
$posten = offene_posten($pdo);
// Zwei: die unbezahlte und die angezahlte. Eine Anzahlung nimmt eine
// Rechnung nicht aus den offenen Posten heraus, sie verkleinert sie.
$checks['nur offene Rechnungen']    = count($posten) === 2
                                   && $posten[0]['title'] === 'RE-2';
$checks['angezahlte mit Rest drin'] = $posten[1]['title'] === 'RE-5'
                                   && abs((float) $posten[1]['offen'] - 600.00) < 0.001;
$checks['der Kundenname kommt mit'] = $posten[0]['kunde'] === 'Anna';

// =====================================================================
// Zeit je Projekt
// =====================================================================
$pdo->prepare("INSERT INTO tasks (title, status, contact_id, hourly_rate) VALUES ('Mit Satz', 'Offen', ?, 120.00)")
    ->execute([$anna]);
$mit_satz = (int) $pdo->lastInsertId();
$pdo->prepare("INSERT INTO tasks (title, status, contact_id) VALUES ('Kundensatz', 'Offen', ?)")
    ->execute([$anna]);
$kundensatz = (int) $pdo->lastInsertId();
$pdo->prepare("INSERT INTO tasks (title, status, contact_id) VALUES ('Ohne alles', 'Offen', ?)")
    ->execute([$bruno]);
$ohne = (int) $pdo->lastInsertId();
// Ein Satz von 0,00 heisst "wird nicht berechnet" - er darf nicht durch
// die Voreinstellung ersetzt werden.
$pdo->prepare("INSERT INTO tasks (title, status, contact_id, hourly_rate) VALUES ('Kulanz', 'Offen', ?, 0.00)")
    ->execute([$anna]);
$kulanz = (int) $pdo->lastInsertId();
// Ein Projekt ohne jede Zeit taucht in der Auswertung nicht auf.
$pdo->prepare("INSERT INTO tasks (title, status, contact_id) VALUES ('Nie bearbeitet', 'Offen', ?)")
    ->execute([$anna]);

$te = $pdo->prepare("INSERT INTO time_entries (task_id, duration_minutes, note, created_at) VALUES (?, ?, ?, ?)");
$te->execute([$mit_satz, 120, 'Konzept',  '2026-09-01 09:00:00']);
$te->execute([$mit_satz,  60, 'Umsetzung','2026-09-02 10:00:00']);
$te->execute([$kundensatz, 90, 'Beratung','2026-09-03 11:00:00']);
$te->execute([$ohne, 30, 'Anruf',         '2026-09-04 12:00:00']);
$te->execute([$kulanz, 60, 'Kulanz',      '2026-09-04 13:00:00']);

// Zwei Stunden auf "Mit Satz" sind bereits abgerechnet.
$pdo->exec("UPDATE time_entries SET billed_at = '2026-09-05 08:00:00' WHERE task_id = $mit_satz AND duration_minutes = 120");

$projekte = zeit_je_projekt($pdo, 80.0);
$nach_titel = [];
foreach ($projekte as $p) {
    $nach_titel[$p['title']] = $p;
}

$checks['Projektsatz hat Vorrang']       = $nach_titel['Mit Satz']['satz'] === 120.0;
$checks['sonst der Kundensatz']          = $nach_titel['Kundensatz']['satz'] === 95.0;
$checks['sonst die Voreinstellung']      = $nach_titel['Ohne alles']['satz'] === 80.0;
$checks['null ist eine Aussage']         = $nach_titel['Kulanz']['satz'] === 0.0;
$checks['Projekte ohne Zeit fehlen']     = !isset($nach_titel['Nie bearbeitet']);

$checks['erfasste Minuten stimmen']      = $nach_titel['Mit Satz']['minuten'] === 180;
$checks['abgerechnete Minuten stimmen']  = $nach_titel['Mit Satz']['berechnet'] === 120;
$checks['offene Minuten stimmen']        = $nach_titel['Mit Satz']['offen'] === 60;
// Eine Stunde offen zu 120,00 - das ist die Zahl, wegen der es diese
// Auswertung gibt: was ist geleistet und noch nicht in Rechnung?
$checks['offener Wert stimmt']           = abs($nach_titel['Mit Satz']['offen_wert'] - 120.00) < 0.001;
$checks['Kulanz ist nichts wert']        = abs($nach_titel['Kulanz']['offen_wert']) < 0.001;

// =====================================================================
// Stundenzettel
// =====================================================================
$eintraege = zeiteintraege($pdo, '2026-09-01', '2026-09-04');
$checks['der Zeitraum greift'] = count($eintraege) === 5;

// Die obere Grenze muss den letzten Tag ganz enthalten. created_at ist
// ein Zeitstempel; ein "<= 2026-09-04" verloere alles nach Mitternacht.
$te->execute([$ohne, 15, 'Spaetschicht', '2026-09-04 23:50:00']);
$eintraege = zeiteintraege($pdo, '2026-09-04', '2026-09-04');
$checks['der letzte Tag zaehlt ganz'] = count($eintraege) === 3;

$eintraege = zeiteintraege($pdo, '2026-09-01', '2026-09-30');
$tage = zeiten_nach_tag($eintraege);
$checks['nach Tag gruppiert']       = count($tage) === 4;
$checks['Tagessumme stimmt']        = $tage['2026-09-04']['minuten'] === 105;
$checks['Eintraege haengen am Tag'] = count($tage['2026-09-04']['eintraege']) === 3;

$je_projekt = zeiten_nach_projekt($eintraege);
$checks['nach Dauer sortiert']    = $je_projekt[0]['projekt'] === 'Mit Satz';
$checks['Projektsumme stimmt']    = $je_projekt[0]['minuten'] === 180;
$checks['davon offen']            = $je_projekt[0]['offen'] === 60;

// Ein Zeiteintrag an einem geloeschten Projekt taucht nicht auf: der
// JOIN filtert ihn, sonst stuende auf dem Stundenzettel Arbeit an
// etwas, das es nicht mehr gibt.
$pdo->prepare("INSERT INTO tasks (title, status, contact_id, deleted_at) VALUES ('Papierkorb', 'Offen', ?, '2026-09-01')")
    ->execute([$anna]);
$weg = (int) $pdo->lastInsertId();
$te->execute([$weg, 240, 'Verlorene Zeit', '2026-09-03 08:00:00']);

$checks['geloeschte Projekte bleiben draussen']
    = count(zeiteintraege($pdo, '2026-09-01', '2026-09-30')) === count($eintraege);

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
