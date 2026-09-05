<?php
/**
 * Rechnungen als XML (XRechnung, UBL 2.1).
 *
 * Die Positionen liegen seit Schemaversion 8 strukturiert vor —
 * `finances.items` als JSON mit Beschreibung, Menge, Preis und Einheit,
 * dazu `tax_type`, `net_amount` und `tax_amount`. Genau der Bestand,
 * aus dem sich eine elektronische Rechnung erzeugen lässt. Ausgegeben
 * wurde bisher nur ein von FPDF gezeichnetes PDF — ein Bild einer
 * Rechnung, das keine Software auslesen kann.
 *
 * ── Was diese Datei ist, und was nicht ─────────────────────────────
 * Sie erzeugt UBL-2.1-XML in der Gestalt, die XRechnung 3.0 vorsieht.
 * Sie ist KEIN Validator. Ob eine Datei die Prüfung eines konkreten
 * Empfängers besteht, hängt an Feldern, die dieses Panel gar nicht
 * kennen kann — der Leitweg-ID eines Amtes, vereinbarten
 * Bestellnummern, Zusatzangaben je Branche. Vor dem ersten echten
 * Versand gehört eine erzeugte Datei durch ein offizielles Prüfwerkzeug
 * (etwa den Validator der KoSIT). Die Oberfläche sagt das ebenfalls.
 *
 * ZUGFeRD ist bewusst nicht dabei: das verlangt PDF/A-3 mit
 * eingebettetem XML, und FPDF kann kein PDF/A-3. Das wäre eine weitere
 * Abhängigkeit und eine eigene Änderung.
 *
 * ── Warum DOMDocument und keine Zeichenketten ──────────────────────
 * Ein Kundenname mit einem "&" darin macht aus zusammengeklebtem XML
 * eine unlesbare Datei. DOM maskiert selbst, und zwar richtig.
 */

require_once __DIR__ . '/invoice_items.php';

/** Kennung des Formats, das die Datei zu erfüllen vorgibt. */
const XR_CUSTOMIZATION = 'urn:cen.eu:en16931:2017#compliant#urn:xoev-de:kosit:standard:xrechnung_3.0';
const XR_PROFILE       = 'urn:fdc:peppol.eu:2017:poacc:billing:01:1.0';

/** Rechnung (380) - im Unterschied zur Gutschrift (381). */
const XR_TYPE_RECHNUNG = '380';

/**
 * Die Steuerkategorie nach UNTDID 5305.
 *
 * 'S' ist der Regelsatz. 'E' steht für steuerbefreit und ist der Fall
 * des Kleinunternehmers nach §19 UStG — dort gehört zusätzlich ein
 * Grund in die Datei, sonst weist die Prüfung sie ab.
 */
function xr_steuerkategorie(string $steuerart): string
{
    return $steuerart === 'regel' ? 'S' : 'E';
}

/** Der Befreiungsgrund, den eine Rechnung ohne Steuer nennen muss. */
function xr_befreiungsgrund(string $steuerart): ?string
{
    return $steuerart === 'regel'
        ? null
        : 'Kleinunternehmer gemäß § 19 UStG - kein Ausweis von Umsatzsteuer';
}

/**
 * Die Einheit nach UN/ECE Recommendation 20.
 *
 * Das Panel lässt freien Text ("Std", "Tage", "Pauschale"); die Norm
 * verlangt einen Code. Was nicht in der Liste steht, wird zu C62 —
 * "Stück" im Sinne von "eine Einheit, unbenannt". Das ist die Antwort,
 * die eine Prüfung besteht, ohne etwas zu behaupten.
 */
function xr_einheit(string $einheit): string
{
    $karte = [
        'std' => 'HUR', 'stunde' => 'HUR', 'stunden' => 'HUR', 'h' => 'HUR',
        'tag' => 'DAY', 'tage' => 'DAY', 'tagen' => 'DAY',
        'stk' => 'H87', 'stück' => 'H87', 'stueck' => 'H87',
        'km' => 'KMT',
        'monat' => 'MON', 'monate' => 'MON',
        'jahr' => 'ANN', 'jahre' => 'ANN',
    ];

    $key = mb_strtolower(trim($einheit));

    return $karte[$key] ?? 'C62';
}

/** Zahl in der Schreibweise, die XML erwartet: Punkt, zwei Stellen. */
function xr_zahl(float $wert, int $stellen = 2): string
{
    return number_format($wert, $stellen, '.', '');
}

/**
 * Was der Datei noch fehlt, damit eine Prüfung sie annimmt.
 *
 * Vor dem Erzeugen aufgerufen, damit die Oberfläche es sagen kann,
 * statt eine Datei auszuliefern, die beim Empfänger abgewiesen wird.
 *
 * @return array<int, string> leere Liste = nichts fehlt
 */
function xr_fehlende_angaben(array $rechnung, array $firma): array
{
    $fehlt = [];

    foreach (['name' => 'Firmenname', 'street' => 'Straße', 'city' => 'Ort', 'zip' => 'Postleitzahl'] as $feld => $label) {
        if (trim((string) ($firma[$feld] ?? '')) === '') {
            $fehlt[] = 'Ihre Anschrift: ' . $label . ' (Einstellungen → Unternehmen)';
        }
    }
    // Entweder Umsatzsteuer-Identifikationsnummer oder Steuernummer -
    // eines von beiden verlangt die Norm vom Aussteller.
    if (trim((string) ($firma['vat_id'] ?? '')) === '' && trim((string) ($firma['tax_number'] ?? '')) === '') {
        $fehlt[] = 'Ihre USt-IdNr. oder Steuernummer (Einstellungen → Unternehmen)';
    }

    if (trim((string) ($rechnung['kunde_name'] ?? '')) === '') {
        $fehlt[] = 'Name des Kunden';
    }
    foreach (['kunde_street' => 'Straße', 'kunde_city' => 'Ort', 'kunde_zip' => 'Postleitzahl'] as $feld => $label) {
        if (trim((string) ($rechnung[$feld] ?? '')) === '') {
            $fehlt[] = 'Anschrift des Kunden: ' . $label;
        }
    }

    if (trim((string) ($rechnung['invoice_number'] ?? '')) === '') {
        $fehlt[] = 'Rechnungsnummer';
    }
    if (!positionen_aus_json($rechnung['items'] ?? null)) {
        $fehlt[] = 'Rechnungspositionen (bei Rechnungen von vor Schemaversion 8 stehen sie nur im PDF)';
    }

    return $fehlt;
}

/**
 * Erzeugt das XML einer Rechnung.
 *
 * Erwartet die Rechnung mit aufgelösten Kundendaten (kunde_name,
 * kunde_street, …) und die Firmenangaben als eigenes Feld — die
 * Funktion greift bewusst weder auf setting() noch auf die Datenbank
 * zu, damit sie sich mit gestellten Werten prüfen lässt.
 */
function xr_erzeugen(array $rechnung, array $firma): string
{
    $steuerart  = (string) ($rechnung['tax_type'] ?? 'kleinunternehmer');
    $kategorie  = xr_steuerkategorie($steuerart);
    $satz       = STEUERSAETZE[$steuerart] ?? 0.0;
    $positionen = positionen_aus_json($rechnung['items'] ?? null);
    $summen     = positionen_summen($positionen, $steuerart);

    $dom = new DOMDocument('1.0', 'UTF-8');
    $dom->formatOutput = true;

    $ns  = 'urn:oasis:names:specification:ubl:schema:xsd:Invoice-2';
    $cac = 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2';
    $cbc = 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2';

    $inv = $dom->createElementNS($ns, 'Invoice');
    $inv->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:cac', $cac);
    $inv->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:cbc', $cbc);
    $dom->appendChild($inv);

    /** Legt ein Element mit Text an. */
    $el = static function (string $name, ?string $text = null) use ($dom, $cac, $cbc): DOMElement {
        $raum = strpos($name, 'cac:') === 0 ? $cac : $cbc;
        $e = $dom->createElementNS($raum, $name);
        if ($text !== null && $text !== '') {
            $e->appendChild($dom->createTextNode($text));
        }
        return $e;
    };

    // ── Kopf ────────────────────────────────────────────────────────
    $inv->appendChild($el('cbc:CustomizationID', XR_CUSTOMIZATION));
    $inv->appendChild($el('cbc:ProfileID', XR_PROFILE));
    $inv->appendChild($el('cbc:ID', (string) ($rechnung['invoice_number'] ?? '')));
    $inv->appendChild($el('cbc:IssueDate', substr((string) ($rechnung['record_date'] ?? date('Y-m-d')), 0, 10)));
    if (!empty($rechnung['due_date'])) {
        $inv->appendChild($el('cbc:DueDate', substr((string) $rechnung['due_date'], 0, 10)));
    }
    $inv->appendChild($el('cbc:InvoiceTypeCode', XR_TYPE_RECHNUNG));
    if (trim((string) ($rechnung['notes'] ?? '')) !== '') {
        $inv->appendChild($el('cbc:Note', trim((string) $rechnung['notes'])));
    }
    $inv->appendChild($el('cbc:DocumentCurrencyCode', 'EUR'));

    // Die Kaeufer-Referenz ist in XRechnung Pflicht. Fehlt sie, steht
    // hier ausdruecklich "nicht vorhanden" statt eines erfundenen
    // Wertes - das ist die Angabe, die eine Pruefung erwartet, und sie
    // behauptet nichts.
    $ref = trim((string) ($rechnung['buyer_reference'] ?? ''));
    $inv->appendChild($el('cbc:BuyerReference', $ref !== '' ? $ref : 'nicht vorhanden'));

    // ── Aussteller ──────────────────────────────────────────────────
    $lieferant = $el('cac:AccountingSupplierParty');
    $partei    = $el('cac:Party');

    $name = $el('cac:PartyName');
    $name->appendChild($el('cbc:Name', (string) ($firma['name'] ?? '')));
    $partei->appendChild($name);

    $adr = $el('cac:PostalAddress');
    $adr->appendChild($el('cbc:StreetName', (string) ($firma['street'] ?? '')));
    $adr->appendChild($el('cbc:CityName', (string) ($firma['city'] ?? '')));
    $adr->appendChild($el('cbc:PostalZone', (string) ($firma['zip'] ?? '')));
    $land = $el('cac:Country');
    $land->appendChild($el('cbc:IdentificationCode', (string) ($firma['country'] ?? 'DE')));
    $adr->appendChild($land);
    $partei->appendChild($adr);

    if (trim((string) ($firma['vat_id'] ?? '')) !== '') {
        $steuer = $el('cac:PartyTaxScheme');
        $steuer->appendChild($el('cbc:CompanyID', (string) $firma['vat_id']));
        $schema = $el('cac:TaxScheme');
        $schema->appendChild($el('cbc:ID', 'VAT'));
        $steuer->appendChild($schema);
        $partei->appendChild($steuer);
    }

    $recht = $el('cac:PartyLegalEntity');
    $recht->appendChild($el('cbc:RegistrationName', (string) ($firma['name'] ?? '')));
    if (trim((string) ($firma['tax_number'] ?? '')) !== '') {
        $recht->appendChild($el('cbc:CompanyID', (string) $firma['tax_number']));
    }
    $partei->appendChild($recht);

    // Ein Ansprechpartner ist Pflicht - Name, Telefon oder Adresse.
    $kontakt = $el('cac:Contact');
    $kontakt->appendChild($el('cbc:Name', (string) ($firma['name'] ?? '')));
    if (trim((string) ($firma['email'] ?? '')) !== '') {
        $kontakt->appendChild($el('cbc:ElectronicMail', (string) $firma['email']));
    }
    $partei->appendChild($kontakt);

    $lieferant->appendChild($partei);
    $inv->appendChild($lieferant);

    // ── Empfänger ───────────────────────────────────────────────────
    $kunde  = $el('cac:AccountingCustomerParty');
    $kpartei = $el('cac:Party');

    $kname = $el('cac:PartyName');
    $kname->appendChild($el('cbc:Name', (string) ($rechnung['kunde_name'] ?? '')));
    $kpartei->appendChild($kname);

    $kadr = $el('cac:PostalAddress');
    $kadr->appendChild($el('cbc:StreetName', (string) ($rechnung['kunde_street'] ?? '')));
    $kadr->appendChild($el('cbc:CityName', (string) ($rechnung['kunde_city'] ?? '')));
    $kadr->appendChild($el('cbc:PostalZone', (string) ($rechnung['kunde_zip'] ?? '')));
    $kland = $el('cac:Country');
    $kland->appendChild($el('cbc:IdentificationCode', (string) ($rechnung['kunde_country'] ?? 'DE')));
    $kadr->appendChild($kland);
    $kpartei->appendChild($kadr);

    if (trim((string) ($rechnung['kunde_vat_id'] ?? '')) !== '') {
        $ksteuer = $el('cac:PartyTaxScheme');
        $ksteuer->appendChild($el('cbc:CompanyID', (string) $rechnung['kunde_vat_id']));
        $kschema = $el('cac:TaxScheme');
        $kschema->appendChild($el('cbc:ID', 'VAT'));
        $ksteuer->appendChild($kschema);
        $kpartei->appendChild($ksteuer);
    }

    $krecht = $el('cac:PartyLegalEntity');
    $krecht->appendChild($el('cbc:RegistrationName', (string) ($rechnung['kunde_name'] ?? '')));
    $kpartei->appendChild($krecht);

    $kunde->appendChild($kpartei);
    $inv->appendChild($kunde);

    // ── Zahlung ─────────────────────────────────────────────────────
    if (trim((string) ($firma['iban'] ?? '')) !== '') {
        $zahlung = $el('cac:PaymentMeans');
        // 58 = SEPA-Überweisung.
        $zahlung->appendChild($el('cbc:PaymentMeansCode', '58'));
        $zahlung->appendChild($el('cbc:PaymentID', (string) ($rechnung['invoice_number'] ?? '')));
        $konto = $el('cac:PayeeFinancialAccount');
        $konto->appendChild($el('cbc:ID', preg_replace('/\s+/', '', (string) $firma['iban'])));
        if (trim((string) ($firma['bank_holder'] ?? '')) !== '') {
            $konto->appendChild($el('cbc:Name', (string) $firma['bank_holder']));
        }
        $zahlung->appendChild($konto);
        $inv->appendChild($zahlung);
    }

    // ── Steuer ──────────────────────────────────────────────────────
    $steuersumme = $el('cac:TaxTotal');
    $betrag = $el('cbc:TaxAmount', xr_zahl($summen['steuer']));
    $betrag->setAttribute('currencyID', 'EUR');
    $steuersumme->appendChild($betrag);

    $unter = $el('cac:TaxSubtotal');
    $b1 = $el('cbc:TaxableAmount', xr_zahl($summen['netto']));
    $b1->setAttribute('currencyID', 'EUR');
    $unter->appendChild($b1);
    $b2 = $el('cbc:TaxAmount', xr_zahl($summen['steuer']));
    $b2->setAttribute('currencyID', 'EUR');
    $unter->appendChild($b2);

    $kat = $el('cac:TaxCategory');
    $kat->appendChild($el('cbc:ID', $kategorie));
    $kat->appendChild($el('cbc:Percent', xr_zahl($satz * 100)));
    $grund = xr_befreiungsgrund($steuerart);
    if ($grund !== null) {
        $kat->appendChild($el('cbc:TaxExemptionReason', $grund));
    }
    $ksch = $el('cac:TaxScheme');
    $ksch->appendChild($el('cbc:ID', 'VAT'));
    $kat->appendChild($ksch);
    $unter->appendChild($kat);
    $steuersumme->appendChild($unter);
    $inv->appendChild($steuersumme);

    // ── Summen ──────────────────────────────────────────────────────
    $gesamt = $el('cac:LegalMonetaryTotal');
    foreach ([
        'cbc:LineExtensionAmount' => $summen['netto'],
        'cbc:TaxExclusiveAmount'  => $summen['netto'],
        'cbc:TaxInclusiveAmount'  => $summen['brutto'],
        'cbc:PayableAmount'       => $summen['brutto'],
    ] as $feld => $wert) {
        $e = $el($feld, xr_zahl((float) $wert));
        $e->setAttribute('currencyID', 'EUR');
        $gesamt->appendChild($e);
    }
    $inv->appendChild($gesamt);

    // ── Positionen ──────────────────────────────────────────────────
    foreach ($positionen as $i => $p) {
        $zeilensumme = round(((float) $p['qty']) * ((float) $p['price']), 2);

        $zeile = $el('cac:InvoiceLine');
        $zeile->appendChild($el('cbc:ID', (string) ($i + 1)));

        $menge = $el('cbc:InvoicedQuantity', xr_zahl((float) $p['qty']));
        $menge->setAttribute('unitCode', xr_einheit((string) ($p['unit'] ?? '')));
        $zeile->appendChild($menge);

        $zs = $el('cbc:LineExtensionAmount', xr_zahl($zeilensumme));
        $zs->setAttribute('currencyID', 'EUR');
        $zeile->appendChild($zs);

        $artikel = $el('cac:Item');
        $artikel->appendChild($el('cbc:Name', mb_substr((string) $p['desc'], 0, 100)));
        $akat = $el('cac:ClassifiedTaxCategory');
        $akat->appendChild($el('cbc:ID', $kategorie));
        $akat->appendChild($el('cbc:Percent', xr_zahl($satz * 100)));
        $asch = $el('cac:TaxScheme');
        $asch->appendChild($el('cbc:ID', 'VAT'));
        $akat->appendChild($asch);
        $artikel->appendChild($akat);
        $zeile->appendChild($artikel);

        $preis = $el('cac:Price');
        $pb = $el('cbc:PriceAmount', xr_zahl((float) $p['price'], 4));
        $pb->setAttribute('currencyID', 'EUR');
        $preis->appendChild($pb);
        $zeile->appendChild($preis);

        $inv->appendChild($zeile);
    }

    return (string) $dom->saveXML();
}

/**
 * Der Dateiname, unter dem die Rechnung heruntergeladen wird.
 *
 * Die Rechnungsnummer im Namen, damit sie im Postfach des Empfängers
 * auffindbar bleibt.
 */
function xr_dateiname(string $rechnungsnummer): string
{
    $sicher = preg_replace('/[^A-Za-z0-9_-]/', '_', $rechnungsnummer) ?: 'Rechnung';

    return 'XRechnung_' . $sicher . '.xml';
}
