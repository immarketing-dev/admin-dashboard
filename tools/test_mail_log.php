<?php
/**
 * Test fuer das Mailprotokoll.
 * Aufruf: php tools/test_mail_log.php
 *
 * Das Panel verschickt neun Sorten Mail und hielt von keiner fest, was
 * wann an wen ging. Bei "ich habe nie ein Angebot bekommen" gab es
 * nichts nachzusehen.
 *
 * Zwei Dinge sind hier wichtiger, als sie aussehen:
 *
 *  - Ein fehlgeschlagenes Protokoll darf keine Mail verhindern. Die
 *    Reihenfolge ist: erst verschicken, dann aufschreiben, und wenn das
 *    Aufschreiben scheitert, ist die Mail trotzdem draussen.
 *  - Das Protokoll haelt laenger als das Ereignisprotokoll. Ein
 *    Versandnachweis wird Monate spaeter gebraucht, nicht Tage - wer
 *    log_retention_days auf eine Woche stellt, darf damit nicht die
 *    Nachweise wegwerfen.
 */

require_once __DIR__ . '/lib_sqlite_mirror.php';

// setting() kommt sonst aus config.php. Als Variable, damit die
// Aufbewahrungsfrist im Test umgestellt werden kann.
$EINSTELLUNGEN = [];
if (!function_exists('setting')) {
    function setting(string $key, string $default = ''): string {
        global $EINSTELLUNGEN;
        return $EINSTELLUNGEN[$key] ?? $default;
    }
}

require_once __DIR__ . '/../includes/mail_log.php';

$wurzel = dirname(__DIR__);
$pdo = new SqliteSpiegelPDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('PRAGMA foreign_keys = ON');
foreach (nach_sqlite(file_get_contents($wurzel . '/install/schema.sql')) as $anweisung) {
    $pdo->exec($anweisung);
}

$checks = [];

// =====================================================================
// Schreiben
// =====================================================================
mail_protokollieren($pdo, 'quote_send', 'kunde@example.com', 'Angebot ANG-2026-001',
    true, null, 'Angebot ANG-2026-001');

$zeile = $pdo->query('SELECT * FROM mail_log ORDER BY id DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC);

$checks['Eintrag wird geschrieben']  = is_array($zeile);
$checks['Vorlage steht drin']        = $zeile['template'] === 'quote_send';
$checks['Empfaenger steht drin']     = $zeile['recipient'] === 'kunde@example.com';
$checks['Betreff steht drin']        = $zeile['subject'] === 'Angebot ANG-2026-001';
$checks['Status ist sent']           = $zeile['status'] === 'sent';
$checks['ohne Fehler kein Fehler']   = $zeile['error'] === null;
$checks['Bezug steht drin']          = $zeile['context'] === 'Angebot ANG-2026-001';
$checks['Zeitpunkt wird gesetzt']    = !empty($zeile['created_at']);

// --- Der Fehlerfall ----------------------------------------------------
mail_protokollieren($pdo, 'invoice_send', 'kunde@example.com', 'Rechnung RE-2026-007',
    false, 'SMTP connect() failed', 'Rechnung RE-2026-007');

$zeile = $pdo->query('SELECT * FROM mail_log ORDER BY id DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
$checks['Fehler wird als failed vermerkt'] = $zeile['status'] === 'failed';
$checks['die Meldung wird aufbewahrt']     = $zeile['error'] === 'SMTP connect() failed';

// --- Ueberlange Werte --------------------------------------------------
// Betreff und Empfaenger kommen teils aus Formularen. Laufen sie ueber
// die Spaltenbreite, wirft MySQL - und der Versand waere protokolliert
// gescheitert, obwohl er geklappt hat.
mail_protokollieren(
    $pdo,
    str_repeat('x', 200),
    str_repeat('a', 400) . '@example.com',
    str_repeat('B', 900),
    true,
    str_repeat('F', 5000),
    str_repeat('C', 900)
);
$zeile = $pdo->query('SELECT * FROM mail_log ORDER BY id DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC);

$checks['Vorlage wird auf 50 gekappt']    = mb_strlen((string) $zeile['template']) === 50;
$checks['Empfaenger auf 255']             = mb_strlen((string) $zeile['recipient']) === 255;
$checks['Betreff auf 255']                = mb_strlen((string) $zeile['subject']) === 255;
$checks['Fehlertext auf 2000']            = mb_strlen((string) $zeile['error']) === 2000;
$checks['Bezug auf 255']                  = mb_strlen((string) $zeile['context']) === 255;

// --- Ein kaputtes Protokoll reisst nichts mit --------------------------
// Die entscheidende Zusage: schlaegt das Schreiben fehl, wirft die
// Funktion nicht. Sonst waere eine verschickte Mail ein abgebrochener
// Vorgang.
$kaputt = new SqliteSpiegelPDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$geworfen = false;
$log_alt = ini_get('error_log');
ini_set('error_log', tempnam(sys_get_temp_dir(), 'maillogtest'));
try {
    // Diese Verbindung hat die Tabelle gar nicht.
    mail_protokollieren($kaputt, 'quote_send', 'a@example.com', 'Betreff', true);
} catch (Throwable $e) {
    $geworfen = true;
}
ini_set('error_log', $log_alt === false ? '' : $log_alt);
$checks['fehlendes Protokoll wirft nicht'] = $geworfen === false;

// =====================================================================
// Lesen
// =====================================================================
$alle = mail_protokoll($pdo);
$checks['alle Eintraege kommen zurueck'] = count($alle) === 3;
// Neueste zuerst - beim Nachsehen interessiert der letzte Versuch.
$checks['neueste zuerst'] = mb_strlen((string) $alle[0]['template']) === 50;

$nur_fehler = mail_protokoll($pdo, 'failed');
$checks['Filter auf Fehler greift']   = count($nur_fehler) === 1
                                     && $nur_fehler[0]['template'] === 'invoice_send';
$nur_ok = mail_protokoll($pdo, 'sent');
$checks['Filter auf Erfolg greift']   = count($nur_ok) === 2;
// Ein unbekannter Filter zeigt alles, statt nichts zu zeigen.
$checks['unbekannter Filter zeigt alles'] = count(mail_protokoll($pdo, 'quatsch')) === 3;

$checks['Limit wird beachtet'] = count(mail_protokoll($pdo, '', 1)) === 1;
// Ein unsinniges Limit darf die Abfrage nicht kippen - der Wert landet
// als Zahl in der Anweisung, nicht als gebundener Wert.
$checks['Limit 0 wird auf 1 gehoben']    = count(mail_protokoll($pdo, '', 0)) === 1;
$checks['negatives Limit ebenso']        = count(mail_protokoll($pdo, '', -5)) === 1;

$zahlen = mail_protokoll_zahlen($pdo);
$checks['Gesamtzahl stimmt']   = $zahlen['gesamt'] === 3;
$checks['Fehlerzahl stimmt']   = $zahlen['fehler'] === 1;

// =====================================================================
// Aufbewahrung
// =====================================================================
// Ein Eintrag von vor zwei Jahren.
$pdo->exec(
    "INSERT INTO mail_log (template, recipient, subject, status, created_at)
     VALUES ('milestone', 'alt@example.com', 'Uralt', 'sent', '2023-01-01 10:00:00')"
);
// Und einer von vor einem halben Jahr.
$halbjahr = date('Y-m-d H:i:s', strtotime('-180 days'));
$pdo->prepare(
    "INSERT INTO mail_log (template, recipient, subject, status, created_at)
     VALUES ('milestone', 'mittel@example.com', 'Halbjahr', 'sent', ?)"
)->execute([$halbjahr]);

// Selbst bei einer Woche Aufbewahrung im Systemprotokoll bleibt der
// Versandnachweis ein Jahr. Ohne diese Untergrenze waere er weg, sobald
// jemand die Logs kurz haelt - und genau dann fragt der Kunde nach.
$EINSTELLUNGEN['log_retention_days'] = '7';
$weg = mail_protokoll_kuerzen($pdo);

$uebrig = array_column($pdo->query('SELECT subject FROM mail_log')->fetchAll(PDO::FETCH_ASSOC), 'subject');

$checks['der uralte Eintrag faellt weg']      = !in_array('Uralt', $uebrig, true);
$checks['der halbjaehrige bleibt']            = in_array('Halbjahr', $uebrig, true);
$checks['das Kuerzen meldet die Zahl']        = $weg === 1;
$checks['die frischen Eintraege bleiben']     = count($uebrig) === 4;

// Ein zweiter Lauf findet nichts mehr.
$checks['zweiter Lauf raeumt nichts'] = mail_protokoll_kuerzen($pdo) === 0;

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
