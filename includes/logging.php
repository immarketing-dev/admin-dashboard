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

/**
 * Raeumt alte Protokolleintraege weg - hoechstens einmal am Tag.
 *
 * logs waechst unbegrenzt: jede Anmeldung, jeder Fehlversuch, jede
 * Aenderung. Bei einer Welle von Anmeldeversuchen sind das schnell
 * Zehntausende Zeilen, und die Tabelle traegt die Sperrlogik - wird sie
 * langsam, wird die Anmeldung langsam.
 *
 * Der Tagesriegel steht in settings und kostet nichts: setting() hat die
 * Tabelle ohnehin schon vollstaendig geladen. Erst wenn der Tag wechselt,
 * faellt ueberhaupt eine Abfrage an.
 *
 * @return int Anzahl geloeschter Zeilen (0 wenn heute schon geraeumt).
 */
function logs_aufraeumen(PDO $pdo): int
{
    $heute = date('Y-m-d');
    if (setting('logs_pruned_on', '') === $heute) {
        return 0;
    }

    $tage = (int) setting('log_retention_days', '365');
    if ($tage < 7) {
        $tage = 7;   // Untergrenze: eine Woche bleibt immer nachvollziehbar.
    }

    try {
        $stmt = $pdo->prepare(
            'DELETE FROM logs WHERE created_at < DATE_SUB(NOW(), INTERVAL ' . $tage . ' DAY)'
        );
        $stmt->execute();
        $weg = $stmt->rowCount();

        $pdo->prepare(
            "INSERT INTO settings (k, v) VALUES ('logs_pruned_on', ?)
             ON DUPLICATE KEY UPDATE v = VALUES(v)"
        )->execute([$heute]);

        if ($weg > 0) {
            log_event($pdo, 'LOGS_PRUNED', "$weg Protokolleintraege aelter als $tage Tage entfernt.");
        }
        return $weg;
    } catch (PDOException $e) {
        // Aufraeumen ist Nebensache - es darf keine Seite zum Absturz bringen.
        error_log('Protokoll aufraeumen fehlgeschlagen: ' . $e->getMessage());
        return 0;
    }
}