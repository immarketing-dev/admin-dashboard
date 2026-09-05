<?php
/**
 * Erfasste Zeit in eine Rechnungsposition ueberfuehren.
 *
 * Der Timer in tasks.php haelt seit Langem fest, wie lange an einem
 * Projekt gearbeitet wurde - nur endete das nie auf einer Rechnung.
 * time_entries kannte weder einen Preis noch ein Kennzeichen
 * "abgerechnet": wer abrechnen wollte, zaehlte die Minuten von Hand
 * zusammen, rechnete sie in Stunden um und tippte eine Position.
 *
 * Der gefaehrlichere Teil ist nicht das Rechnen, sondern das Merken.
 * Ohne billed_at taucht dieselbe Stunde beim naechsten Abrechnen wieder
 * auf, und der Kunde zahlt sie zweimal - ein Fehler, der niemandem
 * auffaellt, ausser dem Kunden.
 */

/**
 * Welcher Stundensatz gilt fuer dieses Projekt?
 *
 * Projekt schlaegt Kunde, Kunde schlaegt Voreinstellung. Diese
 * Reihenfolge, damit ein Sonderpreis fuer ein einzelnes Projekt nicht
 * dazu zwingt, den Satz des Kunden zu aendern - was alle seine anderen
 * Projekte falsch machen wuerde.
 */
function stundensatz(PDO $pdo, int $task_id, float $voreinstellung): float
{
    $stmt = $pdo->prepare(
        'SELECT t.hourly_rate AS projekt, c.hourly_rate AS kunde
           FROM tasks t
           LEFT JOIN contacts c ON c.id = t.contact_id
          WHERE t.id = ?'
    );
    $stmt->execute([$task_id]);
    $zeile = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$zeile) {
        return $voreinstellung;
    }
    // Auf null pruefen, nicht auf leer: ein Satz von 0,00 ist eine
    // Aussage ("dieses Projekt wird nicht berechnet") und darf nicht
    // stillschweigend durch den Kundensatz ersetzt werden.
    if ($zeile['projekt'] !== null) {
        return (float) $zeile['projekt'];
    }
    if ($zeile['kunde'] !== null) {
        return (float) $zeile['kunde'];
    }
    return $voreinstellung;
}

/**
 * Die noch nicht abgerechneten Zeiten eines Projekts, aelteste zuerst.
 *
 * @return array<int, array{id: int, duration_minutes: int, note: ?string, created_at: string}>
 */
function offene_zeiten(PDO $pdo, int $task_id): array
{
    $stmt = $pdo->prepare(
        'SELECT id, duration_minutes, note, created_at
           FROM time_entries
          WHERE task_id = ? AND billed_at IS NULL
          ORDER BY created_at ASC, id ASC'
    );
    $stmt->execute([$task_id]);

    $aus = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $z) {
        $aus[] = [
            'id'               => (int) $z['id'],
            'duration_minutes' => (int) $z['duration_minutes'],
            'note'             => $z['note'],
            'created_at'       => (string) $z['created_at'],
        ];
    }
    return $aus;
}

/** Summe der Minuten einer Zeitliste. */
function zeiten_minuten(array $eintraege): int
{
    $summe = 0;
    foreach ($eintraege as $e) {
        $summe += (int) ($e['duration_minutes'] ?? 0);
    }
    return $summe;
}

/**
 * Minuten als Stundenangabe, wie sie auf einer Rechnung stünde.
 *
 * Zwei Nachkommastellen, damit Stundenzahl mal Satz den ausgewiesenen
 * Betrag ergibt - dieselbe Rundung wie in zeiten_als_position().
 */
function stunden(int $minuten): float
{
    return round($minuten / 60, 2);
}

/**
 * Formt die Zeiten zu EINER Rechnungsposition.
 *
 * Bewusst eine und nicht eine je Eintrag: auf der Rechnung steht
 * "Projekt X, 12,50 Std" und nicht eine Liste angefangener
 * Viertelstunden. Die Einzelnachweise bleiben in time_entries und sind
 * ueber invoice_id der Rechnung zugeordnet, falls jemand nachfragt.
 *
 * Die Menge wird auf zwei Stellen gerundet - sonst stuende auf der
 * Rechnung eine Stundenzahl, die sich mit dem Einzelpreis nicht auf den
 * ausgewiesenen Betrag multiplizieren laesst.
 *
 * @return array{desc: string, qty: float, price: float, unit: string}|null
 */
function zeiten_als_position(array $eintraege, float $satz, string $projekttitel): ?array
{
    $minuten = zeiten_minuten($eintraege);
    if ($minuten <= 0) {
        return null;
    }

    return [
        'desc'  => $projekttitel . ' – ' . anzahl_zeiten($eintraege) . ' Zeiterfassung(en), '
                 . 'Zeitraum ' . zeitraum($eintraege),
        'qty'   => round($minuten / 60, 2),
        'price' => $satz,
        'unit'  => 'Std',
    ];
}

/** Wie viele Einzeleintraege stecken darin? */
function anzahl_zeiten(array $eintraege): int
{
    return count($eintraege);
}

/** Von wann bis wann - fuer die Beschreibung auf der Rechnung. */
function zeitraum(array $eintraege): string
{
    $zeiten = [];
    foreach ($eintraege as $e) {
        if (!empty($e['created_at'])) {
            $zeiten[] = strtotime((string) $e['created_at']);
        }
    }
    if (!$zeiten) {
        return date('d.m.Y');
    }
    $von = date('d.m.Y', min($zeiten));
    $bis = date('d.m.Y', max($zeiten));
    return $von === $bis ? $von : "$von – $bis";
}

/**
 * Vermerkt die Zeiten als abgerechnet und haengt sie an die Rechnung.
 *
 * Der Zeitpunkt kommt aus der Datenbank (NOW()), nicht aus PHP: laeuft
 * der Webserver in einer anderen Zeitzone als die Datenbank, stuenden
 * sonst zwei verschiedene Uhrzeiten in derselben Zeile.
 */
function zeiten_abrechnen(PDO $pdo, array $ids, int $invoice_id): void
{
    $ids = array_values(array_filter(array_map('intval', $ids), fn($i) => $i > 0));
    if (!$ids || $invoice_id <= 0) {
        return;
    }

    $platzhalter = implode(',', array_fill(0, count($ids), '?'));

    // "AND billed_at IS NULL" ist die eigentliche Absicherung: zwei
    // gleichzeitige Abrechnungen desselben Projekts koennen so nicht
    // beide dieselben Zeiten fuer sich verbuchen.
    $stmt = $pdo->prepare(
        "UPDATE time_entries
            SET billed_at = NOW(), invoice_id = ?
          WHERE id IN ($platzhalter) AND billed_at IS NULL"
    );
    $stmt->execute(array_merge([$invoice_id], $ids));
}
