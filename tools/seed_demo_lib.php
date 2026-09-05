<?php
/**
 * Helfer für die Demodaten.
 *
 * Getrennt von tools/seed_demo.php, damit tests/test_seed_demo.php den
 * Seed gegen eine SQLite-Datenbank laufen lassen kann, ohne die
 * CLI-Hülle mit ihrer MySQL-Verbindung mitzuziehen.
 */

// ── Helfer ──────────────────────────────────────────────────────────

/** Datum relativ zu heute, Format Y-m-d. */
function tag(int $tage): string
{
    return date('Y-m-d', strtotime($tage . ' days'));
}

/** Zeitpunkt relativ zu heute, Format Y-m-d H:i:s. */
function zeit(int $tage, string $uhr = '09:30'): string
{
    return date('Y-m-d', strtotime($tage . ' days')) . ' ' . $uhr . ':00';
}

/** Fügt eine Zeile ein und gibt den vergebenen Schlüssel zurück. */
function ins(string $tabelle, array $daten): int
{
    global $pdo;
    $spalten = array_keys($daten);
    $sql = 'INSERT INTO ' . $tabelle . ' (' . implode(', ', $spalten) . ') VALUES ('
         . implode(', ', array_fill(0, count($spalten), '?')) . ')';
    $pdo->prepare($sql)->execute(array_values($daten));
    return (int) $pdo->lastInsertId();
}

/**
 * Portal-Zugang, aus dem Schlüssel abgeleitet.
 *
 * Bewusst nicht zufällig: der Demo-Link soll ein erneutes Befüllen
 * überleben, sonst ist jeder verschickte Link danach tot. In einer echten
 * Installation wäre das ein Fehler - dort ist der Token ein Geheimnis.
 * In einer Demo, deren Inhalt ohnehin öffentlich ist, ist es der Zweck.
 */
function demo_token(string $schluessel): string
{
    return hash('sha256', 'admin-dashboard-demo::' . $schluessel);
}

/**
 * Ein winziges, gültiges PDF mit einer Textzeile.
 *
 * Die Belege der Demo müssen sich öffnen lassen. Ein Verweis auf eine
 * nicht vorhandene Datei liefe beim Klick ins Leere, und eine Textdatei
 * mit der Endung .pdf zeigt kein Betrachter an. Also das kleinstmögliche
 * echte PDF - mit korrekter Querverweistabelle, sonst lehnen die
 * strengeren Betrachter es ab.
 *
 * Umlaute werden umschrieben: Helvetica bringt hier keine Kodierungs-
 * angabe mit, ein ü käme als Kästchen heraus.
 */
function demo_pdf(string $zeile): string
{
    $zeile = strtr($zeile, [
        'ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue',
        'Ä' => 'Ae', 'Ö' => 'Oe', 'Ü' => 'Ue', 'ß' => 'ss',
    ]);
    $text  = str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $zeile);
    $strom = 'BT /F1 13 Tf 60 780 Td (' . $text . ') Tj ET';

    $objekte = [
        '<</Type/Catalog/Pages 2 0 R>>',
        '<</Type/Pages/Kids[3 0 R]/Count 1>>',
        '<</Type/Page/Parent 2 0 R/MediaBox[0 0 595 842]'
            . '/Resources<</Font<</F1 5 0 R>>>>/Contents 4 0 R>>',
        '<</Length ' . strlen($strom) . ">>\nstream\n" . $strom . "\nendstream",
        '<</Type/Font/Subtype/Type1/BaseFont/Helvetica>>',
    ];

    $pdf     = "%PDF-1.4\n";
    $stellen = [];
    foreach ($objekte as $i => $rumpf) {
        $stellen[] = strlen($pdf);
        $pdf .= ($i + 1) . " 0 obj\n" . $rumpf . "\nendobj\n";
    }

    $xref = strlen($pdf);
    $pdf .= "xref\n0 " . (count($objekte) + 1) . "\n0000000000 65535 f \n";
    foreach ($stellen as $stelle) {
        $pdf .= sprintf("%010d 00000 n \n", $stelle);
    }
    $pdf .= "trailer\n<</Size " . (count($objekte) + 1) . "/Root 1 0 R>>\n"
          . "startxref\n" . $xref . "\n%%EOF\n";

    return $pdf;
}
