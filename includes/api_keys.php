<?php
/**
 * Schlüssel für die Schnittstellen.
 *
 * Zuerst gab es nur die Anfrage-Schnittstelle, und ihre Schlüssellogik
 * stand in includes/api_leads.php. Mit dem zweiten Endpunkt — den
 * eingehenden Mails — wäre daraus eine wortgleiche zweite Fassung
 * geworden: dieselbe Prüfung, derselbe Header, dieselbe Bremse, an zwei
 * Stellen zu pflegen. Deshalb hier einmal.
 *
 * Je Zweck ein eigener Schlüssel. Sie gehen an verschiedene Dienste —
 * das Kontaktformular der Website, der Maildienst —, und wer einen davon
 * wechselt, soll nicht den anderen mitentziehen müssen.
 */

require_once __DIR__ . '/logging.php';

/**
 * Der eingestellte Schlüssel eines Zwecks, oder ''.
 *
 * Kein Schlüssel eingerichtet heißt: der Endpunkt ist zu — nicht offen.
 * Dasselbe Prinzip wie beim CRON_TOKEN: eine Installation, die nichts
 * eingerichtet hat, bekommt keinen unbewachten Schreibzugang.
 */
function api_schluessel(string $zweck): string
{
    return trim(setting('api_key_' . $zweck, ''));
}

/** Erzeugt einen neuen Schlüssel. */
function api_schluessel_erzeugen(): string
{
    return bin2hex(random_bytes(24));
}

/**
 * Liest den Schlüssel aus der Anfrage.
 *
 * Zwei Schreibweisen, weil beide verbreitet sind und die Wahl den
 * Absender nichts angeht.
 */
function api_schluessel_aus_anfrage(array $server): string
{
    $key = trim((string) ($server['HTTP_X_API_KEY'] ?? ''));
    if ($key !== '') {
        return $key;
    }

    $auth = (string) ($server['HTTP_AUTHORIZATION'] ?? '');
    if (stripos($auth, 'Bearer ') === 0) {
        return trim(substr($auth, 7));
    }
    return '';
}

/**
 * Hat diese IP zu oft angefragt?
 *
 * Zählt über das Protokoll, wie die Anmeldesperre und die
 * Rücksetz-Bremse — dieselbe Tabelle, dieselbe ip-Spalte, kein dritter
 * Mechanismus.
 */
function api_zu_haeufig(PDO $pdo, string $ip, string $aktion, int $grenze): bool
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM logs
          WHERE action_type = ?
            AND ip = ?
            AND created_at > (NOW() - INTERVAL 60 MINUTE)'
    );
    $stmt->execute([$aktion, $ip]);

    return (int) $stmt->fetchColumn() >= $grenze;
}

/**
 * Antwortet mit JSON und beendet die Anfrage.
 *
 * Stand in api/leads.php; die zweite Schnittstelle braucht dieselbe
 * Antwortform, damit ein Aufrufer nicht zwei Formate kennen muss.
 */
function api_antwort(int $status, array $daten): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('X-Robots-Tag: noindex, nofollow', true);
    header('Cache-Control: no-store');
    echo json_encode($daten, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/**
 * Liest den Rumpf einer Anfrage.
 *
 * JSON und Formularfelder, weil beides vorkommt: ein Skript schickt
 * JSON, ein Formular- oder Maildienst oft
 * `application/x-www-form-urlencoded`. Wer den Unterschied nicht kennen
 * muss, soll ihn nicht kennen müssen.
 */
function api_rumpf(string $roh, string $content_type, array $post): array
{
    if (stripos($content_type, 'application/json') !== false) {
        $daten = json_decode($roh, true);
        return is_array($daten) ? $daten : [];
    }
    return $post;
}

/**
 * Die vier Türen, die jede Schnittstelle durchläuft.
 *
 * Demo-Modus, Methode, Schlüssel, Bremse — in dieser Reihenfolge, und
 * jede beendet die Anfrage selbst. Als Funktion, damit ein dritter
 * Endpunkt sie nicht abschreibt und dabei eine vergisst.
 *
 * Protokolliert die Anfrage vor der inhaltlichen Prüfung: sonst zählt
 * die Bremse nur die gelungenen, und wer den Endpunkt flutet, bleibt
 * ungezählt.
 */
function api_tuer(PDO $pdo, string $zweck, string $aktion, int $grenze): void
{
    if (demo_mode()) {
        api_antwort(403, ['ok' => false, 'error' => 'Im Demo-Modus abgeschaltet.']);
    }

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        header('Allow: POST');
        api_antwort(405, ['ok' => false, 'error' => 'Nur POST.']);
    }

    $erwartet = api_schluessel($zweck);
    if ($erwartet === '') {
        api_antwort(503, [
            'ok'    => false,
            'error' => 'Kein API-Schlüssel eingerichtet. Einstellungen → System.',
        ]);
    }
    if (!hash_equals($erwartet, api_schluessel_aus_anfrage($_SERVER))) {
        // Keine Auskunft darüber, was falsch war.
        api_antwort(401, ['ok' => false, 'error' => 'Nicht berechtigt.']);
    }

    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    if (api_zu_haeufig($pdo, $ip, $aktion, $grenze)) {
        header('Retry-After: 3600');
        api_antwort(429, ['ok' => false, 'error' => 'Zu viele Anfragen.']);
    }

    log_event($pdo, $aktion, 'Anfrage über die Schnittstelle empfangen.');
}
