<?php
/**
 * Der Einstiegspunkt für alles, was ohne Besucher laufen muss.
 *
 * Aufruf auf einem Server mit Shell:
 *
 *     php /pfad/zum/panel/cron.php
 *
 * Auf einem Massenhoster ohne Shell gibt es meist einen "Web-Cron", der
 * eine Adresse aufruft. Dafür der Token:
 *
 *     https://admin.example.com/cron?token=<CRON_TOKEN aus der .env>
 *
 * Stündlich ist eine vernünftige Einstellung. Die Aufgaben sind alle
 * wiederholbar: ein zweiter Lauf in derselben Stunde findet nichts mehr
 * zu tun, und Zahlungserinnerungen haben zusätzlich eine eigene Sperre
 * von 20 Stunden je Rechnung (siehe includes/reminders.php).
 *
 * Warum ein Token und nicht die Anmeldung: ein Cron-Dienst kann sich
 * nicht anmelden. Warum kein offener Endpunkt: die Aufgaben verschicken
 * Mails an Kunden - wer die Adresse kennt, könnte sie sonst beliebig oft
 * auslösen. Die 20-Stunden-Sperre begrenzt den Schaden, aber sie ist das
 * zweite Schloss, nicht das erste.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/cron_tasks.php';

$cli = PHP_SAPI === 'cli';

/** Beendet den Lauf mit einer Meldung und einem Rückgabewert. */
function cron_ende(string $text, int $code, bool $cli, int $status = 200): void
{
    if (!$cli && !headers_sent()) {
        http_response_code($status);
        header('Content-Type: text/plain; charset=utf-8');
        // Ein Cron-Ergebnis gehört in keinen Suchindex und in keinen
        // Zwischenspeicher.
        header('X-Robots-Tag: noindex, nofollow', true);
        header('Cache-Control: no-store');
    }
    echo $text . "\n";
    exit($code);
}

// ── Tür 1: nicht in der Demo ───────────────────────────────────────
// Die Demo läuft auf einem Datenbankbenutzer, der nur lesen darf, und
// zeigt erfundene Kontakte mit erfundenen Adressen. Ein Cron-Lauf würde
// dort im besten Fall scheitern und im schlechtesten Mails verschicken.
if (demo_mode()) {
    cron_ende('Im Demo-Modus abgeschaltet.', 0, $cli, 403);
}

// ── Tür 2: Token, sobald der Aufruf über HTTP kommt ────────────────
// Auf der Kommandozeile entfällt sie: wer dort steht, hat bereits
// Zugriff auf den Server und auf die .env, in der der Token stünde.
if (!$cli) {
    $erwartet = (string) env('CRON_TOKEN', '');

    // Kein Token eingerichtet heißt: dieser Weg ist nicht freigegeben.
    // Bewusst nicht "dann eben ohne Prüfung" - das wäre ein offener
    // Endpunkt auf jeder Installation, die die .env nicht angefasst hat.
    if ($erwartet === '') {
        cron_ende(
            'Kein CRON_TOKEN in der .env gesetzt - der Aufruf über HTTP ist damit'
            . ' nicht freigegeben. Entweder einen Token eintragen oder den Lauf'
            . ' über die Kommandozeile starten.',
            1, $cli, 503
        );
    }
    if (strlen($erwartet) < 16) {
        cron_ende('CRON_TOKEN ist zu kurz (mindestens 16 Zeichen).', 1, $cli, 503);
    }

    $gegeben = (string) ($_GET['token'] ?? '');
    if (!hash_equals($erwartet, $gegeben)) {
        // Keine Auskunft darüber, was falsch war.
        cron_ende('Nicht berechtigt.', 1, $cli, 403);
    }
}

// ── Lauf ───────────────────────────────────────────────────────────
$start = microtime(true);

$ergebnis = cron_ausfuehren($pdo, [
    'jetzt'      => date('Y-m-d H:i:s'),
    'firma'      => setting('company_name', COMPANY_NAME),
    'wurzel'     => __DIR__,
    'mahnstufen' => setting('reminder_days', ''),
]);

$zeilen = ['Cron-Lauf ' . date('d.m.Y H:i:s')];
foreach ($ergebnis['ergebnisse'] as $e) {
    $zeilen[] = ($e['ok'] ? '  [ok]   ' : '  [FEHL] ') . $e['titel'] . ': ' . $e['meldung'];
}
$zeilen[] = sprintf('Fertig in %.2f s, %d Fehler.', microtime(true) - $start, $ergebnis['fehler']);

cron_ende(implode("\n", $zeilen), $ergebnis['fehler'] > 0 ? 1 : 0, $cli);
