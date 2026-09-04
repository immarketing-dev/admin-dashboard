<?php
/**
 * Wer darf welche hochgeladene Datei sehen.
 *
 * Bis hierher lagen alle Dateien unter uploads/ und wurden vom Webserver
 * an jeden ausgeliefert, der den Pfad kannte. Bei Rechnungen war der Pfad
 * nicht einmal zu raten: sie hiessen "Rechnung_RE-2026-001.pdf", und wer
 * bis 999 zaehlte, hatte die Rechnungen aller Kunden - mit Namen,
 * Anschrift und Betrag. Jetzt sperrt uploads/.htaccess das Verzeichnis
 * ganz, und file.php liefert nur noch heraus, was diese Funktion freigibt.
 *
 * Der Zugriff ist bewusst dieselbe Regel, nach der auch das Portal seine
 * Listen fuellt (portal.php) - eine zweite, abweichende Regel waere genau
 * die Sorte Unterschied, die spaeter niemand mehr bemerkt:
 *   - Projektdateien: jeder am Projekt Beteiligte (task_contacts)
 *   - Rechnungen:     der Kontakt, auf den sie ausgestellt sind
 *   - Angebote:       derselbe, aber keine Entwuerfe
 *   - Wiki-Anhaenge:  nur ausdruecklich freigegebene Artikel
 */

/**
 * Loest eine Datei auf und prueft dabei die Berechtigung.
 *
 * Prueft absichtlich NICHT, ob die Datei auf der Platte liegt - das
 * entscheidet file.php und beantwortet es mit 404. So bleibt diese
 * Funktion ohne Dateisystem pruefbar.
 *
 * @param string   $typ        'asset', 'wiki', 'invoice' oder 'quote'
 * @param int      $id         Kennung in der jeweiligen Tabelle
 * @param int|null $kontakt_id null = angemeldeter Verwalter (sieht alles),
 *                             sonst der Kontakt, dem das Portal gehoert
 * @return array{pfad: string, name: string}|null null = kein Zugriff
 */
function datei_zugriff(PDO $pdo, string $typ, int $id, ?int $kontakt_id): ?array
{
    if ($id <= 0) {
        return null;
    }

    $ist_verwalter = ($kontakt_id === null);

    switch ($typ) {
        case 'asset':
            if ($ist_verwalter) {
                $sql  = "SELECT file_path AS pfad, file_name AS name
                         FROM client_assets WHERE id = ?";
                $werte = [$id];
            } else {
                // Mitgliedschaft statt Besitz - seit Migration 5 kann ein
                // Projekt mehrere Beteiligte haben, und alle sehen im
                // Portal dieselbe Dateiliste.
                $sql  = "SELECT ca.file_path AS pfad, ca.file_name AS name
                         FROM client_assets ca
                         JOIN task_contacts tc ON tc.task_id = ca.task_id
                         JOIN tasks t          ON t.id = ca.task_id
                         WHERE ca.id = ? AND tc.contact_id = ? AND t.deleted_at IS NULL";
                $werte = [$id, $kontakt_id];
            }
            break;

        case 'wiki':
            if ($ist_verwalter) {
                $sql  = "SELECT file_path AS pfad, file_name AS name
                         FROM wiki_attachments WHERE id = ?";
                $werte = [$id];
            } else {
                $sql  = "SELECT wat.file_path AS pfad, wat.file_name AS name
                         FROM wiki_attachments wat
                         JOIN wiki_client_shares wcs ON wcs.article_id = wat.article_id
                         WHERE wat.id = ? AND wcs.contact_id = ?";
                $werte = [$id, $kontakt_id];
            }
            break;

        case 'invoice':
            if ($ist_verwalter) {
                // Auch geloeschte: der Papierkorb stellt wieder her, und
                // dazu gehoert die Datei.
                $sql  = "SELECT invoice_pdf_path AS pfad FROM finances WHERE id = ?";
                $werte = [$id];
            } else {
                $sql  = "SELECT invoice_pdf_path AS pfad FROM finances
                         WHERE id = ? AND contact_id = ?
                           AND deleted_at IS NULL AND type = 'INCOME'";
                $werte = [$id, $kontakt_id];
            }
            break;

        case 'quote':
            if ($ist_verwalter) {
                $sql  = "SELECT quote_pdf_path AS pfad FROM quotes WHERE id = ?";
                $werte = [$id];
            } else {
                // Entwuerfe bleiben aussen vor - was noch nicht gesendet
                // wurde, geht den Empfaenger nichts an. Dieselbe Liste
                // wie in portal.php.
                $sql  = "SELECT quote_pdf_path AS pfad FROM quotes
                         WHERE id = ? AND contact_id = ? AND deleted_at IS NULL
                           AND status IN ('Gesendet','Angenommen','Abgelehnt')";
                $werte = [$id, $kontakt_id];
            }
            break;

        default:
            // Unbekannte Art: keine Auskunft, kein Zugriff.
            return null;
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($werte);
    $zeile = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$zeile || !datei_pfad_erlaubt((string) ($zeile['pfad'] ?? ''))) {
        return null;
    }

    $pfad = str_replace('\\', '/', (string) $zeile['pfad']);

    return [
        'pfad' => $pfad,
        // Rechnungen und Angebote fuehren keinen eigenen Anzeigenamen -
        // ihr Dateiname ist die Rechnungs- bzw. Angebotsnummer und damit
        // schon der richtige Name zum Herunterladen.
        'name' => ($zeile['name'] ?? '') !== '' ? (string) $zeile['name'] : basename($pfad),
    ];
}

/**
 * Mit welchem Typ und welcher Disposition geht eine Datei heraus.
 *
 * Die Liste ist eine Freigabe, keine Zuordnung: was nicht darin steht,
 * geht als Download heraus und traegt application/octet-stream. Der
 * Grund ist der Origin - die Dateien kommen von derselben Adresse wie
 * das Panel. Liefe ein SVG oder eine HTML-Datei im Browser auf, liefe
 * ein Skript darin mit der Sitzung des Angemeldeten und koennte in
 * seinem Namen handeln. Uploads erlauben SVG zwar ohnehin nicht
 * (includes/upload_helper.php), aber diese Entscheidung soll auch dann
 * richtig bleiben, wenn dort einmal etwas dazukommt.
 *
 * @return array{typ: string, disposition: string}
 */
function datei_auslieferung(string $dateiname, bool $als_download = false): array
{
    static $inline = [
        'pdf'  => 'application/pdf',
        'png'  => 'image/png',
        'jpg'  => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'gif'  => 'image/gif',
        'webp' => 'image/webp',
        'txt'  => 'text/plain; charset=utf-8',
    ];

    // Die LETZTE Endung entscheidet: "Rechnung.pdf.html" ist HTML.
    $endung = strtolower(pathinfo($dateiname, PATHINFO_EXTENSION));

    if (!isset($inline[$endung])) {
        return ['typ' => 'application/octet-stream', 'disposition' => 'attachment'];
    }

    return [
        'typ'         => $inline[$endung],
        'disposition' => $als_download ? 'attachment' : 'inline',
    ];
}

/**
 * Darf dieser gespeicherte Pfad ueberhaupt ausgeliefert werden?
 *
 * Der Wert kommt aus der Datenbank, nicht aus der Anfrage - aber er ist
 * dort einmal als Text gelandet, und ein Pfad, der aus uploads/
 * herausfuehrt, waere auch dann falsch, wenn ihn niemand angegriffen hat.
 * config.php und .env liegen ein Verzeichnis darueber.
 */
function datei_pfad_erlaubt(string $pfad): bool
{
    $pfad = str_replace('\\', '/', trim($pfad));

    if ($pfad === '' || strpos($pfad, 'uploads/') !== 0) {
        return false;
    }
    // Jeder Rueckschritt, gleich an welcher Stelle.
    if (strpos($pfad, '..') !== false) {
        return false;
    }
    if (strpos($pfad, "\0") !== false) {
        return false;
    }
    return true;
}
