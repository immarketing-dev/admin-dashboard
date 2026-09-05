<?php
/**
 * Wer hat wann was bekommen.
 *
 * Das Panel verschickt neun Sorten Mail — Angebote, Rechnungen,
 * Portalzugänge, Meilensteinmeldungen, Ticketantworten,
 * Termineinladungen, Angebotsreaktionen aus dem Portal, dazu
 * Zahlungserinnerungen und Passwort-Links. Nirgends stand hinterher,
 * was wann an wen ging und ob der Server es angenommen hat. Bei "ich
 * habe nie ein Angebot bekommen" gab es nichts nachzusehen.
 *
 * Die Ereignistabelle `logs` half dabei nicht: dort steht ein Satz
 * Freitext ohne Empfänger, ohne Betreff, ohne Ergebnis, und sie wird
 * nach `log_retention_days` geleert — für einen Versandnachweis der
 * falsche Ort und die falsche Aufbewahrung.
 *
 * Geschrieben wird an einer Stelle mehr als nötig: `mail_versenden()`
 * protokolliert von selbst, die sieben älteren PHPMailer-Blöcke rufen
 * `mail_protokollieren()` ausdrücklich auf. Sie umzubauen wäre eine
 * eigene Änderung mit eigenem Risiko — jeder trägt Eigenheiten
 * (Anhänge, HTML gegen Text, abweichender Absender), und ein Protokoll
 * einzuziehen ist nicht der Anlass, sie anzutasten.
 */

require_once __DIR__ . '/logging.php';

/**
 * Mindesthaltbarkeit des Mailprotokolls, in Tagen.
 *
 * Das Systemprotokoll darf auf eine Woche zusammenschrumpfen — dort
 * geht es um Nachvollziehbarkeit im Betrieb. Ein Versandnachweis ist
 * kurzfristig wertlos: gefragt wird Monate später, ob eine Rechnung
 * hinausging. Deshalb gilt hier dieselbe Einstellung, aber nach unten
 * begrenzt.
 */
const MAIL_LOG_MIN_TAGE = 365;

/**
 * Schreibt einen Eintrag ins Mailprotokoll.
 *
 * Schlägt das Schreiben fehl, darf es den Versand nicht mitreißen: eine
 * verschickte, aber nicht protokollierte Mail ist ärgerlich, eine
 * abgebrochene Aktion ist schlimmer. Dieselbe Haltung wie in
 * log_event().
 *
 * @param string  $vorlage   Schlüssel aus mail_templates(), oder ein
 *                           eigener Name für Mails ohne Vorlage
 * @param string  $empfaenger
 * @param string  $betreff
 * @param bool    $erfolg
 * @param ?string $fehler    Meldung im Fehlerfall
 * @param ?string $bezug     Woran hing die Mail: "Angebot ANG-2026-003"
 */
function mail_protokollieren(
    PDO $pdo,
    string $vorlage,
    string $empfaenger,
    string $betreff,
    bool $erfolg,
    ?string $fehler = null,
    ?string $bezug = null
): void {
    try {
        $pdo->prepare(
            'INSERT INTO mail_log (template, recipient, subject, status, error, context)
             VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([
            mb_substr($vorlage, 0, 50),
            mb_substr($empfaenger, 0, 255),
            mb_substr($betreff, 0, 255),
            $erfolg ? 'sent' : 'failed',
            $fehler !== null ? mb_substr($fehler, 0, 2000) : null,
            $bezug !== null ? mb_substr($bezug, 0, 255) : null,
        ]);
    } catch (Throwable $e) {
        error_log('Mailprotokoll fehlgeschlagen (' . $vorlage . '): ' . $e->getMessage());
    }
}

/**
 * Die Einträge des Mailprotokolls, neueste zuerst.
 *
 * @param string $filter '' (alles), 'sent' oder 'failed'
 */
function mail_protokoll(PDO $pdo, string $filter = '', int $limit = 200): array
{
    $limit = max(1, min(2000, $limit));

    // Die Grenze steht als Zahl in der Abfrage und nicht als Wert: MySQL
    // erwartet hinter LIMIT einen Zahlenausdruck, und mit echten
    // Prepared Statements (siehe config.php) käme ein gebundener Wert
    // dort als Zeichenkette an. Der Wert ist oben gedeckelt und
    // ganzzahlig.
    if ($filter === 'sent' || $filter === 'failed') {
        $stmt = $pdo->prepare(
            'SELECT * FROM mail_log WHERE status = ? ORDER BY created_at DESC, id DESC LIMIT ' . (int) $limit
        );
        $stmt->execute([$filter]);
    } else {
        $stmt = $pdo->prepare(
            'SELECT * FROM mail_log ORDER BY created_at DESC, id DESC LIMIT ' . (int) $limit
        );
        $stmt->execute();
    }

    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/** Wie viele Sendungen sind fehlgeschlagen, und wie viele insgesamt? */
function mail_protokoll_zahlen(PDO $pdo): array
{
    $zeile = $pdo->query(
        "SELECT COUNT(*) AS gesamt,
                SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) AS fehler
           FROM mail_log"
    )->fetch(PDO::FETCH_ASSOC);

    return [
        'gesamt' => (int) ($zeile['gesamt'] ?? 0),
        'fehler' => (int) ($zeile['fehler'] ?? 0),
    ];
}

/**
 * Kürzt das Mailprotokoll.
 *
 * Läuft im Cron-Lauf mit. Die Untergrenze aus MAIL_LOG_MIN_TAGE greift
 * auch dann, wenn jemand log_retention_days auf eine Woche stellt.
 *
 * @return int Anzahl gelöschter Zeilen
 */
function mail_protokoll_kuerzen(PDO $pdo): int
{
    $tage = (int) setting('log_retention_days', '365');
    if ($tage < MAIL_LOG_MIN_TAGE) {
        $tage = MAIL_LOG_MIN_TAGE;
    }

    try {
        $stmt = $pdo->prepare(
            'DELETE FROM mail_log WHERE created_at < DATE_SUB(NOW(), INTERVAL ' . (int) $tage . ' DAY)'
        );
        $stmt->execute();

        return $stmt->rowCount();
    } catch (PDOException $e) {
        error_log('Mailprotokoll kuerzen fehlgeschlagen: ' . $e->getMessage());
        return 0;
    }
}
