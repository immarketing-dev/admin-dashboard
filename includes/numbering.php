<?php
/**
 * Vergabe von Rechnungs- und Angebotsnummern.
 *
 * Bisher stand an fünf Stellen dieselbe Zeile: SELECT COUNT(*) für das
 * laufende Jahr, plus eins. Das zählt Zeilen statt Nummern — löscht man
 * eine Rechnung, vergibt die nächste dieselbe Nummer erneut, und weil auf
 * der Nummernspalte kein eindeutiger Index lag, fiel das niemandem auf.
 * §14 UStG verlangt für Ausgangsrechnungen eine fortlaufende, einmalig
 * vergebene Nummer.
 *
 * Stattdessen wird das Maximum der bereits vergebenen Nummer genommen. Der
 * eindeutige Index auf finances.invoice_number (Migration 3) fängt den
 * Rest ab: vergeben zwei gleichzeitige Anfragen dieselbe Nummer, schlägt
 * die zweite Einfügung fehl, statt still eine Dublette anzulegen.
 */

/** Höchste bereits vergebene laufende Nummer eines Präfixes im Jahr. */
function highest_number(PDO $pdo, string $table, string $column, string $prefix, string $year): int
{
    // Tabellen- und Spaltenname stammen ausschließlich aus dem Code dieser
    // Datei, nie aus einer Eingabe – der Wert im LIKE ist gebunden.
    $sql = "SELECT MAX(CAST(SUBSTRING_INDEX($column, '-', -1) AS UNSIGNED))"
         . " FROM $table WHERE $column LIKE ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$prefix . '-' . $year . '-%']);
    return (int) $stmt->fetchColumn();
}

/** Nächste freie Rechnungsnummer, Format RE-JJJJ-NNN. */
function next_invoice_number(PDO $pdo, ?string $year = null): string
{
    $year = $year ?? date('Y');
    $next = highest_number($pdo, 'finances', 'invoice_number', 'RE', $year) + 1;
    return 'RE-' . $year . '-' . str_pad((string) $next, 3, '0', STR_PAD_LEFT);
}

/** Nächste freie Angebotsnummer, Format ANG-JJJJ-NNN. */
function next_quote_number(PDO $pdo, ?string $year = null): string
{
    $year = $year ?? date('Y');
    $next = highest_number($pdo, 'quotes', 'quote_number', 'ANG', $year) + 1;
    return 'ANG-' . $year . '-' . str_pad((string) $next, 3, '0', STR_PAD_LEFT);
}
