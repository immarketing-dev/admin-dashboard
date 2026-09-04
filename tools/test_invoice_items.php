<?php
/**
 * Test fuer Rechnungspositionen und Summen.
 * Aufruf: php tools/test_invoice_items.php
 *
 * Bis hierher nahm invoice.php die Positionen per POST entgegen, druckte
 * sie ins PDF und warf sie weg. In finances stand nur ein Betrag. Damit
 * liess sich eine Rechnung nicht korrigieren, das PDF nicht neu erzeugen
 * und die Umsatzsteuer nicht auswerten - und eine E-Rechnung schon gar
 * nicht erzeugen, denn die braucht die Positionen einzeln.
 *
 * Gerechnet wird mit Geld, deshalb sind die Rundungsfaelle hier keine
 * Formsache: ein halber Cent an der falschen Stelle macht eine Rechnung
 * falsch, und zwar dauerhaft.
 */

require_once __DIR__ . '/../includes/invoice_items.php';

$checks = [];

// --- Einlesen aus dem Formular ----------------------------------------
$post = [
    'item_desc'  => ['Konzeption', 'Umsetzung', '', 'Schulung'],
    'item_qty'   => ['2', '10,5', '3', '1'],
    'item_price' => ['100,00', '85,50', '50', '0'],
    'item_unit'  => ['Std', 'Std', 'Stk', 'Pauschal'],
];
$items = positionen_aus_post($post);

$checks['leere Zeilen fallen heraus'] = count($items) === 3;
$checks['Beschreibung bleibt erhalten'] = $items[0]['desc'] === 'Konzeption';
$checks['Komma gilt als Dezimaltrenner'] = $items[1]['qty'] === 10.5 && $items[1]['price'] === 85.5;
$checks['Einheit bleibt erhalten'] = $items[1]['unit'] === 'Std';
$checks['Position mit Preis 0 bleibt']
    = count(array_filter($items, fn($i) => $i['desc'] === 'Schulung')) === 1;
$checks['Schluessel sind wie bei den Angeboten']
    = array_keys($items[0]) === ['desc', 'qty', 'price', 'unit'];

// Eine Zeile, die nur aus Leerzeichen besteht, ist keine Position.
$leer = positionen_aus_post(['item_desc' => ['   '], 'item_qty' => ['1'], 'item_price' => ['10']]);
$checks['Zeile aus Leerzeichen faellt heraus'] = $leer === [];

// Fehlende Felder duerfen keinen Fehler ausloesen - ein altes Formular
// oder eine abgeschnittene Sendung liefert sie schlicht nicht mit.
$unvollstaendig = positionen_aus_post(['item_desc' => ['Nur Text']]);
$checks['fehlende Menge wird zu 1']  = $unvollstaendig[0]['qty'] === 1.0;
$checks['fehlender Preis wird zu 0'] = $unvollstaendig[0]['price'] === 0.0;
$checks['fehlende Einheit ist leer'] = $unvollstaendig[0]['unit'] === '';

// --- Summen -----------------------------------------------------------
$summen = positionen_summen($items, 'regel');
// 2*100 + 10,5*85,5 + 1*0 = 200 + 897,75 = 1097,75
$checks['Nettosumme stimmt'] = $summen['netto'] === 1097.75;
$checks['19 Prozent werden ausgewiesen'] = $summen['steuersatz'] === 0.19;
$checks['Steuerbetrag stimmt'] = $summen['steuer'] === 208.57;
$checks['Bruttosumme stimmt'] = $summen['brutto'] === 1306.32;

$klein = positionen_summen($items, 'kleinunternehmer');
$checks['Kleinunternehmer weist keine Steuer aus'] = $klein['steuer'] === 0.0;
$checks['Kleinunternehmer: brutto gleich netto'] = $klein['brutto'] === $klein['netto'];
$checks['Kleinunternehmer: Satz ist 0'] = $klein['steuersatz'] === 0.0;

// Unbekannte Steuerart ist nicht "vielleicht 19 Prozent" - im Zweifel
// keine Steuer ausweisen, das ist der Fall, der nicht falsch abrechnet.
$unbekannt = positionen_summen($items, 'irgendwas');
$checks['unbekannte Steuerart weist keine Steuer aus'] = $unbekannt['steuer'] === 0.0;

// --- Runden ------------------------------------------------------------
// 0,105 * 19 % = 0,01995 - kaufmaennisch gerundet 0,02.
$rund = positionen_summen([['desc' => 'x', 'qty' => 1, 'price' => 0.105, 'unit' => '']], 'regel');
$checks['Steuer wird auf zwei Stellen gerundet'] = $rund['steuer'] === 0.02;
$checks['Brutto ist Netto plus gerundete Steuer'] = $rund['brutto'] === round($rund['netto'] + 0.02, 2);

$leer_summe = positionen_summen([], 'regel');
$checks['keine Positionen ergeben null'] = $leer_summe['netto'] === 0.0 && $leer_summe['brutto'] === 0.0;

// --- Rueckweg: gespeicherte Positionen wieder lesen ---------------------
$json = json_encode($items);
$zurueck = positionen_aus_json($json);
$checks['gespeicherte Positionen kommen unveraendert zurueck'] = $zurueck == $items;
$checks['ungueltiges JSON ergibt eine leere Liste'] = positionen_aus_json('{kaputt') === [];
$checks['NULL ergibt eine leere Liste'] = positionen_aus_json(null) === [];
$checks['JSON ohne Liste ergibt eine leere Liste'] = positionen_aus_json('"text"') === [];

// Fremde Schluessel werden nicht durchgereicht - was aus der Datenbank
// kommt, wird genauso geformt wie was aus dem Formular kommt.
$fremd = positionen_aus_json('[{"desc":"A","qty":2,"price":3,"unit":"","boese":"<script>"}]');
$checks['unbekannte Felder fallen weg'] = array_keys($fremd[0]) === ['desc', 'qty', 'price', 'unit'];

// ----------------------------------------------------------------------
$fail = 0;
foreach ($checks as $name => $ok) {
    echo ($ok ? 'PASS' : 'FAIL') . "  $name\n";
    if (!$ok) $fail = 1;
}
echo $fail === 0
    ? 'OK: ' . count($checks) . " Pruefungen bestanden.\n"
    : "FEHLGESCHLAGEN.\n";
exit($fail);
