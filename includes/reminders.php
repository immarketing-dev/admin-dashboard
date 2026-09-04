<?php
/**
 * Zahlungserinnerungen.
 *
 * Das Panel wusste seit Langem, wer nicht zahlt: finances.php stempelt
 * offene Rechnungen nach Ablauf der Frist auf "Überfällig", und die
 * Seitenleiste zeigt den Zähler. Gesagt hat es das nur dir.
 *
 * Die Vorlage dafür stand ebenfalls schon fertig da - 'payment_reminder'
 * in includes/mail_templates.php, mit Betreff, Text und Platzhaltern -
 * und wurde an keiner einzigen Stelle im Projekt aufgerufen.
 *
 * Diese Datei schließt die Lücke. Sie ist in zwei Hälften geteilt: die
 * obere rechnet und entscheidet und kommt ohne Datenbank, ohne
 * Einstellungen und ohne Mailversand aus (und ist deshalb prüfbar), die
 * untere fasst die Datenbank an.
 *
 * Bewusste Enthaltsamkeit: Mahngebühren und Verzugszinsen kommen hier
 * nicht vor. Beides sind Entscheidungen mit rechtlichen Folgen, keine
 * Funktionen - und beides gehörte, wenn überhaupt, auf eine Rechnung
 * und nicht in eine Erinnerungsmail.
 */

require_once __DIR__ . '/dates.php';
require_once __DIR__ . '/mailer.php';
require_once __DIR__ . '/mail_templates.php';
require_once __DIR__ . '/logging.php';

/**
 * Höchstens eine Erinnerung je Rechnung und Tag.
 *
 * Der Cron-Lauf darf mehrmals täglich laufen - stündlich ist eine
 * vernünftige Einstellung, damit die wiederkehrenden Rechnungen zeitnah
 * entstehen. Ohne diese Sperre bekäme der Kunde dann stündlich dieselbe
 * Mahnung.
 */
const MAHNUNG_SPERRE_STUNDEN = 20;

// ---------------------------------------------------------------------
// Rechnen und entscheiden - ohne Datenbank
// ---------------------------------------------------------------------

/**
 * Liest die Mahnstufen aus der Einstellung.
 *
 * Format ist eine Liste von Tagen nach Fälligkeit: "7, 21" heißt eine
 * freundliche Erinnerung nach einer Woche und eine zweite nach drei.
 *
 * Eine leere Einstellung bedeutet: keine Automatik. Das ist der
 * Auslieferungszustand, und zwar mit Absicht - ein Schemaschritt darf
 * eine bestehende Installation nicht dazu bringen, unaufgefordert Mails
 * an ihre Kunden zu schicken. Der manuelle Knopf in der Rechnungsliste
 * funktioniert unabhängig davon.
 *
 * @return array<int, int> aufsteigend, ohne Dubletten
 */
function mahnstufen(string $roh): array
{
    $tage = [];
    foreach (preg_split('/[,;\s]+/', trim($roh)) ?: [] as $stueck) {
        if ($stueck === '' || !preg_match('/^\d+$/', $stueck)) {
            continue;
        }
        $wert = (int) $stueck;
        if ($wert > 0) {
            $tage[$wert] = true;
        }
    }
    $tage = array_keys($tage);
    sort($tage);
    return $tage;
}

// tage_ueberfaellig() steht in includes/dates.php - die offene-Posten-
// Liste in includes/reports.php rechnet dasselbe und soll dafuer nicht
// den Mailversand mitladen muessen.

/**
 * Ist für diese Rechnung die nächste Stufe erreicht?
 *
 * $bisher ist finances.reminder_count: die Zahl der bereits
 * verschickten Erinnerungen. Sie ist zugleich der Index der nächsten
 * Stufe. Sind alle Stufen abgearbeitet, passiert nichts mehr - das
 * Panel mahnt nicht endlos, ab da ist es eine Sache für einen Menschen.
 */
function mahnung_faellig(int $ueberfaellig, int $bisher, array $stufen): bool
{
    if ($bisher < 0 || $bisher >= count($stufen)) {
        return false;
    }
    return $ueberfaellig >= (int) $stufen[$bisher];
}

/**
 * Liegt die letzte Erinnerung lange genug zurück?
 *
 * Fehlt der Zeitpunkt, wurde noch nie gemahnt - dann steht nichts im Weg.
 */
function mahnsperre_abgelaufen(?string $zuletzt, string $jetzt): bool
{
    if ($zuletzt === null || trim($zuletzt) === '') {
        return true;
    }
    $z = strtotime($zuletzt);
    $j = strtotime($jetzt);
    if ($z === false || $j === false) {
        return true;
    }
    return ($j - $z) >= MAHNUNG_SPERRE_STUNDEN * 3600;
}

/**
 * Wählt aus einer Liste offener Rechnungen die aus, die jetzt eine
 * Erinnerung bekommen.
 *
 * Bewusst getrennt von der Abfrage: so lässt sich die Auswahl mit
 * gestellten Zeilen prüfen, ohne eine Datenbank und ohne ein Datum, das
 * beim nächsten Testlauf ein anderes ist.
 *
 * @param array<int, array<string, mixed>> $zeilen
 * @return array<int, array<string, mixed>>
 */
function faellige_mahnungen(array $zeilen, array $stufen, string $jetzt): array
{
    if (!$stufen) {
        return [];
    }

    $treffer = [];
    foreach ($zeilen as $z) {
        $ueber = tage_ueberfaellig($z['due_date'] ?? null, $jetzt);

        if (!mahnung_faellig($ueber, (int) ($z['reminder_count'] ?? 0), $stufen)) {
            continue;
        }
        if (!mahnsperre_abgelaufen($z['last_reminder_at'] ?? null, $jetzt)) {
            continue;
        }
        // Ohne Adresse gibt es nichts zu verschicken. Die Rechnung bleibt
        // offen und taucht beim nächsten Lauf wieder auf - der Bericht
        // nennt sie, damit die fehlende Adresse auffällt.
        if (!filter_var(trim((string) ($z['empfaenger'] ?? '')), FILTER_VALIDATE_EMAIL)) {
            continue;
        }
        $treffer[] = $z;
    }
    return $treffer;
}

/**
 * Füllt die Platzhalter der Vorlage 'payment_reminder'.
 *
 * Die Namen sind die, die in includes/mail_templates.php unter 'vars'
 * stehen - kunde, nummer, betrag, faellig, firma. Die Vorlage selbst
 * wird nicht angefasst: eine bereits vom Benutzer bearbeitete Fassung
 * liegt in der settings-Tabelle und würde durch einen neuen Platzhalter
 * unvollständig.
 */
function mahnung_variablen(array $rechnung, string $firma): array
{
    $betrag = number_format((float) ($rechnung['amount'] ?? 0), 2, ',', '.');
    $faellig = $rechnung['due_date'] ?? '';
    $faellig = $faellig ? date('d.m.Y', strtotime((string) $faellig)) : '';

    return [
        'kunde'   => (string) ($rechnung['kundenname'] ?? ''),
        'nummer'  => (string) ($rechnung['invoice_number'] ?? $rechnung['title'] ?? ''),
        'betrag'  => $betrag,
        'faellig' => $faellig,
        'firma'   => $firma,
    ];
}

// ---------------------------------------------------------------------
// Datenbank
// ---------------------------------------------------------------------

/**
 * Alle offenen Ausgangsrechnungen mit Fälligkeit und Empfänger.
 *
 * Ohne Filter auf das Datum: welche davon dran sind, entscheidet
 * faellige_mahnungen() in PHP. Das kostet bei der Größenordnung, um die
 * es hier geht, nichts und hält die Entscheidung an einem Ort statt
 * halb in SQL und halb in PHP.
 *
 * COALESCE auf die Kontaktadresse: eine Rechnung kann auf einen
 * gespeicherten Kontakt zeigen oder nur einen freien Namen tragen. Im
 * zweiten Fall gibt es keine Adresse, und die Zeile fällt später heraus.
 */
function offene_rechnungen(PDO $pdo): array
{
    $sql =
        "SELECT f.id, f.title, f.invoice_number, f.amount, f.due_date,
                f.reminder_count, f.last_reminder_at, f.invoice_pdf_path,
                COALESCE(c.name, f.custom_name) AS kundenname,
                c.email AS empfaenger
           FROM finances f
           LEFT JOIN contacts c ON c.id = f.contact_id AND c.deleted_at IS NULL
          WHERE f.deleted_at IS NULL
            AND f.type = 'INCOME'
            AND f.status IN ('Offen', 'Überfällig')
            AND f.due_date IS NOT NULL
          ORDER BY f.due_date ASC, f.id ASC";

    return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/**
 * Eine einzelne Rechnung samt Empfänger - für den Knopf in der Liste.
 */
function rechnung_fuer_mahnung(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare(
        "SELECT f.id, f.title, f.invoice_number, f.amount, f.due_date, f.status,
                f.reminder_count, f.last_reminder_at, f.invoice_pdf_path,
                COALESCE(c.name, f.custom_name) AS kundenname,
                c.email AS empfaenger
           FROM finances f
           LEFT JOIN contacts c ON c.id = f.contact_id AND c.deleted_at IS NULL
          WHERE f.deleted_at IS NULL AND f.type = 'INCOME' AND f.id = ?"
    );
    $stmt->execute([$id]);
    $zeile = $stmt->fetch(PDO::FETCH_ASSOC);

    return $zeile ?: null;
}

/**
 * Vermerkt eine verschickte Erinnerung.
 *
 * Der Zeitpunkt kommt aus der Datenbank, nicht aus PHP - läuft der
 * Webserver in einer anderen Zeitzone als die Datenbank, stünden sonst
 * zwei Uhrzeiten in derselben Zeile (dieselbe Überlegung wie bei
 * zeiten_abrechnen() in includes/time_billing.php).
 *
 * Die Bedingung auf reminder_count ist die Absicherung gegen zwei
 * gleichzeitige Läufe: nur einer von beiden erhöht den Zähler, der
 * andere trifft auf einen bereits erhöhten Wert und ändert nichts.
 */
function mahnung_vermerken(PDO $pdo, int $id, int $bisher): bool
{
    $stmt = $pdo->prepare(
        'UPDATE finances
            SET reminder_count = reminder_count + 1, last_reminder_at = NOW()
          WHERE id = ? AND reminder_count = ?'
    );
    $stmt->execute([$id, $bisher]);

    return $stmt->rowCount() === 1;
}

/**
 * Baut die Mail und verschickt sie.
 *
 * Hängt das Rechnungs-PDF an, wenn es eines gibt: eine Erinnerung ohne
 * die Rechnung, an die sie erinnert, zwingt den Empfänger zum Suchen.
 *
 * $betreff und $text übersteuern die Vorlage. Der Knopf in der
 * Rechnungsliste füllt damit das, was im Fenster stand - dort darf der
 * Wortlaut vor dem Absenden noch angepasst werden. Der Cron-Lauf
 * übergibt beides leer und bekommt die Vorlage.
 *
 * Beide Wege laufen bewusst durch dieselbe Funktion: so schreibt eine
 * von Hand verschickte Erinnerung denselben Zähler fort und verschiebt
 * die nächste automatische Stufe, statt neben ihr herzulaufen.
 *
 * @return array{ok: bool, error: string}
 */
function mahnung_senden(
    PDO $pdo,
    array $rechnung,
    string $firma,
    string $wurzel,
    string $betreff = '',
    string $text = ''
): array {
    $vars = mahnung_variablen($rechnung, $firma);
    $mail = mail_render('payment_reminder', $vars);

    if (trim($betreff) !== '') {
        $mail['subject'] = $betreff;
    }
    if (trim($text) !== '') {
        $mail['text'] = $text;
    }

    $anhang = null;
    $pfad   = (string) ($rechnung['invoice_pdf_path'] ?? '');
    if ($pfad !== '' && is_file($wurzel . '/' . $pfad)) {
        $anhang = $wurzel . '/' . $pfad;
    }

    $ergebnis = mail_versenden([
        'to'              => (string) ($rechnung['empfaenger'] ?? ''),
        'subject'         => $mail['subject'],
        'body'            => $mail['text'],
        'attachment'      => $anhang,
        'attachment_name' => $anhang ? basename($anhang) : '',
    ]);

    if ($ergebnis['ok']) {
        mahnung_vermerken($pdo, (int) $rechnung['id'], (int) ($rechnung['reminder_count'] ?? 0));
        log_event(
            $pdo,
            'PAYMENT_REMINDER_SENT',
            'Zahlungserinnerung zu ' . ($vars['nummer'] ?: '#' . $rechnung['id'])
            . ' an ' . $rechnung['empfaenger'] . ' gesendet (Stufe '
            . ((int) ($rechnung['reminder_count'] ?? 0) + 1) . ').'
        );
    }

    return $ergebnis;
}
