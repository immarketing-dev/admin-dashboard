<?php
/**
 * Zaehlt Platzhalter gegen uebergebene Werte.
 * Aufruf: php tools/check_placeholders.php
 *
 * Der Anlass: beim Erweitern der Rechnungstabelle bekam eine Abfrage
 * vier Spalten mehr, die zugehoerige execute()-Zeile aber nicht. "php -l"
 * sieht das nicht - die Datei ist syntaktisch tadellos. Es faellt erst
 * auf, wenn jemand die Aktion ausloest, und dann als HTTP 500 mitten im
 * Speichern einer Rechnung.
 *
 * Geprueft wird nur das eindeutig Erkennbare: ein prepare() mit einer
 * durchgehenden Zeichenkette, gefolgt von einem execute() mit einem
 * woertlichen Feld. Alles andere - Werte aus Variablen, dynamisch
 * zusammengesetzte IN-Listen - wird uebersprungen, denn dort ist die
 * Zahl der Werte zur Pruefzeit nicht bekannt.
 */

/**
 * Entfernt Kommentare aus einem Stueck PHP-Code.
 *
 * Der Zaehler weiter unten laeuft zeichenweise und wuerde jedes Komma in
 * einem Kommentar als Werttrenner lesen. Ein erklaerender Satz zwischen
 * zwei Werten - und in diesem Projekt stehen Kommentare gern genau dort,
 * wo etwas erklaerungsbeduerftig ist - ergab so "14 Platzhalter, 17
 * Werte" fuer eine tadellose Abfrage.
 *
 * token_get_all() braucht ein oeffnendes Tag; es wird vorn angesetzt und
 * das entsprechende Token danach uebersprungen.
 */
function ohne_kommentare(string $abschnitt): string
{
    $aus = '';
    foreach (token_get_all('<?php ' . $abschnitt) as $t) {
        if (is_array($t)) {
            if ($t[0] === T_COMMENT || $t[0] === T_DOC_COMMENT || $t[0] === T_OPEN_TAG) {
                // Ein Zeilenkommentar traegt sein Zeilenende selbst; ohne
                // Ersatz wuechse die naechste Zeile an die vorige an. Fuer
                // die Zaehlung ist das gleichgueltig, fuer die Lesbarkeit
                // beim Nachsehen nicht.
                $aus .= "\n";
                continue;
            }
            $aus .= $t[1];
        } else {
            $aus .= $t;
        }
    }
    return $aus;
}

/**
 * Liest ab $start bis zur passenden schliessenden eckigen Klammer.
 *
 * $start zeigt hinter das oeffnende "[". Zeichenketten werden
 * uebersprungen, damit ein "]" darin nicht als Ende zaehlt.
 */
function klammer_inhalt(string $quelle, int $start): ?string
{
    $tiefe  = 1;
    $inText = '';

    for ($i = $start, $n = strlen($quelle); $i < $n; $i++) {
        $z = $quelle[$i];

        if ($inText !== '') {
            if ($z === chr(92)) { $i++; continue; }
            if ($z === $inText) $inText = '';
            continue;
        }
        if ($z === '"' || $z === "'") { $inText = $z; continue; }
        if ($z === '[') $tiefe++;
        if ($z === ']') {
            $tiefe--;
            if ($tiefe === 0) return substr($quelle, $start, $i - $start);
        }
    }
    return null;
}

$wurzel  = dirname(__DIR__);
$dateien = array_merge(
    glob($wurzel . '/*.php'),
    glob($wurzel . '/includes/*.php')
);

$funde    = [];
$geprueft = 0;

foreach ($dateien as $datei) {
    $rel    = str_replace('\\', '/', ltrim(str_replace($wurzel, '', $datei), '/\\'));
    $quelle = file_get_contents($datei);

    // prepare("…")->execute([…])  oder  $x = prepare("…"); $x->execute([…])
    // Beide Formen kommen im Projekt vor.
    // Nur bis zum oeffnenden "[" der Werte per Muster - der Werteteil
    // selbst wird danach klammernbewusst gelesen. Ein nicht-gieriges
    // ".*?\]" endete sonst am ERSTEN "])", und das steht schon in
    // trim($_POST['x']) mitten in der Liste. Der Zaehler meldete
    // daraufhin "7 Platzhalter, 2 Werte" fuer eine tadellose Abfrage.
    $muster = '/prepare\(\s*(["\'])(.*?)\1\s*\)\s*(?:;\s*\$\w+)?\s*->\s*execute\(\s*\[/s';

    if (!preg_match_all($muster, $quelle, $treffer, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
        continue;
    }

    foreach ($treffer as $t) {
        $sql   = $t[2][0];
        $werte = klammer_inhalt($quelle, $t[0][1] + strlen($t[0][0]));
        if ($werte === null) {
            continue;   // unausgeglichene Klammern - nicht auswertbar
        }

        // Dynamisch zusammengesetzt: die Zahl der Platzhalter steht erst
        // zur Laufzeit fest (IN-Listen, angehaengte WHERE-Teile).
        if (strpos($sql, '$') !== false) {
            continue;
        }
        // Kommentare heraus, bevor gezaehlt wird - ihre Kommas sind
        // keine Werttrenner (siehe ohne_kommentare()).
        $werte = ohne_kommentare($werte);

        // Werte, die selbst aus einem Aufruf oder einer Ausbreitung
        // kommen, lassen sich nicht zaehlen.
        if (strpos($werte, '...') !== false) {
            continue;
        }

        $platzhalter = substr_count($sql, '?');
        if ($platzhalter === 0) {
            continue;
        }

        // Werte auf oberster Ebene zaehlen: Kommas innerhalb von
        // Klammern, eckigen Klammern oder Zeichenketten zaehlen nicht mit
        // ($a['x'], f($b, $c) und "a,b" sind je EIN Wert).
        $anzahl  = 0;
        $tiefe   = 0;
        $inText  = '';
        $hatWert = false;

        for ($i = 0, $n = strlen($werte); $i < $n; $i++) {
            $z = $werte[$i];

            if ($inText !== '') {
                if ($z === $inText && ($i === 0 || $werte[$i - 1] !== '\\')) $inText = '';
                continue;
            }
            if ($z === '"' || $z === "'") { $inText = $z; $hatWert = true; continue; }
            if (strpos('([{', $z) !== false) { $tiefe++; $hatWert = true; continue; }
            if (strpos(')]}', $z) !== false) { $tiefe--; continue; }
            if ($z === ',' && $tiefe === 0) { $anzahl++; $hatWert = false; continue; }
            if (!ctype_space($z)) $hatWert = true;
        }
        if ($hatWert) $anzahl++;

        $geprueft++;
        if ($anzahl === $platzhalter) {
            continue;
        }

        $zeile = substr_count(substr($quelle, 0, $t[0][1]), "\n") + 1;
        $funde[] = [
            'datei' => $rel,
            'zeile' => $zeile,
            'p'     => $platzhalter,
            'w'     => $anzahl,
            'sql'   => trim(preg_replace('/\s+/', ' ', substr($sql, 0, 80))),
        ];
    }
}

echo "=== Platzhalter gegen uebergebene Werte ===\n\n";
echo "Geprueft: $geprueft Abfrage(n) mit fester Platzhalterzahl\n\n";

if (!$funde) {
    echo "OK: jede Abfrage bekommt so viele Werte, wie sie Platzhalter hat.\n";
    exit(0);
}

$letzte = '';
foreach ($funde as $f) {
    if ($f['datei'] !== $letzte) {
        echo "\n{$f['datei']}\n";
        $letzte = $f['datei'];
    }
    printf("  Zeile %-5d %d Platzhalter, %d Wert(e): %s\n", $f['zeile'], $f['p'], $f['w'], $f['sql']);
}

echo "\nFEHLGESCHLAGEN: " . count($funde) . " Abfrage(n) mit falscher Wertzahl.\n";
exit(1);
