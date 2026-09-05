<?php
/**
 * Was der Cron-Lauf tut.
 *
 * Bis hierher passierte im Panel nichts, solange niemand eine Seite
 * öffnete. finances.php stempelte überfällige Rechnungen beim Rendern
 * der Liste, includes/auth.php kürzte das Protokoll beim Anmelden,
 * index.php fragte bei jedem Aufruf synchron alle überwachten URLs ab.
 * Wer eine Woche nicht hineinsah, hatte eine Woche lang keinen einzigen
 * dieser Vorgänge.
 *
 * Die Aufgaben stehen hier und nicht in cron.php, aus zwei Gründen:
 * sie sind so ohne HTTP prüfbar, und cron.php bleibt das, was es sein
 * soll - eine Tür mit einem Schloss davor.
 *
 * Jede Aufgabe ist für sich abgesichert: wirft eine, laufen die
 * übrigen weiter. Ein Cron-Lauf, der beim ersten Fehler abbricht,
 * verschweigt still alles, was danach gekommen wäre.
 */

require_once __DIR__ . '/logging.php';
require_once __DIR__ . '/reminders.php';
require_once __DIR__ . '/recurring.php';
require_once __DIR__ . '/auth_reset.php';
require_once __DIR__ . '/mail_log.php';
require_once __DIR__ . '/uptime.php';

/**
 * Markiert offene Rechnungen nach Fristablauf als überfällig.
 *
 * Dieselbe Anweisung steht weiterhin in finances.php. Das ist Absicht
 * und keine Dublette aus Versehen: nicht jede Installation richtet einen
 * Cron ein, und dort soll der Status trotzdem stimmen. Die Anweisung ist
 * wiederholbar - sie trifft beim zweiten Lauf keine Zeile mehr.
 *
 * @return array{titel: string, ok: bool, meldung: string}
 */
function cron_ueberfaellig_markieren(PDO $pdo): array
{
    $stmt = $pdo->prepare(
        "UPDATE finances SET status = 'Überfällig'
          WHERE deleted_at IS NULL AND type = 'INCOME'
            AND status = 'Offen' AND due_date < CURDATE()"
    );
    $stmt->execute();
    $anzahl = $stmt->rowCount();

    if ($anzahl > 0) {
        log_event($pdo, 'INVOICE_OVERDUE', $anzahl . ' Rechnung(en) als überfällig markiert.');
    }

    return [
        'titel'   => 'Überfällige Rechnungen',
        'ok'      => true,
        'meldung' => $anzahl > 0 ? $anzahl . ' neu als überfällig markiert.' : 'Nichts zu tun.',
    ];
}

/**
 * Verschickt fällige Zahlungserinnerungen.
 *
 * Ohne konfigurierte Stufen passiert nichts. Das ist der
 * Auslieferungszustand: eine Installation, die dieses Update einspielt,
 * fängt nicht von selbst an, Mails an ihre Kunden zu schicken.
 *
 * @return array{titel: string, ok: bool, meldung: string}
 */
function cron_mahnungen(PDO $pdo, string $stufen_roh, string $firma, string $wurzel, string $jetzt): array
{
    $stufen = mahnstufen($stufen_roh);

    if (!$stufen) {
        return [
            'titel'   => 'Zahlungserinnerungen',
            'ok'      => true,
            'meldung' => 'Ausgeschaltet (keine Mahnstufen eingestellt).',
        ];
    }

    $faellig = faellige_mahnungen(offene_rechnungen($pdo), $stufen, $jetzt);

    if (!$faellig) {
        return [
            'titel'   => 'Zahlungserinnerungen',
            'ok'      => true,
            'meldung' => 'Nichts fällig (Stufen: ' . implode(', ', $stufen) . ' Tage).',
        ];
    }

    $gesendet = 0;
    $fehler   = [];

    foreach ($faellig as $rechnung) {
        $ergebnis = mahnung_senden($pdo, $rechnung, $firma, $wurzel);
        if ($ergebnis['ok']) {
            $gesendet++;
        } else {
            $fehler[] = ($rechnung['invoice_number'] ?: '#' . $rechnung['id'])
                      . ': ' . $ergebnis['error'];
        }
    }

    $meldung = $gesendet . ' von ' . count($faellig) . ' verschickt.';
    if ($fehler) {
        $meldung .= ' Fehlgeschlagen: ' . implode('; ', $fehler);
    }

    return [
        'titel'   => 'Zahlungserinnerungen',
        'ok'      => $fehler === [],
        'meldung' => $meldung,
    ];
}

/**
 * Legt fällige wiederkehrende Einträge an.
 *
 * @return array{titel: string, ok: bool, meldung: string}
 */
function cron_wiederholungen(PDO $pdo, string $heute): array
{
    $ergebnis = wiederholungen_ausfuehren($pdo, $heute);

    $meldung = $ergebnis['erzeugt'] > 0
        ? $ergebnis['erzeugt'] . ' Eintrag/Einträge aus ' . $ergebnis['vorlagen'] . ' Vorlage(n) erzeugt.'
        : 'Nichts fällig.';

    foreach ($ergebnis['meldungen'] as $m) {
        $meldung .= "\n    " . $m;
    }

    return ['titel' => 'Wiederkehrende Einträge', 'ok' => true, 'meldung' => $meldung];
}

/**
 * Kürzt das Protokoll.
 *
 * Hängt bisher an includes/auth.php und lief damit erst bei der
 * nächsten Anmeldung. logs_aufraeumen() hat seinen eigenen Tagesriegel,
 * ein zusätzlicher Aufruf kostet also nichts.
 *
 * @return array{titel: string, ok: bool, meldung: string}
 */
function cron_protokoll_kuerzen(PDO $pdo): array
{
    $weg = logs_aufraeumen($pdo);

    // Das Mailprotokoll hat eine eigene, längere Untergrenze: ein
    // Versandnachweis wird Monate später gebraucht, nicht Tage.
    $mails = mail_protokoll_kuerzen($pdo);

    $meldung = $weg > 0 ? $weg . ' alte Einträge entfernt.' : 'Heute bereits geräumt oder nichts zu tun.';
    if ($mails > 0) {
        $meldung .= ' ' . $mails . ' Mailprotokoll-Einträge entfernt.';
    }

    return ['titel' => 'Protokoll', 'ok' => true, 'meldung' => $meldung];
}

/**
 * Räumt verbrauchte und abgelaufene Rücksetz-Token weg.
 *
 * Kein dringender Vorgang - ein abgelaufenes Token ist bereits wirkungslos,
 * die Prüfung in reset_token_einloesen() sieht auf expires_at. Es geht
 * darum, dass die Tabelle nicht unbegrenzt wächst.
 *
 * @return array{titel: string, ok: bool, meldung: string}
 */
function cron_reset_token(PDO $pdo): array
{
    $weg = reset_token_aufraeumen($pdo);

    return [
        'titel'   => 'Rücksetz-Token',
        'ok'      => true,
        'meldung' => $weg > 0 ? $weg . ' abgelaufene(s) Token entfernt.' : 'Nichts zu tun.',
    ];
}

/**
 * Misst die überwachten Adressen und meldet Zustandswechsel.
 *
 * Der Grund, warum das hierher gehört und nicht auf die Startseite: dort
 * lief es nur, während jemand hinsah. Ein Ausfall am Wochenende fiel
 * damit erst am Montag auf - und auch dann nur, wenn er noch andauerte.
 *
 * @return array{titel: string, ok: bool, meldung: string}
 */
function cron_uptime(PDO $pdo, string $empfaenger, string $firma): array
{
    $urls = (int) $pdo->query('SELECT COUNT(*) FROM monitored_urls')->fetchColumn();
    if ($urls === 0) {
        return ['titel' => 'Erreichbarkeit', 'ok' => true, 'meldung' => 'Keine Adressen überwacht.'];
    }

    $ergebnis = uptime_durchlauf($pdo, $empfaenger, $firma);
    $weg      = uptime_aufraeumen($pdo);

    $meldung = $ergebnis['gemessen'] . ' Adresse(n) geprüft, '
             . $ergebnis['offline'] . ' nicht erreichbar.';
    if ($ergebnis['meldungen'] > 0) {
        $meldung .= ' ' . $ergebnis['meldungen'] . ' Meldung(en) verschickt.';
    }
    if ($weg > 0) {
        $meldung .= ' ' . $weg . ' alte Messung(en) entfernt.';
    }

    return ['titel' => 'Erreichbarkeit', 'ok' => true, 'meldung' => $meldung];
}

/**
 * Führt alle Aufgaben aus und sammelt die Ergebnisse.
 *
 * Jede Aufgabe einzeln abgesichert: fällt eine aus - eine fehlende
 * Spalte, ein SMTP-Server, der nicht antwortet -, laufen die übrigen
 * trotzdem.
 *
 * @return array{ergebnisse: array<int, array{titel: string, ok: bool, meldung: string}>, fehler: int}
 */
function cron_ausfuehren(PDO $pdo, array $umgebung): array
{
    $jetzt  = $umgebung['jetzt']  ?? date('Y-m-d H:i:s');
    $heute  = substr($jetzt, 0, 10);
    $firma  = (string) ($umgebung['firma'] ?? '');
    $wurzel = (string) ($umgebung['wurzel'] ?? dirname(__DIR__));
    $stufen = (string) ($umgebung['mahnstufen'] ?? '');

    $aufgaben = [
        fn() => cron_ueberfaellig_markieren($pdo),
        fn() => cron_wiederholungen($pdo, $heute),
        fn() => cron_mahnungen($pdo, $stufen, $firma, $wurzel, $jetzt),
        fn() => cron_protokoll_kuerzen($pdo),
        fn() => cron_reset_token($pdo),
        fn() => cron_uptime($pdo, (string) ($umgebung['admin_email'] ?? ''), $firma),
    ];

    $ergebnisse = [];
    $fehler     = 0;

    foreach ($aufgaben as $aufgabe) {
        try {
            $ergebnis = $aufgabe();
        } catch (Throwable $e) {
            $ergebnis = [
                'titel'   => 'Aufgabe abgebrochen',
                'ok'      => false,
                'meldung' => $e->getMessage(),
            ];
            error_log('Cron-Aufgabe fehlgeschlagen: ' . $e->getMessage());
        }
        if (!$ergebnis['ok']) {
            $fehler++;
        }
        $ergebnisse[] = $ergebnis;
    }

    return ['ergebnisse' => $ergebnisse, 'fehler' => $fehler];
}
