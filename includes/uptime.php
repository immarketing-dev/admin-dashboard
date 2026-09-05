<?php
/**
 * Erreichbarkeit der überwachten Adressen.
 *
 * Bis hierher fragte index.php bei jedem Dashboardaufruf alle URLs
 * gleichzeitig ab und warf das Ergebnis danach weg. Kein Verlauf, keine
 * Quote, keine Nachricht bei Ausfall. Ein Kundenserver konnte drei Tage
 * weg sein, und wer in der Zeit nicht hinsah, erfuhr es nicht.
 *
 * Zwei Änderungen, die zusammengehören:
 *
 *  - Jede Messung wird aufgeschrieben (`url_checks`). Daraus entstehen
 *    Quote und Verlauf, und der Vergleich mit der vorigen Messung ist
 *    das, was einen Ausfall überhaupt meldbar macht.
 *  - Gemessen wird im Cron-Lauf. Der Dashboardaufruf liest dann nur
 *    noch — die bis zu sechs Sekunden Wartezeit verschwinden aus dem
 *    Seitenaufbau.
 *
 * Ohne eingerichteten Cron bleibt es beim alten Verhalten: sind die
 * gespeicherten Werte zu alt, misst die Seite selbst. Sonst zeigte eine
 * Installation ohne Cron gar nichts mehr an, und das wäre schlechter
 * als sechs Sekunden Wartezeit.
 */

require_once __DIR__ . '/logging.php';
require_once __DIR__ . '/mailer.php';

/**
 * Wie alt eine Messung höchstens sein darf, damit die Seite sie
 * übernimmt statt selbst zu messen.
 *
 * Etwas mehr als ein stündlicher Cron-Lauf braucht: läuft er
 * pünktlich, misst die Seite nie selbst; bleibt er aus, merkt man es
 * nach spätestens 75 Minuten daran, dass es wieder dauert.
 */
const UPTIME_FRISCH_MINUTEN = 75;

/** Wie viele Messungen die Sparkline zeigt. */
const UPTIME_VERLAUF_PUNKTE = 24;

/**
 * Ab welcher Antwortzeit eine Adresse als langsam gilt, in
 * Millisekunden. Derselbe Wert, den index.php vorher inline trug.
 */
const UPTIME_LANGSAM_MS = 1500;

// ---------------------------------------------------------------------
// Messen
// ---------------------------------------------------------------------

/**
 * Fragt alle Adressen gleichzeitig ab.
 *
 * Stand vorher als getParallelSiteStatuses() in index.php. Hierher
 * umgezogen, weil der Cron-Lauf sie ebenfalls braucht — und weil eine
 * Funktion, die nach außen greift, an einem Ort stehen sollte, an dem
 * man sie sucht.
 *
 * @param array<int|string, array{url_link: string}> $urls
 * @return array<int|string, array{online: bool, code: int, time: int, error: string}>
 */
function uptime_messen(array $urls): array
{
    if (empty($urls)) {
        return [];
    }

    // Der einzige Zugriff nach außen, der ohne POST zustande kommt. Ohne
    // Sperre ließe sich der Server über die Überwachungsliste auf
    // beliebige Adressen ansetzen - auch auf interne. In der Demo wird
    // deshalb nichts abgerufen, sondern ein aus der Adresse abgeleiteter
    // Zustand gezeigt: gleichbleibend über Seitenaufrufe hinweg, und
    // ohne die bis zu sechs Sekunden Wartezeit im ersten Eindruck.
    if (demo_mode()) {
        $demo = [];
        foreach ($urls as $key => $url_row) {
            $h = crc32((string) ($url_row['url_link'] ?? $key));
            $demo[$key] = ['online' => true, 'code' => 200,
                           'time' => 90 + ($h % 380), 'error' => ''];
        }
        return $demo;
    }

    $mh          = curl_multi_init();
    $handles     = [];
    $start_times = [];

    foreach ($urls as $key => $url_row) {
        $ch = curl_init($url_row['url_link']);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_NOBODY         => true,
            CURLOPT_TIMEOUT        => 6,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_USERAGENT      => 'Mozilla/5.0 AdminMonitor/1.0',
        ]);
        $start_times[$key] = microtime(true);
        $handles[$key]     = $ch;
        curl_multi_add_handle($mh, $ch);
    }

    $running = null;
    do {
        curl_multi_exec($mh, $running);
        curl_multi_select($mh, 0.1);
    } while ($running > 0);

    $results = [];
    foreach ($handles as $key => $ch) {
        $code      = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err       = curl_error($ch);
        $time_ms   = round((microtime(true) - $start_times[$key]) * 1000);
        $is_online = ($code >= 200 && $code < 400);

        $results[$key] = [
            'online' => $is_online,
            'code'   => (int) $code,
            'time'   => (int) $time_ms,
            'error'  => $err,
        ];
        curl_multi_remove_handle($mh, $ch);
        curl_close($ch);
    }
    curl_multi_close($mh);

    return $results;
}

/**
 * Der Zustand einer Messung als Wort.
 *
 * Drei Stufen, weil zwei zu wenig sind: eine Seite, die antwortet, aber
 * vier Sekunden braucht, ist nicht "online" im Sinne von "in Ordnung".
 */
function uptime_zustand(array $messung): string
{
    if (!($messung['online'] ?? false)) {
        return 'offline';
    }
    return ((int) ($messung['time'] ?? 0)) > UPTIME_LANGSAM_MS ? 'slow' : 'online';
}

// ---------------------------------------------------------------------
// Aufschreiben und auswerten
// ---------------------------------------------------------------------

/**
 * Schreibt eine Messung in den Verlauf.
 *
 * Wie beim Mailprotokoll: schlägt das Schreiben fehl, darf es die
 * Messung nicht mitreißen.
 */
function uptime_aufzeichnen(PDO $pdo, int $url_id, array $messung): void
{
    try {
        $pdo->prepare(
            'INSERT INTO url_checks (url_id, status, http_code, response_ms, error)
             VALUES (?, ?, ?, ?, ?)'
        )->execute([
            $url_id,
            uptime_zustand($messung),
            (int) ($messung['code'] ?? 0),
            (int) ($messung['time'] ?? 0),
            ($messung['error'] ?? '') !== '' ? mb_substr((string) $messung['error'], 0, 255) : null,
        ]);
    } catch (Throwable $e) {
        error_log('Uptime-Aufzeichnung fehlgeschlagen (URL ' . $url_id . '): ' . $e->getMessage());
    }
}

/**
 * Die letzte aufgezeichnete Messung je Adresse.
 *
 * Über eine Unterabfrage auf die höchste Kennung und nicht über
 * MAX(checked_at): zwei Messungen innerhalb derselben Sekunde sind bei
 * einem Cron-Lauf über mehrere Adressen der Normalfall, und dann wäre
 * nicht entschieden, welche die letzte ist.
 *
 * @return array<int, array<string, mixed>> url_id => Messung
 */
function uptime_letzte(PDO $pdo): array
{
    $zeilen = $pdo->query(
        'SELECT uc.* FROM url_checks uc
          WHERE uc.id IN (SELECT MAX(id) FROM url_checks GROUP BY url_id)'
    )->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $aus = [];
    foreach ($zeilen as $z) {
        $aus[(int) $z['url_id']] = $z;
    }
    return $aus;
}

/**
 * Sind die gespeicherten Messungen frisch genug?
 *
 * Entscheidet, ob die Seite die Werte übernimmt oder selbst misst.
 * Fehlt für eine Adresse jede Messung, ist die Antwort nein - sonst
 * bliebe eine neu eingetragene URL bis zum nächsten Cron-Lauf ohne
 * Zustand.
 */
function uptime_frisch(array $letzte, array $urls, string $jetzt): bool
{
    if (!$urls) {
        return true;
    }
    $grenze = strtotime($jetzt) - UPTIME_FRISCH_MINUTEN * 60;

    foreach ($urls as $url) {
        $id = (int) ($url['id'] ?? 0);
        if (!isset($letzte[$id])) {
            return false;
        }
        $zeit = strtotime((string) $letzte[$id]['checked_at']);
        if ($zeit === false || $zeit < $grenze) {
            return false;
        }
    }
    return true;
}

/**
 * Verfügbarkeit und Verlauf je Adresse.
 *
 * Die Quote zählt "nicht offline" als verfügbar: eine langsame Antwort
 * ist eine Antwort. Wer die Langsamkeit sehen will, sieht sie in der
 * Sparkline und in der Antwortzeit daneben.
 *
 * @return array<int, array{quote: ?float, punkte: array<int, string>, messungen: int}>
 */
function uptime_verlauf(PDO $pdo, int $stunden = 24): array
{
    $stmt = $pdo->prepare(
        'SELECT url_id, status, response_ms, checked_at
           FROM url_checks
          WHERE checked_at > DATE_SUB(NOW(), INTERVAL ' . (int) $stunden . ' HOUR)
          ORDER BY url_id ASC, id ASC'
    );
    $stmt->execute();

    $roh = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $z) {
        $roh[(int) $z['url_id']][] = $z;
    }

    $aus = [];
    foreach ($roh as $url_id => $zeilen) {
        $gesamt = count($zeilen);
        $oben   = 0;
        foreach ($zeilen as $z) {
            if ($z['status'] !== 'offline') {
                $oben++;
            }
        }

        // Nur die letzten Punkte für die Sparkline - bei stündlicher
        // Messung ist das genau ein Tag.
        $punkte = array_column(array_slice($zeilen, -UPTIME_VERLAUF_PUNKTE), 'status');

        $aus[$url_id] = [
            'quote'     => $gesamt > 0 ? round($oben / $gesamt * 100, 1) : null,
            'punkte'    => $punkte,
            'messungen' => $gesamt,
        ];
    }
    return $aus;
}

/**
 * Räumt alte Messungen weg.
 *
 * Bei stündlicher Messung und zehn Adressen sind das rund 88.000 Zeilen
 * im Jahr - für eine Tabelle, aus der nur die letzten 24 Stunden
 * angezeigt werden. 30 Tage sind reichlich für die Frage "war da letzte
 * Woche etwas".
 */
function uptime_aufraeumen(PDO $pdo, int $tage = 30): int
{
    try {
        $stmt = $pdo->prepare(
            'DELETE FROM url_checks WHERE checked_at < DATE_SUB(NOW(), INTERVAL ' . (int) $tage . ' DAY)'
        );
        $stmt->execute();
        return $stmt->rowCount();
    } catch (PDOException $e) {
        error_log('Uptime-Verlauf aufraeumen fehlgeschlagen: ' . $e->getMessage());
        return 0;
    }
}

// ---------------------------------------------------------------------
// Melden
// ---------------------------------------------------------------------

/**
 * Ist das ein Zustandswechsel, der eine Nachricht wert ist?
 *
 * Gemeldet wird nur der Wechsel zwischen erreichbar und nicht
 * erreichbar. "Langsam" bleibt stumm: eine Seite, die einmal vier
 * Sekunden braucht, ist kein Vorfall, und eine Meldung, die zu oft
 * kommt, wird nicht mehr gelesen.
 *
 * Ohne vorherige Messung wird nicht gemeldet - sonst schickt die erste
 * Messung nach dem Einrichten eine Nachricht für jede Adresse, die
 * gerade nicht antwortet, ohne dass sich etwas geändert hätte.
 */
function uptime_meldenswert(?string $vorher, string $jetzt): bool
{
    if ($vorher === null) {
        return false;
    }
    return ($vorher === 'offline') !== ($jetzt === 'offline');
}

/**
 * Baut die Nachricht zu einem Zustandswechsel.
 *
 * @return array{subject: string, body: string}
 */
function uptime_meldung(array $url, string $zustand, array $messung, string $firma): array
{
    $name = (string) ($url['url_name'] ?? '');
    $link = (string) ($url['url_link'] ?? '');

    if ($zustand === 'offline') {
        $grund = ((int) ($messung['code'] ?? 0)) > 0
            ? 'HTTP ' . (int) $messung['code']
            : (($messung['error'] ?? '') !== '' ? (string) $messung['error'] : 'keine Antwort');

        return [
            'subject' => '[' . $firma . '] ' . $name . ' ist nicht erreichbar',
            'body'    => $name . ' antwortet nicht.' . "\n\n"
                       . 'Adresse: ' . $link . "\n"
                       . 'Grund:   ' . $grund . "\n"
                       . 'Zeit:    ' . date('d.m.Y H:i') . "\n\n"
                       . 'Diese Nachricht kommt vom Monitor Ihres Panels. '
                       . 'Sie wird nur beim Wechsel des Zustands verschickt, nicht bei jeder Messung.',
        ];
    }

    return [
        'subject' => '[' . $firma . '] ' . $name . ' ist wieder erreichbar',
        'body'    => $name . ' antwortet wieder.' . "\n\n"
                   . 'Adresse:     ' . $link . "\n"
                   . 'Antwortzeit: ' . (int) ($messung['time'] ?? 0) . ' ms' . "\n"
                   . 'Zeit:        ' . date('d.m.Y H:i') . "\n",
    ];
}

/**
 * Misst alle Adressen, schreibt den Verlauf fort und meldet Wechsel.
 *
 * @return array{gemessen: int, offline: int, meldungen: int}
 */
function uptime_durchlauf(PDO $pdo, string $empfaenger, string $firma): array
{
    $urls = $pdo->query('SELECT * FROM monitored_urls ORDER BY id ASC')->fetchAll(PDO::FETCH_ASSOC) ?: [];
    if (!$urls) {
        return ['gemessen' => 0, 'offline' => 0, 'meldungen' => 0];
    }

    // Der Zustand vor dieser Messung - Grundlage für den Vergleich.
    $vorher = [];
    foreach (uptime_letzte($pdo) as $url_id => $z) {
        $vorher[$url_id] = (string) $z['status'];
    }

    $messungen = uptime_messen($urls);

    $offline   = 0;
    $meldungen = 0;

    foreach ($urls as $key => $url) {
        $id      = (int) $url['id'];
        $messung = $messungen[$key] ?? ['online' => false, 'code' => 0, 'time' => 0, 'error' => 'keine Messung'];
        $zustand = uptime_zustand($messung);

        uptime_aufzeichnen($pdo, $id, $messung);

        if ($zustand === 'offline') {
            $offline++;
        }

        if (!uptime_meldenswert($vorher[$id] ?? null, $zustand)) {
            continue;
        }

        $text = uptime_meldung($url, $zustand, $messung, $firma);
        $ergebnis = mail_versenden([
            'to'       => $empfaenger,
            'subject'  => $text['subject'],
            'body'     => $text['body'],
            'pdo'      => $pdo,
            'template' => 'uptime_alert',
            'context'  => (string) $url['url_name'],
        ]);

        if ($ergebnis['ok']) {
            $meldungen++;
        }
        log_event(
            $pdo,
            $zustand === 'offline' ? 'MONITOR_DOWN' : 'MONITOR_UP',
            $url['url_name'] . ' ist ' . ($zustand === 'offline' ? 'nicht mehr' : 'wieder') . ' erreichbar.'
        );
    }

    return ['gemessen' => count($urls), 'offline' => $offline, 'meldungen' => $meldungen];
}
