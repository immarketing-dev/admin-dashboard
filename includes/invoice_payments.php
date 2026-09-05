<?php
/**
 * Das Zahlungsjournal einer Rechnung.
 *
 * Bis hierher war eine Rechnung entweder offen oder bezahlt. Für eine
 * Anzahlung, eine Rate oder einen um die Bankgebühr gekürzten Betrag gab
 * es keinen Zustand: entweder mahnte der Nachtlauf weiter die volle
 * Summe, oder man setzte auf „Bezahlt" und die Restforderung verschwand
 * aus der Auswertung. Falsch war beides, und auffallen konnte es
 * frühestens beim Jahresabschluss.
 *
 * Die Aufteilung:
 *
 *   - `payments` ist das Journal. Jede Zeile ist ein Geldeingang, den es
 *     wirklich gab, mit Datum und Herkunft.
 *   - `finances.status` bleibt, was es war, wird aber aus dem Journal
 *     abgeleitet: Summe ≥ Betrag heißt „Bezahlt", sonst „Offen" oder
 *     „Überfällig" je nach Fälligkeit.
 *
 * Zwei Quellen für dieselbe Wahrheit sind der sichere Weg in
 * widersprüchliche Zahlen. Deshalb gilt hier: geschrieben wird ins
 * Journal, der Status folgt. Auch der Schalter in der Liste schreibt ins
 * Journal — er legt beim Umstellen auf „Bezahlt" den Restbetrag als
 * Zahlung an und nimmt sie beim Zurücksetzen wieder weg. Diese eine
 * Zeile trägt dafür die Herkunft `status`: sie ist ein Vermerk, kein
 * Beleg, und nur sie darf ein Schalter wieder entfernen.
 *
 * „Storniert" wird nie angefasst. Eine stornierte Rechnung ist keine
 * offene, und sie wird durch eine Zahlung auch keine bezahlte.
 */

/** Herkunft einer Zeile im Journal. */
const ZAHLUNG_QUELLEN = ['manual', 'bank', 'status'];

/**
 * Cent-genau vergleichen.
 *
 * DECIMAL(10,2) kommt als Zeichenkette aus PDO und wird zu float; ein
 * direkter Vergleich zweier Beträge ginge irgendwann daneben. Ein halber
 * Cent ist die Grenze, unterhalb derer zwei Beträge derselbe sind.
 */
const ZAHLUNG_EPSILON = 0.005;

/**
 * Der offene Rest als SQL-Ausdruck, für Abfragen, die Rechnungen listen.
 *
 * Als Konstante und nicht an fünf Stellen abgeschrieben: der Ausdruck
 * entscheidet, was gemahnt und was als offener Posten gezählt wird.
 * Erwartet die finances-Zeile als `f`.
 */
const RECHNUNG_OFFEN_SQL =
    "(f.amount - COALESCE((SELECT SUM(p.amount) FROM payments p WHERE p.finance_id = f.id), 0))";

/** Alle Zahlungen zu einer Rechnung, jüngste zuerst. */
function zahlungen_einer_rechnung(PDO $pdo, int $finanz_id): array
{
    $stmt = $pdo->prepare(
        'SELECT id, amount, paid_at, note, source, created_at
           FROM payments
          WHERE finance_id = ?
          ORDER BY paid_at DESC, id DESC'
    );
    $stmt->execute([$finanz_id]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/** Was auf eine Rechnung bereits eingegangen ist. */
function zahlung_summe(PDO $pdo, int $finanz_id): float
{
    $stmt = $pdo->prepare('SELECT COALESCE(SUM(amount), 0) FROM payments WHERE finance_id = ?');
    $stmt->execute([$finanz_id]);
    return round((float) $stmt->fetchColumn(), 2);
}

/**
 * Die Summen zu mehreren Rechnungen auf einmal.
 *
 * Für Listen: dreißig Rechnungen sollen nicht dreißig Abfragen kosten.
 *
 * @param int[] $ids
 * @return array<int, float> finance_id => Summe
 */
function zahlung_summen(PDO $pdo, array $ids): array
{
    $ids = array_values(array_unique(array_map('intval', $ids)));
    if ($ids === []) return [];

    $platzhalter = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare(
        "SELECT finance_id, SUM(amount) AS summe
           FROM payments
          WHERE finance_id IN ($platzhalter)
          GROUP BY finance_id"
    );
    $stmt->execute($ids);

    $aus = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $z) {
        $aus[(int) $z['finance_id']] = round((float) $z['summe'], 2);
    }
    return $aus;
}

/**
 * Der offene Rest. Nie negativ gerundet dargestellt — eine Überzahlung
 * ist kein negativer offener Posten, sondern eine Sache für sich.
 */
function rechnung_offen(float $betrag, float $bezahlt): float
{
    $rest = round($betrag - $bezahlt, 2);
    return $rest < ZAHLUNG_EPSILON ? 0.0 : $rest;
}

/** Ist die Rechnung damit vollständig beglichen? */
function rechnung_beglichen(float $betrag, float $bezahlt): bool
{
    return $bezahlt >= $betrag - ZAHLUNG_EPSILON;
}

/**
 * Setzt den Status einer Rechnung auf das, was das Journal hergibt.
 *
 * Wird nach jeder Änderung am Journal aufgerufen. Ausgaben und
 * stornierte Rechnungen bleiben unberührt.
 *
 * @return string der Status, der jetzt gilt (leer, wenn es die
 *                Rechnung nicht gibt oder sie storniert ist)
 */
function rechnung_status_ableiten(PDO $pdo, int $finanz_id, ?string $heute = null): string
{
    $heute = $heute ?? date('Y-m-d');

    $stmt = $pdo->prepare(
        "SELECT amount, due_date, status FROM finances
          WHERE id = ? AND deleted_at IS NULL AND type = 'INCOME'"
    );
    $stmt->execute([$finanz_id]);
    $r = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$r || $r['status'] === 'Storniert') return '';

    $bezahlt = zahlung_summe($pdo, $finanz_id);

    if (rechnung_beglichen((float) $r['amount'], $bezahlt)) {
        $neu = 'Bezahlt';
    } elseif (!empty($r['due_date']) && $r['due_date'] < $heute) {
        $neu = 'Überfällig';
    } else {
        $neu = 'Offen';
    }

    if ($neu !== $r['status']) {
        $pdo->prepare('UPDATE finances SET status = ? WHERE id = ?')->execute([$neu, $finanz_id]);
    }
    return $neu;
}

/**
 * Trägt einen Zahlungseingang ein.
 *
 * Der Betrag muss positiv sein; eine Rückzahlung ist kein
 * Zahlungseingang mit Minus, sondern eine Gutschrift, und die gehört
 * nicht in dieses Journal. Über den offenen Rest hinaus darf gebucht
 * werden: eine Überzahlung kommt vor, und sie zu verschweigen wäre
 * schlimmer, als sie anzuzeigen.
 *
 * @return int die Nummer der neuen Zeile, oder 0 wenn nichts gebucht wurde
 */
function zahlung_erfassen(
    PDO $pdo,
    int $finanz_id,
    float $betrag,
    ?string $datum = null,
    string $notiz = '',
    string $quelle = 'manual'
): int {
    $betrag = round($betrag, 2);
    if ($betrag < 0.01) return 0;
    if (!in_array($quelle, ZAHLUNG_QUELLEN, true)) $quelle = 'manual';

    // Nur zu einer Ausgangsrechnung, die es noch gibt.
    $stmt = $pdo->prepare(
        "SELECT status FROM finances
          WHERE id = ? AND deleted_at IS NULL AND type = 'INCOME'"
    );
    $stmt->execute([$finanz_id]);
    $status = $stmt->fetchColumn();
    if ($status === false || $status === 'Storniert') return 0;

    $pdo->prepare(
        'INSERT INTO payments (finance_id, amount, paid_at, note, source) VALUES (?, ?, ?, ?, ?)'
    )->execute([
        $finanz_id,
        $betrag,
        $datum !== null && $datum !== '' ? $datum : date('Y-m-d'),
        mb_substr(trim($notiz), 0, 255) ?: null,
        $quelle,
    ]);

    $id = (int) $pdo->lastInsertId();
    rechnung_status_ableiten($pdo, $finanz_id);
    return $id;
}

/**
 * Nimmt eine Zahlung wieder heraus.
 *
 * @return int die Rechnung, zu der sie gehörte, oder 0
 */
function zahlung_entfernen(PDO $pdo, int $zahlung_id): int
{
    $stmt = $pdo->prepare('SELECT finance_id FROM payments WHERE id = ?');
    $stmt->execute([$zahlung_id]);
    $finanz_id = (int) $stmt->fetchColumn();
    if ($finanz_id === 0) return 0;

    $pdo->prepare('DELETE FROM payments WHERE id = ?')->execute([$zahlung_id]);
    rechnung_status_ableiten($pdo, $finanz_id);
    return $finanz_id;
}

/**
 * Der Schalter in der Liste.
 *
 * Er setzt den Status nicht mehr selbst, sondern schreibt ins Journal
 * und lässt ihn sich daraus ergeben:
 *
 *   - auf „Bezahlt": der offene Rest wird als Zahlung von heute
 *     eingetragen, Herkunft `status`.
 *   - zurück auf „Offen" oder „Überfällig": die Zeilen mit Herkunft
 *     `status` fallen weg. Eine erfasste Teilzahlung und eine über den
 *     Kontoauszug zugeordnete bleiben stehen — die hat es gegeben,
 *     unabhängig davon, wie ein Schalter steht.
 *   - „Storniert" ist der einzige Fall, in dem der Status direkt gesetzt
 *     wird. Er sagt nichts über Geld, sondern über die Rechnung.
 *
 * @return string der Status, der danach gilt
 */
function rechnung_status_setzen(PDO $pdo, int $finanz_id, string $status): string
{
    $stmt = $pdo->prepare(
        "SELECT amount, type FROM finances WHERE id = ? AND deleted_at IS NULL"
    );
    $stmt->execute([$finanz_id]);
    $r = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$r) return '';

    // Ausgaben haben kein Journal - dort bleibt der Schalter, was er war.
    if ($r['type'] !== 'INCOME') {
        $pdo->prepare('UPDATE finances SET status = ? WHERE id = ?')->execute([$status, $finanz_id]);
        return $status;
    }

    if ($status === 'Storniert') {
        $pdo->prepare('UPDATE finances SET status = ? WHERE id = ?')->execute([$status, $finanz_id]);
        return $status;
    }

    if ($status === 'Bezahlt') {
        $offen = rechnung_offen((float) $r['amount'], zahlung_summe($pdo, $finanz_id));
        if ($offen > 0) {
            zahlung_erfassen($pdo, $finanz_id, $offen, date('Y-m-d'),
                'Über den Schalter in der Liste als bezahlt gesetzt.', 'status');
        }
    } else {
        $pdo->prepare("DELETE FROM payments WHERE finance_id = ? AND source = 'status'")
            ->execute([$finanz_id]);
    }

    // Nach dem Stornieren wieder auf „Offen": der Status stammt dann
    // nicht mehr aus dem Journal, also erst zurücksetzen, dann ableiten.
    $pdo->prepare("UPDATE finances SET status = 'Offen' WHERE id = ? AND status = 'Storniert'")
        ->execute([$finanz_id]);

    return rechnung_status_ableiten($pdo, $finanz_id);
}
