<?php
/**
 * Test fuer Zahlungserinnerungen und wiederkehrende Eintraege.
 * Aufruf: php tools/test_cron_billing.php
 *
 * Zwei Dinge, die das Panel wusste, aber nicht tat:
 *
 *  - Die Vorlage 'payment_reminder' stand seit Langem fertig in
 *    includes/mail_templates.php und wurde nirgends aufgerufen.
 *  - is_recurring war ein Etikett: gesetzt, gefiltert, im CSV
 *    ausgegeben - und ohne jede Wirkung.
 *
 * Die gefaehrlichen Stellen sind hier nicht das Verschicken, sondern das
 * Zaehlen. Eine Mahnstufe, die zweimal ausloest, schickt dem Kunden
 * dieselbe Mahnung doppelt; ein next_run, das nicht weiterspringt,
 * erzeugt dieselbe Rechnung in jedem Lauf erneut. Beides faellt im
 * Betrieb erst auf, wenn es schon passiert ist.
 *
 * Der Monatsletzte hat einen eigenen Block: 31.01. + 1 Monat ist in PHP
 * der 3. Maerz, und eine Reihe, die einmal auf den 28. gerutscht ist,
 * kommt ohne Ankertag nie wieder zurueck.
 */

require_once __DIR__ . '/lib_sqlite_mirror.php';

// setting() kommt sonst aus config.php - das braucht eine Datenbank.
// mail_templates.php ruft es beim Rendern auf; hier genuegt der
// Standardwert, denn geprueft wird die Auswahl, nicht der Wortlaut.
if (!function_exists('setting')) {
    function setting(string $key, string $default = ''): string { return $default; }
}

require_once __DIR__ . '/../includes/reminders.php';
require_once __DIR__ . '/../includes/recurring.php';

$wurzel = dirname(__DIR__);
$pdo = new SqliteSpiegelPDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('PRAGMA foreign_keys = ON');
foreach (nach_sqlite(file_get_contents($wurzel . '/install/schema.sql')) as $anweisung) {
    $pdo->exec($anweisung);
}

$checks = [];

// =====================================================================
// Mahnstufen aus der Einstellung lesen
// =====================================================================
// Leer heisst aus. Das ist der Auslieferungszustand: ein Update darf
// eine bestehende Installation nicht dazu bringen, unaufgefordert Mails
// an ihre Kunden zu schicken.
$checks['leere Einstellung = keine Automatik']   = mahnstufen('') === [];
$checks['nur Leerzeichen = keine Automatik']     = mahnstufen("  \n ") === [];
$checks['Stufen werden gelesen']                 = mahnstufen('7,21') === [7, 21];
$checks['Trennzeichen sind grosszuegig']         = mahnstufen('7; 21  30') === [7, 21, 30];
$checks['unsortiert wird sortiert']              = mahnstufen('21,7') === [7, 21];
$checks['Dubletten fallen weg']                  = mahnstufen('7,7,21') === [7, 21];
$checks['Text wird ignoriert']                   = mahnstufen('abc,7') === [7];
$checks['Null und Negatives fallen weg']         = mahnstufen('0,-3,7') === [7];

// =====================================================================
// Wie lange ist das ueberfaellig?
// =====================================================================
// Auf Tagesgrenzen gerechnet: der Cron darf zu jeder Uhrzeit kommen,
// ohne dass sich das Ergebnis aendert.
$checks['heute faellig ist nicht ueberfaellig'] = tage_ueberfaellig('2026-09-04', '2026-09-04 23:59:59') === 0;
$checks['gestern faellig ist ein Tag']          = tage_ueberfaellig('2026-09-03', '2026-09-04 00:01:00') === 1;
$checks['zwei Wochen']                          = tage_ueberfaellig('2026-08-21', '2026-09-04 12:00:00') === 14;
$checks['noch nicht faellig ist negativ']       = tage_ueberfaellig('2026-09-10', '2026-09-04 12:00:00') === -6;
$checks['ohne Faelligkeit: null']               = tage_ueberfaellig(null, '2026-09-04') === 0;
$checks['leere Faelligkeit: null']              = tage_ueberfaellig('', '2026-09-04') === 0;

// =====================================================================
// Welche Stufe ist dran?
// =====================================================================
$stufen = [7, 21];
$checks['Stufe 1 greift ab Tag 7']       = mahnung_faellig(7, 0, $stufen) === true;
$checks['Stufe 1 noch nicht an Tag 6']   = mahnung_faellig(6, 0, $stufen) === false;
$checks['Stufe 2 erst ab Tag 21']        = mahnung_faellig(20, 1, $stufen) === false;
$checks['Stufe 2 greift an Tag 21']      = mahnung_faellig(21, 1, $stufen) === true;
// Nach der letzten Stufe ist Schluss. Ein Panel, das endlos weitermahnt,
// waere schlimmer als eines, das gar nicht mahnt.
$checks['nach der letzten Stufe: Ruhe']  = mahnung_faellig(90, 2, $stufen) === false;
$checks['ohne Stufen passiert nichts']   = mahnung_faellig(90, 0, []) === false;

// =====================================================================
// Die Tagessperre
// =====================================================================
// Der Cron soll stuendlich laufen duerfen, damit wiederkehrende
// Rechnungen zeitnah entstehen. Ohne diese Sperre bekaeme der Kunde
// dann stuendlich dieselbe Mahnung.
$checks['noch nie gemahnt: frei']      = mahnsperre_abgelaufen(null, '2026-09-04 10:00:00') === true;
$checks['vor einer Stunde: gesperrt']  = mahnsperre_abgelaufen('2026-09-04 09:00:00', '2026-09-04 10:00:00') === false;
$checks['vor einem Tag: frei']         = mahnsperre_abgelaufen('2026-09-03 09:00:00', '2026-09-04 10:00:00') === true;

// =====================================================================
// Auswahl aus echten Zeilen
// =====================================================================
$zeilen = [
    // faellig: 10 Tage ueberfaellig, noch nie gemahnt
    ['id' => 1, 'due_date' => '2026-08-25', 'reminder_count' => 0, 'last_reminder_at' => null,
     'empfaenger' => 'kunde@example.com'],
    // noch nicht so weit
    ['id' => 2, 'due_date' => '2026-09-02', 'reminder_count' => 0, 'last_reminder_at' => null,
     'empfaenger' => 'kunde@example.com'],
    // faellig, aber heute schon gemahnt
    ['id' => 3, 'due_date' => '2026-08-01', 'reminder_count' => 1, 'last_reminder_at' => '2026-09-04 08:00:00',
     'empfaenger' => 'kunde@example.com'],
    // faellig, aber ohne Adresse - eine Rechnung auf einen freien Namen
    ['id' => 4, 'due_date' => '2026-08-01', 'reminder_count' => 0, 'last_reminder_at' => null,
     'empfaenger' => null],
    // faellig, Adresse unbrauchbar
    ['id' => 5, 'due_date' => '2026-08-01', 'reminder_count' => 0, 'last_reminder_at' => null,
     'empfaenger' => 'keine-adresse'],
];
$treffer = faellige_mahnungen($zeilen, [7, 21], '2026-09-04 12:00:00');
$ids = array_column($treffer, 'id');

$checks['nur die faellige Zeile wird gewaehlt'] = $ids === [1];
$checks['ohne Stufen wird nichts gewaehlt']     = faellige_mahnungen($zeilen, [], '2026-09-04 12:00:00') === [];

// =====================================================================
// Der Zaehler, gegen zwei gleichzeitige Laeufe
// =====================================================================
$pdo->exec("INSERT INTO contacts (name, contact_type, email) VALUES ('Anna', 'Kunde', 'anna@example.com')");
$anna = (int) $pdo->lastInsertId();

$pdo->prepare(
    "INSERT INTO finances (type, title, invoice_number, contact_id, amount, status, record_date, due_date)
     VALUES ('INCOME', 'Rechnung A', 'RE-2026-001', ?, 500.00, 'Überfällig', '2026-08-01', '2026-08-15')"
)->execute([$anna]);
$rechnung = (int) $pdo->lastInsertId();

$checks['erste Mahnung wird vermerkt'] = mahnung_vermerken($pdo, $rechnung, 0) === true;
// Derselbe Aufruf noch einmal: der zweite Lauf sieht einen bereits
// erhoehten Zaehler und aendert nichts. Ohne diese Bedingung schickten
// zwei gleichzeitige Cron-Laeufe dieselbe Mahnung zweimal.
$checks['zweiter Lauf greift ins Leere'] = mahnung_vermerken($pdo, $rechnung, 0) === false;

$stand = $pdo->query("SELECT reminder_count, last_reminder_at FROM finances WHERE id = $rechnung")
             ->fetch(PDO::FETCH_ASSOC);
$checks['Zaehler steht auf 1']        = (int) $stand['reminder_count'] === 1;
$checks['Zeitpunkt wurde gesetzt']    = !empty($stand['last_reminder_at']);

// =====================================================================
// Die Abfrage der offenen Rechnungen
// =====================================================================
$pdo->prepare(
    "INSERT INTO finances (type, title, contact_id, amount, status, record_date, due_date)
     VALUES ('EXPENSE', 'Serverkosten', ?, 20.00, 'Offen', '2026-08-01', '2026-08-15')"
)->execute([$anna]);
$pdo->prepare(
    "INSERT INTO finances (type, title, contact_id, amount, status, record_date, due_date)
     VALUES ('INCOME', 'Bezahlt', ?, 100.00, 'Bezahlt', '2026-08-01', '2026-08-15')"
)->execute([$anna]);
$pdo->prepare(
    "INSERT INTO finances (type, title, contact_id, amount, status, record_date, due_date, deleted_at)
     VALUES ('INCOME', 'Im Papierkorb', ?, 100.00, 'Offen', '2026-08-01', '2026-08-15', '2026-09-01')"
)->execute([$anna]);

$offen = offene_rechnungen($pdo);
$titel = array_column($offen, 'title');

$checks['Ausgaben werden nicht gemahnt']       = !in_array('Serverkosten', $titel, true);
$checks['bezahlte Rechnungen nicht']           = !in_array('Bezahlt', $titel, true);
$checks['Papierkorb bleibt draussen']          = !in_array('Im Papierkorb', $titel, true);
$checks['die offene Rechnung ist dabei']       = in_array('Rechnung A', $titel, true);
$checks['die Kundenadresse kommt mit']         = ($offen[0]['empfaenger'] ?? '') === 'anna@example.com';
$checks['der Kundenname kommt mit']            = ($offen[0]['kundenname'] ?? '') === 'Anna';

// =====================================================================
// Platzhalter der Vorlage
// =====================================================================
$vars = mahnung_variablen([
    'kundenname'     => 'Anna Beispiel',
    'invoice_number' => 'RE-2026-001',
    'amount'         => 1234.5,
    'due_date'       => '2026-08-15',
], 'Meine Firma');

$checks['Betrag deutsch formatiert'] = $vars['betrag'] === '1.234,50';
$checks['Datum deutsch formatiert']  = $vars['faellig'] === '15.08.2026';
$checks['Nummer wird uebernommen']   = $vars['nummer'] === 'RE-2026-001';
$checks['Firma wird uebernommen']    = $vars['firma'] === 'Meine Firma';

// =====================================================================
// Wiederkehrende Eintraege: der naechste Termin
// =====================================================================
$checks['monatlich']        = naechster_termin('2026-09-04', 'monthly')   === '2026-10-04';
$checks['vierteljaehrlich'] = naechster_termin('2026-09-04', 'quarterly') === '2026-12-04';
$checks['jaehrlich']        = naechster_termin('2026-09-04', 'yearly')    === '2027-09-04';
$checks['ueber den Jahreswechsel'] = naechster_termin('2026-12-15', 'monthly') === '2027-01-15';
$checks['Quartal ueber den Jahreswechsel'] = naechster_termin('2026-11-30', 'quarterly') === '2027-02-28';
$checks['unbekanntes Intervall gibt null'] = naechster_termin('2026-09-04', 'weekly') === null;
$checks['unlesbares Datum gibt null']      = naechster_termin('kein Datum', 'monthly') === null;

// --- Der Monatsletzte -------------------------------------------------
// mktime(0,0,0,2,31,2026) ist in PHP der 3. Maerz. Ohne Kuerzung auf die
// Monatslaenge wandert eine Reihe, die am Monatsletzten laeuft, jedes
// Jahr weiter nach vorn.
$checks['31.01. wird zum 28.02.'] = naechster_termin('2026-01-31', 'monthly') === '2026-02-28';
$checks['31.03. wird zum 30.04.'] = naechster_termin('2026-03-31', 'monthly') === '2026-04-30';
// Und der entscheidende Fall: MIT Ankertag kommt die Reihe zurueck. Ohne
// ihn bliebe sie ab Februar fuer immer auf dem 28.
$checks['mit Ankertag zurueck auf den 31.'] = naechster_termin('2026-02-28', 'monthly', 31) === '2026-03-31';
$checks['Ankertag im kurzen Monat gekuerzt'] = naechster_termin('2026-01-31', 'monthly', 31) === '2026-02-28';
// Schaltjahr: 2028 hat einen 29. Februar.
$checks['Schaltjahr wird beachtet'] = naechster_termin('2028-01-31', 'monthly', 31) === '2028-02-29';

$checks['Ankertag aus record_date'] = wiederholung_ankertag(['record_date' => '2026-01-31']) === 31;
$checks['sonst aus next_run']       = wiederholung_ankertag(['record_date' => null, 'next_run' => '2026-05-15']) === 15;
$checks['ohne beides: null']        = wiederholung_ankertag([]) === null;

// --- Ist das faellig? -------------------------------------------------
$checks['Termin heute ist faellig']    = wiederholung_faellig('2026-09-04', '2026-09-04 08:00:00') === true;
$checks['Termin gestern ist faellig']  = wiederholung_faellig('2026-09-03', '2026-09-04 08:00:00') === true;
$checks['Termin morgen noch nicht']    = wiederholung_faellig('2026-09-05', '2026-09-04 23:00:00') === false;
$checks['ohne Termin nicht faellig']   = wiederholung_faellig(null, '2026-09-04') === false;

// =====================================================================
// Eine Vorlage ausfuehren
// =====================================================================
$pdo->prepare(
    "INSERT INTO finances (type, title, contact_id, amount, status, record_date, notes,
                           items, tax_type, is_recurring, recurrence, next_run)
     VALUES ('INCOME', 'Wartung Website', ?, 119.00, 'Offen', '2026-01-15', 'Monatlicher Vertrag',
             '[{\"desc\":\"Wartung\",\"qty\":1,\"price\":100,\"unit\":\"Pauschale\"}]',
             'regel', 1, 'monthly', '2026-02-15')"
)->execute([$anna]);
$vorlage_id = (int) $pdo->lastInsertId();

$vorlage = $pdo->query("SELECT * FROM finances WHERE id = $vorlage_id")->fetch(PDO::FETCH_ASSOC);
$neu_id  = vorlage_ausfuehren($pdo, $vorlage, '2026-02-15');
$neu     = $pdo->query("SELECT * FROM finances WHERE id = $neu_id")->fetch(PDO::FETCH_ASSOC);

$checks['aus der Vorlage entsteht ein Eintrag'] = $neu_id > 0;
$checks['Titel wird uebernommen']       = $neu['title'] === 'Wartung Website';
$checks['Kunde wird uebernommen']       = (int) $neu['contact_id'] === $anna;
$checks['Betrag wird uebernommen']      = (float) $neu['amount'] === 119.00;
$checks['Positionen wandern mit']       = strpos((string) $neu['items'], 'Wartung') !== false;
$checks['Steuerart wandert mit']        = $neu['tax_type'] === 'regel';
$checks['Datum ist der Termin']         = $neu['record_date'] === '2026-02-15';
$checks['Zahlungsziel 14 Tage']         = $neu['due_date'] === '2026-03-01';
$checks['Status ist offen']             = $neu['status'] === 'Offen';
$checks['Herkunft wird vermerkt']       = (int) $neu['recurring_parent_id'] === $vorlage_id;
// Der wichtigste: die erzeugte Rechnung ist selbst KEINE Vorlage. Waere
// sie es, verdoppelte sich die Reihe bei jedem Lauf.
$checks['erzeugter Eintrag ist keine Vorlage'] = $neu['recurrence'] === '' && $neu['next_run'] === null;
// Eine Einnahme bekommt eine Rechnungsnummer.
$checks['Einnahme bekommt eine Nummer'] = preg_match('/^RE-2026-\d{3}$/', (string) $neu['invoice_number']) === 1;

// Und die Vorlage ist weitergerueckt.
$nach = $pdo->query("SELECT next_run FROM finances WHERE id = $vorlage_id")->fetch(PDO::FETCH_ASSOC);
$checks['Vorlage rueckt weiter'] = $nach['next_run'] === '2026-03-15';

// --- Eine Ausgabe bekommt keine Rechnungsnummer -----------------------
// Sie ist keine Ausgangsrechnung. Jede vergebene Nummer ohne Rechnung
// dahinter ist eine Luecke in der Reihe, die spaeter erklaert werden muss.
$pdo->exec(
    "INSERT INTO finances (type, title, amount, status, record_date, recurrence, next_run)
     VALUES ('EXPENSE', 'Serverkosten', 29.00, 'Offen', '2026-01-01', 'monthly', '2026-02-01')"
);
$ausgabe_vorlage = (int) $pdo->lastInsertId();
$v2 = $pdo->query("SELECT * FROM finances WHERE id = $ausgabe_vorlage")->fetch(PDO::FETCH_ASSOC);
$ausgabe_neu = vorlage_ausfuehren($pdo, $v2, '2026-02-01');
$a = $pdo->query("SELECT * FROM finances WHERE id = $ausgabe_neu")->fetch(PDO::FETCH_ASSOC);

$checks['Ausgabe bleibt eine Ausgabe']       = $a['type'] === 'EXPENSE';
$checks['Ausgabe bekommt keine Nummer']      = $a['invoice_number'] === null;

// --- Unbekanntes Intervall erzeugt nichts -----------------------------
$checks['unbekanntes Intervall erzeugt nichts'] =
    vorlage_ausfuehren($pdo, ['id' => 999, 'recurrence' => 'weekly'], '2026-02-01') === null;

// =====================================================================
// Nachholen: der Cron lief drei Monate nicht
// =====================================================================
$pdo->exec(
    "INSERT INTO finances (type, title, amount, status, record_date, recurrence, next_run)
     VALUES ('EXPENSE', 'Lizenz', 10.00, 'Offen', '2026-01-10', 'monthly', '2026-01-10')"
);
$lueckenhaft = (int) $pdo->lastInsertId();

$ergebnis = wiederholungen_ausfuehren($pdo, '2026-04-15');
$erzeugte = (int) $pdo->query(
    "SELECT COUNT(*) FROM finances WHERE recurring_parent_id = $lueckenhaft"
)->fetchColumn();

// Januar, Februar, Maerz, April - vier Termine bis zum 15.04.
$checks['ausgefallene Termine werden nachgeholt'] = $erzeugte === 4;
$stand2 = $pdo->query("SELECT next_run FROM finances WHERE id = $lueckenhaft")->fetch(PDO::FETCH_ASSOC);
$checks['danach steht der Termin in der Zukunft'] = $stand2['next_run'] === '2026-05-10';
$checks['der Lauf meldet, was er tat']            = $ergebnis['erzeugt'] >= 4;

// --- Der Deckel gegen Ausreisser --------------------------------------
// Ein next_run aus dem Jahr 2010 - eine von Hand gesetzte Zeile, eine
// Datenbank aus dem Backup - darf nicht ein Jahrzehnt Rechnungen in
// einem Lauf erzeugen.
$pdo->exec(
    "INSERT INTO finances (type, title, amount, status, record_date, recurrence, next_run)
     VALUES ('EXPENSE', 'Uralt', 5.00, 'Offen', '2010-01-01', 'monthly', '2010-01-01')"
);
$uralt = (int) $pdo->lastInsertId();
wiederholungen_ausfuehren($pdo, '2026-04-15');
$uralt_erzeugt = (int) $pdo->query(
    "SELECT COUNT(*) FROM finances WHERE recurring_parent_id = $uralt"
)->fetchColumn();

$checks['der Deckel greift'] = $uralt_erzeugt === WIEDERHOLUNG_MAX_NACHHOLEN;

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
