<?php
/**
 * Prueft, dass jede aufgerufene Projektfunktion auch geladen wird.
 *
 * settings.php rief zwoelfmal log_event() auf, ohne
 * includes/logging.php einzubinden. Ergebnis: jede Speicheraktion auf der
 * Einstellungsseite endete mit HTTP 500 - die Daten wurden noch
 * geschrieben, die Rueckmeldung blieb aus, und beim Zuruecksetzen der
 * Farben passierte gar nichts.
 *
 * "php -l" sieht so etwas nicht: ein Aufruf einer nicht existierenden
 * Funktion ist syntaktisch einwandfrei und faellt erst zur Laufzeit auf.
 * Auf einer Seite, die man selten benutzt, kann das lange dauern.
 *
 * Vorgehen: fuer jede Seite im Wurzelverzeichnis wird der transitive
 * Abschluss ihrer require-Anweisungen gebildet. Alle darin definierten
 * Funktionen sind verfuegbar; alle darin aufgerufenen Projektfunktionen
 * muessen darunter sein.
 *
 * Geprueft werden nur die Seiten im Wurzelverzeichnis - sie sind die
 * Einstiegspunkte. Dateien unter includes/ sind Bruchstuecke: sie duerfen
 * sich darauf verlassen, dass die einbindende Seite das Noetige geladen
 * hat, und einzeln betrachtet saehe jede von ihnen fehlerhaft aus.
 *
 * Aufruf: php tools/check_includes.php
 */

$wurzel = dirname(__DIR__);

/** Alle PHP-Dateien des Projekts ausser vendor/. */
function projektdateien(string $wurzel): array
{
    $aus = [];
    foreach (['', 'includes/'] as $unter) {
        foreach (glob($wurzel . '/' . $unter . '*.php') as $pfad) {
            $aus[] = str_replace('\\', '/', substr($pfad, strlen($wurzel) + 1));
        }
    }
    return $aus;
}

/**
 * Funktionen, die eine Datei definiert.
 *
 * Nur Funktionen auf oberster Ebene; Methoden in Klassen zaehlen nicht,
 * die werden ueber ihr Objekt aufgeloest.
 */
function definierte_funktionen(string $pfad): array
{
    $aus = [];
    $tokens = token_get_all(file_get_contents($pfad));
    $tiefe = 0;
    $in_klasse = [];

    for ($i = 0, $n = count($tokens); $i < $n; $i++) {
        $t = $tokens[$i];

        if (is_string($t)) {
            if ($t === '{') $tiefe++;
            elseif ($t === '}') {
                if ($in_klasse !== [] && end($in_klasse) === $tiefe) array_pop($in_klasse);
                $tiefe--;
            }
            continue;
        }

        if ($t[0] === T_CLASS || $t[0] === T_INTERFACE || $t[0] === T_TRAIT) {
            $in_klasse[] = $tiefe + 1;
            continue;
        }

        if ($t[0] !== T_FUNCTION) continue;
        if ($in_klasse !== []) continue;            // Methode

        // Naechstes bedeutsames Token ist der Name (sonst: anonyme Funktion).
        for ($j = $i + 1; $j < $n; $j++) {
            if (is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) continue;
            if (is_array($tokens[$j]) && $tokens[$j][0] === T_STRING) {
                $aus[] = strtolower($tokens[$j][1]);
            }
            break;
        }
    }
    return $aus;
}

/** Funktionen, die eine Datei aufruft. */
function aufgerufene_funktionen(string $pfad): array
{
    $aus = [];
    $tokens = token_get_all(file_get_contents($pfad));

    for ($i = 0, $n = count($tokens); $i < $n; $i++) {
        $t = $tokens[$i];
        if (!is_array($t) || $t[0] !== T_STRING) continue;

        // Folgt eine oeffnende Klammer?
        $klammer = false;
        for ($j = $i + 1; $j < $n; $j++) {
            if (is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) continue;
            $klammer = ($tokens[$j] === '(');
            break;
        }
        if (!$klammer) continue;

        // Kein Methodenaufruf, keine Definition, kein new.
        $davor = null;
        for ($j = $i - 1; $j >= 0; $j--) {
            if (is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) continue;
            $davor = $tokens[$j];
            break;
        }
        if ($davor === '->' || $davor === '::') continue;
        if (is_array($davor) && in_array($davor[0],
            [T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_NEW, T_CLASS], true)) continue;

        $aus[] = strtolower($t[1]);
    }
    return array_unique($aus);
}

/**
 * Dateien, die eine Datei direkt einbindet.
 *
 * Nur literale Pfade - ein zusammengesetzter Pfad liesse sich hier
 * ohnehin nicht aufloesen, und im Projekt kommt keiner vor.
 */
function direkte_einbindungen(string $pfad, string $wurzel): array
{
    $code = file_get_contents($pfad);
    $aus = [];

    if (preg_match_all(
            '/\b(?:require|include)(?:_once)?\s*(?:\(\s*)?(?:__DIR__\s*\.\s*)?[\'"]([^\'"]+)[\'"]/',
            $code, $treffer)) {
        foreach ($treffer[1] as $ziel) {
            $ziel = ltrim(str_replace('\\', '/', $ziel), '/');
            $ziel = preg_replace('#^\./#', '', $ziel);
            // Relativ zum Verzeichnis der einbindenden Datei aufloesen.
            $basis = dirname($pfad);
            $kandidaten = [$wurzel . '/' . $ziel, $basis . '/' . $ziel];
            foreach ($kandidaten as $k) {
                $echt = realpath($k);
                if ($echt !== false) {
                    $aus[] = str_replace('\\', '/', substr($echt, strlen(realpath($wurzel)) + 1));
                    break;
                }
            }
        }
    }
    return array_unique($aus);
}

// ---------------------------------------------------------------------
$dateien = projektdateien($wurzel);

$definiert = [];   // datei => [funktionen]
$aufgerufen = [];  // datei => [funktionen]
$bindet = [];      // datei => [dateien]
$alle_projektfunktionen = [];

foreach ($dateien as $d) {
    $voll = $wurzel . '/' . $d;
    $definiert[$d]  = definierte_funktionen($voll);
    $aufgerufen[$d] = aufgerufene_funktionen($voll);
    $bindet[$d]     = direkte_einbindungen($voll, $wurzel);
    foreach ($definiert[$d] as $f) $alle_projektfunktionen[$f] = $d;
}

/** Transitiver Abschluss der Einbindungen. */
function abschluss(string $start, array $bindet): array
{
    $gesehen = [$start => true];
    $stapel  = [$start];
    while ($stapel) {
        $aktuell = array_pop($stapel);
        foreach ($bindet[$aktuell] ?? [] as $n) {
            if (isset($gesehen[$n])) continue;
            $gesehen[$n] = true;
            $stapel[] = $n;
        }
    }
    return array_keys($gesehen);
}

echo "=== Pruefung: jede aufgerufene Projektfunktion ist geladen ===\n";

$fehler = [];
$geprueft = 0;

foreach ($dateien as $d) {
    if (strpos($d, 'includes/') === 0) continue;   // Bruchstueck, kein Einstiegspunkt
    $geprueft++;

    $kette = abschluss($d, $bindet);

    $verfuegbar = [];
    $benutzt    = [];
    foreach ($kette as $k) {
        foreach ($definiert[$k]  ?? [] as $f) $verfuegbar[$f] = true;
        foreach ($aufgerufen[$k] ?? [] as $f) $benutzt[$f] = $k;
    }

    foreach ($benutzt as $f => $woher) {
        if (!isset($alle_projektfunktionen[$f])) continue;   // eingebaute Funktion
        if (isset($verfuegbar[$f])) continue;

        $fehler[] = sprintf('%s ruft %s() auf (aus %s), laedt aber %s nicht',
            $d, $f, $woher, $alle_projektfunktionen[$f]);
    }
}

if ($fehler === []) {
    echo "OK: $geprueft Einstiegspunkte geprueft, alle aufgerufenen Projektfunktionen "
       . 'sind ueber die require-Kette erreichbar (' . count($alle_projektfunktionen)
       . " Funktionen im Projekt).\n";
    exit(0);
}

foreach ($fehler as $f) echo "FEHLER: $f\n";
echo "\n  Eine solche Stelle endet zur Laufzeit mit HTTP 500. 'php -l' bemerkt sie\n";
echo "  nicht - der Aufruf ist syntaktisch einwandfrei.\n";
exit(1);
