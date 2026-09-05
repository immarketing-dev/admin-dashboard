<?php
/**
 * Zahlungseingänge einlesen und offenen Rechnungen zuordnen.
 *
 * Die Kette war bis hierher fast geschlossen: offene Posten, Mahnstufen,
 * automatischer Versand — nur das Abhaken beim Geldeingang blieb
 * Handarbeit. Wer zwanzig offene Rechnungen hat, vergleicht zwanzigmal
 * einen Kontoauszug mit einer Liste, und übersieht dabei irgendwann eine.
 *
 * GEBUCHT WIRD NICHTS VON SELBST. Diese Datei liest eine Datei und macht
 * Vorschläge; welcher davon zutrifft, entscheidet ein Mensch. Eine
 * Rechnung fälschlich auf "Bezahlt" zu setzen ist teurer als jede
 * gesparte Minute: die Mahnung bleibt aus, und auffallen wird es
 * frühestens beim Jahresabschluss.
 *
 * Zwei Formate:
 *
 *   - CAMT.053 (XML). Der SEPA-Standard, den jede Bank liefert. Verlässlich
 *     aufgebaut, deshalb der bevorzugte Weg.
 *   - CSV. Jede Bank baut ihn anders. Die Spalten werden über die
 *     Kopfzeile erkannt, nicht über ihre Position — eine feste
 *     Reihenfolge hielte genau bis zur nächsten Bank.
 */

/** Mehr Zeilen als ein Kontoauszug je hat; schützt vor einer Fehl-Datei. */
const ZAHLUNG_MAX_ZEILEN = 5000;

/** Wie sicher eine Zuordnung ist. */
const ZAHLUNG_SICHER   = 'sicher';    // Rechnungsnummer im Verwendungszweck
const ZAHLUNG_MOEGLICH = 'moeglich';  // Betrag und Name passen
const ZAHLUNG_UNKLAR   = 'unklar';    // nur der Betrag passt, mehrdeutig

/**
 * Einen Betrag aus einem Bankexport lesen.
 *
 * "1.234,56" (deutsch), "1,234.56" (englisch), "1234.56", "-45,00".
 * Entscheidend ist, welches Zeichen zuletzt kommt: das ist das
 * Dezimaltrennzeichen. Ohne diese Unterscheidung wird aus 1.234,56 je
 * nach Auslegung 1,23 oder 123456.
 */
function zahlung_betrag_parsen(string $roh): ?float
{
    $roh = trim(str_replace(["\xc2\xa0", ' ', "'"], '', $roh));
    if ($roh === '') {
        return null;
    }

    $vorzeichen = 1.0;
    if (strpos($roh, '-') !== false) {
        $vorzeichen = -1.0;
    }
    // Währungszeichen und alles andere raus.
    $roh = preg_replace('/[^0-9.,]/', '', $roh) ?? '';
    if ($roh === '') {
        return null;
    }

    $letztes_komma = strrpos($roh, ',');
    $letzter_punkt = strrpos($roh, '.');

    if ($letztes_komma !== false && $letzter_punkt !== false) {
        // Beide da: das hintere trennt die Nachkommastellen.
        if ($letztes_komma > $letzter_punkt) {
            $roh = str_replace('.', '', $roh);
            $roh = str_replace(',', '.', $roh);
        } else {
            $roh = str_replace(',', '', $roh);
        }
    } elseif ($letztes_komma !== false) {
        // Nur Komma: Dezimaltrenner, wenn zwei Stellen folgen, sonst
        // Tausendertrenner ("1,234" ist eintausendzweihundertvierunddreißig).
        $nach = strlen($roh) - $letztes_komma - 1;
        $roh = $nach === 3 ? str_replace(',', '', $roh) : str_replace(',', '.', $roh);
    } elseif ($letzter_punkt !== false) {
        $nach = strlen($roh) - $letzter_punkt - 1;
        if ($nach === 3) {
            $roh = str_replace('.', '', $roh);
        }
    }

    if (!is_numeric($roh)) {
        return null;
    }
    return $vorzeichen * abs((float) $roh);
}

/**
 * Ordnet die Spalten einer CSV-Kopfzeile ihre Rolle zu.
 *
 * Über Stichworte, nicht über die Position: Sparkasse, Volksbank, DKB
 * und N26 haben alle eine andere Reihenfolge, aber die Überschriften
 * ähneln sich.
 *
 * @return array{datum:?int, name:?int, zweck:?int, betrag:?int}
 */
function zahlung_csv_spalten(array $kopf): array
{
    $rollen = [
        'datum'  => ['buchungstag', 'buchungsdatum', 'valuta', 'wertstellung', 'datum', 'date', 'booking'],
        'name'   => ['beguenstigter', 'begünstigter', 'zahlungspflichtiger', 'auftraggeber',
                     'name', 'empfaenger', 'empfänger', 'payer', 'payee', 'partner'],
        'zweck'  => ['verwendungszweck', 'buchungstext', 'zweck', 'reference', 'referenz',
                     'beschreibung', 'description', 'subject'],
        'betrag' => ['betrag', 'umsatz', 'amount', 'value'],
    ];

    $gefunden = ['datum' => null, 'name' => null, 'zweck' => null, 'betrag' => null];

    foreach ($kopf as $i => $spalte) {
        $s = mb_strtolower(trim((string) $spalte));
        $s = str_replace(['"', "\xef\xbb\xbf"], '', $s);
        if ($s === '') {
            continue;
        }
        foreach ($rollen as $rolle => $worte) {
            if ($gefunden[$rolle] !== null) {
                continue;
            }
            foreach ($worte as $w) {
                if (mb_strpos($s, $w) !== false) {
                    $gefunden[$rolle] = $i;
                    break 2;
                }
            }
        }
    }

    return $gefunden;
}

/**
 * Liest einen CSV-Kontoauszug.
 *
 * @return array{zahlungen:array, fehler:?string}
 */
function zahlungen_aus_csv(string $inhalt): array
{
    // BOM weg, sonst trägt die erste Überschrift ihn mit.
    $inhalt = preg_replace('/^\xEF\xBB\xBF/', '', $inhalt) ?? $inhalt;

    // Nicht als UTF-8 lesbar? Dann kommt es vermutlich als ISO-8859-1,
    // wie es ältere Bankexporte noch liefern.
    if (!mb_check_encoding($inhalt, 'UTF-8')) {
        $inhalt = mb_convert_encoding($inhalt, 'UTF-8', 'ISO-8859-1');
    }

    // Trennzeichen: Semikolon ist in Deutschland die Regel, Komma die
    // Ausnahme. Es gewinnt, was in der ersten Zeile häufiger vorkommt.
    $erste = strtok($inhalt, "\r\n");
    $erste = $erste === false ? '' : $erste;
    $trenner = substr_count($erste, ';') >= substr_count($erste, ',') ? ';' : ',';

    $zeilen = preg_split('/\r\n|\r|\n/', $inhalt) ?: [];
    $kopf   = null;
    $spalten = [];
    $zahlungen = [];

    foreach ($zeilen as $nr => $zeile) {
        if (trim($zeile) === '') {
            continue;
        }
        if (count($zahlungen) >= ZAHLUNG_MAX_ZEILEN) {
            break;
        }

        $felder = str_getcsv($zeile, $trenner, '"', '\\');

        if ($kopf === null) {
            // Die Kopfzeile ist die erste, in der sich Spalten erkennen
            // lassen. Manche Banken schreiben zwei Zeilen Vorspann.
            $probe = zahlung_csv_spalten($felder);
            if ($probe['betrag'] !== null && ($probe['zweck'] !== null || $probe['name'] !== null)) {
                $kopf    = $felder;
                $spalten = $probe;
            }
            continue;
        }

        $betrag = zahlung_betrag_parsen((string) ($felder[$spalten['betrag']] ?? ''));
        if ($betrag === null || $betrag <= 0) {
            // Lastschriften und Abbuchungen sind hier uninteressant:
            // gesucht wird Geld, das hereinkommt.
            continue;
        }

        $zahlungen[] = [
            'datum'  => zahlung_datum_parsen((string) ($felder[$spalten['datum']] ?? '')),
            'name'   => trim((string) ($felder[$spalten['name']] ?? '')),
            'zweck'  => trim((string) ($felder[$spalten['zweck']] ?? '')),
            'betrag' => $betrag,
        ];
    }

    if ($kopf === null) {
        return [
            'zahlungen' => [],
            'fehler'    => 'In dieser Datei war keine Kopfzeile mit Betrag und '
                         . 'Verwendungszweck zu finden. Erwartet wird ein CSV-Export '
                         . 'des Kontos oder eine CAMT.053-Datei.',
        ];
    }

    return ['zahlungen' => $zahlungen, 'fehler' => null];
}

/** Ein Datum aus einem Bankexport, als Y-m-d. */
function zahlung_datum_parsen(string $roh): ?string
{
    $roh = trim($roh);
    if ($roh === '') {
        return null;
    }
    // Deutsch zuerst: 15.01.2026 und 15.01.26.
    if (preg_match('/^(\d{1,2})\.(\d{1,2})\.(\d{2,4})$/', $roh, $m)) {
        $jahr = (int) $m[3];
        if ($jahr < 100) {
            $jahr += 2000;
        }
        return sprintf('%04d-%02d-%02d', $jahr, (int) $m[2], (int) $m[1]);
    }
    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $roh, $m)) {
        return $m[1] . '-' . $m[2] . '-' . $m[3];
    }
    $t = strtotime($roh);
    return $t === false ? null : date('Y-m-d', $t);
}

/**
 * Liest eine CAMT.053-Datei.
 *
 * Über local-name(): der Namensraum trägt die Version, und die
 * unterscheidet sich von Bank zu Bank. Ein fester Präfix hielte bis zur
 * nächsten.
 *
 * @return array{zahlungen:array, fehler:?string}
 */
function zahlungen_aus_camt(string $xml): array
{
    $vorher = libxml_use_internal_errors(true);
    $doc = new DOMDocument();
    // LIBXML_NONET: keine externen Entitäten nachladen. Die Datei kommt
    // von außen, auch wenn sie von der eigenen Bank stammt.
    $ok = $doc->loadXML($xml, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
    libxml_clear_errors();
    libxml_use_internal_errors($vorher);

    if (!$ok) {
        return ['zahlungen' => [], 'fehler' => 'Die Datei ist kein gültiges XML.'];
    }

    $xp = new DOMXPath($doc);
    $eintraege = $xp->query('//*[local-name()="Ntry"]');
    if ($eintraege === false || $eintraege->length === 0) {
        return [
            'zahlungen' => [],
            'fehler'    => 'Keine Buchungen gefunden. Erwartet wird eine CAMT.053-Datei.',
        ];
    }

    $zahlungen = [];
    foreach ($eintraege as $ntry) {
        if (count($zahlungen) >= ZAHLUNG_MAX_ZEILEN) {
            break;
        }

        // CRDT = Gutschrift. Alles andere ist abgehendes Geld.
        $richtung = $xp->evaluate('string(.//*[local-name()="CdtDbtInd"])', $ntry);
        if (strtoupper(trim((string) $richtung)) !== 'CRDT') {
            continue;
        }

        $betrag = zahlung_betrag_parsen(
            (string) $xp->evaluate('string(.//*[local-name()="Amt"])', $ntry)
        );
        if ($betrag === null || $betrag <= 0) {
            continue;
        }

        $datum = (string) $xp->evaluate(
            'string(.//*[local-name()="BookgDt"]/*[local-name()="Dt"])', $ntry
        );
        if ($datum === '') {
            $datum = (string) $xp->evaluate(
                'string(.//*[local-name()="ValDt"]/*[local-name()="Dt"])', $ntry
            );
        }

        // Der Zahler steht unter Dbtr; bei einer Gutschrift ist das der
        // Kunde.
        $name = (string) $xp->evaluate(
            'string(.//*[local-name()="Dbtr"]/*[local-name()="Nm"])', $ntry
        );

        // Der Verwendungszweck kann in mehreren Ustrd stehen.
        $zweck_teile = [];
        $ustrd = $xp->query('.//*[local-name()="Ustrd"]', $ntry);
        if ($ustrd !== false) {
            foreach ($ustrd as $u) {
                $zweck_teile[] = trim($u->textContent);
            }
        }
        if ($zweck_teile === []) {
            $zweck_teile[] = trim((string) $xp->evaluate(
                'string(.//*[local-name()="AddtlNtryInf"])', $ntry
            ));
        }

        $zahlungen[] = [
            'datum'  => zahlung_datum_parsen($datum),
            'name'   => trim($name),
            'zweck'  => trim(implode(' ', array_filter($zweck_teile))),
            'betrag' => $betrag,
        ];
    }

    return ['zahlungen' => $zahlungen, 'fehler' => null];
}

/**
 * Erkennt das Format und liest die Datei.
 *
 * @return array{zahlungen:array, fehler:?string, format:string}
 */
function zahlungen_lesen(string $inhalt, string $dateiname = ''): array
{
    $probe = ltrim(substr($inhalt, 0, 400));
    $ist_xml = strpos($probe, '<?xml') === 0 || stripos($probe, '<Document') !== false;

    if ($ist_xml) {
        $e = zahlungen_aus_camt($inhalt);
        return $e + ['format' => 'CAMT.053'];
    }

    $e = zahlungen_aus_csv($inhalt);
    return $e + ['format' => 'CSV'];
}

/**
 * Sucht eine der bekannten Rechnungsnummern im Text.
 *
 * Ohne Rücksicht auf Bindestriche und Groß-/Kleinschreibung: manche
 * Kunden tippen "RE 2026 014", manche "re2026014".
 */
function rechnungsnummer_finden(string $text, array $nummern): ?string
{
    $flach = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $text) ?? '');
    if ($flach === '') {
        return null;
    }

    // Die längste zuerst: "RE-2026-1" darf nicht in "RE-2026-14" treffen,
    // bevor die längere geprüft wurde.
    usort($nummern, fn($a, $b) => strlen((string) $b) <=> strlen((string) $a));

    foreach ($nummern as $nr) {
        $n = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) $nr) ?? '');
        if ($n !== '' && strpos($flach, $n) !== false) {
            return (string) $nr;
        }
    }
    return null;
}

/**
 * Namen so weit vereinfachen, dass sie sich vergleichen lassen.
 *
 * Die Bank schreibt "HOFMANN + PARTNER STEUERBERATUNG", im CRM steht
 * "Hofmann & Partner Steuerberatung".
 */
function zahlung_name_flach(string $name): string
{
    $n = mb_strtolower(trim($name));
    $n = strtr($n, ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss', '&' => 'und', '+' => 'und']);
    $n = preg_replace('/\b(gmbh|ag|kg|ohg|ug|e\.?k\.?|mbh|co|und)\b/', '', $n) ?? $n;
    return trim(preg_replace('/[^a-z0-9]/', '', $n) ?? '');
}

/**
 * Die offenen Rechnungen, gegen die abgeglichen wird.
 */
function offene_rechnungen_fuer_abgleich(PDO $pdo): array
{
    $stmt = $pdo->query(
        "SELECT f.id, f.invoice_number, f.title, f.amount, f.record_date, f.due_date,
                COALESCE(NULLIF(c.company, ''), c.name, f.custom_name, '') AS kunde
           FROM finances f
           LEFT JOIN contacts c ON c.id = f.contact_id AND c.deleted_at IS NULL
          WHERE f.deleted_at IS NULL
            AND f.type = 'INCOME'
            AND f.status IN ('Offen', 'Überfällig')
          ORDER BY f.record_date ASC"
    );
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Macht zu einer Zahlung einen Vorschlag.
 *
 * Drei Stufen, und die Stufe steht im Ergebnis: der Mensch, der
 * bestätigt, soll sehen, worauf die Zuordnung beruht.
 *
 * @return array{treffer:?array, sicherheit:?string, grund:string}
 */
function zahlung_vorschlag(array $zahlung, array $offene): array
{
    $text = $zahlung['zweck'] . ' ' . $zahlung['name'];

    // 1. Die Rechnungsnummer im Verwendungszweck. Das ist der Fall, für
    //    den die Nummer auf der Rechnung steht.
    $nummern = array_values(array_filter(array_column($offene, 'invoice_number')));
    $nr = rechnungsnummer_finden($text, $nummern);
    if ($nr !== null) {
        foreach ($offene as $r) {
            if ((string) $r['invoice_number'] === $nr) {
                $passt = abs((float) $r['amount'] - $zahlung['betrag']) < 0.005;
                return [
                    'treffer'    => $r,
                    'sicherheit' => $passt ? ZAHLUNG_SICHER : ZAHLUNG_MOEGLICH,
                    'grund'      => $passt
                        ? 'Rechnungsnummer im Verwendungszweck, Betrag stimmt.'
                        : 'Rechnungsnummer im Verwendungszweck, aber der Betrag weicht ab.',
                ];
            }
        }
    }

    // 2. Betrag und Name.
    $zahler = zahlung_name_flach($zahlung['name']);
    $nach_betrag = [];
    foreach ($offene as $r) {
        if (abs((float) $r['amount'] - $zahlung['betrag']) < 0.005) {
            $nach_betrag[] = $r;
        }
    }

    if ($zahler !== '') {
        $mit_name = [];
        foreach ($nach_betrag as $r) {
            $kunde = zahlung_name_flach((string) $r['kunde']);
            if ($kunde !== '' && (strpos($zahler, $kunde) !== false || strpos($kunde, $zahler) !== false)) {
                $mit_name[] = $r;
            }
        }
        if (count($mit_name) === 1) {
            return [
                'treffer'    => $mit_name[0],
                'sicherheit' => ZAHLUNG_MOEGLICH,
                'grund'      => 'Betrag und Name des Zahlers passen.',
            ];
        }
    }

    // 3. Nur der Betrag, und nur wenn er eindeutig ist. Bei zwei
    //    Rechnungen über denselben Betrag wäre jede Wahl geraten.
    if (count($nach_betrag) === 1) {
        return [
            'treffer'    => $nach_betrag[0],
            'sicherheit' => ZAHLUNG_UNKLAR,
            'grund'      => 'Nur der Betrag passt — bitte prüfen.',
        ];
    }
    if (count($nach_betrag) > 1) {
        return [
            'treffer'    => null,
            'sicherheit' => null,
            'grund'      => count($nach_betrag) . ' offene Rechnungen über diesen Betrag — '
                          . 'keine eindeutige Zuordnung.',
        ];
    }

    return ['treffer' => null, 'sicherheit' => null, 'grund' => 'Keine offene Rechnung passt.'];
}

/**
 * Vorschläge für alle gelesenen Zahlungen.
 *
 * Eine Rechnung wird höchstens einmal vorgeschlagen: zwei Zahlungen über
 * denselben Betrag dürfen nicht beide auf dieselbe Rechnung zeigen.
 */
function zahlungen_vorschlaege(array $zahlungen, array $offene): array
{
    $aus      = [];
    $vergeben = [];

    // Erst die sicheren Treffer, dann der Rest: sonst nimmt ein
    // Betragstreffer die Rechnung weg, die eine Nummer eindeutig meint.
    foreach ([[ZAHLUNG_SICHER], [ZAHLUNG_MOEGLICH], [ZAHLUNG_UNKLAR, null]] as $stufe) {
        foreach ($zahlungen as $i => $z) {
            if (isset($aus[$i])) {
                continue;
            }
            $frei = array_values(array_filter(
                $offene,
                fn($r) => !in_array((int) $r['id'], $vergeben, true)
            ));
            $v = zahlung_vorschlag($z, $frei);
            if (!in_array($v['sicherheit'], $stufe, true)) {
                continue;
            }
            if ($v['treffer'] !== null) {
                $vergeben[] = (int) $v['treffer']['id'];
            }
            $aus[$i] = ['zahlung' => $z] + $v;
        }
    }

    ksort($aus);
    return array_values($aus);
}

/**
 * Bucht eine bestätigte Zuordnung.
 *
 * Nur von 'Offen' oder 'Überfällig' aus: eine bereits bezahlte Rechnung
 * noch einmal zu buchen wäre ein doppelter Zahlungseingang, und ein
 * zweiter Klick auf denselben Knopf darf das nicht auslösen.
 *
 * @return bool true, wenn wirklich gebucht wurde
 */
function zahlung_buchen(PDO $pdo, int $finanz_id, string $zweck, ?string $datum): bool
{
    $stmt = $pdo->prepare(
        "UPDATE finances
            SET status = 'Bezahlt',
                notes = TRIM(CONCAT(COALESCE(notes, ''), ?))
          WHERE id = ?
            AND deleted_at IS NULL
            AND type = 'INCOME'
            AND status IN ('Offen', 'Überfällig')"
    );

    $vermerk = "\n" . 'Zahlungseingang'
             . ($datum !== null ? ' am ' . date('d.m.Y', strtotime($datum)) : '')
             . ' über den Kontoauszug zugeordnet.'
             . ($zweck !== '' ? ' Verwendungszweck: ' . mb_substr($zweck, 0, 140) : '');

    $stmt->execute([$vermerk, $finanz_id]);
    return $stmt->rowCount() > 0;
}
