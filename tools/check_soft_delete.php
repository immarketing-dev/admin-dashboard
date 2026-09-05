<?php
/**
 * Prüft, dass gelöschte Datensätze nirgends mehr auftauchen.
 *
 * Seit Migration 4 tragen contacts, tasks, finances und quotes eine Spalte
 * deleted_at. Eine Abfrage, die sie nicht berücksichtigt, holt Gelöschtes
 * zurück in eine Liste, eine Summe oder ein Auswahlfeld — und das fällt
 * erst auf, wenn es jemandem auffällt.
 *
 * Geprüft wird jede SELECT-Abfrage, in der eine der vier Tabellen hinter
 * FROM steht. Sie muss entweder deleted_at erwähnen oder in der Liste der
 * geprüften Ausnahmen stehen — mit Begründung, damit eine Ausnahme eine
 * Entscheidung bleibt und kein Versehen.
 *
 * Die Abfragen werden über token_get_all() aus dem Quelltext geholt, nicht
 * über einen Ausdruck auf dem Rohtext: so werden auch über mehrere Zeilen
 * verkettete Zeichenketten als eine Abfrage erkannt, und Vorkommen in
 * Kommentaren zählen nicht mit.
 *
 * Aufruf: php tools/check_soft_delete.php
 */

const WEICHE_TABELLEN = ['contacts', 'tasks', 'finances', 'quotes'];

/**
 * Geprüfte Ausnahmen: Datei => ['*'] für die ganze Datei, sonst eine Liste
 * von Textstücken, die eine Abfrage eindeutig kennzeichnen.
 */
const AUSNAHMEN = [
    // Der Papierkorb zeigt das Gelöschte ja gerade.
    'trash.php' => ['*'],
    // Migrationen arbeiten am Schema, nicht an Nutzdaten.
    'includes/migrations.php' => ['*'],

    // invoice.php sucht eine bestehende Rechnung ueber ihre Nummer, um sie
    // zu aktualisieren statt neu anzulegen. Wuerde sie Geloeschtes uebersehen,
    // entstuende eine zweite Zeile mit derselben Nummer - und der eindeutige
    // Index aus Migration 3 wiese sie ab. Eine geloeschte Rechnung wird beim
    // Neuerzeugen wiederhergestellt (siehe dort).
    'invoice.php' => ['SELECT id FROM finances WHERE title = ?'],

    // Die Bedingung steht dort in $kpi_where, weil der Zeitraumfilter an
    // dieselbe Stelle angehaengt wird. Der Pruefer sieht nur Zeichenketten,
    // keine Variableninhalte - siehe finances.php an der Zuweisung.
    'finances.php' => ['SELECT type, status, amount FROM finances'],

    // Die beiden Verwalter-Zweige holen bewusst auch Geloeschtes: der
    // Papierkorb stellt eine Rechnung wieder her, und dazu gehoert ihr
    // PDF. Fuer den Kunden gilt das NICHT - dessen Zweige tragen
    // deleted_at IS NULL und stehen deshalb nicht in dieser Liste.
    // tools/test_file_access.php haelt beide Faelle einzeln nach.
    // stundensatz() liest eine Stammdatenangabe, keine Liste: der Preis
    // eines Projekts gilt auch dann, wenn das Projekt im Papierkorb
    // liegt. Wuerde hier gefiltert, faende die Funktion nichts und
    // fiele stillschweigend auf die Voreinstellung zurueck - die
    // Abrechnung alter Zeiten bekaeme einen anderen Preis als
    // vereinbart, ohne dass es jemandem auffiele.
    'includes/time_billing.php' => [
        'SELECT t.hourly_rate AS projekt, c.hourly_rate AS kunde',
    ],

    'includes/file_access.php' => [
        'SELECT invoice_pdf_path AS pfad FROM finances WHERE id = ?',
        'SELECT quote_pdf_path AS pfad FROM quotes WHERE id = ?',
        // Belege folgen derselben Regel. Der Zweig hat gar keine
        // Kunden-Variante: ein Ausgabenbeleg ist die Rechnung eines
        // Dritten an den Betreiber und geht das Portal nie etwas an.
        'SELECT receipt_path AS pfad FROM finances WHERE id = ?',
    ],
];

$wurzel  = dirname(__DIR__);
$dateien = array_merge(
    glob($wurzel . '/*.php'),
    glob($wurzel . '/includes/*.php'),
    glob($wurzel . '/api/*.php') ?: []
);

$funde    = [];
$geprueft = 0;

foreach ($dateien as $datei) {
    $rel = str_replace('\\', '/', ltrim(str_replace($wurzel, '', $datei), '/\\'));
    if ((AUSNAHMEN[$rel] ?? null) === ['*']) continue;

    $quelle = file_get_contents($datei);
    $tokens = token_get_all($quelle);

    // Aufeinanderfolgende Zeichenketten, die mit '.' verkettet sind, zu
    // einer logischen Abfrage zusammenfassen. Dazwischen dürfen Variablen
    // stehen (Parameter, Tabellenpräfixe) - die werden übersprungen.
    $puffer = '';
    $zeile  = 0;
    $spuel = function () use (&$puffer, &$zeile, &$funde, &$geprueft, $rel) {
        if ($puffer === '') return;
        $sql = $puffer;
        $puffer = '';

        if (stripos($sql, 'SELECT') === false) return;
        if (!preg_match('/\bFROM\s+(' . implode('|', WEICHE_TABELLEN) . ')\b/i', $sql, $m)) return;

        $geprueft++;

        // Zaehlen statt nur nachsehen, ob das Wort vorkommt.
        //
        // Der Anlass: das Deadline-Widget in ajax_poll.php verband per
        // UNION ALL eine Abfrage auf tasks mit einer auf finances. Die
        // erste Haelfte trug "deleted_at IS NULL", die zweite nicht - und
        // weil das Wort damit irgendwo im SQL stand, ging die Pruefung
        // durch. Geloeschte Rechnungen tauchten monatelang unter den
        // faelligen auf.
        //
        // Nicht exakt, aber am richtigen Ende grob: kommen mehrere weiche
        // Tabellen vor, muss "deleted_at" mindestens ebenso oft
        // auftauchen. Falsch-positiv ist es nur dort, wo eine Abfrage
        // dieselbe Tabelle mehrfach nennt und eine Bedingung fuer beide
        // reicht - dann greift die Ausnahmeliste unten.
        // Bewusst nur FROM, nicht JOIN: ein LEFT JOIN auf contacts soll
        // einen geloeschten Kontakt in aller Regel MITnehmen - sonst
        // stuende bei einer Rechnung an einen inzwischen geloeschten
        // Kunden gar kein Name mehr. Mit JOIN in diesem Muster meldete
        // die Pruefung 22 solcher Stellen, und keine davon war falsch.
        // Mehrere FROM dagegen heisst UNION - und da hat jeder Zweig
        // seine eigene Bedingung noetig.
        $tabellen_treffer = preg_match_all(
            '/\bFROM\s+(?:' . implode('|', WEICHE_TABELLEN) . ')\b/i',
            $sql
        );
        $noetig = max(1, $tabellen_treffer);
        if (substr_count(strtolower($sql), 'deleted_at') >= $noetig) return;

        foreach (AUSNAHMEN[$rel] ?? [] as $muster) {
            if ($muster !== '*' && strpos($sql, $muster) !== false) return;
        }

        $funde[] = [
            'datei'   => $rel,
            'zeile'   => $zeile,
            'tabelle' => strtolower($m[1]),
            'sql'     => trim(preg_replace('/\s+/', ' ', mb_substr($sql, 0, 100))),
        ];
    };

    foreach ($tokens as $t) {
        if (is_array($t)) {
            $id = $t[0];
            if ($id === T_CONSTANT_ENCAPSED_STRING || $id === T_ENCAPSED_AND_WHITESPACE) {
                if ($puffer === '') $zeile = $t[2];
                $puffer .= ' ' . trim($t[1], "\"'");
                continue;
            }
            if ($id === T_WHITESPACE || $id === T_VARIABLE || $id === T_OBJECT_OPERATOR
                || $id === T_STRING || $id === T_LNUMBER || $id === T_COMMENT
                || $id === T_DOC_COMMENT || $id === T_START_HEREDOC || $id === T_END_HEREDOC) {
                continue;   // unterbricht eine Verkettung nicht
            }
            $spuel();
            continue;
        }
        if ($t === '.' || $t === '(' || $t === ')' || $t === '[' || $t === ']') continue;
        $spuel();
    }
    $spuel();
}

echo "=== Papierkorb: Abfragen ohne deleted_at ===\n\n";
echo "Geprueft: $geprueft SELECT-Abfrage(n) auf " . implode(', ', WEICHE_TABELLEN) . "\n\n";

if (!$funde) {
    echo "OK: jede Abfrage beruecksichtigt geloeschte Datensaetze.\n";
    exit(0);
}

$proDatei = [];
foreach ($funde as $f) { $proDatei[$f['datei']][] = $f; }
ksort($proDatei);
foreach ($proDatei as $datei => $liste) {
    echo "$datei\n";
    foreach ($liste as $f) {
        printf("  Zeile %-5s [%-8s] %s\n", $f['zeile'], $f['tabelle'], $f['sql']);
    }
    echo "\n";
}
echo 'FEHLGESCHLAGEN: ' . count($funde) . " Abfrage(n) ohne deleted_at.\n";
echo "Entweder die Bedingung ergaenzen oder die Abfrage in AUSNAHMEN\n";
echo "in dieser Datei eintragen - mit Begruendung.\n";
exit(1);
