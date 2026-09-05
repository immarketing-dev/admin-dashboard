<?php
// Prueft install/schema.sql strukturell.
//
// Warum so ausfuehrlich: In der Entwicklungsumgebung dieses Projekts gibt
// es keinen mysql-Client, keinen MySQL/MariaDB-Server und kein pdo_mysql
// in PHP. Ein echter "mysql < schema.sql"-Import ist hier nicht moeglich.
// Dieses Skript ist deshalb der einzige Schutz vor einem kaputten Schema
// vor der Veroeffentlichung und prueft daher nicht nur Tabellennamen,
// sondern auch Klammerbalance, doppelte Spalten, Fremdschluessel-Ziele,
// KEY/INDEX-Spalten, Definitionsreihenfolge und die schema_version-Zeile.
//
// Aufruf: php tools/check_schema.php

$root   = dirname(__DIR__);
$schema = $root . '/install/schema.sql';

if (!is_readable($schema)) {
    fwrite(STDERR, "FEHLT: install/schema.sql\n");
    exit(1);
}

$sql  = file_get_contents($schema);
$fail = 0;

// Kommentarbereinigte Fassung fuer alle strukturellen Pruefungen weiter
// unten (Klammer-Scan, Tabellen-Parser, foreign_key_checks-Suche). Ohne
// das wuerde z.B. ein "-- ..."-Kommentar zwischen zwei Spalten/KEY-
// Definitionen (kein Komma dazwischen) beide zu einem Textblock
// verschmelzen und der Parser wuerde die zweite Definition verschlucken.
$sqlNoComments = strip_sql_comments($sql);

// ---------------------------------------------------------------------
// Hilfsfunktionen
// ---------------------------------------------------------------------

// Entfernt einzeilige SQL-Kommentare (-- bis Zeilenende). Wird fuer die
// Klammerbalance-Pruefung gebraucht, damit ein Bindestrich-Kommentar mit
// Klammern im Fliesstext die Zaehlung nicht verfaelscht.
function strip_sql_comments(string $sql): string
{
    return (string) preg_replace('/--[^\n]*/', '', $sql);
}

// Zerlegt den Rumpf einer CREATE-TABLE-Klammer an Kommas auf oberster
// Klammerebene. VARCHAR(255), DECIMAL(10,2), ENUM('a','b') etc. enthalten
// selbst Kommas/Klammern und duerfen dabei nicht aufgebrochen werden.
function split_top_level(string $body): array
{
    $items = [];
    $depth = 0;
    $cur   = '';
    $len   = strlen($body);
    for ($i = 0; $i < $len; $i++) {
        $ch = $body[$i];
        if ($ch === '(') {
            $depth++;
            $cur .= $ch;
            continue;
        }
        if ($ch === ')') {
            $depth--;
            $cur .= $ch;
            continue;
        }
        if ($ch === ',' && $depth === 0) {
            $items[] = trim($cur);
            $cur = '';
            continue;
        }
        $cur .= $ch;
    }
    if (trim($cur) !== '') {
        $items[] = trim($cur);
    }
    return $items;
}

// Findet alle CREATE-TABLE-Anweisungen per Klammertiefen-Scan (kein
// naiver Regex bis zur ersten schliessenden Klammer - das schlaegt schon
// an "VARCHAR(255)" fehl). Liefert je Tabelle Name, Spaltenliste,
// KEY/INDEX-Definitionen, FOREIGN-KEY-Definitionen und Textposition
// (fuer die Reihenfolge-Pruefung).
function find_tables(string $sql): array
{
    $tables = [];
    $len    = strlen($sql);
    $offset = 0;

    while (preg_match(
        '/CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?`?([a-zA-Z_][a-zA-Z0-9_]*)`?\s*\(/i',
        $sql,
        $m,
        PREG_OFFSET_CAPTURE,
        $offset
    )) {
        $name       = strtolower($m[1][0]);
        $matchStart = $m[0][1];
        $parenPos   = $matchStart + strlen($m[0][0]) - 1; // Position von '('

        $depth   = 0;
        $bodyEnd = null;
        for ($i = $parenPos; $i < $len; $i++) {
            if ($sql[$i] === '(') {
                $depth++;
            } elseif ($sql[$i] === ')') {
                $depth--;
                if ($depth === 0) {
                    $bodyEnd = $i;
                    break;
                }
            }
        }

        if ($bodyEnd === null) {
            // Klammer wird bis Dateiende nie wieder geschlossen. Ab hier
            // ist der Rest der Datei nicht mehr zuverlaessig auswertbar.
            $tables[] = [
                'name'         => $name,
                'unterminated' => true,
                'pos'          => $matchStart,
            ];
            break;
        }

        $body    = substr($sql, $parenPos + 1, $bodyEnd - $parenPos - 1);
        $semiPos = strpos($sql, ';', $bodyEnd);
        if ($semiPos === false) {
            $semiPos = null;
        }

        $columns = [];
        $keys    = []; // ['cols' => [...]]
        $fks     = []; // ['col' => ..., 'ref_table' => ..., 'ref_col' => ...]

        foreach (split_top_level($body) as $item) {
            if ($item === '') {
                continue;
            }

            if (preg_match(
                '/^CONSTRAINT\s+`?[a-zA-Z0-9_]+`?\s+FOREIGN\s+KEY\s*\(\s*`?([a-zA-Z0-9_]+)`?\s*\)\s*REFERENCES\s+`?([a-zA-Z0-9_]+)`?\s*\(\s*`?([a-zA-Z0-9_]+)`?\s*\)/i',
                $item,
                $fkm
            )) {
                $fks[] = [
                    'col'       => strtolower($fkm[1]),
                    'ref_table' => strtolower($fkm[2]),
                    'ref_col'   => strtolower($fkm[3]),
                ];
                continue;
            }

            if (preg_match('/^(PRIMARY\s+KEY|UNIQUE\s+KEY|UNIQUE|KEY|INDEX)\b/i', $item)) {
                if (preg_match('/\(([^()]*)\)/', $item, $colm)) {
                    $cols = array_map(
                        static function (string $c): string {
                            $c = strtolower(trim(str_replace('`', '', $c)));
                            // Praefix-Laengenangaben wie email(191) kuerzen.
                            return (string) preg_replace('/\(\d+\)$/', '', $c);
                        },
                        explode(',', $colm[1])
                    );
                    $keys[] = ['cols' => array_filter($cols, static fn($c) => $c !== '')];
                }
                continue;
            }

            // Spaltendefinition: erstes Token ist der Spaltenname.
            if (preg_match('/^`?([a-zA-Z0-9_]+)`?\s/', $item, $cm)) {
                $columns[] = strtolower($cm[1]);
            }
        }

        $tables[] = [
            'name'    => $name,
            'columns' => $columns,
            'keys'    => $keys,
            'fks'     => $fks,
            'pos'     => $matchStart,
            'bodyEnd' => $bodyEnd,
            'semiPos' => $semiPos,
        ];

        $offset = $bodyEnd + 1;
    }

    return $tables;
}

$tables      = find_tables($sqlNoComments);
$okTables    = array_values(array_filter($tables, static fn($t) => !isset($t['unterminated'])));
$tableByName = [];
foreach ($okTables as $t) {
    $tableByName[$t['name']] = $t;
}

// ---------------------------------------------------------------------
// Pruefung 1: Tabellenabdeckung
// Jede im Code (FROM/JOIN/INSERT INTO/UPDATE) verwendete Tabelle muss im
// Schema definiert sein. Logik unveraendert aus der urspruenglichen
// Fassung dieses Skripts uebernommen.
// ---------------------------------------------------------------------
echo "=== Pruefung 1: Tabellenabdeckung ===\n";

$defined = array_map(static fn($t) => $t['name'], $okTables);

$used  = [];
$files = new RegexIterator(
    new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root)),
    '/\.php$/'
);
foreach ($files as $file) {
    $path = str_replace('\\', '/', $file->getPathname());
    if (str_contains($path, '/vendor/') || str_contains($path, '/tools/')) {
        continue;
    }
    preg_match_all(
        '/\b(?:FROM|JOIN|INSERT\s+(?:IGNORE\s+)?INTO|UPDATE)\s+`?([a-z_]{3,})`?/i',
        file_get_contents($path),
        $mm
    );
    foreach ($mm[1] as $t) {
        $used[strtolower($t)] = true;
    }
}

// Nur Namen pruefen, die plausibel Tabellen sind: Treffer aus Fliesstext
// (Kommentare, englische Prosa) fliegen ueber eine Positivliste raus.
$known = [
    'settings', 'users', 'logs', 'contacts', 'tasks', 'task_milestones',
    'milestone_comments', 'client_assets', 'time_entries', 'finances',
    'quotes', 'support_tickets', 'ticket_notes', 'leads_inbox',
    'wiki_articles', 'wiki_attachments', 'wiki_client_shares',
    'monitored_urls', 'calendar_events', 'event_contacts', 'sso_tokens',
    // Ueber die Migrationen 5 und 6 dazugekommen und bis hierher in
    // dieser Liste vergessen - Pruefung 1 hat sie deshalb nicht
    // ueberwacht.
    'task_contacts', 'project_comments',
    'password_resets', 'mail_log', 'url_checks', 'totp_backup_codes',
];

$check1_fail = 0;
foreach ($known as $t) {
    if (!in_array($t, $defined, true)) {
        echo "FEHLT im Schema: $t\n";
        $check1_fail = 1;
    }
}
foreach (array_keys($used) as $t) {
    if (in_array($t, $known, true) && !in_array($t, $defined, true)) {
        echo "VERWENDET, nicht definiert: $t\n";
        $check1_fail = 1;
    }
}
if (!$check1_fail) {
    echo 'OK: ' . count($defined) . " Tabellen definiert, alle verwendeten abgedeckt.\n";
} else {
    $fail = 1;
}
echo "\n";

// ---------------------------------------------------------------------
// Pruefung 2: Klammerbalance
// Global ueber die ganze Datei, plus je CREATE TABLE: die Spaltenliste
// muss sauber schliessen und die Anweisung mit ';' enden. Wegen der
// ENGINE=InnoDB DEFAULT CHARSET=...-Klausel steht nach der schliessenden
// Klammer nicht woertlich ");" - geprueft wird stattdessen, dass die
// Klammer ueberhaupt schliesst, danach keine weiteren Klammern mehr
// auftauchen und die Anweisung mit ';' terminiert ist.
// ---------------------------------------------------------------------
echo "=== Pruefung 2: Klammerbalance ===\n";
$check2_fail = 0;

$stripped   = $sqlNoComments; // bereits kommentarbereinigt, siehe oben
$openCount  = substr_count($stripped, '(');
$closeCount = substr_count($stripped, ')');
if ($openCount !== $closeCount) {
    echo "FEHLER: Klammern global unausgeglichen: $openCount auf, $closeCount zu.\n";
    $check2_fail = 1;
} else {
    echo "OK: Klammern global ausgeglichen ($openCount auf, $closeCount zu).\n";
}

$unterminated = array_filter($tables, static fn($t) => isset($t['unterminated']));
if ($unterminated !== []) {
    foreach ($unterminated as $t) {
        echo "FEHLER: CREATE TABLE {$t['name']} - Klammer wird nie wieder geschlossen.\n";
    }
    $check2_fail = 1;
} elseif ($okTables === []) {
    echo "FEHLER: keine einzige CREATE-TABLE-Anweisung gefunden.\n";
    $check2_fail = 1;
} else {
    $termOk = 0;
    foreach ($okTables as $t) {
        if ($t['semiPos'] === null) {
            echo "FEHLER: CREATE TABLE {$t['name']} - kein abschliessendes ';' nach der Spaltenliste gefunden.\n";
            $check2_fail = 1;
            continue;
        }
        $between = substr($sqlNoComments, $t['bodyEnd'] + 1, $t['semiPos'] - $t['bodyEnd'] - 1);
        if (str_contains($between, '(') || str_contains($between, ')')) {
            echo "FEHLER: CREATE TABLE {$t['name']} - unerwarteter Klammerinhalt zwischen Spaltenliste und ';'.\n";
            $check2_fail = 1;
            continue;
        }
        $termOk++;
    }
    if (!$check2_fail) {
        echo "OK: alle $termOk CREATE-TABLE-Anweisungen sauber geschlossen und mit ';' abgeschlossen.\n";
    }
}
if ($check2_fail) {
    $fail = 1;
}
echo "\n";

// ---------------------------------------------------------------------
// Pruefung 3: doppelte Spaltennamen
// ---------------------------------------------------------------------
echo "=== Pruefung 3: doppelte Spaltennamen ===\n";
$check3_fail = 0;
$colTotal    = 0;
foreach ($okTables as $t) {
    $colTotal += count($t['columns']);
    $counts = array_count_values($t['columns']);
    foreach ($counts as $col => $n) {
        if ($n > 1) {
            echo "FEHLER: Tabelle {$t['name']} - Spalte '$col' $n mal definiert.\n";
            $check3_fail = 1;
        }
    }
}
if ($colTotal === 0) {
    echo "HINWEIS: keine Spalten gefunden - Pruefung trivial bestanden.\n";
} elseif (!$check3_fail) {
    echo 'OK: keine doppelten Spaltennamen (' . count($okTables) . " Tabellen, $colTotal Spalten insgesamt).\n";
}
if ($check3_fail) {
    $fail = 1;
}
echo "\n";

// ---------------------------------------------------------------------
// Pruefung 4: Fremdschluessel-Ziele
// Jede FOREIGN KEY ... REFERENCES tabelle(spalte)-Angabe muss auf eine
// im Schema definierte Tabelle und dort tatsaechlich vorhandene Spalte
// zeigen.
// ---------------------------------------------------------------------
echo "=== Pruefung 4: Fremdschluessel-Ziele ===\n";
$check4_fail = 0;
$fkCount     = 0;
foreach ($okTables as $t) {
    foreach ($t['fks'] as $fk) {
        $fkCount++;
        if (!isset($tableByName[$fk['ref_table']])) {
            echo "FEHLER: {$t['name']}.{$fk['col']} verweist auf unbekannte Tabelle '{$fk['ref_table']}'.\n";
            $check4_fail = 1;
            continue;
        }
        if (!in_array($fk['ref_col'], $tableByName[$fk['ref_table']]['columns'], true)) {
            echo "FEHLER: {$t['name']}.{$fk['col']} verweist auf {$fk['ref_table']}.{$fk['ref_col']}, aber diese Spalte existiert dort nicht.\n";
            $check4_fail = 1;
        }
    }
}
if ($fkCount === 0) {
    echo "HINWEIS: keine FOREIGN-KEY-Constraints im Schema gefunden - Pruefung trivial bestanden.\n";
} elseif (!$check4_fail) {
    echo "OK: $fkCount FOREIGN-KEY-Constraint(s) geprueft, alle Ziel-Tabellen/-Spalten vorhanden.\n";
}
if ($check4_fail) {
    $fail = 1;
}
echo "\n";

// ---------------------------------------------------------------------
// Pruefung 5: KEY/INDEX-Spalten
// Jede in einer KEY/INDEX/UNIQUE KEY/PRIMARY KEY-Definition genannte
// Spalte muss in derselben Tabelle als Spalte existieren.
// ---------------------------------------------------------------------
echo "=== Pruefung 5: KEY/INDEX-Spalten ===\n";
$check5_fail = 0;
$keyCount    = 0;
foreach ($okTables as $t) {
    foreach ($t['keys'] as $k) {
        $keyCount++;
        foreach ($k['cols'] as $col) {
            if (!in_array($col, $t['columns'], true)) {
                echo "FEHLER: Tabelle {$t['name']} - KEY/INDEX referenziert unbekannte Spalte '$col'.\n";
                $check5_fail = 1;
            }
        }
    }
}
if ($keyCount === 0) {
    echo "HINWEIS: keine KEY/INDEX-Definitionen im Schema gefunden - Pruefung trivial bestanden.\n";
} elseif (!$check5_fail) {
    echo "OK: $keyCount KEY/INDEX-Definition(en) geprueft, alle referenzierten Spalten vorhanden.\n";
}
if ($check5_fail) {
    $fail = 1;
}
echo "\n";

// ---------------------------------------------------------------------
// Pruefung 6: Tabellenreihenfolge vs. Fremdschluessel
// Entweder definiert jede Tabelle ihre Fremdschluessel-Ziele erst NACH
// deren eigener Definition (Reihenfolge im Dateisinn wichtig), oder
// "SET foreign_key_checks = 0/1" umschliesst alle CREATE-TABLE-
// Anweisungen - dann ist die Reihenfolge fuer den Import egal.
// ---------------------------------------------------------------------
echo "=== Pruefung 6: Tabellenreihenfolge vs. Fremdschluessel ===\n";
$check6_fail = 0;

if ($okTables === []) {
    echo "HINWEIS: keine Tabellen zum Pruefen vorhanden.\n";
} else {
    preg_match('/SET\s+foreign_key_checks\s*=\s*0/i', $sqlNoComments, $mZero, PREG_OFFSET_CAPTURE);
    preg_match_all('/SET\s+foreign_key_checks\s*=\s*1/i', $sqlNoComments, $mOnes, PREG_OFFSET_CAPTURE);

    $zeroPos = $mZero[0][1] ?? null;
    $onePos  = null;
    if (!empty($mOnes[0])) {
        $last   = end($mOnes[0]);
        $onePos = $last[1];
    }

    $firstTablePos = $okTables[0]['pos'];
    $lastTable     = end($okTables);
    $lastTableEnd  = $lastTable['semiPos'] ?? $lastTable['bodyEnd'];

    $wrapsFile = $zeroPos !== null && $onePos !== null
        && $zeroPos < $firstTablePos && $onePos > $lastTableEnd;

    if ($wrapsFile) {
        echo "OK: SET foreign_key_checks=0/1 umschliesst alle CREATE-TABLE-Anweisungen - Reihenfolge der Tabellen ist fuer den Import unkritisch.\n";
    } else {
        $definedSoFar = [];
        $orderOk      = true;
        foreach ($okTables as $t) {
            foreach ($t['fks'] as $fk) {
                if (!in_array($fk['ref_table'], $definedSoFar, true)) {
                    echo "FEHLER: {$t['name']} referenziert {$fk['ref_table']}, das an dieser Stelle noch nicht definiert ist, und foreign_key_checks=0 umschliesst die Datei nicht.\n";
                    $orderOk = false;
                }
            }
            $definedSoFar[] = $t['name'];
        }
        if ($orderOk) {
            echo "OK: Tabellenreihenfolge respektiert alle Fremdschluessel (kein foreign_key_checks-Schalter noetig).\n";
        } else {
            $check6_fail = 1;
        }
    }
}
if ($check6_fail) {
    $fail = 1;
}
echo "\n";

// ---------------------------------------------------------------------
// Pruefung 7: schema_version-Seed vs. includes/migrations.php
// Ohne den Seed-Wert wuerde run_migrations() bei einer frischen
// Installation Migrationen gegen ein bereits aktuelles Schema fahren.
// includes/migrations.php existiert erst ab Task 6 - bis dahin wird
// diese Pruefung uebersprungen statt fehlzuschlagen.
// ---------------------------------------------------------------------
echo "=== Pruefung 7: schema_version ===\n";
$check7_fail = 0;

if (!preg_match(
    "/INSERT\s+INTO\s+settings\s*\(\s*k\s*,\s*v\s*\)\s*VALUES\s*\(\s*'schema_version'\s*,\s*'([^']+)'\s*\)/i",
    $sqlNoComments,
    $svm
)) {
    echo "FEHLER: keine 'INSERT INTO settings (k, v) VALUES (''schema_version'', ...)' Zeile im Schema gefunden.\n";
    $check7_fail = 1;
} else {
    $seedVersion    = $svm[1];
    $migrationsFile = $root . '/includes/migrations.php';
    if (!is_readable($migrationsFile)) {
        echo "HINWEIS: includes/migrations.php existiert noch nicht (kommt erst mit Task 6) - Abgleich uebersprungen. schema_version im Seed ist '$seedVersion'.\n";
    } else {
        $migSrc = file_get_contents($migrationsFile);
        if (!preg_match("/SCHEMA_VERSION['\"]?\s*[,=]\s*['\"]?(\d+)/i", $migSrc, $mvm)) {
            echo "FEHLER: SCHEMA_VERSION in includes/migrations.php nicht gefunden.\n";
            $check7_fail = 1;
        } else {
            $codeVersion = $mvm[1];
            if ((string) $codeVersion !== (string) $seedVersion) {
                echo "FEHLER: schema_version im Seed ist '$seedVersion', SCHEMA_VERSION in includes/migrations.php ist '$codeVersion'.\n";
                $check7_fail = 1;
            } else {
                echo "OK: schema_version im Seed ('$seedVersion') stimmt mit SCHEMA_VERSION in includes/migrations.php ueberein.\n";
            }
        }
    }
}
if ($check7_fail) {
    $fail = 1;
}
echo "\n";

// ---------------------------------------------------------------------
// Pruefung 8: Komma vor der schliessenden Klammer
//
// Zweimal passiert, beide Male gleich: eine Migration ergaenzt eine
// Spalte, jemand haengt sie ans Ende der CREATE-TABLE-Klammer und laesst
// das Komma der Vorgaengerzeile stehen. MySQL lehnt die Anweisung ab -
// eine Neuinstallation bricht dann mitten im Schema ab, waehrend jede
// bestehende Installation weiterlaeuft und nichts davon merkt. Genau
// deshalb faellt es so spaet auf.
// ---------------------------------------------------------------------
echo "=== Pruefung 8: Komma vor der schliessenden Klammer ===\n";

$zeilen   = file(__DIR__ . '/../install/schema.sql');
$check8   = 0;
$vorige   = '';
$vorigeNr = 0;
foreach ($zeilen as $i => $zeile) {
    if (preg_match('/^\)\s*ENGINE/i', $zeile)) {
        if (preg_match('/,\s*$/', $vorige)) {
            echo 'FEHLER: Zeile ' . $vorigeNr . ' endet mit einem Komma, '
               . "Zeile " . ($i + 1) . " schliesst die Klammer:\n";
            echo '  ' . trim($vorige) . "\n";
            $check8 = 1;
        }
        continue;
    }
    $roh = trim($zeile);
    if ($roh === '' || strpos($roh, '--') === 0) continue;
    $vorige   = $zeile;
    $vorigeNr = $i + 1;
}
if ($check8) {
    $fail = 1;
} else {
    echo "OK: keine CREATE-TABLE-Klammer wird durch ein ueberzaehliges Komma ungueltig.\n";
}
echo "\n";

// ---------------------------------------------------------------------
// Zusammenfassung
// ---------------------------------------------------------------------
echo "=== Zusammenfassung ===\n";
if ($fail) {
    echo "FEHLGESCHLAGEN: mindestens eine Pruefung ist fehlgeschlagen (siehe oben).\n";
} else {
    echo 'OK: ' . count($okTables) . " Tabellen definiert, alle Strukturpruefungen bestanden.\n";
}

exit($fail);
