<?php
/**
 * Der Papierkorb: was er umfasst, wie lange er haelt, und das Aufraeumen.
 *
 * Herausgeloest aus trash.php, weil der naechtliche Lauf dieselbe Arbeit
 * braucht. Bis dahin raeumte allein die Seite auf, und zwar beim
 * Oeffnen - was bedeutete: in einer Installation, in der niemand den
 * Papierkorb aufschlaegt, blieb Geloeschtes fuer immer liegen, samt der
 * Dateien mit Kundenname und Betrag im Namen. Die Seite verspricht
 * dreissig Tage; gehalten hat sie das nur bei Publikumsverkehr.
 *
 * Die Seite raeumt weiterhin selbst mit auf. Ohne eingerichteten Cron
 * waere sonst gar niemand mehr dafuer zustaendig.
 */

// Wegen datei_pfad_erlaubt(). Solange das nur in trash.php stand, lud
// die Seite es selbst; cron.php tut das nicht, und der Aufruf waere
// dort zur Laufzeit gescheitert. Ein Include laedt, was es braucht.
require_once __DIR__ . '/file_access.php';
/**
 * Die Bereiche des Papierkorbs. Nur Daten, deren Verlust wehtut —
 * Logs, Meilensteine, Kommentare und Dateien werden weiterhin sofort
 * gelöscht, dort wäre ein Papierkorb nur Ballast.
 */
const PAPIERKORB = [
    'contacts' => [
        'label'  => 'Kontakte',
        'icon'   => 'bi-people-fill',
        'titel'  => 'name',
        'zusatz' => "TRIM(CONCAT_WS(' · ', NULLIF(company,''), NULLIF(email,'')))",
    ],
    'tasks' => [
        'label'  => 'Projekte',
        'icon'   => 'bi-check2-square',
        'titel'  => 'title',
        'zusatz' => "TRIM(CONCAT_WS(' · ', status, NULLIF(category,'')))",
    ],
    'finances' => [
        'label'  => 'Finanzen',
        'icon'   => 'bi-currency-euro',
        'titel'  => "COALESCE(NULLIF(invoice_number,''), title)",
        'zusatz' => "TRIM(CONCAT_WS(' · ', CONCAT(FORMAT(amount, 2, 'de_DE'), ' €'), status))",
    ],
    'quotes' => [
        'label'  => 'Angebote',
        'icon'   => 'bi-file-earmark-text',
        'titel'  => "CONCAT(quote_number, ' · ', COALESCE(NULLIF(subject,''), 'Angebot'))",
        'zusatz' => "TRIM(CONCAT_WS(' · ', CONCAT(FORMAT(total_amount, 2, 'de_DE'), ' €'), status))",
    ],
];

const AUFBEWAHRUNG_TAGE = 30;

/**
 * Spalten, die auf eine hochgeladene oder erzeugte Datei zeigen.
 *
 * Bis hierher loeschte der Papierkorb ausschliesslich Datenbankzeilen.
 * Die Dateien blieben liegen - dauerhaft, mit Kundenname und Betrag im
 * Dateinamen ("Rechnung_RE-2026-014.pdf"), in einem Verzeichnis, das
 * niemand mehr ansieht. Zusammen mit dem Papierkorb war das nicht
 * gedacht: finances.php entfernte das PDF frueher schon beim
 * Verschieben in den Papierkorb, was den Eintrag nach dem
 * Wiederherstellen ohne Datei zuruecklaesst. Beides gehoert
 * zusammen - die Datei geht mit dem Datensatz, und zwar endgueltig
 * mit dem endgueltigen Loeschen.
 *
 * Projektdateien und Wiki-Anhaenge stehen hier nicht: sie haengen ueber
 * ON DELETE CASCADE an ihrem Projekt bzw. Artikel und haben keinen
 * eigenen Papierkorb.
 */
const PAPIERKORB_DATEIEN = [
    'finances' => ['invoice_pdf_path', 'receipt_path'],
    'quotes'   => ['quote_pdf_path'],
];

/**
 * Entfernt die Dateien der genannten Zeilen von der Platte.
 *
 * Vor dem DELETE aufzurufen - danach sind die Pfade nicht mehr zu
 * erfahren. datei_pfad_erlaubt() prueft mit: der Wert kommt zwar aus
 * der Datenbank, aber ein Pfad, der aus uploads/ herausfuehrt, waere
 * auch dann falsch, wenn ihn niemand angegriffen hat.
 *
 * @param string $bedingung SQL-Bedingung ohne "WHERE"
 * @param array  $werte     Werte dazu
 * @return int Anzahl entfernter Dateien
 */
function papierkorb_dateien_entfernen(PDO $pdo, string $tabelle, string $bedingung, array $werte): int
{
    if (!isset(PAPIERKORB_DATEIEN[$tabelle])) {
        return 0;
    }
    $spalten = PAPIERKORB_DATEIEN[$tabelle];

    // Der Tabellenname stammt aus PAPIERKORB, die Spalten aus der
    // Konstante darueber - beide aus dem Code, nie aus einer Eingabe.
    $stmt = $pdo->prepare(
        'SELECT ' . implode(', ', $spalten) . " FROM $tabelle WHERE $bedingung"
    );
    $stmt->execute($werte);

    $weg = 0;
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $zeile) {
        foreach ($spalten as $spalte) {
            $pfad = (string) ($zeile[$spalte] ?? '');
            if ($pfad === '' || !datei_pfad_erlaubt($pfad)) {
                continue;
            }
            // dirname(__DIR__): diese Datei liegt in includes/, die
            // Pfade in der Datenbank sind relativ zur Wurzel.
            $voll = dirname(__DIR__) . '/' . $pfad;
            if (is_file($voll) && @unlink($voll)) {
                $weg++;
            }
        }
    }
    return $weg;
}

/**
 * Entfernt alles, was laenger als AUFBEWAHRUNG_TAGE im Papierkorb liegt.
 *
 * @return array{0:int,1:int} [Zeilen, Dateien]
 */
function papierkorb_verfallen(PDO $pdo): array
{
    $geraeumt    = 0;
    $dateien_weg = 0;

    foreach (array_keys(PAPIERKORB) as $tabelle) {
        $abgelaufen = 'deleted_at IS NOT NULL'
                    . ' AND deleted_at < DATE_SUB(NOW(), INTERVAL ' . AUFBEWAHRUNG_TAGE . ' DAY)';

        // Erst die Dateien, dann die Zeilen: danach waeren die Pfade
        // nicht mehr zu erfahren.
        $dateien_weg += papierkorb_dateien_entfernen($pdo, $tabelle, $abgelaufen, []);

        $st = $pdo->prepare("DELETE FROM $tabelle WHERE $abgelaufen");
        $st->execute();
        $geraeumt += $st->rowCount();
    }

    return [$geraeumt, $dateien_weg];
}