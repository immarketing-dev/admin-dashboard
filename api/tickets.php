<?php
/**
 * Nimmt eingehende Mails als Support-Anfragen entgegen.
 *
 * Ein Ticket entstand bisher nur im Panel oder im Portal. Kunden
 * schreiben aber E-Mails, und die support@-Adresse wurde nur zum
 * Versenden benutzt.
 *
 * Aufruf durch einen Maildienst, der eingehende Nachrichten weiterreicht
 * (Cloudflare Email Routing, Postmark, Mailgun und andere können das):
 *
 *     POST /api/tickets
 *     X-Api-Key: <Schlüssel aus den Einstellungen>
 *     Content-Type: application/json
 *
 *     {"from":"anna@example.com","subject":"Re: [#14] Drucker",
 *      "text":"Das Problem besteht weiterhin."}
 *
 * Kein IMAP-Abruf im Cron-Lauf: der bräuchte die PHP-Erweiterung `imap`,
 * und die ist seit PHP 8.4 nicht mehr Teil des Kerns. Ein Weg, der auf
 * der nächsten PHP-Version stillschweigend aufhört zu funktionieren, ist
 * kein guter Weg.
 *
 * Antwortet mit JSON: 201 angenommen (mit `art` und `id`), 400 bei
 * fehlenden Feldern, 401 ohne gültigen Schlüssel, 429 bei zu vielen
 * Anfragen, 503 wenn kein Schlüssel eingerichtet ist.
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/api_keys.php';
require_once __DIR__ . '/../includes/api_tickets.php';

// Demo-Modus, Methode, Schlüssel, Bremse - alle vier hinter einem
// Aufruf, damit ein Endpunkt nicht eine davon vergessen kann.
api_tuer($pdo, 'tickets', 'API_TICKET', API_TICKETS_MAX_PRO_STUNDE);

$eingabe = api_rumpf(
    (string) file_get_contents('php://input'),
    (string) ($_SERVER['CONTENT_TYPE'] ?? ''),
    $_POST
);

$geprueft = ticket_mail_pruefen($eingabe);

if (!$geprueft['ok']) {
    api_antwort(400, ['ok' => false, 'error' => 'Ungültige Anfrage.', 'details' => $geprueft['fehler']]);
}

$ergebnis = ticket_aus_mail($pdo, $geprueft['werte']);

api_antwort(201, ['ok' => true, 'art' => $ergebnis['art'], 'id' => $ergebnis['id']]);
