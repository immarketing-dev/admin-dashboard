<?php
/**
 * Prueft die Uebersetzungstabelle gegen den Code.
 *
 * Der deutsche Text ist der Schluessel (siehe includes/i18n.php). Das ist
 * bequem, hat aber eine Schwachstelle: aendert jemand den deutschen Text,
 * findet t() die Uebersetzung nicht mehr und faellt still auf das Deutsche
 * zurueck. Auf einer englisch eingestellten Oberflaeche steht dann mitten
 * im Satz ein deutsches Wort, und niemand bemerkt es.
 *
 * Drei Pruefungen:
 *   1. Jede in t()/te() verpackte Zeichenkette hat eine Uebersetzung.
 *   2. Jeder Eintrag in lang/en.php wird auch verwendet (sonst ist sein
 *      deutsches Original weggeaendert worden - der Fall von oben).
 *   3. Die Platzhalter stimmen ueberein. Verliert die Uebersetzung ein
 *      %s, wirft vsprintf() zur Laufzeit einen Fehler.
 *
 * Aufruf: php tools/check_i18n.php
 */

$wurzel = dirname(__DIR__);
$fail = 0;

/**
 * Alle in t() oder te() verpackten Zeichenketten einer Datei.
 *
 * Ueber den Tokenizer, nicht per regulaerem Ausdruck: 't' ist ein sehr
 * kurzer Name, und ein Muster wie /\bt\(/ traefe auch mitten in anderen
 * Bezeichnern.
 */
function verpackte_texte(string $pfad): array
{
    $tokens = token_get_all(file_get_contents($pfad));
    $aus = [];

    for ($i = 0, $n = count($tokens); $i < $n; $i++) {
        $t = $tokens[$i];
        if (!is_array($t) || $t[0] !== T_STRING) continue;
        if ($t[1] !== 't' && $t[1] !== 'te') continue;

        // Kein Methodenaufruf und keine Definition.
        $davor = null;
        for ($j = $i - 1; $j >= 0; $j--) {
            if (is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) continue;
            $davor = $tokens[$j];
            break;
        }
        if ($davor === '->' || $davor === '::') continue;
        if (is_array($davor) && in_array($davor[0], [T_FUNCTION, T_OBJECT_OPERATOR,
                                                     T_DOUBLE_COLON, T_NEW], true)) continue;

        // Klammer, dann eine reine Zeichenkette.
        $k = $i + 1;
        while ($k < $n && is_array($tokens[$k]) && $tokens[$k][0] === T_WHITESPACE) $k++;
        if (($tokens[$k] ?? null) !== '(') continue;

        $k++;
        while ($k < $n && is_array($tokens[$k]) && $tokens[$k][0] === T_WHITESPACE) $k++;
        if (!is_array($tokens[$k]) || $tokens[$k][0] !== T_CONSTANT_ENCAPSED_STRING) {
            // Variable statt Zeichenkette - laesst sich hier nicht pruefen.
            continue;
        }

        // Anfuehrungszeichen entfernen und Maskierungen aufloesen.
        $roh = $tokens[$k][1];
        $text = substr($roh, 1, -1);
        $text = $roh[0] === "'"
            ? str_replace(["\\'", '\\\\'], ["'", '\\'], $text)
            : stripcslashes($text);

        $aus[$text] = ($aus[$text] ?? []);
        $aus[$text][] = basename($pfad) . ':' . $tokens[$k][2];
    }
    return $aus;
}

/** Platzhalter einer printf-Zeichenkette, sortiert. */
function platzhalter(string $text): array
{
    preg_match_all('/%(?:\d+\$)?[-+ 0\'#]*\d*(?:\.\d+)?[bcdeEfFgGosuxX%]/', $text, $m);
    $aus = array_filter($m[0], fn($p) => $p !== '%%');
    sort($aus);
    return $aus;
}

// ── Einsammeln ──────────────────────────────────────────────────────
$dateien = array_merge(glob($wurzel . '/*.php'), glob($wurzel . '/includes/*.php'));
$benutzt = [];
foreach ($dateien as $pfad) {
    foreach (verpackte_texte($pfad) as $text => $stellen) {
        $benutzt[$text] = array_merge($benutzt[$text] ?? [], $stellen);
    }
}

$sprachdatei = $wurzel . '/lang/en.php';
if (!is_file($sprachdatei)) {
    echo "FEHLER: lang/en.php fehlt.\n";
    exit(1);
}
$en = require $sprachdatei;

// ── 1. Ohne Uebersetzung ────────────────────────────────────────────
echo "=== Pruefung 1: jede verpackte Zeichenkette ist uebersetzt ===\n";
$ohne = [];
foreach ($benutzt as $text => $stellen) {
    if (!array_key_exists($text, $en)) $ohne[$text] = $stellen;
}
if ($ohne === []) {
    echo 'OK: alle ' . count($benutzt) . " verpackten Zeichenketten haben eine Uebersetzung.\n";
} else {
    foreach ($ohne as $text => $stellen) {
        echo "FEHLER: ohne Uebersetzung: '" . mb_substr($text, 0, 60) . "'\n";
        echo '        ' . implode(', ', array_slice($stellen, 0, 3)) . "\n";
    }
    echo "  Eintrag in lang/en.php ergaenzen. Verpackt, aber nicht uebersetzt heisst:\n";
    echo "  auf englischer Oberflaeche steht dort weiterhin Deutsch.\n";
    $fail = 1;
}
echo "\n";

// ── 2. Verwaiste Eintraege ──────────────────────────────────────────
echo "=== Pruefung 2: keine verwaisten Uebersetzungen ===\n";
$verwaist = array_keys(array_diff_key($en, $benutzt));
if ($verwaist === []) {
    echo 'OK: alle ' . count($en) . " Eintraege in lang/en.php werden verwendet.\n";
} else {
    foreach ($verwaist as $text) {
        echo "FEHLER: verwaist: '" . mb_substr($text, 0, 60) . "'\n";
    }
    echo "  Der deutsche Text wurde vermutlich geaendert. Dann findet t() die\n";
    echo "  Uebersetzung nicht mehr und faellt still auf Deutsch zurueck.\n";
    echo "  Schluessel angleichen oder Eintrag entfernen.\n";
    $fail = 1;
}
echo "\n";

// ── 3. Platzhalter ──────────────────────────────────────────────────
echo "=== Pruefung 3: Platzhalter stimmen ueberein ===\n";
$schief = [];
foreach ($en as $de => $eng) {
    $a = platzhalter((string) $de);
    $b = platzhalter((string) $eng);
    if ($a !== $b) {
        $schief[] = sprintf("'%s'\n        deutsch: %s\n        englisch: %s",
            mb_substr((string) $de, 0, 50),
            $a ? implode(' ', $a) : '(keine)',
            $b ? implode(' ', $b) : '(keine)');
    }
}
if ($schief === []) {
    echo "OK: Platzhalter stimmen in beiden Sprachen ueberein.\n";
} else {
    foreach ($schief as $s) echo "FEHLER: $s\n";
    echo "  vsprintf() wirft zur Laufzeit einen Fehler, wenn ein Platzhalter fehlt.\n";
    $fail = 1;
}
echo "\n";

echo "=== Zusammenfassung ===\n";
echo $fail
    ? "FEHLGESCHLAGEN: mindestens eine Pruefung ist fehlgeschlagen (siehe oben).\n"
    : 'OK: ' . count($benutzt) . ' Zeichenketten verpackt, ' . count($en) . " uebersetzt.\n";
exit($fail);
