<?php
/**
 * Eingehende Mails werden zu Support-Anfragen.
 *
 * Ein Ticket entstand nur, wenn du es anlegst oder ein Kunde sich ins
 * Portal einloggt. Kunden schreiben aber E-Mails. Die support@-Adresse
 * steht in der .env und wird für den Versand benutzt — gelesen wurde sie
 * nie.
 *
 * ── Warum ein Webhook und kein IMAP-Abruf ──────────────────────────
 * Der naheliegende Weg wäre, im Cron-Lauf ein Postfach abzurufen. Das
 * braucht die PHP-Erweiterung `imap` — und die ist seit PHP 8.4 nicht
 * mehr Teil des Kerns, sondern nur noch über PECL zu bekommen. Auf
 * einem Massenhoster heißt das: nicht verfügbar. Ein Weg, der auf der
 * nächsten PHP-Version stillschweigend aufhört zu funktionieren, ist
 * kein guter Weg.
 *
 * Stattdessen nimmt dieser Endpunkt entgegen, was ein Maildienst ihm
 * schickt (Cloudflare Email Routing, Postmark, Mailgun und andere
 * bieten das). Kein PHP-Zusatz nötig, läuft überall.
 *
 * ── Wie eine Antwort ihr Ticket wiederfindet ───────────────────────
 * Über eine Kennung im Betreff: `[#14]`. Ausgehende Antworten tragen
 * sie, und die meisten Mailprogramme lassen sie beim Antworten stehen.
 * Findet sich keine, entsteht ein neues Ticket — lieber eines zu viel
 * als eine Nachricht, die niemand sieht.
 */

require_once __DIR__ . '/logging.php';

/** Wie viele Mails eine IP je Stunde einliefern darf. */
const API_TICKETS_MAX_PRO_STUNDE = 60;

/** Die Kennung, mit der eine Antwort ihr Ticket wiederfindet. */
function ticket_betreffkennung(int $ticket_id): string
{
    return '[#' . $ticket_id . ']';
}

/**
 * Sucht die Ticketkennung in einem Betreff.
 *
 * Absichtlich streng: nur `[#` gefolgt von Ziffern und `]`. Ein Betreff
 * wie "Rechnung [#2026] falsch" soll keine Antwort auf Ticket 2026
 * werden — deshalb wird die gefundene Nummer anschließend gegen die
 * Datenbank geprüft, und zwar gegen ein Ticket desselben Absenders.
 */
function ticket_kennung_aus_betreff(string $betreff): ?int
{
    if (preg_match('/\[#(\d{1,9})\]/', $betreff, $m)) {
        return (int) $m[1];
    }
    return null;
}

/**
 * Entfernt Kennung und Antwortpräfixe aus einem Betreff.
 *
 * "Re: AW: [#14] Drucker geht nicht" wird zu "Drucker geht nicht" — als
 * Titel eines neuen Tickets ist das der lesbare Teil.
 */
function ticket_betreff_saeubern(string $betreff): string
{
    $betreff = preg_replace('/\[#\d+\]/', '', $betreff) ?? $betreff;
    // Mehrfach, weil sich die Präfixe stapeln: "Re: AW: Re: …".
    do {
        $vorher  = $betreff;
        $betreff = preg_replace('/^\s*(re|aw|wg|fwd|fw)\s*(\[\d+\])?\s*:\s*/i', '', $betreff) ?? $betreff;
    } while ($betreff !== $vorher);

    return trim($betreff);
}

/**
 * Schneidet zitierten Text ab.
 *
 * Eine Antwort enthält üblicherweise die ganze Vorgeschichte. Sie in
 * jede Notiz zu kopieren macht den Verlauf unlesbar - beim dritten
 * Hin und Her steht dieselbe Frage viermal da.
 *
 * Erkannt werden die verbreiteten Trenner. Was nicht erkannt wird,
 * bleibt stehen: lieber zu viel Text als eine abgeschnittene Frage.
 */
function ticket_zitat_entfernen(string $text): string
{
    // Zeilenweise und nicht mit einem grossen Ausdruck ueber den ganzen
    // Text: die Trennzeilen sind je nach Mailprogramm verschieden
    // aufgebaut ("Am … schrieb Support:", "On … wrote:", eine Reihe
    // Bindestriche), und ein Muster, das sie alle abdecken will, wird
    // entweder unlesbar oder trifft daneben. Beim ersten Anlauf tat es
    // Letzteres - "schrieb Support:" passte nicht auf "schrieb\s*:".
    $trenner = [
        // "Am 01.03.2026 um 10:00 schrieb Support:" / "On … wrote:"
        '/^\s*(Am|On)\s.*\b(schrieb|wrote)\b.*:\s*$/iu',
        // Outlook und Verwandte
        '/^\s*-{2,}\s*(Urspr(ü|ue)ngliche Nachricht|Original Message)\s*-{2,}\s*$/iu',
        '/^\s*_{5,}\s*$/u',
        // Der Kopf eines weitergeleiteten Blocks
        '/^\s*(Von|From)\s*:\s*\S/iu',
        '/^\s*-{3,}\s*Forwarded message\s*-{3,}\s*$/iu',
    ];

    $behalten = [];
    foreach (preg_split('/\R/', $text) ?: [] as $zeile) {
        foreach ($trenner as $muster) {
            if (preg_match($muster, $zeile)) {
                // Ab hier ist alles Vorgeschichte.
                return trim(implode("\n", $behalten));
            }
        }
        // Zeilen, die mit ">" beginnen, sind Zitat.
        if (preg_match('/^\s*>/', $zeile)) {
            continue;
        }
        $behalten[] = $zeile;
    }

    return trim(implode("\n", $behalten));
}

/**
 * Prüft die Felder einer eingelieferten Mail.
 *
 * @return array{ok: bool, fehler: array<int, string>, werte: array<string, string>}
 */
function ticket_mail_pruefen(array $eingabe): array
{
    $fehler = [];

    // Verschiedene Dienste nennen die Felder verschieden. Die
    // verbreiteten Schreibweisen werden angenommen, damit niemand seinen
    // Dienst umbauen muss.
    $absender = trim((string) ($eingabe['from'] ?? $eingabe['sender'] ?? $eingabe['From'] ?? ''));
    // "Anna Beispiel <anna@example.com>" - die Adresse herausschneiden.
    if (preg_match('/<([^>]+)>/', $absender, $m)) {
        $absender = trim($m[1]);
    }
    if (!filter_var($absender, FILTER_VALIDATE_EMAIL)) {
        $fehler[] = 'Das Feld "from" fehlt oder enthält keine gültige Adresse.';
    }

    $betreff = trim((string) ($eingabe['subject'] ?? $eingabe['Subject'] ?? ''));
    $text    = trim((string) ($eingabe['text'] ?? $eingabe['body'] ?? $eingabe['plain'] ?? ''));

    if ($text === '' && $betreff === '') {
        $fehler[] = 'Weder "subject" noch "text" enthalten etwas.';
    }

    if ($fehler) {
        return ['ok' => false, 'fehler' => $fehler, 'werte' => []];
    }

    return [
        'ok'     => true,
        'fehler' => [],
        'werte'  => [
            'from'    => mb_substr($absender, 0, 255),
            'subject' => mb_substr($betreff !== '' ? $betreff : '(ohne Betreff)', 0, 255),
            'text'    => mb_substr(ticket_zitat_entfernen($text), 0, 20000),
            'name'    => mb_substr(trim((string) ($eingabe['name'] ?? '')), 0, 255),
        ],
    ];
}

/**
 * Sucht den Kontakt zu einer Absenderadresse.
 *
 * Ohne Treffer bleibt es bei null: das Ticket entsteht trotzdem, nur
 * ohne Zuordnung. Eine Anfrage wegzuwerfen, weil der Absender noch
 * nicht im Adressbuch steht, wäre die falsche Entscheidung — genau so
 * melden sich neue Kunden.
 */
function ticket_kontakt_finden(PDO $pdo, string $email): ?int
{
    $stmt = $pdo->prepare(
        'SELECT id FROM contacts WHERE deleted_at IS NULL AND email = ? ORDER BY id ASC LIMIT 1'
    );
    $stmt->execute([$email]);
    $id = $stmt->fetchColumn();

    return $id === false ? null : (int) $id;
}

/**
 * Gehört dieses Ticket zu diesem Absender?
 *
 * Die Kennung steht im Betreff und damit in einer Mail, die jeder
 * schreiben kann. Ohne diese Prüfung könnte jemand mit "[#14]" im
 * Betreff eine Notiz in ein fremdes Ticket schreiben - und die wäre im
 * Portal des echten Kunden sichtbar.
 */
function ticket_gehoert_zu(PDO $pdo, int $ticket_id, ?int $contact_id): bool
{
    if ($ticket_id <= 0 || $contact_id === null) {
        return false;
    }

    $stmt = $pdo->prepare('SELECT 1 FROM support_tickets WHERE id = ? AND contact_id = ?');
    $stmt->execute([$ticket_id, $contact_id]);

    return (bool) $stmt->fetchColumn();
}

/**
 * Legt die Mail als Ticket oder als Notiz ab.
 *
 * @return array{art: string, id: int}  art: 'ticket' oder 'note'
 */
function ticket_aus_mail(PDO $pdo, array $werte): array
{
    $contact_id = ticket_kontakt_finden($pdo, $werte['from']);
    $kennung    = ticket_kennung_aus_betreff($werte['subject']);

    // Antwort auf ein bestehendes Ticket - aber nur, wenn es wirklich
    // dem Absender gehört.
    if ($kennung !== null && ticket_gehoert_zu($pdo, $kennung, $contact_id)) {
        $pdo->prepare(
            "INSERT INTO ticket_notes (ticket_id, note, author, is_public) VALUES (?, ?, 'client', 1)"
        )->execute([$kennung, $werte['text']]);

        // Eine Antwort holt das Ticket zurück in die Bearbeitung.
        $pdo->prepare(
            "UPDATE support_tickets SET status = 'Offen' WHERE id = ? AND status = 'Erledigt'"
        )->execute([$kennung]);

        log_event($pdo, 'TICKET_MAIL_REPLY', 'Antwort per E-Mail zu Ticket #' . $kennung . ' von ' . $werte['from'] . '.');

        return ['art' => 'note', 'id' => $kennung];
    }

    $titel = ticket_betreff_saeubern($werte['subject']);
    if ($titel === '') {
        $titel = '(ohne Betreff)';
    }

    // Ohne Kontakt steht die Adresse im Text - sonst wäre nicht mehr zu
    // erkennen, wer geschrieben hat.
    $nachricht = $contact_id === null
        ? 'Von: ' . $werte['from'] . ($werte['name'] !== '' ? ' (' . $werte['name'] . ')' : '') . "\n\n" . $werte['text']
        : $werte['text'];

    $pdo->prepare(
        "INSERT INTO support_tickets (contact_id, subject, message, status, priority)
         VALUES (?, ?, ?, 'Offen', 'Mittel')"
    )->execute([$contact_id, $titel, $nachricht]);

    $id = (int) $pdo->lastInsertId();

    log_event(
        $pdo,
        'TICKET_MAIL_NEW',
        'Neue Support-Anfrage per E-Mail von ' . $werte['from']
        . ($contact_id === null ? ' (kein Kontakt zugeordnet)' : '') . '.'
    );

    return ['art' => 'ticket', 'id' => $id];
}
