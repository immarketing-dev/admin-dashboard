<?php
/**
 * Sucht SQL, das auf einer MySQL-Erweiterung beruht.
 *
 * Anlass: die Auswertungsseite lief auf der oeffentlichen Demo mit
 * HTTP 500, waehrend sie hier einwandfrei rendert. Der Grund ist
 * bauartbedingt: die Pruefumgebung dieses Projekts hat keinen
 * MySQL-Server, alles laeuft gegen die SQLite-Spiegelung in
 * tools/lib_sqlite_mirror.php. Was SQLite durchwinkt, muss ein echter
 * Server nicht annehmen - und umgekehrt sieht kein Test hier, was dort
 * scheitert.
 *
 * Geprueft wird der eine Fall, der sich zuverlaessig erkennen laesst:
 * ein Spalten-Alias aus der SELECT-Liste, der in GROUP BY oder HAVING
 * wieder auftaucht.
 *
 *     SELECT COALESCE(a, b) AS kunde ... GROUP BY kunde
 *
 * Das ist eine Erweiterung von MySQL. Standard-SQL verlangt an beiden
 * Stellen den Ausdruck selbst, und wer den Ausdruck ausschreibt, laeuft
 * ueberall. ORDER BY bleibt aussen vor: dort ist ein einzelner Alias
 * seit SQL:2003 zulaessig und ueberall unterstuetzt.
 *
 * Aufruf: php tools/check_sql_portability.php
 */

chdir(dirname(__DIR__));

$dateien = array_merge(glob('*.php'), glob('includes/*.php'), glob('api/*.php'));

// Namen, die kein Alias sind, auch wenn sie hinter AS stehen koennten.
$schluesselwoerter = ['asc', 'desc', 'and', 'or', 'not', 'null', 'case', 'when', 'then', 'else', 'end'];

$funde = [];

foreach ($dateien as $datei) {
    foreach (token_get_all(file_get_contents($datei)) as $t) {
        if (!is_array($t) || $t[0] !== T_CONSTANT_ENCAPSED_STRING) {
            continue;
        }
        $sql = trim($t[1], "'\"");
        if (!preg_match('/\bSELECT\b/i', $sql)) {
            continue;
        }
        if (!preg_match('/\b(GROUP\s+BY|HAVING)\b/i', $sql)) {
            continue;
        }

        // Die Aliase der SELECT-Liste.
        if (!preg_match_all('/\bAS\s+`?([a-z_][a-z0-9_]*)`?/i', $sql, $m)) {
            continue;
        }
        $aliase = array_unique(array_map('strtolower', $m[1]));

        // Was hinter GROUP BY bzw. HAVING steht, bis zur naechsten Klausel.
        foreach (['GROUP\s+BY', 'HAVING'] as $klausel) {
            if (!preg_match(
                '/\b' . $klausel . '\b(.*?)(?=\b(HAVING|ORDER\s+BY|LIMIT|UNION|\)\s*$)\b|$)/is',
                $sql,
                $k
            )) {
                continue;
            }
            $teil = $k[1];

            foreach ($aliase as $alias) {
                if (in_array($alias, $schluesselwoerter, true)) {
                    continue;
                }
                // Als eigenstaendiges Wort, und nicht qualifiziert
                // (t.kunde waere eine echte Spalte).
                if (preg_match('/(?<![.`\w])' . preg_quote($alias, '/') . '\b/i', $teil)) {
                    $funde[] = [
                        'datei'   => $datei,
                        'zeile'   => $t[2],
                        'klausel' => preg_replace('/\\\\s\+/', ' ', $klausel),
                        'alias'   => $alias,
                    ];
                }
            }
        }
    }
}

echo "=== Pruefung: kein Alias in GROUP BY oder HAVING ===\n";

if ($funde === []) {
    echo 'OK: ' . count($dateien) . " Dateien geprueft, keine Abfrage stuetzt sich darauf.\n";
    exit(0);
}

foreach ($funde as $f) {
    printf("FEHLER: %s:%d  Alias '%s' in %s\n", $f['datei'], $f['zeile'], $f['alias'], $f['klausel']);
}
echo "\n" . count($funde) . " Stelle(n). Den Ausdruck ausschreiben statt den Alias zu nennen:\n"
   . "ein Alias ist dort eine Erweiterung von MySQL, kein Standard-SQL, und\n"
   . "die Spiegelung in tools/lib_sqlite_mirror.php winkt ihn durch.\n";
exit(1);
