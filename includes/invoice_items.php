<?php
/**
 * Rechnungspositionen: einlesen, rechnen, wieder auslesen.
 *
 * invoice.php nahm die Positionen bisher per POST entgegen, druckte sie
 * ins PDF und warf sie weg - in finances stand am Ende nur ein einziger
 * Betrag. Eine Rechnung liess sich damit nicht korrigieren, ihr PDF
 * nicht neu erzeugen, die Umsatzsteuer nicht auswerten und eine
 * E-Rechnung (XRechnung, ZUGFeRD) gar nicht erst erstellen: die verlangt
 * die Positionen einzeln und maschinenlesbar.
 *
 * Das Format ist absichtlich dasselbe wie bei den Angeboten
 * (quotes.items): desc, qty, price, unit. So kann die Umwandlung eines
 * Angebots in eine Rechnung die Positionen einfach uebernehmen, statt
 * sie unterwegs zu verlieren.
 */

/** Die Steuersaetze, die das Panel kennt. */
const STEUERSAETZE = [
    'regel'             => 0.19,
    'kleinunternehmer'  => 0.00,
];

/**
 * Liest die Positionen aus einer Formularsendung.
 *
 * Zeilen ohne Beschreibung fallen heraus: das Formular haelt immer eine
 * leere Zeile zum Weitertippen bereit, und die ist keine Position.
 *
 * @param array $post Ueblicherweise $_POST
 * @return array<int, array{desc: string, qty: float, price: float, unit: string}>
 */
function positionen_aus_post(array $post): array
{
    $descs  = $post['item_desc']  ?? [];
    $qtys   = $post['item_qty']   ?? [];
    $prices = $post['item_price'] ?? [];
    $units  = $post['item_unit']  ?? [];

    $items = [];
    foreach ($descs as $i => $desc) {
        $desc = trim((string) $desc);
        if ($desc === '') {
            continue;
        }
        $items[] = [
            'desc'  => $desc,
            // Menge fehlt: eine Position ist im Zweifel eine Einheit.
            'qty'   => zahl_aus_eingabe($qtys[$i] ?? null, 1.0),
            'price' => zahl_aus_eingabe($prices[$i] ?? null, 0.0),
            'unit'  => trim((string) ($units[$i] ?? '')),
        ];
    }
    return $items;
}

/**
 * Deutsche Zahleneingabe zu float.
 *
 * "10,5" und "10.5" meinen dasselbe - im Formular tippt jeder das Komma,
 * PHP versteht nur den Punkt. Ohne diese Umsetzung macht (float)"10,5"
 * eine 10 daraus, und die Rechnung ist um die Nachkommastellen falsch.
 */
function zahl_aus_eingabe($wert, float $ersatz): float
{
    if ($wert === null || trim((string) $wert) === '') {
        return $ersatz;
    }
    return (float) str_replace(',', '.', trim((string) $wert));
}

/**
 * Netto, Steuer und Brutto zu einer Positionsliste.
 *
 * Gerundet wird die Steuer, nicht der Bruttobetrag: so ist der
 * ausgewiesene Steuerbetrag genau die Differenz zwischen den beiden
 * gedruckten Summen. Rundete man beide einzeln, koennte auf der Rechnung
 * ein Cent fehlen, den niemand zuordnen kann.
 *
 * Eine unbekannte Steuerart weist KEINE Steuer aus. Das ist die Seite,
 * auf der ein Fehler auffaellt, statt still 19 Prozent auf eine Rechnung
 * zu schreiben, die keine ausweisen darf.
 *
 * @return array{netto: float, steuersatz: float, steuer: float, brutto: float}
 */
function positionen_summen(array $items, string $steuerart): array
{
    $netto = 0.0;
    foreach ($items as $it) {
        $netto += ((float) ($it['qty'] ?? 0)) * ((float) ($it['price'] ?? 0));
    }
    $netto = round($netto, 2);

    $satz   = STEUERSAETZE[$steuerart] ?? 0.00;
    $steuer = round($netto * $satz, 2);

    return [
        'netto'      => $netto,
        'steuersatz' => $satz,
        'steuer'     => $steuer,
        'brutto'     => round($netto + $steuer, 2),
    ];
}

/**
 * Liest gespeicherte Positionen aus der Datenbank.
 *
 * Formt sie dabei genauso wie positionen_aus_post(): was aus der
 * Datenbank kommt, soll die gleiche Gestalt haben wie was aus dem
 * Formular kommt - sonst muss jede Verwendungsstelle beide Faelle
 * kennen. Unbekannte Felder fallen weg.
 *
 * @param string|null $json Inhalt von finances.items
 */
function positionen_aus_json(?string $json): array
{
    if ($json === null || trim($json) === '') {
        return [];
    }

    $roh = json_decode($json, true);
    if (!is_array($roh)) {
        return [];
    }

    $items = [];
    foreach ($roh as $it) {
        if (!is_array($it)) {
            continue;
        }
        $desc = trim((string) ($it['desc'] ?? ''));
        if ($desc === '') {
            continue;
        }
        $items[] = [
            'desc'  => $desc,
            'qty'   => (float) ($it['qty'] ?? 1),
            'price' => (float) ($it['price'] ?? 0),
            'unit'  => trim((string) ($it['unit'] ?? '')),
        ];
    }
    return $items;
}
