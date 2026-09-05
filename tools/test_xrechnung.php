<?php
/**
 * Test fuer die elektronische Rechnung.
 * Aufruf: php tools/test_xrechnung.php
 *
 * Die Positionen lagen seit Schemaversion 8 strukturiert vor -
 * finances.items als JSON, dazu tax_type, net_amount, tax_amount. Genau
 * der Bestand, aus dem sich eine XRechnung erzeugen laesst. Ausgegeben
 * wurde nur ein gezeichnetes PDF: ein Bild einer Rechnung, das keine
 * Software auslesen kann.
 *
 * Was dieser Test PRUEFT: dass die Datei wohlgeformt ist, die Betraege
 * zusammenpassen, Sonderzeichen maskiert werden und der Fall des
 * Kleinunternehmers seinen Befreiungsgrund traegt.
 *
 * Was er NICHT prueft: ob ein bestimmter Empfaenger die Datei annimmt.
 * Das haengt an Feldern, die dieses Panel nicht kennen kann, und
 * gehoert vor dem ersten echten Versand durch ein offizielles
 * Pruefwerkzeug. includes/xrechnung.php sagt das ebenso.
 */

require_once __DIR__ . '/../includes/xrechnung.php';

$checks = [];

// =====================================================================
// Die Bausteine
// =====================================================================
$checks['Regelsatz ist Kategorie S']       = xr_steuerkategorie('regel') === 'S';
$checks['Kleinunternehmer ist Kategorie E'] = xr_steuerkategorie('kleinunternehmer') === 'E';
// Eine Rechnung ohne Steuer muss sagen, warum - sonst weist die
// Pruefung sie ab.
$checks['Regelsatz braucht keinen Grund']  = xr_befreiungsgrund('regel') === null;
$checks['Kleinunternehmer nennt § 19']     = strpos((string) xr_befreiungsgrund('kleinunternehmer'), '19 UStG') !== false;

// Das Panel laesst freien Text als Einheit, die Norm verlangt einen Code.
$checks['Std wird HUR']       = xr_einheit('Std') === 'HUR';
$checks['Stunden wird HUR']   = xr_einheit('Stunden') === 'HUR';
$checks['Tage wird DAY']      = xr_einheit('Tage') === 'DAY';
$checks['Stueck wird H87']    = xr_einheit('Stück') === 'H87';
$checks['Gross-/Kleinschreibung egal'] = xr_einheit('TAGE') === 'DAY';
// Was nicht in der Liste steht, wird C62 - "eine Einheit, unbenannt".
// Das besteht die Pruefung, ohne etwas zu behaupten.
$checks['Pauschale wird C62'] = xr_einheit('Pauschale') === 'C62';
$checks['leer wird C62']      = xr_einheit('') === 'C62';

$checks['Zahl mit Punkt']     = xr_zahl(1234.5) === '1234.50';
$checks['Zahl auf vier Stellen'] = xr_zahl(0.12345, 4) === '0.1235';

$checks['Dateiname traegt die Nummer'] = xr_dateiname('RE-2026-007') === 'XRechnung_RE-2026-007.xml';
// Auf die Eigenschaft geprueft, nicht auf die Zeichenzahl: es geht
// darum, dass kein Pfad uebrig bleibt.
$_gefaehrlich = xr_dateiname('../../etc/passwd');
$checks['Dateiname enthaelt keinen Pfad'] = strpbrk($_gefaehrlich, '/') === false
                                        && strpos($_gefaehrlich, chr(92)) === false;
$checks['Dateiname hat genau eine Endung'] = substr_count($_gefaehrlich, '.') === 1;
$checks['Dateiname endet auf .xml']      = str_ends_with($_gefaehrlich, '.xml');

// =====================================================================
// Was noch fehlt
// =====================================================================
// Lieber vorher sagen, was fehlt, als eine Datei ausliefern, die beim
// Empfaenger abgewiesen wird.
$leer = xr_fehlende_angaben([], []);
$checks['ohne alles wird alles gemeldet'] = count($leer) >= 6;

$firma = [
    'name' => 'Testfirma GmbH', 'street' => 'Musterweg 1', 'zip' => '10115',
    'city' => 'Berlin', 'country' => 'DE', 'vat_id' => 'DE123456789',
    'email' => 'buchhaltung@example.com', 'iban' => 'DE02 1203 0000 0000 2020 51',
    'bank_holder' => 'Testfirma GmbH',
];
$rechnung = [
    'invoice_number' => 'RE-2026-007',
    'record_date'    => '2026-03-01',
    'due_date'       => '2026-03-15',
    'tax_type'       => 'regel',
    'notes'          => 'Vielen Dank für Ihren Auftrag.',
    'buyer_reference' => 'BEST-4711',
    'kunde_name'     => 'Kunde & Partner GmbH',
    'kunde_street'   => 'Kundenstraße 5',
    'kunde_zip'      => '20095',
    'kunde_city'     => 'Hamburg',
    'kunde_country'  => 'DE',
    'kunde_vat_id'   => 'DE987654321',
    'items' => json_encode([
        ['desc' => 'Beratung',  'qty' => 10, 'price' => 120.00, 'unit' => 'Std'],
        ['desc' => 'Schulung',  'qty' => 2,  'price' => 800.00, 'unit' => 'Tage'],
    ]),
];

$checks['vollstaendige Angaben: nichts fehlt'] = xr_fehlende_angaben($rechnung, $firma) === [];

// Fehlt die Steuernummer UND die USt-IdNr., ist das ein Mangel.
$ohne_steuer = $firma;
unset($ohne_steuer['vat_id']);
$fehlt = xr_fehlende_angaben($rechnung, $ohne_steuer);
$checks['ohne Steuernummer wird gemeldet']
    = count(array_filter($fehlt, fn($m) => strpos($m, 'USt-IdNr') !== false)) === 1;
// Die Steuernummer allein genuegt.
$ohne_steuer['tax_number'] = '12/345/67890';
$checks['Steuernummer allein genuegt'] = xr_fehlende_angaben($rechnung, $ohne_steuer) === [];

// Eine Bestandsrechnung ohne Positionen (vor Schemaversion 8) laesst
// sich nicht umwandeln - ihre Aufstellung steht nur im PDF.
$ohne_pos = $rechnung;
$ohne_pos['items'] = null;
$checks['ohne Positionen wird gemeldet']
    = count(array_filter(xr_fehlende_angaben($ohne_pos, $firma), fn($m) => strpos($m, 'position') !== false)) === 1;

// =====================================================================
// Das erzeugte XML
// =====================================================================
$xml = xr_erzeugen($rechnung, $firma);

$checks['die Datei ist nicht leer'] = strlen($xml) > 500;

// Wohlgeformt - der Mindestanspruch.
$dom = new DOMDocument();
$geladen = @$dom->loadXML($xml);
$checks['die Datei ist wohlgeformt'] = $geladen === true;

$xp = new DOMXPath($dom);
$xp->registerNamespace('ubl', 'urn:oasis:names:specification:ubl:schema:xsd:Invoice-2');
$xp->registerNamespace('cac', 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2');
$xp->registerNamespace('cbc', 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2');

/** Der Textinhalt des ersten Treffers, oder null. */
$wert = static function (string $pfad) use ($xp): ?string {
    $treffer = $xp->query($pfad);
    return ($treffer && $treffer->length > 0) ? $treffer->item(0)->textContent : null;
};

$checks['Wurzelelement ist Invoice'] = $dom->documentElement->localName === 'Invoice';
$checks['Formatkennung steht drin']  = $wert('/ubl:Invoice/cbc:CustomizationID') === XR_CUSTOMIZATION;
$checks['Rechnungsnummer steht drin'] = $wert('/ubl:Invoice/cbc:ID') === 'RE-2026-007';
$checks['Rechnungsdatum steht drin']  = $wert('/ubl:Invoice/cbc:IssueDate') === '2026-03-01';
$checks['Faelligkeit steht drin']     = $wert('/ubl:Invoice/cbc:DueDate') === '2026-03-15';
$checks['Typ ist 380 (Rechnung)']     = $wert('/ubl:Invoice/cbc:InvoiceTypeCode') === '380';
$checks['Waehrung ist EUR']           = $wert('/ubl:Invoice/cbc:DocumentCurrencyCode') === 'EUR';
$checks['Kaeufer-Referenz steht drin'] = $wert('/ubl:Invoice/cbc:BuyerReference') === 'BEST-4711';

$checks['Aussteller steht drin']
    = $wert('/ubl:Invoice/cac:AccountingSupplierParty/cac:Party/cac:PartyName/cbc:Name') === 'Testfirma GmbH';
$checks['seine Anschrift steht drin']
    = $wert('/ubl:Invoice/cac:AccountingSupplierParty/cac:Party/cac:PostalAddress/cbc:PostalZone') === '10115';
$checks['seine USt-IdNr. steht drin']
    = $wert('/ubl:Invoice/cac:AccountingSupplierParty/cac:Party/cac:PartyTaxScheme/cbc:CompanyID') === 'DE123456789';

// Der Kundenname enthaelt ein kaufmaennisches Und - genau der Fall, an
// dem zusammengeklebtes XML zerbricht.
$checks['der Kundenname kommt unversehrt an']
    = $wert('/ubl:Invoice/cac:AccountingCustomerParty/cac:Party/cac:PartyName/cbc:Name') === 'Kunde & Partner GmbH';
$checks['und ist im Text maskiert'] = strpos($xml, 'Kunde &amp; Partner GmbH') !== false;
$checks['die Anschrift des Kunden steht drin']
    = $wert('/ubl:Invoice/cac:AccountingCustomerParty/cac:Party/cac:PostalAddress/cbc:CityName') === 'Hamburg';

// --- Die Betraege ------------------------------------------------------
// 10 x 120 + 2 x 800 = 2800 netto, 19 % = 532, brutto 3332.
$checks['Nettosumme stimmt']  = $wert('/ubl:Invoice/cac:LegalMonetaryTotal/cbc:TaxExclusiveAmount') === '2800.00';
$checks['Steuerbetrag stimmt'] = $wert('/ubl:Invoice/cac:TaxTotal/cbc:TaxAmount') === '532.00';
$checks['Bruttosumme stimmt']  = $wert('/ubl:Invoice/cac:LegalMonetaryTotal/cbc:TaxInclusiveAmount') === '3332.00';
$checks['der Zahlbetrag stimmt'] = $wert('/ubl:Invoice/cac:LegalMonetaryTotal/cbc:PayableAmount') === '3332.00';
$checks['der Steuersatz steht drin'] = $wert('/ubl:Invoice/cac:TaxTotal/cac:TaxSubtotal/cac:TaxCategory/cbc:Percent') === '19.00';

// Die Waehrung gehoert an jeden Betrag.
$ohne_waehrung = 0;
foreach ($xp->query('//cbc:TaxAmount | //cbc:PayableAmount | //cbc:LineExtensionAmount | //cbc:PriceAmount') as $knoten) {
    if (!$knoten->hasAttribute('currencyID')) $ohne_waehrung++;
}
$checks['jeder Betrag traegt die Waehrung'] = $ohne_waehrung === 0;

// --- Die Positionen ----------------------------------------------------
$zeilen = $xp->query('/ubl:Invoice/cac:InvoiceLine');
$checks['zwei Positionen'] = $zeilen->length === 2;
$checks['die erste ist Nummer 1']
    = $wert('/ubl:Invoice/cac:InvoiceLine[1]/cbc:ID') === '1';
$checks['ihre Menge stimmt']
    = $wert('/ubl:Invoice/cac:InvoiceLine[1]/cbc:InvoicedQuantity') === '10.00';
$checks['ihre Einheit ist HUR']
    = $xp->query('/ubl:Invoice/cac:InvoiceLine[1]/cbc:InvoicedQuantity')->item(0)->getAttribute('unitCode') === 'HUR';
$checks['ihre Zeilensumme stimmt']
    = $wert('/ubl:Invoice/cac:InvoiceLine[1]/cbc:LineExtensionAmount') === '1200.00';
$checks['ihr Name steht drin']
    = $wert('/ubl:Invoice/cac:InvoiceLine[1]/cac:Item/cbc:Name') === 'Beratung';
$checks['die zweite hat DAY']
    = $xp->query('/ubl:Invoice/cac:InvoiceLine[2]/cbc:InvoicedQuantity')->item(0)->getAttribute('unitCode') === 'DAY';

// Die Summe der Zeilen muss die Nettosumme ergeben - sonst weist jede
// Pruefung die Datei ab, und der Empfaenger sieht einen anderen Betrag
// als das PDF.
$zeilensumme = 0.0;
foreach ($xp->query('/ubl:Invoice/cac:InvoiceLine/cbc:LineExtensionAmount') as $k) {
    $zeilensumme += (float) $k->textContent;
}
$checks['die Zeilen ergeben die Nettosumme'] = abs($zeilensumme - 2800.00) < 0.001;

// --- Die Bankverbindung ------------------------------------------------
$checks['die IBAN steht ohne Leerzeichen drin']
    = $wert('/ubl:Invoice/cac:PaymentMeans/cac:PayeeFinancialAccount/cbc:ID') === 'DE02120300000000202051';
$checks['SEPA-Ueberweisung als Zahlungsart']
    = $wert('/ubl:Invoice/cbc:PaymentMeansCode') === null   // nicht auf oberster Ebene
   && $wert('/ubl:Invoice/cac:PaymentMeans/cbc:PaymentMeansCode') === '58';

// =====================================================================
// Der Kleinunternehmer
// =====================================================================
$klein = $rechnung;
$klein['tax_type'] = 'kleinunternehmer';
$xml2 = xr_erzeugen($klein, $firma);

$dom2 = new DOMDocument();
$dom2->loadXML($xml2);
$xp2 = new DOMXPath($dom2);
$xp2->registerNamespace('ubl', 'urn:oasis:names:specification:ubl:schema:xsd:Invoice-2');
$xp2->registerNamespace('cac', 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2');
$xp2->registerNamespace('cbc', 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2');

$wert2 = static function (string $pfad) use ($xp2): ?string {
    $t = $xp2->query($pfad);
    return ($t && $t->length > 0) ? $t->item(0)->textContent : null;
};

$checks['Kleinunternehmer: keine Steuer']  = $wert2('/ubl:Invoice/cac:TaxTotal/cbc:TaxAmount') === '0.00';
$checks['Kleinunternehmer: Satz null']     = $wert2('/ubl:Invoice/cac:TaxTotal/cac:TaxSubtotal/cac:TaxCategory/cbc:Percent') === '0.00';
$checks['Kleinunternehmer: Kategorie E']   = $wert2('/ubl:Invoice/cac:TaxTotal/cac:TaxSubtotal/cac:TaxCategory/cbc:ID') === 'E';
// Der entscheidende Punkt: eine Rechnung ohne Steuer muss sagen, warum.
$checks['Kleinunternehmer: Grund steht drin']
    = strpos((string) $wert2('/ubl:Invoice/cac:TaxTotal/cac:TaxSubtotal/cac:TaxCategory/cbc:TaxExemptionReason'), '19 UStG') !== false;
$checks['Kleinunternehmer: brutto = netto']
    = $wert2('/ubl:Invoice/cac:LegalMonetaryTotal/cbc:PayableAmount') === '2800.00';

// =====================================================================
// Fehlende Kaeufer-Referenz
// =====================================================================
// Sie ist Pflicht. Fehlt sie, steht ausdruecklich "nicht vorhanden" da -
// die Angabe, die eine Pruefung erwartet, und sie behauptet nichts.
$ohne_ref = $rechnung;
unset($ohne_ref['buyer_reference']);
$dom3 = new DOMDocument();
$dom3->loadXML(xr_erzeugen($ohne_ref, $firma));
$xp3 = new DOMXPath($dom3);
$xp3->registerNamespace('ubl', 'urn:oasis:names:specification:ubl:schema:xsd:Invoice-2');
$xp3->registerNamespace('cbc', 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2');
$checks['ohne Referenz steht ein Platzhalter']
    = $xp3->query('/ubl:Invoice/cbc:BuyerReference')->item(0)->textContent === 'nicht vorhanden';

// =====================================================================
// Ergebnis
// =====================================================================
$fehler = 0;
foreach ($checks as $name => $ok) {
    if (!$ok) {
        echo "FEHLER: $name\n";
        $fehler++;
    }
}

if ($fehler === 0) {
    echo 'OK: ' . count($checks) . " Pruefungen bestanden.\n";
    echo "HINWEIS: Wohlgeformtheit und Inhalt sind geprueft, die Annahme durch\n"
       . "         einen konkreten Empfaenger nicht. Vor dem ersten echten Versand\n"
       . "         gehoert eine erzeugte Datei durch ein offizielles Pruefwerkzeug.\n";
    exit(0);
}
echo "\nFEHLGESCHLAGEN: $fehler von " . count($checks) . " Pruefungen.\n";
exit(1);
