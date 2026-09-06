<?php
/**
 * Benachrichtigungen aus dem Panel ins Postfach des Kunden.
 *
 * Bis hierher lief die Meldung in eine Richtung: das Panel zeigte jede
 * Regung des Kunden als Zaehler in der Seitenleiste, umgekehrt erfuhr er
 * nichts. Wer im Portal auf eine Antwort wartete, musste zufaellig
 * hineinschauen - eine Antwort im Austausch, ein Kommentar an einem
 * Schritt und eine hochgeladene Datei blieben stumm.
 *
 * ── Zwei Bremsen, beide muessen zustimmen ───────────────────────────
 * Zuerst der globale Schalter der Ereignisart (Einstellungen →
 * Benachrichtigungen): er entscheidet, ob diese Art ueberhaupt
 * verschickt wird. Danach das Haekchen am Kontakt
 * (contacts.portal_notify): es entscheidet, ob dieser eine Empfaenger
 * sie bekommt. Der Absender bestimmt das Erste, der Empfaenger das
 * Zweite.
 *
 * ── Alle Beteiligten, nicht nur der Hauptkontakt ────────────────────
 * Seit Migration 5 kann ein Projekt mehrere Beteiligte haben, und jeder
 * von ihnen sieht es im eigenen Portal. Eine Nachricht, die nur an
 * tasks.contact_id ginge, erreichte den Geschaeftspartner nie, der am
 * selben Projekt mitarbeitet.
 *
 * Jeder bekommt seine eigene Mail: in seiner Sprache
 * (contacts.language) und mit seinem eigenen Zugangslink
 * (contacts.portal_token). Ein gemeinsamer Verteiler waere beides
 * nicht - und gaebe die Adressen der uebrigen preis.
 */

// Der Versandweg. Die Bedingung laesst einem Test den Vortritt, der
// mail_versenden() durch eine Aufzeichnung ersetzt: er prueft, WER Post
// bekommt und WAS darin steht, und dafuer darf kein SMTP-Server noetig
// sein. Im Betrieb ist die Funktion nie vorher definiert, dann greift
// das require wie ueberall sonst.
if (!function_exists('mail_versenden')) {
    require_once __DIR__ . '/mailer.php';
}
require_once __DIR__ . '/mail_templates.php';
require_once __DIR__ . '/logging.php';

/**
 * Die Ereignisarten: welcher Schalter, welche Vorlage.
 *
 * Eine Art, die hier fehlt, verschickt nichts - portal_benachrichtigen()
 * gibt dann 0 zurueck, ohne Fehler. Das ist die richtige Richtung: ein
 * Tippfehler im Aufruf schickt lieber keine Mail als eine falsche.
 *
 * @return array<string, array{setting: string, template: string}>
 */
function portal_notify_arten(): array
{
    return [
        'project_reply'     => ['setting' => 'notify_project_reply',     'template' => 'project_reply'],
        'milestone_comment' => ['setting' => 'notify_milestone_comment', 'template' => 'milestone_comment'],
        'asset_upload'      => ['setting' => 'notify_asset_upload',      'template' => 'asset_upload'],
        'milestone'         => ['setting' => 'notify_milestone_email',   'template' => 'milestone'],
        'ticket_reply'      => ['setting' => 'notify_ticket_reply',      'template' => 'ticket_reply'],
        'invoice_created'   => ['setting' => 'notify_invoice_created',   'template' => 'invoice_send'],
    ];
}

/**
 * Ist diese Art eingeschaltet?
 *
 * Vorgabe '1': ein Schluessel, den noch niemand gespeichert hat, gilt als
 * an. Sonst waere das Panel nach dem Update stumm, bis jemand die
 * Einstellungen einmal oeffnet und speichert.
 */
function portal_notify_aktiv(string $art): bool
{
    $arten = portal_notify_arten();
    if (!isset($arten[$art])) {
        return false;
    }

    return setting($arten[$art]['setting'], '1') === '1';
}

/**
 * Die Empfänger eines Projekts: alle Beteiligten mit Adresse, die nicht
 * abbestellt haben.
 *
 * @return array<int, array{id:int, name:string, email:string, language:?string, portal_token:?string}>
 */
function portal_empfaenger(PDO $pdo, int $task_id): array
{
    if ($task_id <= 0) {
        return [];
    }
    $stmt = $pdo->prepare(
        "SELECT c.id, c.name, c.email, c.language, c.portal_token
           FROM task_contacts tc
           JOIN contacts c ON c.id = tc.contact_id
          WHERE tc.task_id = ?
            AND c.deleted_at IS NULL
            AND c.portal_notify = 1
            AND c.email IS NOT NULL AND c.email <> ''
          ORDER BY tc.role = 'owner' DESC, c.name ASC"
    );
    $stmt->execute([$task_id]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/**
 * Ein einzelner Kontakt, falls er Mail bekommen darf.
 *
 * @return array{id:int, name:string, email:string, language:?string, portal_token:?string}|null
 */
function portal_empfaenger_einzeln(PDO $pdo, int $contact_id): ?array
{
    if ($contact_id <= 0) {
        return null;
    }
    $stmt = $pdo->prepare(
        "SELECT id, name, email, language, portal_token
           FROM contacts
          WHERE id = ? AND deleted_at IS NULL AND portal_notify = 1
            AND email IS NOT NULL AND email <> ''"
    );
    $stmt->execute([$contact_id]);
    $zeile = $stmt->fetch(PDO::FETCH_ASSOC);

    return $zeile ?: null;
}

/**
 * Schickt einer Liste von Empfängern je eine Mail.
 *
 * Der gemeinsame Kern von portal_benachrichtigen() und
 * portal_benachrichtigen_kontakt(). Ein Fehlschlag bei einem Empfänger
 * bricht die Schleife nicht ab: die übrigen sollen ihre Mail bekommen,
 * auch wenn eine Adresse nicht mehr stimmt.
 *
 * @param array $empfaenger Zeilen aus portal_empfaenger()
 * @return int Anzahl der wirklich versendeten Mails
 */
function portal_senden(PDO $pdo, string $art, array $empfaenger, array $daten): int
{
    $arten = portal_notify_arten();
    if (!isset($arten[$art]) || $empfaenger === []) {
        return 0;
    }

    $vorlage  = $arten[$art]['template'];
    $firma    = setting('company_short', COMPANY_SHORT);
    $basis    = rtrim(setting('main_website', MAIN_WEBSITE), '/');
    $gesendet = 0;

    foreach ($empfaenger as $e) {
        // Der Vorname reicht in der Anrede und liest sich weniger nach
        // Serienbrief als "Sehr geehrte Frau Dr. Hofmann-Krueger".
        $vars = $daten + [
            'kunde' => explode(' ', trim((string) $e['name']))[0] ?: (string) $e['name'],
            'firma' => $firma,
        ];

        $link = !empty($e['portal_token'])
            ? $basis . '/portal?token=' . urlencode((string) $e['portal_token'])
            : '';

        // In der Sprache des Empfaengers - er liest die Mail, nicht wir.
        $sprache = mail_sprache($e['language'] ?? null);
        $m = mail_in_sprache($sprache, fn() => mail_render($vorlage, $vars, $link));

        $ergebnis = mail_versenden([
            'to'       => (string) $e['email'],
            'subject'  => $m['subject'],
            'body'     => $m['html'],
            'pdo'      => $pdo,
            'template' => $art,
            'context'  => (string) ($daten['projekt'] ?? $daten['nummer'] ?? ''),
        ]);

        if (!empty($ergebnis['ok'])) {
            $gesendet++;
        }
    }

    return $gesendet;
}

/**
 * Benachrichtigt alle Beteiligten eines Projekts.
 *
 * @param string $art   Schlüssel aus portal_notify_arten()
 * @param array  $daten Platzhalter der Vorlage; 'kunde' und 'firma'
 *                      werden je Empfänger ergänzt.
 * @return int Anzahl der versendeten Mails
 */
function portal_benachrichtigen(PDO $pdo, string $art, int $task_id, array $daten): int
{
    if (!portal_notify_aktiv($art)) {
        return 0;
    }

    return portal_senden($pdo, $art, portal_empfaenger($pdo, $task_id), $daten);
}

/**
 * Benachrichtigt einen einzelnen Kontakt - für alles, was nicht an einem
 * Projekt hängt (Rechnung, Support-Anfrage).
 *
 * @return int 1 oder 0
 */
function portal_benachrichtigen_kontakt(PDO $pdo, string $art, int $contact_id, array $daten): int
{
    if (!portal_notify_aktiv($art)) {
        return 0;
    }
    $e = portal_empfaenger_einzeln($pdo, $contact_id);

    return $e === null ? 0 : portal_senden($pdo, $art, [$e], $daten);
}
