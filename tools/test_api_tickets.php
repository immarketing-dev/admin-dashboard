<?php
/**
 * Test fuer die eingehenden Mails.
 * Aufruf: php tools/test_api_tickets.php
 *
 * Ein Ticket entstand nur, wenn du es anlegst oder ein Kunde sich ins
 * Portal einloggt. Kunden schreiben aber E-Mails - die support@-Adresse
 * stand in der .env und wurde nur zum Versenden benutzt.
 *
 * Die gefaehrlichste Stelle ist die Zuordnung. Die Kennung [#14] steht
 * im Betreff und damit in einer Mail, die JEDER schreiben kann. Ohne
 * Pruefung, ob das Ticket dem Absender gehoert, koennte jemand mit der
 * richtigen Nummer im Betreff eine Notiz in einen fremden Vorgang
 * schreiben - und die waere im Portal des echten Kunden sichtbar.
 */

require_once __DIR__ . '/lib_sqlite_mirror.php';
require_once __DIR__ . '/../includes/api_tickets.php';

$wurzel = dirname(__DIR__);
$pdo = new SqliteSpiegelPDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('PRAGMA foreign_keys = ON');
foreach (nach_sqlite(file_get_contents($wurzel . '/install/schema.sql')) as $anweisung) {
    $pdo->exec($anweisung);
}

$checks = [];

// =====================================================================
// Die Kennung
// =====================================================================
$checks['Kennung wird gebaut']       = ticket_betreffkennung(14) === '[#14]';
$checks['Kennung wird gefunden']     = ticket_kennung_aus_betreff('Re: [#14] Drucker') === 14;
$checks['auch mitten im Betreff']    = ticket_kennung_aus_betreff('Drucker [#7] geht nicht') === 7;
$checks['ohne Kennung: null']        = ticket_kennung_aus_betreff('Drucker geht nicht') === null;
// Absichtlich streng: nur [# gefolgt von Ziffern.
$checks['ohne Raute: null']          = ticket_kennung_aus_betreff('Rechnung [2026] falsch') === null;
$checks['Buchstaben: null']          = ticket_kennung_aus_betreff('[#abc]') === null;

// --- Der Betreff als Titel ---------------------------------------------
$checks['Kennung faellt weg']        = ticket_betreff_saeubern('[#14] Drucker geht nicht') === 'Drucker geht nicht';
$checks['Re: faellt weg']            = ticket_betreff_saeubern('Re: Drucker') === 'Drucker';
$checks['AW: faellt weg']            = ticket_betreff_saeubern('AW: Drucker') === 'Drucker';
// Die Praefixe stapeln sich beim dritten Hin und Her.
$checks['gestapelte Praefixe fallen weg'] = ticket_betreff_saeubern('Re: AW: Re: Drucker') === 'Drucker';
$checks['zusammen mit der Kennung']  = ticket_betreff_saeubern('Re: AW: [#14] Drucker') === 'Drucker';
$checks['Re[2]: faellt weg']         = ticket_betreff_saeubern('Re[2]: Drucker') === 'Drucker';
$checks['ein sauberer Betreff bleibt'] = ticket_betreff_saeubern('Drucker geht nicht') === 'Drucker geht nicht';

// =====================================================================
// Zitierten Text abschneiden
// =====================================================================
// Ohne das steht beim dritten Hin und Her dieselbe Frage viermal im
// Verlauf.
$mit_zitat = "Das Problem besteht weiterhin.\n\nAm 01.03.2026 um 10:00 schrieb Support:\n> Haben Sie es neu gestartet?\n> Viele Grüße";
$checks['Zitat wird abgeschnitten'] = ticket_zitat_entfernen($mit_zitat) === 'Das Problem besteht weiterhin.';

$outlook = "Danke!\n\n-----Ursprüngliche Nachricht-----\nVon: Support\nGesendet: Montag";
$checks['Outlook-Trenner wird erkannt'] = ticket_zitat_entfernen($outlook) === 'Danke!';

$englisch = "Still broken.\n\nOn Mar 1, 2026, Support wrote:\n> Did you restart it?";
$checks['englischer Trenner wird erkannt'] = ticket_zitat_entfernen($englisch) === 'Still broken.';

$nur_groesser = "Meine Antwort.\n> zitiert\n> noch mehr zitiert";
$checks['Zitatzeilen fallen weg'] = ticket_zitat_entfernen($nur_groesser) === 'Meine Antwort.';

// Was nicht erkannt wird, bleibt stehen - lieber zu viel Text als eine
// abgeschnittene Frage.
$ohne_zitat = "Guten Tag,\n\nder Drucker geht nicht.\n\nViele Grüße";
$checks['ohne Zitat bleibt alles'] = ticket_zitat_entfernen($ohne_zitat) === $ohne_zitat;

// =====================================================================
// Die Felder einer Mail
// =====================================================================
$gut = ticket_mail_pruefen(['from' => 'anna@example.com', 'subject' => 'Drucker', 'text' => 'Geht nicht.']);
$checks['gueltige Mail geht durch'] = $gut['ok'] === true;
$checks['die Adresse steht drin']   = $gut['werte']['from'] === 'anna@example.com';

// "Anna Beispiel <anna@example.com>" - so kommt es aus den meisten
// Diensten.
$mit_name = ticket_mail_pruefen(['from' => 'Anna Beispiel <anna@example.com>', 'text' => 'Hallo']);
$checks['Adresse aus spitzen Klammern'] = $mit_name['werte']['from'] === 'anna@example.com';

// Verschiedene Dienste nennen die Felder verschieden.
$checks['sender statt from']  = ticket_mail_pruefen(['sender' => 'a@example.com', 'text' => 'x'])['ok'] === true;
$checks['From gross']         = ticket_mail_pruefen(['From' => 'a@example.com', 'text' => 'x'])['ok'] === true;
$checks['body statt text']    = ticket_mail_pruefen(['from' => 'a@example.com', 'body' => 'x'])['werte']['text'] === 'x';
$checks['plain statt text']   = ticket_mail_pruefen(['from' => 'a@example.com', 'plain' => 'x'])['werte']['text'] === 'x';

$checks['ohne Absender: abgelehnt']    = ticket_mail_pruefen(['text' => 'x'])['ok'] === false;
$checks['unbrauchbare Adresse: abgelehnt'] = ticket_mail_pruefen(['from' => 'keine', 'text' => 'x'])['ok'] === false;
$checks['ohne Betreff und Text: abgelehnt'] = ticket_mail_pruefen(['from' => 'a@example.com'])['ok'] === false;
// Ein Betreff allein genuegt - eine kurze Mail ist trotzdem eine Anfrage.
$checks['Betreff allein genuegt'] = ticket_mail_pruefen(['from' => 'a@example.com', 'subject' => 'Rueckruf?'])['ok'] === true;
$checks['ohne Betreff ein Ersatz']
    = ticket_mail_pruefen(['from' => 'a@example.com', 'text' => 'x'])['werte']['subject'] === '(ohne Betreff)';

// =====================================================================
// Ticket oder Notiz
// =====================================================================
$pdo->exec("INSERT INTO contacts (name, contact_type, email) VALUES ('Anna', 'Kunde', 'anna@example.com')");
$anna = (int) $pdo->lastInsertId();
$pdo->exec("INSERT INTO contacts (name, contact_type, email) VALUES ('Bruno', 'Kunde', 'bruno@example.com')");
$bruno = (int) $pdo->lastInsertId();

$checks['bekannter Absender wird gefunden'] = ticket_kontakt_finden($pdo, 'anna@example.com') === $anna;
$checks['unbekannter gibt null']            = ticket_kontakt_finden($pdo, 'fremd@example.com') === null;

// --- Eine neue Anfrage --------------------------------------------------
$neu = ticket_aus_mail($pdo, ticket_mail_pruefen([
    'from' => 'anna@example.com', 'subject' => 'Drucker geht nicht', 'text' => 'Seit heute früh.',
])['werte']);

$checks['es entsteht ein Ticket'] = $neu['art'] === 'ticket';
$zeile = $pdo->query("SELECT * FROM support_tickets WHERE id = {$neu['id']}")->fetch(PDO::FETCH_ASSOC);
$checks['der Kunde wird zugeordnet'] = (int) $zeile['contact_id'] === $anna;
$checks['der Betreff wird der Titel'] = $zeile['subject'] === 'Drucker geht nicht';
$checks['der Text steht drin']        = $zeile['message'] === 'Seit heute früh.';
$checks['der Status ist offen']       = $zeile['status'] === 'Offen';

// --- Eine Antwort darauf ------------------------------------------------
$antwort = ticket_aus_mail($pdo, ticket_mail_pruefen([
    'from'    => 'anna@example.com',
    'subject' => 'Re: ' . ticket_betreffkennung($neu['id']) . ' Drucker geht nicht',
    'text'    => 'Immer noch.',
])['werte']);

$checks['es entsteht eine Notiz']     = $antwort['art'] === 'note';
$checks['am richtigen Ticket']        = $antwort['id'] === $neu['id'];
$notiz = $pdo->query("SELECT * FROM ticket_notes WHERE ticket_id = {$neu['id']}")->fetch(PDO::FETCH_ASSOC);
$checks['die Notiz kommt vom Kunden'] = $notiz['author'] === 'client';
$checks['und ist im Portal sichtbar']  = (int) $notiz['is_public'] === 1;
$checks['der Text steht drin']         = $notiz['note'] === 'Immer noch.';
// Es darf kein zweites Ticket entstanden sein.
$checks['kein zweites Ticket'] = (int) $pdo->query('SELECT COUNT(*) FROM support_tickets')->fetchColumn() === 1;

// --- Eine Antwort holt ein erledigtes Ticket zurueck ---------------------
$pdo->exec("UPDATE support_tickets SET status = 'Erledigt' WHERE id = {$neu['id']}");
ticket_aus_mail($pdo, ticket_mail_pruefen([
    'from'    => 'anna@example.com',
    'subject' => ticket_betreffkennung($neu['id']) . ' Noch da',
    'text'    => 'Doch nicht gelöst.',
])['werte']);
$status = $pdo->query("SELECT status FROM support_tickets WHERE id = {$neu['id']}")->fetchColumn();
$checks['eine Antwort oeffnet es wieder'] = $status === 'Offen';

// =====================================================================
// Die Kennung schuetzt nicht von allein
// =====================================================================
// Der wichtigste Fall: jemand schreibt mit fremder Kennung im Betreff.
// Ohne Pruefung landete seine Nachricht im Vorgang eines anderen - und
// waere in dessen Portal sichtbar.
$fremd = ticket_aus_mail($pdo, ticket_mail_pruefen([
    'from'    => 'bruno@example.com',
    'subject' => 'Re: ' . ticket_betreffkennung($neu['id']) . ' Drucker',
    'text'    => 'Ich lese mit.',
])['werte']);

$checks['fremde Kennung wird nicht angenommen'] = $fremd['art'] === 'ticket';
$checks['es entsteht ein eigenes Ticket']       = $fremd['id'] !== $neu['id'];
$fremdzeile = $pdo->query("SELECT contact_id FROM support_tickets WHERE id = {$fremd['id']}")->fetch(PDO::FETCH_ASSOC);
$checks['und gehoert dem richtigen Kunden']     = (int) $fremdzeile['contact_id'] === $bruno;

$checks['ticket_gehoert_zu prueft den Besitzer'] = ticket_gehoert_zu($pdo, $neu['id'], $anna) === true;
$checks['und weist einen Fremden ab']            = ticket_gehoert_zu($pdo, $neu['id'], $bruno) === false;
$checks['ohne Kontakt kein Zugriff']             = ticket_gehoert_zu($pdo, $neu['id'], null) === false;
$checks['unbekanntes Ticket: nein']              = ticket_gehoert_zu($pdo, 99999, $anna) === false;

// =====================================================================
// Ein unbekannter Absender
// =====================================================================
// Die Anfrage wegzuwerfen, weil der Absender noch nicht im Adressbuch
// steht, waere falsch - genau so melden sich neue Kunden.
$unbekannt = ticket_aus_mail($pdo, ticket_mail_pruefen([
    'from' => 'neu@example.com', 'subject' => 'Anfrage', 'text' => 'Guten Tag.', 'name' => 'Neue Kundin',
])['werte']);

$uz = $pdo->query("SELECT * FROM support_tickets WHERE id = {$unbekannt['id']}")->fetch(PDO::FETCH_ASSOC);
$checks['unbekannter Absender: Ticket entsteht'] = $unbekannt['art'] === 'ticket';
$checks['ohne Zuordnung']                        = $uz['contact_id'] === null;
// Dann muss die Adresse im Text stehen - sonst ist nicht mehr zu
// erkennen, wer geschrieben hat.
$checks['die Adresse steht im Text']  = strpos((string) $uz['message'], 'neu@example.com') !== false;
$checks['der Name auch']              = strpos((string) $uz['message'], 'Neue Kundin') !== false;

// Und die Kennung hilft ihm nicht: ohne Kontakt gehoert ihm kein Ticket.
$ohne_kontakt = ticket_aus_mail($pdo, ticket_mail_pruefen([
    'from'    => 'fremd@example.com',
    'subject' => ticket_betreffkennung($neu['id']) . ' Hallo',
    'text'    => 'Ich auch.',
])['werte']);
$checks['ohne Kontakt keine fremde Notiz'] = $ohne_kontakt['art'] === 'ticket';

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
