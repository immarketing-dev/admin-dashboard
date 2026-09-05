<?php
// Test für die E-Mail-Vorlagen. Aufruf: php tools/test_mail_templates.php

define('COLOR_PRIMARY', '#149ddd');
define('COLOR_SIDEBAR', '#040b14');
define('COMPANY_NAME',  'Beispiel IT');
define('COMPANY_SHORT', 'Beispiel');
define('MAIN_WEBSITE',  'https://example.com');

// setting() liest sonst aus der Datenbank. Hier liefert es den Standard,
// damit die Vorlagen ohne Datenbank prüfbar bleiben.
$GLOBALS['test_settings'] = [];
function setting(string $key, string $default = ''): string {
    return $GLOBALS['test_settings'][$key] ?? $default;
}

require_once __DIR__ . '/../includes/mail_templates.php';

$vars = mail_preview_vars();
$m    = mail_render('milestone', $vars, 'https://example.com/portal?t=abc');
$q    = mail_render('quote_send', $vars);

$checks = [
    'Platzhalter im Betreff ersetzt'
        => strpos($m['subject'], '{{') === false
        && strpos($m['subject'], 'Entwurf Startseite') !== false,

    'Platzhalter im Text ersetzt'
        => strpos($m['text'], '{{') === false
        && strpos($m['text'], 'Max Mustermann') !== false,

    // Der Fehler, der im Kundenportal schon einmal auftrat: E-Mail-Clients
    // lösen CSS-Variablen nicht auf, aus var(--x) wird gar keine Farbe.
    'keine CSS-Variablen im Mail-HTML'
        => strpos($m['html'], 'var(') === false,

    'HTML trägt den Rahmen'
        => strpos($m['html'], '<table') !== false
        && strpos($m['html'], '</html>') !== false,

    'Schaltfläche wird gesetzt, wenn eine URL vorliegt'
        => strpos($m['html'], 'https://example.com/portal?t=abc') !== false
        && strpos($m['html'], 'Zum Projektportal') !== false,

    'ohne URL keine Schaltfläche'
        => strpos(mail_render('milestone', $vars)['html'], 'Zum Projektportal') === false,

    // Werte kommen aus der Datenbank und aus Formularen.
    'Werte werden im HTML maskiert'
        => strpos(mail_render('milestone', ['kunde' => '<script>x</script>'] + $vars)['html'], '<script>') === false,

    'Werte werden im Text nicht maskiert'
        => strpos(mail_render('milestone', ['kunde' => 'Müller & Co'] + $vars)['text'], 'Müller & Co') !== false,

    // Eine unbelegte Zeile soll verschwinden, nicht leer stehen bleiben.
    'leerer Platzhalter räumt seine Zeile ab'
        => strpos(mail_render('event_invite', ['ort' => ''] + $vars)['text'], "\n\n\n") === false,

    'gesetzte Leerzeile bleibt erhalten'
        => substr_count(mail_render('milestone', $vars)['text'], "\n\n") >= 2,

    'Plaintext-Vorlage liefert kein HTML'
        => $q['html'] === '' && $q['text'] !== '',

    'unbekannter Schlüssel liefert leere Felder'
        => mail_template_subject('gibtsnicht') === '' && mail_template_body('gibtsnicht') === '',

    'jede Vorlage hat Bezeichnung, Betreff, Text und Platzhalterliste'
        => (function () {
            foreach (mail_templates() as $k => $t) {
                foreach (['label', 'hint', 'vars', 'subject', 'body'] as $feld) {
                    if (!isset($t[$feld])) { echo "  (fehlt: $k.$feld)\n"; return false; }
                }
                if (!is_array($t['vars']) || $t['vars'] === []) return false;
            }
            return true;
        })(),

    'jeder Platzhalter im Text ist in vars aufgeführt'
        => (function () {
            foreach (mail_templates() as $k => $t) {
                preg_match_all('/\{\{\s*([a-z_]+)\s*\}\}/', $t['subject'] . ' ' . $t['body'], $m);
                foreach (array_unique($m[1]) as $ph) {
                    if (!in_array($ph, $t['vars'], true)) { echo "  ($k: {{$ph}} fehlt in vars)\n"; return false; }
                }
            }
            return true;
        })(),

    'jeder Platzhalter hat einen Beispielwert für die Vorschau'
        => (function () {
            $bsp = mail_preview_vars();
            foreach (mail_templates() as $k => $t) {
                foreach ($t['vars'] as $ph) {
                    if (!array_key_exists($ph, $bsp)) { echo "  ($k: $ph fehlt in mail_preview_vars)\n"; return false; }
                }
            }
            return true;
        })(),
];

// Eine gespeicherte Fassung muss den Standard verdrängen.
$GLOBALS['test_settings']['mailtpl_milestone_subject'] = 'Eigener Betreff: {{meilenstein}}';
$checks['gespeicherte Fassung schlägt den Standard']
    = mail_render('milestone', $vars)['subject'] === 'Eigener Betreff: Entwurf Startseite';

// =====================================================================
// Sprache des Empfaengers
// =====================================================================
// Bis hierher ging jede Mail auf Deutsch hinaus - auch an einen
// Empfaenger, der sein Portal auf Englisch liest. Beim Versand gibt es
// keine Sitzung, aus der sich seine Sprache ergaebe; sie steht deshalb
// am Kontakt.

// --- Der Schluessel der gespeicherten Fassung ---
// Deutsch behaelt den Schluessel ohne Kuerzel. Genau das erspart eine
// Migration: was ein Betreiber bisher angepasst hat, gilt weiter.
$checks['deutscher Schluessel ohne Kuerzel']
    = mail_template_key('milestone', 'subject', 'de') === 'mailtpl_milestone_subject';
$checks['englischer Schluessel mit Kuerzel']
    = mail_template_key('milestone', 'subject', 'en') === 'mailtpl_milestone_en_subject';

// --- mail_sprache() nimmt nur, was es gibt ---
$checks['bekannte Sprache wird genommen'] = mail_sprache('en') === 'en';
$checks['leere Angabe ergibt null']       = mail_sprache('') === null;
$checks['null bleibt null']               = mail_sprache(null) === null;
$checks['Unbekanntes ergibt null']        = mail_sprache('kl') === null;

// --- mail_in_sprache() stellt die Sprache wieder her ---
sprache_setzen('de');
$innen = mail_in_sprache('en', fn() => lang());
$checks['innen gilt die Empfaengersprache'] = $innen === 'en';
$checks['danach wieder die alte']           = lang() === 'de';

// Auch wenn es darin knallt: eine Ausnahme darf die Seite nicht in der
// Sprache des Empfaengers weiterlaufen lassen.
try {
    mail_in_sprache('en', function () { throw new RuntimeException('Absicht'); });
} catch (RuntimeException $e) {
    // erwartet
}
$checks['nach einer Ausnahme wiederhergestellt'] = lang() === 'de';

// --- Die Mail selbst ---
$de = mail_render('portal_access', $vars, 'https://example.com/portal');
$en = mail_in_sprache('en', fn() => mail_render('portal_access', $vars, 'https://example.com/portal'));

$checks['deutscher Betreff bleibt deutsch']
    = strpos($de['subject'], 'Ihr Zugang zum Projekt-Portal') !== false;
$checks['englischer Betreff ist englisch']
    = strpos($en['subject'], 'Your access to the project portal') !== false;
$checks['englischer Text ist englisch']
    = strpos($en['text'], 'Hello ') !== false && strpos($en['text'], 'Hallo ') === false;

// Der Rahmen traegt die Sprache, nicht ein festes "de".
$checks['Rahmen deutsch']  = strpos($de['html'], '<html lang="de">') !== false;
$checks['Rahmen englisch'] = strpos($en['html'], '<html lang="en">') !== false;

// Und nach alldem steht die Sprache wieder auf Deutsch.
$checks['Sprache am Ende unveraendert'] = lang() === 'de';

// --- Eine angepasste Fassung gilt nur fuer ihre Sprache ---
// Wer den deutschen Text aendert, soll damit nicht den englischen
// ueberschreiben - und umgekehrt.
$GLOBALS['test_settings']['mailtpl_portal_access_subject']    = 'Deutsch angepasst';
$GLOBALS['test_settings']['mailtpl_portal_access_en_subject'] = 'English customised';

$checks['angepasst: deutsch'] = mail_render('portal_access', $vars)['subject'] === 'Deutsch angepasst';
$checks['angepasst: englisch']
    = mail_in_sprache('en', fn() => mail_render('portal_access', $vars))['subject'] === 'English customised';

// Ohne englische Anpassung gilt der uebersetzte Standard, nicht der
// angepasste deutsche Text.
unset($GLOBALS['test_settings']['mailtpl_portal_access_en_subject']);
$checks['ohne englische Fassung der Standard']
    = mail_in_sprache('en', fn() => mail_render('portal_access', $vars))['subject']
      === 'Your access to the project portal | Beispiel';

$fail = 0;
foreach ($checks as $name => $ok) {
    echo ($ok ? 'PASS' : 'FAIL') . "  $name\n";
    if (!$ok) $fail = 1;
}
exit($fail);
