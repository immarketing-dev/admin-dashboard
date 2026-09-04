<?php
/**
 * Spiegelt install/schema.sql nach SQLite.
 *
 * Auf der Entwicklungsmaschine steht kein MySQL zur Verfügung. Diese
 * Datei übersetzt das Schema so weit, dass SQLite es anlegen kann, und
 * stellt einen PDO-Aufsatz bereit, der die wenigen MySQL-eigenen
 * Anweisungen des Seeds unterwegs umschreibt. Damit lässt sich
 * tools/seed_demo_data.php hier wirklich ausführen.
 *
 * Genutzt von tools/test_seed_demo.php (prüft das Ergebnis) und
 * tools/export_demo_sql.php (schreibt es als MySQL-Dump heraus).
 *
 * Die Übersetzung ist bewusst knapp: Ziel sind gleiche Spaltennamen und
 * Fremdschlüssel, keine originalgetreue Nachbildung der Typen.
 */

/**
 * Zerlegt eine Klammer an den Kommas der obersten Ebene.
 *
 * Ein einfaches explode(',') würde DECIMAL(10,2) und mehrspaltige
 * Schlüssel mitten entzweischneiden.
 */
function oberste_ebene_teilen(string $s): array
{
    $teile = [];
    $puffer = '';
    $tiefe = 0;
    $in_text = false;

    for ($i = 0, $n = strlen($s); $i < $n; $i++) {
        $c = $s[$i];
        if ($c === "'") $in_text = !$in_text;
        if (!$in_text) {
            if ($c === '(')      { $tiefe++; }
            elseif ($c === ')')  { $tiefe--; }
            elseif ($c === ',' && $tiefe === 0) { $teile[] = $puffer; $puffer = ''; continue; }
        }
        $puffer .= $c;
    }
    if (trim($puffer) !== '') $teile[] = $puffer;
    return $teile;
}

/**
 * Übersetzt das MySQL-Schema so weit, dass SQLite es anlegen kann.
 *
 * Bewusst knapp gehalten: das Ziel ist eine Tabelle mit denselben
 * Spaltennamen und Fremdschlüsseln, nicht eine originalgetreue
 * Nachbildung der Typen. Alles, was SQLite ohnehin dynamisch behandelt,
 * wird auf TEXT, INTEGER oder REAL abgebildet.
 */
function nach_sqlite(string $sql): array
{
    // Zuerst die Kommentare, und zwar zeilenweise: install/schema.sql
    // erklärt darin unter anderem Fremdschlüsselverhalten, und diese
    // Sätze enthalten Semikolons. Bliebe der Kommentar stehen, zerlegte
    // das anschließende explode(';') die Anweisungen an der falschen
    // Stelle - genau daran ist der erste Anlauf gescheitert.
    $zeilen = [];
    foreach (preg_split('/\R/', $sql) as $zeile) {
        $zeilen[] = preg_replace('/--.*$/', '', $zeile);
    }
    $sql = implode("\n", $zeilen);

    // Anweisungen, die SQLite nicht kennt und die hier nichts beitragen.
    $sql = preg_replace('/^\s*SET\s+(NAMES|foreign_key_checks)[^;]*;/mi', '', $sql);

    // Der Seed-Eintrag am Ende nutzt MySQL-Syntax.
    $sql = preg_replace('/INSERT INTO settings[^;]*ON DUPLICATE KEY UPDATE[^;]*;/is',
                        "INSERT OR REPLACE INTO settings (k, v) VALUES ('schema_version', '7');", $sql);

    $anweisungen = [];
    foreach (explode(';', $sql) as $teil) {
        $teil = trim($teil);
        if ($teil === '') continue;

        if (stripos($teil, 'CREATE TABLE') !== false) {
            // Tabellenoptionen abschneiden.
            $teil = preg_replace('/\)\s*ENGINE=.*$/is', ')', $teil);

            // Typen abbilden. ENUM kann über mehrere Zeilen gehen.
            $teil = preg_replace('/\bINT\s+AUTO_INCREMENT\s+PRIMARY\s+KEY\b/i',
                                 'INTEGER PRIMARY KEY AUTOINCREMENT', $teil);
            $teil = preg_replace('/\bENUM\s*\([^)]*\)/is', 'TEXT', $teil);
            $teil = preg_replace('/\bON UPDATE CURRENT_TIMESTAMP\b/i', '', $teil);
            $teil = preg_replace('/\b(LONGTEXT|MEDIUMTEXT|JSON)\b/i', 'TEXT', $teil);
            $teil = preg_replace('/\b(DATETIME|TIMESTAMP|DATE|TIME)\b/i', 'TEXT', $teil);
            $teil = preg_replace('/\bDECIMAL\s*\(\s*\d+\s*,\s*\d+\s*\)/i', 'REAL', $teil);
            $teil = preg_replace('/\bTINYINT(\s+UNSIGNED|\s*\(\d+\))?/i', 'INTEGER', $teil);

            // UNIQUE KEY behalten (fängt doppelte Rechnungsnummern),
            // gewöhnliche Indizes entfallen.
            $teil = preg_replace('/\bUNIQUE\s+KEY\s+\w+\s*\(/i', 'UNIQUE (', $teil);
            $teil = preg_replace('/^\s*(KEY|INDEX)\s+\w+\s*\([^)]*\)\s*,?\s*$/mi', '', $teil);

            // Leerzeilen aus entfallenen Indizes zusammenziehen, danach
            // das dadurch verwaiste Komma vor der schliessenden Klammer.
            $teil = preg_replace('/\n[ \t]*\n/', "\n", $teil);
            $teil = preg_replace('/,\s*\)\s*$/s', "\n)", trim($teil));

            // SQLite verlangt erst alle Spalten, dann die Tabellen-
            // bedingungen. In schema.sql stehen sie gemischt, weil jede
            // Migration ihre neue Spalte ans Ende der Klammer gehängt hat
            // - hinter die CONSTRAINT-Zeilen. MySQL nimmt das hin.
            if (preg_match('/^(CREATE TABLE[^(]*\()(.*)(\))\s*$/s', $teil, $m)) {
                $spalten = [];
                $bedingungen = [];
                foreach (oberste_ebene_teilen($m[2]) as $eintrag) {
                    $roh = trim($eintrag);
                    if ($roh === '') continue;
                    if (preg_match('/^(CONSTRAINT|UNIQUE|PRIMARY\s+KEY|FOREIGN\s+KEY|CHECK)\b/i', $roh)) {
                        $bedingungen[] = $roh;
                    } else {
                        $spalten[] = $roh;
                    }
                }
                $teil = $m[1] . "\n  " . implode(",\n  ", array_merge($spalten, $bedingungen)) . "\n)";
            }
        }
        $anweisungen[] = $teil;
    }
    return $anweisungen;
}

/**
 * PDO-Aufsatz, der die MySQL-eigenen Anweisungen uebersetzt, die hier
 * vorkommen. Der gespiegelte Code bleibt dadurch frei von Ruecksichten
 * auf diese Spiegelung - er soll so laufen wie im Betrieb.
 */
class SqliteSpiegelPDO extends PDO
{
    public function exec(string $statement): int|false
    {
        if (preg_match('/^\s*SET\s+FOREIGN_KEY_CHECKS/i', $statement)) return 0;
        $statement = preg_replace('/^\s*TRUNCATE TABLE\s+/i', 'DELETE FROM ', $statement);
        return parent::exec($statement);
    }

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        if (stripos($query, 'ON DUPLICATE KEY UPDATE') !== false) {
            $query = preg_replace('/\s*ON DUPLICATE KEY UPDATE.*$/is', '', $query);
            $query = preg_replace('/^\s*INSERT INTO/i', 'INSERT OR REPLACE INTO', $query);
        }
        // MySQL schreibt INSERT IGNORE, SQLite INSERT OR IGNORE - beide
        // ueberspringen eine Zeile, die einen eindeutigen Schluessel
        // verletzen wuerde.
        $query = preg_replace('/^\s*INSERT\s+IGNORE\s+INTO/i', 'INSERT OR IGNORE INTO', $query);
        return parent::prepare($query, $options);
    }
}
