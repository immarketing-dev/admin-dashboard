<?php
/**
 * Wiederkehrende Einträge.
 *
 * finances.is_recurring war ein Etikett und sonst nichts. Der Schalter
 * hieß "Monatliche Fixkosten", setzte eine 1 in die Spalte, und die
 * Spalte wurde an drei Stellen gelesen: als Filter, als Abzeichen in der
 * Tabelle und als "Ja/Nein" im CSV. Erzeugt hat er nie etwas - ein
 * Wartungsvertrag musste jeden Monat von Hand abgetippt werden.
 *
 * is_recurring bleibt, damit Filter, Abzeichen und CSV weiterlaufen.
 * recurrence steht daneben und ist das, was tatsächlich etwas tut.
 *
 * Was hier NICHT passiert: ein PDF erzeugen. Die Rechnungs-PDF-Erzeugung
 * steht mitten im POST-Handler von invoice.php und wäre von hier aus nur
 * über einen Umbau dieser Datei erreichbar. Der erzeugte Eintrag
 * erscheint deshalb als offene Rechnung in der Liste, und das PDF
 * entsteht mit dem vorhandenen Knopf - was ohnehin der Moment ist, in
 * dem man eine wiederkehrende Rechnung noch einmal ansieht, bevor sie
 * hinausgeht.
 */

require_once __DIR__ . '/numbering.php';
require_once __DIR__ . '/logging.php';

/**
 * Wie viele Einträge ein einzelner Lauf höchstens nachholt.
 *
 * Steht ein next_run aus Versehen weit in der Vergangenheit - eine von
 * Hand gesetzte Zeile, eine Datenbank aus dem Backup -, entstünde sonst
 * in einem Lauf ein Jahrzehnt Rechnungen. Zwölf reicht, um einen
 * ausgefallenen Cron über ein Jahr aufzuholen, und ist klein genug, dass
 * ein Irrtum überschaubar bleibt.
 */
const WIEDERHOLUNG_MAX_NACHHOLEN = 12;

/** Zahlungsziel der erzeugten Rechnung, in Tagen. */
const WIEDERHOLUNG_ZAHLUNGSZIEL = 14;

/**
 * Die Intervalle, die das Panel kennt.
 *
 * Als Liste im Code und nicht als ENUM in der Spalte: ein weiteres
 * Intervall ist damit ein Eintrag hier statt eines ALTER TABLE.
 *
 * @return array<string, array{label: string, monate: int}>
 */
function wiederholung_intervalle(): array
{
    return [
        'monthly'   => ['label' => 'Monatlich',    'monate' => 1],
        'quarterly' => ['label' => 'Vierteljährlich', 'monate' => 3],
        'yearly'    => ['label' => 'Jährlich',     'monate' => 12],
    ];
}

/** Ist das ein Intervall, das wir kennen? */
function wiederholung_gueltig(string $intervall): bool
{
    return isset(wiederholung_intervalle()[$intervall]);
}

/**
 * Der nächste Termin nach $datum.
 *
 * Der Ankertag ist der Tag im Monat, den die Reihe eigentlich meint -
 * üblicherweise der Tag des ersten Eintrags. Ohne ihn wandert eine Reihe,
 * die am 31. beginnt, unwiederbringlich nach vorn: der 31. Januar würde
 * zum 28. Februar, und von da an bliebe es der 28., obwohl der Vertrag
 * den Monatsletzten meint.
 *
 * Mit Ankertag wird stattdessen in jedem Monat der Ankertag angesetzt
 * und nur dort gekürzt, wo der Monat kürzer ist: 31.01. → 28.02. →
 * 31.03.
 *
 * @return string|null Datum als JJJJ-MM-TT, null bei unbekanntem Intervall
 */
function naechster_termin(string $datum, string $intervall, ?int $ankertag = null): ?string
{
    if (!wiederholung_gueltig($intervall)) {
        return null;
    }
    $zeit = strtotime(substr($datum, 0, 10));
    if ($zeit === false) {
        return null;
    }

    $jahr  = (int) date('Y', $zeit);
    $monat = (int) date('n', $zeit);
    $tag   = $ankertag !== null && $ankertag >= 1 && $ankertag <= 31
           ? $ankertag
           : (int) date('j', $zeit);

    $monat += wiederholung_intervalle()[$intervall]['monate'];
    while ($monat > 12) {
        $monat -= 12;
        $jahr++;
    }

    // Auf die Länge des Zielmonats kürzen, statt PHP in den Folgemonat
    // rutschen zu lassen - mktime(0,0,0,2,31,2026) ergibt den 3. März.
    $letzter = (int) date('t', mktime(0, 0, 0, $monat, 1, $jahr));
    $tag     = min($tag, $letzter);

    return sprintf('%04d-%02d-%02d', $jahr, $monat, $tag);
}

/**
 * Der Ankertag einer Vorlage.
 *
 * record_date ist der Termin, mit dem die Reihe angelegt wurde, und
 * damit der gemeinte Tag im Monat. Fehlt er, hilft next_run weiter.
 */
function wiederholung_ankertag(array $vorlage): ?int
{
    foreach (['record_date', 'next_run'] as $feld) {
        $wert = $vorlage[$feld] ?? null;
        if ($wert !== null && trim((string) $wert) !== '') {
            $zeit = strtotime(substr((string) $wert, 0, 10));
            if ($zeit !== false) {
                return (int) date('j', $zeit);
            }
        }
    }
    return null;
}

/**
 * Ist diese Vorlage heute fällig?
 *
 * Vergleich auf Tagesgrenzen: der Cron-Lauf darf zu jeder Uhrzeit
 * kommen, ohne dass sich das Ergebnis ändert.
 */
function wiederholung_faellig(?string $next_run, string $heute): bool
{
    if ($next_run === null || trim($next_run) === '') {
        return false;
    }
    return substr($next_run, 0, 10) <= substr($heute, 0, 10);
}

// ---------------------------------------------------------------------
// Datenbank
// ---------------------------------------------------------------------

/**
 * Alle Vorlagen, deren nächster Termin erreicht ist.
 *
 * Eine Vorlage ist eine Zeile mit gesetztem recurrence und next_run.
 * Die daraus erzeugten Rechnungen tragen recurrence = '' und sind damit
 * selbst keine Vorlagen - sonst würde sich die Reihe bei jedem Lauf
 * verdoppeln.
 */
function faellige_vorlagen(PDO $pdo, string $heute): array
{
    $stmt = $pdo->prepare(
        "SELECT * FROM finances
          WHERE deleted_at IS NULL
            AND recurrence <> ''
            AND next_run IS NOT NULL
            AND next_run <= ?
          ORDER BY next_run ASC, id ASC"
    );
    $stmt->execute([substr($heute, 0, 10)]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/**
 * Erzeugt genau einen Eintrag aus einer Vorlage und schiebt sie weiter.
 *
 * Eine Rechnungsnummer bekommt nur eine Einnahme. Eine wiederkehrende
 * Ausgabe - Server, Lizenz, Versicherung - ist keine Ausgangsrechnung
 * und darf keine Nummer aus der eigenen Reihe verbrauchen; §14 UStG
 * verlangt sie für Ausgangsrechnungen, und jede vergebene Nummer, der
 * keine Rechnung gegenübersteht, ist eine Lücke, die später erklärt
 * werden muss.
 *
 * @return int|null ID des erzeugten Eintrags, null wenn nichts entstand
 */
function vorlage_ausfuehren(PDO $pdo, array $vorlage, string $heute): ?int
{
    $intervall = (string) ($vorlage['recurrence'] ?? '');
    if (!wiederholung_gueltig($intervall)) {
        return null;
    }

    $termin = substr((string) ($vorlage['next_run'] ?? $heute), 0, 10);
    $typ    = ($vorlage['type'] ?? 'INCOME') === 'EXPENSE' ? 'EXPENSE' : 'INCOME';

    $nummer = $typ === 'INCOME' ? next_invoice_number($pdo, date('Y', strtotime($termin))) : null;

    $faellig = date('Y-m-d', strtotime($termin . ' +' . WIEDERHOLUNG_ZAHLUNGSZIEL . ' days'));

    $stmt = $pdo->prepare(
        "INSERT INTO finances
           (type, title, invoice_number, contact_id, custom_name, amount, status,
            record_date, due_date, notes, is_recurring, items, tax_type,
            net_amount, tax_amount, recurrence, next_run, recurring_parent_id)
         VALUES (?, ?, ?, ?, ?, ?, 'Offen', ?, ?, ?, 0, ?, ?, ?, ?, '', NULL, ?)"
    );
    $stmt->execute([
        $typ,
        // Der Titel der Vorlage, nicht die Rechnungsnummer: bei einer
        // Ausgabe gibt es keine Nummer, und bei einer Einnahme ist
        // "Wartung Website" aussagekräftiger als "RE-2026-014", das
        // ohnehin in seiner eigenen Spalte steht.
        (string) ($vorlage['title'] ?? ''),
        $nummer,
        $vorlage['contact_id'] ?? null,
        $vorlage['custom_name'] ?? null,
        $vorlage['amount'] ?? 0,
        $termin,
        $faellig,
        $vorlage['notes'] ?? null,
        $vorlage['items'] ?? null,
        (string) ($vorlage['tax_type'] ?? 'kleinunternehmer'),
        $vorlage['net_amount'] ?? null,
        $vorlage['tax_amount'] ?? null,
        (int) $vorlage['id'],
    ]);

    $neu = (int) $pdo->lastInsertId();

    // Die Vorlage weiterschieben. Die Bedingung auf den alten Wert ist
    // die Absicherung gegen zwei gleichzeitige Läufe: der zweite trifft
    // auf ein bereits verschobenes next_run und erzeugt nichts mehr.
    $weiter = naechster_termin($termin, $intervall, wiederholung_ankertag($vorlage));
    $upd = $pdo->prepare('UPDATE finances SET next_run = ? WHERE id = ? AND next_run = ?');
    $upd->execute([$weiter, (int) $vorlage['id'], $termin]);

    return $neu;
}

/**
 * Arbeitet alle fälligen Vorlagen ab, einschließlich Nachholen.
 *
 * @return array{erzeugt: int, vorlagen: int, meldungen: array<int, string>}
 */
function wiederholungen_ausfuehren(PDO $pdo, string $heute): array
{
    $erzeugt   = 0;
    $meldungen = [];
    $vorlagen  = faellige_vorlagen($pdo, $heute);

    foreach ($vorlagen as $vorlage) {
        $lauf = 0;
        // Frisch aus der Datenbank lesen statt die Kopie fortzuschreiben:
        // vorlage_ausfuehren() verschiebt next_run, und die Schleife muss
        // den verschobenen Wert sehen, um zu wissen, wann sie fertig ist.
        $aktuell = $vorlage;

        while (
            $lauf < WIEDERHOLUNG_MAX_NACHHOLEN
            && wiederholung_faellig($aktuell['next_run'] ?? null, $heute)
        ) {
            $neu = vorlage_ausfuehren($pdo, $aktuell, $heute);
            if ($neu === null) {
                break;
            }
            $erzeugt++;
            $lauf++;

            $meldungen[] = 'Aus "' . ($aktuell['title'] ?? '#' . $aktuell['id'])
                         . '" wurde Eintrag #' . $neu . ' zum '
                         . substr((string) $aktuell['next_run'], 0, 10) . ' erzeugt.';

            // Die Bedingung auf den Papierkorb ist hier keine Formsache:
            // wandert die Vorlage waehrend des Nachholens hinein, muss
            // die Schleife aufhoeren. Ohne sie liefe sie mit der alten
            // Kopie weiter und erzeugte Rechnungen aus einer Vorlage,
            // die es nicht mehr gibt.
            $stmt = $pdo->prepare('SELECT * FROM finances WHERE id = ? AND deleted_at IS NULL');
            $stmt->execute([(int) $vorlage['id']]);
            $frisch = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$frisch) {
                break;
            }
            $aktuell = $frisch;
        }

        if ($lauf >= WIEDERHOLUNG_MAX_NACHHOLEN) {
            $meldungen[] = 'Vorlage #' . $vorlage['id'] . ' hat die Grenze von '
                         . WIEDERHOLUNG_MAX_NACHHOLEN . ' Einträgen je Lauf erreicht -'
                         . ' der Rest folgt beim nächsten Durchgang.';
        }
    }

    if ($erzeugt > 0) {
        log_event($pdo, 'RECURRING_CREATED', $erzeugt . ' wiederkehrende(r) Eintrag/Einträge erzeugt.');
    }

    return ['erzeugt' => $erzeugt, 'vorlagen' => count($vorlagen), 'meldungen' => $meldungen];
}
