<?php
/**
 * Das Budget eines Projekts.
 *
 * Ein Projekt hatte einen Stundensatz und erfasste Zeiten, aber keine
 * Grenze. Dass ein Projekt sich nicht mehr rechnet, fiel deshalb erst
 * beim Schreiben der Rechnung auf - also dann, wenn die Arbeit schon
 * getan ist und die Wahl nur noch lautet, sie zu verschenken oder eine
 * unangenehme Unterhaltung zu führen.
 *
 * Das Budget steht in Euro und nicht in Stunden. Ein Kunde vereinbart
 * einen Preis, keine Stundenzahl; die Stunden ergeben sich daraus über
 * den Satz, umgekehrt wäre es eine Rechnung mit krummem Ergebnis
 * (2.000 € zu 75 €/h sind 26,67 Stunden - eine Zahl, die niemand
 * vereinbart hat).
 *
 * Verglichen wird gegen den Wert der erfassten Zeit, nicht gegen das,
 * was schon abgerechnet ist: die Frage ist, ob die Arbeit den Preis
 * übersteigt, und nicht, ob eine Rechnung geschrieben wurde.
 *
 * Diese Datei rechnet nur. Sie fasst weder Datenbank noch Einstellungen
 * an und ist deshalb ohne beides prüfbar.
 */

require_once __DIR__ . '/time_billing.php';

/** Ab hier wird gewarnt: vier Fünftel sind verbraucht. */
const BUDGET_WARNSCHWELLE = 80;

/**
 * Wie weit ein Budget aufgebraucht ist.
 *
 * @param float $budget Vereinbarter Betrag. 0 oder kleiner heißt „kein
 *                      Budget gesetzt" - dann gibt es nichts zu melden.
 * @param float $wert   Wert der erfassten Zeit.
 *
 * @return array{gesetzt:bool, prozent:int, rest:float, stufe:string}
 *         stufe ist 'ok', 'warnung' oder 'ueber'.
 */
function budget_stand(float $budget, float $wert): array
{
    if ($budget <= 0) {
        return ['gesetzt' => false, 'prozent' => 0, 'rest' => 0.0, 'stufe' => 'ok'];
    }

    // Nicht gerundet vergleichen und dann gerundet anzeigen: bei einem
    // Budget von 1.000 € und 999,60 € Arbeit stünden sonst 100 % da,
    // obwohl noch etwas übrig ist.
    $anteil  = $wert / $budget;
    $prozent = (int) floor($anteil * 100);
    $rest    = round($budget - $wert, 2);

    if ($wert > $budget) {
        $stufe = 'ueber';
    } elseif ($prozent >= BUDGET_WARNSCHWELLE) {
        $stufe = 'warnung';
    } else {
        $stufe = 'ok';
    }

    return ['gesetzt' => true, 'prozent' => $prozent, 'rest' => $rest, 'stufe' => $stufe];
}

/**
 * Die Bootstrap-Klasse zur Stufe.
 *
 * Als Funktion und nicht als Bedingung im Markup: der Balken auf der
 * Projektkarte und das Abzeichen in der Auswertung sollen dieselbe Farbe
 * für denselben Zustand zeigen.
 */
function budget_farbe(string $stufe): string
{
    return match ($stufe) {
        'ueber'   => 'bg-danger',
        'warnung' => 'bg-warning',
        default   => 'bg-success',
    };
}

/**
 * Der Wert der erfassten Zeit je Projekt.
 *
 * Der Stundensatz kommt in derselben Reihenfolge zustande wie beim
 * Abrechnen - Projekt schlägt Kunde schlägt Voreinstellung -, aber in
 * einer Abfrage statt in einer je Projekt.
 *
 * @return array<int, array{minuten:int, satz:float, wert:float}>
 */
function budget_verbrauch_je_projekt(PDO $pdo, float $standardsatz): array
{
    $stmt = $pdo->query(
        "SELECT t.id,
                t.hourly_rate AS satz_projekt,
                c.hourly_rate AS satz_kunde,
                COALESCE(SUM(te.duration_minutes), 0) AS minuten
           FROM tasks t
           LEFT JOIN contacts c ON c.id = t.contact_id AND c.deleted_at IS NULL
           LEFT JOIN time_entries te ON te.task_id = t.id
          WHERE t.deleted_at IS NULL
          GROUP BY t.id, t.hourly_rate, c.hourly_rate"
    );

    $aus = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $z) {
        // Auf null prüfen, nicht auf leer: ein Satz von 0,00 ist eine
        // Aussage ("wird nicht berechnet") und keine fehlende Angabe.
        $satz = $z['satz_projekt'] !== null
            ? (float) $z['satz_projekt']
            : ($z['satz_kunde'] !== null ? (float) $z['satz_kunde'] : $standardsatz);

        $minuten = (int) $z['minuten'];
        $aus[(int) $z['id']] = [
            'minuten' => $minuten,
            'satz'    => $satz,
            'wert'    => round(stunden($minuten) * $satz, 2),
        ];
    }
    return $aus;
}

/**
 * Liest einen eingegebenen Betrag.
 *
 * Leer heißt „kein Budget" und wird zu null - nicht zu 0,00, denn das
 * wäre die Aussage „dieses Projekt darf nichts kosten".
 */
function budget_eingabe(?string $roh): ?float
{
    $roh = trim((string) $roh);
    if ($roh === '') return null;

    $wert = (float) str_replace(',', '.', $roh);
    return $wert > 0 ? round($wert, 2) : null;
}
