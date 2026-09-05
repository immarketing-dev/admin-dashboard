<?php
/**
 * Aus einem Angebot eine Rechnung machen.
 *
 * Die Umwandlung selbst gibt es seit Langem - sie stand als Block mitten
 * in finances.php. Zwei Dinge fehlten ihr, und beide fielen erst auf,
 * als daneben dieselbe Umwandlung in ein Projekt entstand:
 *
 * 1. Ein Riegel gegen die zweite Sendung. Die Schwester prüft vor dem
 *    Anlegen, ob aus dem Angebot schon ein Projekt geworden ist, mit der
 *    Begründung, ein Doppelklick oder eine zurückgeblätterte Seite dürfe
 *    kein zweites anlegen. Für die Rechnung galt dasselbe, geprüft wurde
 *    es nicht - nur der Knopf verschwand, und ein Knopf ist keine
 *    Bedingung. Eine zweite Rechnung über dieselbe Leistung, mit eigener
 *    Nummer, ist teurer als jedes doppelte Projekt.
 *
 * 2. Der übliche Weg war versperrt. Der Knopf zeigte sich nur, solange
 *    das Angebot NICHT angenommen war - und die Umwandlung selbst setzte
 *    es auf „Angenommen". Sagt der Kunde im Portal zu, ist das Angebot
 *    angenommen, und genau dann verschwand der Knopf, mit dem man ihm
 *    die Rechnung dazu schreibt. Der häufigste Ablauf war der einzige,
 *    der nicht ging.
 *
 * Seitdem entscheidet nicht der Zustand über den Knopf, sondern die
 * Frage, ob es die Rechnung schon gibt - dieselbe Regel wie beim
 * Projekt, und sie steht in quotes.converted_invoice_id.
 *
 * Das PDF wird hier nicht erzeugt. Es entsteht in finances.php mit FPDF
 * und der dortigen Layoutfunktion; diese Datei bekommt den fertigen Pfad
 * gereicht und bleibt dadurch ohne Datei- und Layoutabhängigkeiten
 * prüfbar.
 */

require_once __DIR__ . '/invoice_items.php';

/** Zahlungsziel in Tagen, wenn nichts anderes gesetzt ist. */
const RECHNUNG_ZAHLUNGSZIEL_TAGE = 14;

/**
 * Wurde aus diesem Angebot schon eine Rechnung?
 *
 * Gibt die Kennung des Finanzeintrags zurück, oder null. Eine gelöschte
 * Rechnung zählt nicht: der JOIN filtert sie heraus, und dann darf aus
 * dem Angebot wieder eine entstehen.
 */
function angebot_rechnung(PDO $pdo, int $quote_id): ?int
{
    $zeile = angebot_rechnung_zeile($pdo, $quote_id);
    return $zeile === null ? null : (int) $zeile['id'];
}

/**
 * Dasselbe, aber mit der Rechnungsnummer.
 *
 * Für den Verweis in der Angebotsliste: die Finanzliste sucht nach
 * Nummern und nicht nach Kennungen, ein Link auf die Kennung führte also
 * nirgendwohin.
 *
 * @return array{id:int, invoice_number:string}|null
 */
function angebot_rechnung_zeile(PDO $pdo, int $quote_id): ?array
{
    $stmt = $pdo->prepare(
        "SELECT f.id, COALESCE(f.invoice_number, '') AS invoice_number
           FROM quotes q
           JOIN finances f ON f.id = q.converted_invoice_id AND f.deleted_at IS NULL
          WHERE q.id = ? AND q.deleted_at IS NULL"
    );
    $stmt->execute([$quote_id]);
    $zeile = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$zeile) return null;

    return ['id' => (int) $zeile['id'], 'invoice_number' => (string) $zeile['invoice_number']];
}

/**
 * Der Name, auf den die Rechnung ausgestellt wird.
 *
 * Der freie Name des Angebots geht vor: steht er dort, war das eine
 * bewusste Angabe. Sonst der Kontakt. Ist beides leer, bleibt das Feld
 * leer - ein Platzhalter wie „Unbekannt" landete sonst auf einer
 * Rechnung, und dort hat er nichts zu suchen.
 */
function rechnungsempfaenger_aus_angebot(array $angebot): ?string
{
    foreach (['custom_name', 'c_name', 'kunde'] as $feld) {
        $wert = trim((string) ($angebot[$feld] ?? ''));
        if ($wert !== '') return $wert;
    }
    return null;
}

/**
 * Legt die Rechnung an und vermerkt sie am Angebot.
 *
 * Positionen, Steuerart und Notizen wandern mit. Netto und Steuer werden
 * neu gerechnet statt aus dem Angebot übernommen: die Steuerart kann sich
 * seit dem Angebot geändert haben, und dann stimmte die übernommene
 * Aufteilung nicht mehr zu ihr.
 *
 * Das Angebot geht auf „Angenommen" - wer eine Rechnung dazu schreibt,
 * hat den Auftrag.
 *
 * @param string $pdf_pfad Pfad des erzeugten Rechnungs-PDFs, oder ''
 * @return int|null Kennung der Rechnung, null wenn das Angebot fehlt
 */
function rechnung_aus_angebot(
    PDO $pdo,
    int $quote_id,
    string $nummer,
    string $pdf_pfad = '',
    ?string $heute = null,
    int $zahlungsziel = RECHNUNG_ZAHLUNGSZIEL_TAGE
): ?int {
    $stmt = $pdo->prepare(
        "SELECT q.*, c.name AS c_name
           FROM quotes q
           LEFT JOIN contacts c ON c.id = q.contact_id AND c.deleted_at IS NULL
          WHERE q.id = ? AND q.deleted_at IS NULL"
    );
    $stmt->execute([$quote_id]);
    $angebot = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$angebot) return null;

    // Datumsrechnung in PHP und nicht als CURDATE()/DATE_ADD in SQL:
    // dieselbe Anweisung läuft dann auf MySQL wie auf dem Spiegel, gegen
    // den die Tests laufen.
    $heute    = $heute ?? date('Y-m-d');
    $faellig  = date('Y-m-d', strtotime($heute . ' +' . max(0, $zahlungsziel) . ' days'));

    $steuerart = (string) ($angebot['tax_type'] ?? 'kleinunternehmer');
    $positionen = positionen_aus_json($angebot['items'] ?? null);
    $summen     = positionen_summen($positionen, $steuerart);

    $pdo->prepare(
        "INSERT INTO finances
            (type, title, invoice_number, contact_id, custom_name, amount, status,
             record_date, due_date, notes, invoice_pdf_path, is_recurring,
             items, tax_type, net_amount, tax_amount)
         VALUES ('INCOME', ?, ?, ?, ?, ?, 'Offen', ?, ?, ?, ?, 0, ?, ?, ?, ?)"
    )->execute([
        $nummer,
        $nummer,
        $angebot['contact_id'] ?: null,
        rechnungsempfaenger_aus_angebot($angebot),
        $angebot['total_amount'],
        $heute,
        $faellig,
        $angebot['notes'],
        $pdf_pfad !== '' ? $pdf_pfad : null,
        json_encode($positionen),
        $steuerart,
        $summen['netto'],
        $summen['steuer'],
    ]);

    $rechnung_id = (int) $pdo->lastInsertId();

    $pdo->prepare("UPDATE quotes SET status = 'Angenommen', converted_invoice_id = ? WHERE id = ?")
        ->execute([$rechnung_id, $quote_id]);

    return $rechnung_id;
}
