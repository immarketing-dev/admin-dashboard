<?php
/**
 * Ein Ort für alle Protokolleinträge.
 *
 * Vorher stand an achtzig Stellen dasselbe INSERT, und nur drei davon
 * füllten die Spalte `ip` — die Logansicht zeigte also eine Spalte, die
 * fast immer leer war. Über diesen Helfer trägt jeder Eintrag dieselben
 * Felder, und ein späterer Zusatz (etwa der angemeldete Benutzer) ist eine
 * Änderung an einer Stelle statt an achtzig.
 */

/**
 * Ermittelt die IP des Aufrufers.
 *
 * Bewusst nur REMOTE_ADDR: X-Forwarded-For ist frei setzbar, solange nicht
 * feststeht, dass ein vertrauenswürdiger Proxy davorsteht. Eine gefälschte
 * Adresse im Protokoll wäre schlimmer als gar keine — der Sperrzähler in
 * auth_login.php hängt an diesem Wert.
 */
function log_client_ip(): ?string
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    if ($ip === '' || !filter_var($ip, FILTER_VALIDATE_IP)) return null;
    return substr($ip, 0, 45);
}

/**
 * Schreibt einen Protokolleintrag.
 *
 * Schlägt das Schreiben fehl, darf es den auslösenden Vorgang nicht
 * mitreißen: ein nicht protokollierter Vorgang ist ärgerlich, ein
 * abgebrochener Speichervorgang ist schlimmer. Der Fehler landet im
 * PHP-Fehlerprotokoll.
 */
function log_event(PDO $pdo, string $type, string $description): void
{
    try {
        $pdo->prepare('INSERT INTO logs (action_type, description, ip) VALUES (?, ?, ?)')
            ->execute([substr($type, 0, 50), $description, log_client_ip()]);
    } catch (Throwable $e) {
        error_log('Protokolleintrag fehlgeschlagen (' . $type . '): ' . $e->getMessage());
    }
}
