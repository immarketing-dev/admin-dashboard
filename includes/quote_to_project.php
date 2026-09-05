<?php
/**
 * Aus einem angenommenen Angebot ein Projekt machen.
 *
 * Es gab "Angebot zu Rechnung", aber nicht "Angebot zu Projekt". Wird
 * ein Angebot angenommen, tippt man die Positionen, die man gerade
 * beschrieben hat, ein zweites Mal ab — als Meilensteine.
 *
 * Dieselbe Mechanik wie die vorhandene Umwandlung in eine Rechnung, nur
 * ein anderes Ziel: Kontakt, Betreff und Positionen wandern mit, aus
 * jeder Position wird ein Meilenstein.
 *
 * Was hier NICHT passiert: das Angebot auf "Angenommen" setzen. Ein
 * Angebot kann angenommen sein, ohne dass man dafür ein Projekt
 * anlegen will (eine einzelne Lieferung, eine Pauschale), und umgekehrt
 * fängt Arbeit manchmal an, bevor die Zusage schriftlich vorliegt. Die
 * beiden Schritte gehören deshalb nebeneinander, nicht ineinander.
 */

require_once __DIR__ . '/invoice_items.php';
require_once __DIR__ . '/logging.php';

/**
 * Wurde aus diesem Angebot schon ein Projekt?
 *
 * Gibt die Projektkennung zurück, oder null. Grundlage für den Knopf:
 * ein zweites Projekt aus demselben Angebot ist fast immer ein
 * Versehen, und dann steht dieselbe Arbeit doppelt in der Liste.
 */
function angebot_projekt(PDO $pdo, int $quote_id): ?int
{
    $stmt = $pdo->prepare(
        'SELECT q.converted_task_id
           FROM quotes q
           JOIN tasks t ON t.id = q.converted_task_id AND t.deleted_at IS NULL
          WHERE q.id = ? AND q.deleted_at IS NULL'
    );
    $stmt->execute([$quote_id]);
    $id = $stmt->fetchColumn();

    // Ein gelöschtes Projekt zählt nicht: der JOIN filtert es heraus,
    // und dann darf aus dem Angebot wieder eines entstehen.
    return $id ? (int) $id : null;
}

/**
 * Der Titel des künftigen Projekts.
 *
 * Der Betreff des Angebots, sonst dessen Nummer. Nicht der Kundenname:
 * ein Kunde kann mehrere Projekte haben, und "Müller GmbH" dreimal in
 * der Liste hilft niemandem.
 */
function projekt_titel_aus_angebot(array $angebot): string
{
    $betreff = trim((string) ($angebot['subject'] ?? ''));
    if ($betreff !== '') {
        return mb_substr($betreff, 0, 255);
    }
    return 'Projekt zu ' . ($angebot['quote_number'] ?? 'Angebot');
}

/**
 * Formt die Positionen eines Angebots zu Meilensteinen.
 *
 * Eine Position je Meilenstein, in derselben Reihenfolge. Die Menge
 * kommt mit in den Titel, wenn sie nicht eins ist — "Schulung" und
 * "Schulung (3 Tage)" sind verschiedene Zusagen, und beim Abhaken will
 * man wissen, worauf man sich eingelassen hat.
 *
 * Mehrzeilige Beschreibungen werden auf ihre erste Zeile gekürzt: das
 * Feld ist ein Titel, kein Textblock. Die vollständige Fassung bleibt
 * im Angebot stehen.
 *
 * @return array<int, string>
 */
function meilensteine_aus_positionen(array $positionen): array
{
    $titel = [];

    foreach ($positionen as $p) {
        $text = trim((string) ($p['desc'] ?? ''));
        if ($text === '') {
            continue;
        }

        $zeilen = preg_split('/\R/', $text) ?: [$text];
        $text   = trim((string) $zeilen[0]);
        if ($text === '') {
            continue;
        }

        $menge = (float) ($p['qty'] ?? 1);
        $einheit = trim((string) ($p['unit'] ?? ''));

        if (abs($menge - 1.0) > 0.0001) {
            // Ganze Zahlen ohne Nachkommastellen: "3 Tage", nicht
            // "3,00 Tage".
            $mengentext = abs($menge - round($menge)) < 0.0001
                ? (string) (int) round($menge)
                : number_format($menge, 2, ',', '.');
            $text .= ' (' . $mengentext . ($einheit !== '' ? ' ' . $einheit : '') . ')';
        }

        $titel[] = mb_substr($text, 0, 255);
    }

    return $titel;
}

/**
 * Legt das Projekt an und gibt seine Kennung zurück.
 *
 * Der Hauptansprechpartner wird zugleich Mitglied (task_contacts) —
 * seit Migration 5 füllt das Portal seine Listen darüber, und ein
 * Projekt ohne Mitglied wäre für den Kunden unsichtbar.
 *
 * @return int|null Kennung des Projekts, null wenn das Angebot fehlt
 */
function projekt_aus_angebot(PDO $pdo, int $quote_id): ?int
{
    $stmt = $pdo->prepare(
        'SELECT id, quote_number, subject, intro_text, contact_id, items, notes
           FROM quotes WHERE id = ? AND deleted_at IS NULL'
    );
    $stmt->execute([$quote_id]);
    $angebot = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$angebot) {
        return null;
    }

    // Die Beschreibung des Projekts: der Einleitungstext des Angebots,
    // sonst dessen Anmerkungen. Beides ist das, was man dem Kunden
    // ohnehin geschrieben hat.
    $beschreibung = trim((string) ($angebot['intro_text'] ?? ''));
    if ($beschreibung === '') {
        $beschreibung = trim((string) ($angebot['notes'] ?? ''));
    }
    $herkunft = 'Aus Angebot ' . $angebot['quote_number'] . '.';
    $beschreibung = $beschreibung !== '' ? $beschreibung . "\n\n" . $herkunft : $herkunft;

    $pdo->prepare(
        "INSERT INTO tasks (title, description, status, contact_id, start_date)
         VALUES (?, ?, 'Offen', ?, CURDATE())"
    )->execute([
        projekt_titel_aus_angebot($angebot),
        $beschreibung,
        $angebot['contact_id'] ?: null,
    ]);

    $task_id = (int) $pdo->lastInsertId();

    // Der Hauptansprechpartner wird Mitglied - sonst sieht der Kunde
    // sein eigenes Projekt im Portal nicht.
    if (!empty($angebot['contact_id'])) {
        $pdo->prepare(
            "INSERT INTO task_contacts (task_id, contact_id, role) VALUES (?, ?, 'owner')"
        )->execute([$task_id, (int) $angebot['contact_id']]);
    }

    $ms = $pdo->prepare('INSERT INTO task_milestones (task_id, title) VALUES (?, ?)');
    $anzahl = 0;
    foreach (meilensteine_aus_positionen(positionen_aus_json($angebot['items'] ?? null)) as $titel) {
        $ms->execute([$task_id, $titel]);
        $anzahl++;
    }

    // Die Verknüpfung zurück: sie verhindert, dass aus demselben
    // Angebot versehentlich ein zweites Projekt entsteht.
    $pdo->prepare('UPDATE quotes SET converted_task_id = ? WHERE id = ?')
        ->execute([$task_id, $quote_id]);

    log_event(
        $pdo,
        'QUOTE_TO_PROJECT',
        'Angebot ' . $angebot['quote_number'] . ' zu Projekt #' . $task_id
        . ' umgewandelt (' . $anzahl . ' Meilenstein(e)).'
    );

    return $task_id;
}
