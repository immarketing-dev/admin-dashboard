<?php
/**
 * Prueft das Projekt gegen einen echten MySQL/MariaDB-Server.
 *
 * Warum es diese Datei gibt: die gesamte uebrige Pruefumgebung laeuft
 * gegen die SQLite-Spiegelung in tools/lib_sqlite_mirror.php, weil auf
 * der Entwicklungsmaschine kein Datenbankserver steht. Vierzig
 * Pruefungen und ueber tausend Zusicherungen - und keine einzige sah, ob
 * eine Abfrage auf dem Server laeuft, auf dem sie am Ende laeuft.
 *
 * Das ist zweimal teuer geworden. Die Auswertungsseite lief auf der
 * oeffentlichen Demo wochenlang mit HTTP 500, waehrend sie hier
 * einwandfrei rendert, und ohne Zugriff auf das Fehlerprotokoll des
 * Servers blieb nur Raten.
 *
 * Geprueft wird dreierlei:
 *
 *   1. install/schema.sql laesst sich wirklich importieren. Bisher wurde
 *      es nur strukturell geparst - tools/check_schema.php sagt in
 *      seinem eigenen Kopf, dass es nur deshalb existiert, weil hier
 *      kein Server steht.
 *   2. Jede Abfrage, die als reines Literal im Code steht, laesst sich
 *      auf dem Server vorbereiten. Ein unbekannter Spaltenname oder ein
 *      Syntaxfehler faellt damit auf, ohne dass Daten noetig waeren.
 *   3. Die Lesefunktionen laufen gegen echte Daten. Manches faellt erst
 *      beim Ausfuehren auf - der sql_mode etwa, der auf einem fremden
 *      Server anders gesetzt sein kann als hier.
 *
 * Ohne erreichbaren Server ueberspringt der Test sich selbst und meldet
 * Erfolg: auf der Entwicklungsmaschine soll er nicht im Weg stehen. In
 * der CI steht ein MariaDB-Dienst bereit, dort laeuft er wirklich.
 *
 * Aufruf:
 *   TEST_DB_HOST=127.0.0.1 TEST_DB_NAME=test TEST_DB_USER=root \
 *   TEST_DB_PASS=geheim php tools/test_mysql.php
 */

require_once __DIR__ . '/lib_sqlite_mirror.php';   // wegen oberste_ebene_teilen()

$wurzel = dirname(__DIR__);

// ── Verbindung ──────────────────────────────────────────────────────
$host = getenv('TEST_DB_HOST') ?: '';
$name = getenv('TEST_DB_NAME') ?: '';
$user = getenv('TEST_DB_USER') ?: '';
$pass = getenv('TEST_DB_PASS');
$pass = $pass === false ? '' : $pass;
$port = getenv('TEST_DB_PORT') ?: '3306';

if ($host === '' || $name === '' || $user === '') {
    echo "UEBERSPRUNGEN: kein Testserver angegeben (TEST_DB_HOST/NAME/USER).\n";
    echo "  Auf der Entwicklungsmaschine ist das der Normalfall - die CI setzt sie.\n";
    exit(0);
}

if (!in_array('mysql', PDO::getAvailableDrivers(), true)) {
    echo "UEBERSPRUNGEN: pdo_mysql ist nicht geladen.\n";
    exit(0);
}

try {
    $pdo = new PDO(
        "mysql:host=$host;port=$port;dbname=$name;charset=utf8mb4",
        $user,
        $pass,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            // Wie in config.php: echte Prepares, damit der Server die
            // Anweisung wirklich sieht. Genau darauf beruht Pruefung 2.
            PDO::ATTR_EMULATE_PREPARES  => false,
            PDO::ATTR_STRINGIFY_FETCHES => false,
        ]
    );
} catch (PDOException $e) {
    echo "UEBERSPRUNGEN: Server nicht erreichbar (" . $e->getMessage() . ").\n";
    exit(0);
}

$version = (string) $pdo->query('SELECT VERSION()')->fetchColumn();
$modus   = (string) $pdo->query('SELECT @@sql_mode')->fetchColumn();
echo "Server:   $version\n";
echo "sql_mode: " . ($modus !== '' ? $modus : '(leer)') . "\n\n";

$fehler = 0;

/** Meldet eine Pruefung. */
function pruefe(string $titel, bool $ok, string $hinweis = ''): void
{
    global $fehler;
    if ($ok) {
        echo "  OK   $titel\n";
        return;
    }
    echo "  FEHLER $titel\n";
    if ($hinweis !== '') {
        foreach (explode("\n", $hinweis) as $z) {
            echo "         $z\n";
        }
    }
    $fehler++;
}

// =====================================================================
// 1. Das Schema laesst sich importieren
// =====================================================================
echo "=== Pruefung 1: install/schema.sql importieren ===\n";

// Sauberer Anfang: alles wegraeumen, was von einem frueheren Lauf steht.
$pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
foreach ($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $t) {
    $pdo->exec('DROP TABLE IF EXISTS `' . $t . '`');
}
$pdo->exec('SET FOREIGN_KEY_CHECKS = 1');

$schema = (string) file_get_contents($wurzel . '/install/schema.sql');

/**
 * Zerlegt eine SQL-Datei in einzelne Anweisungen.
 *
 * Auf Semikolon, aber nicht innerhalb von Zeichenketten und nicht in
 * Kommentaren - sonst zerfaellt ein Kommentar, der ein Semikolon
 * enthaelt, in zwei halbe Anweisungen.
 */
function sql_anweisungen(string $sql): array
{
    $aus = [];
    $puffer = '';
    $in_text = false;
    $in_kommentar = false;

    for ($i = 0, $n = strlen($sql); $i < $n; $i++) {
        $c = $sql[$i];

        if ($in_kommentar) {
            if ($c === "\n") $in_kommentar = false;
            else { continue; }
        } elseif (!$in_text && $c === '-' && ($sql[$i + 1] ?? '') === '-') {
            $in_kommentar = true;
            continue;
        } elseif ($c === "'") {
            $in_text = !$in_text;
        }

        if (!$in_text && $c === ';') {
            if (trim($puffer) !== '') $aus[] = trim($puffer);
            $puffer = '';
            continue;
        }
        $puffer .= $c;
    }
    if (trim($puffer) !== '') $aus[] = trim($puffer);
    return $aus;
}

$anweisungen = sql_anweisungen($schema);
$importiert  = 0;
$import_fehler = [];

foreach ($anweisungen as $a) {
    try {
        $pdo->exec($a);
        $importiert++;
    } catch (PDOException $e) {
        $import_fehler[] = mb_strimwidth(preg_replace('/\s+/', ' ', $a), 0, 70, '...')
                         . "\n  -> " . $e->getMessage();
    }
}

pruefe(
    $importiert . ' Anweisung(en) importiert',
    $import_fehler === [],
    implode("\n", $import_fehler)
);

$tabellen = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
preg_match_all('/CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?`?([a-z_]+)`?/i', $schema, $m);
$erwartet = array_unique(array_map('strtolower', $m[1]));
pruefe(
    count($tabellen) . ' Tabellen angelegt (erwartet ' . count($erwartet) . ')',
    count($tabellen) === count($erwartet),
    'Fehlend: ' . implode(', ', array_diff($erwartet, array_map('strtolower', $tabellen)))
);

// =====================================================================
// 2. Jede feste Abfrage laesst sich vorbereiten
// =====================================================================
echo "\n=== Pruefung 2: jede feste Abfrage vorbereiten ===\n";

$dateien = array_merge(
    glob($wurzel . '/*.php'),
    glob($wurzel . '/includes/*.php'),
    glob($wurzel . '/api/*.php')
);

$geprueft = 0;
$abfrage_fehler = [];

foreach ($dateien as $datei) {
    $kurz = str_replace($wurzel . '/', '', str_replace('\\', '/', $datei));

    foreach (token_get_all((string) file_get_contents($datei)) as $t) {
        if (!is_array($t) || $t[0] !== T_CONSTANT_ENCAPSED_STRING) {
            continue;
        }
        $roh = $t[1];
        // Nur einfach quotierte Literale: in doppelt quotierten koennte
        // eine Variable stecken, und die kennt der Server nicht.
        if ($roh[0] !== "'") {
            continue;
        }
        $sql = str_replace(["\\'", '\\\\'], ["'", '\\'], substr($roh, 1, -1));

        if (!preg_match('/^\s*(SELECT|INSERT|UPDATE|DELETE|REPLACE)\b/i', $sql)) {
            continue;
        }
        // Zusammengesetzte Abfragen lassen sich so nicht pruefen - dort
        // fehlt der Teil, der zur Laufzeit dazukommt.
        if (strpos($sql, '$') !== false || substr_count($sql, '(') !== substr_count($sql, ')')) {
            continue;
        }

        $geprueft++;
        try {
            $pdo->prepare($sql);
        } catch (PDOException $e) {
            $abfrage_fehler[] = $kurz . ':' . $t[2] . "\n  " . $e->getMessage()
                              . "\n  " . mb_strimwidth(preg_replace('/\s+/', ' ', $sql), 0, 100, '...');
        }
    }
}

pruefe(
    $geprueft . ' feste Abfrage(n) vorbereitet',
    $abfrage_fehler === [],
    implode("\n", $abfrage_fehler)
);

// =====================================================================
// 3. Die Lesefunktionen laufen gegen echte Daten
// =====================================================================
echo "\n=== Pruefung 3: die Auswertung gegen echte Daten ===\n";

$pdo->exec("INSERT INTO contacts (name, company) VALUES ('Lena Hofmann', 'Hofmann & Partner')");
$kontakt = (int) $pdo->lastInsertId();
$pdo->exec("INSERT INTO tasks (title, contact_id, status) VALUES ('Relaunch', $kontakt, 'In Bearbeitung')");
$projekt = (int) $pdo->lastInsertId();
$pdo->exec("INSERT INTO time_entries (task_id, duration_minutes, note) VALUES ($projekt, 120, 'Konzept')");
$pdo->exec("INSERT INTO time_entries (task_id, duration_minutes, billed_at) VALUES ($projekt, 60, NOW())");
$pdo->exec("INSERT INTO finances (type, title, contact_id, amount, status, record_date, due_date, invoice_number)
            VALUES ('INCOME', 'Rechnung', $kontakt, 1190.00, 'Offen', '2026-01-15', '2026-01-29', 'RE-2026-001')");
$pdo->exec("INSERT INTO finances (type, title, contact_id, amount, status, record_date)
            VALUES ('INCOME', 'Bezahlt', $kontakt, 500.00, 'Bezahlt', '2026-02-01')");

// setting() braucht sonst config.php.
if (!function_exists('setting')) {
    function setting(string $key, string $default = ''): string { return $default; }
}
require_once $wurzel . '/includes/reports.php';
require_once $wurzel . '/includes/payments.php';
require_once $wurzel . '/includes/uptime.php';
require_once $wurzel . '/includes/mail_log.php';

$lesefunktionen = [
    'umsatz_jahre()'                    => fn() => umsatz_jahre($pdo),
    'umsatz_je_kunde()'                 => fn() => umsatz_je_kunde($pdo, 2026),
    'offene_posten()'                   => fn() => offene_posten($pdo),
    'zeit_je_projekt()'                 => fn() => zeit_je_projekt($pdo, 60.0),
    'zeiteintraege()'                   => fn() => zeiteintraege($pdo, '2026-01-01', '2026-12-31'),
    'offene_rechnungen_fuer_abgleich()' => fn() => offene_rechnungen_fuer_abgleich($pdo),
    'uptime_verlauf()'                  => fn() => uptime_verlauf($pdo),
    'uptime_letzte()'                   => fn() => uptime_letzte($pdo),
    'mail_protokoll()'                  => fn() => mail_protokoll($pdo),
    'mail_protokoll_zahlen()'           => fn() => mail_protokoll_zahlen($pdo),
];

foreach ($lesefunktionen as $titel => $aufruf) {
    try {
        $aufruf();
        pruefe($titel, true);
    } catch (Throwable $e) {
        pruefe($titel, false, $e->getMessage());
    }
}

// Und die Ergebnisse stimmen auch inhaltlich - eine Abfrage, die
// durchlaeuft und Unsinn liefert, waere nicht besser.
try {
    $u = umsatz_je_kunde($pdo, 2026);
    pruefe('Umsatz je Kunde liefert eine Zeile', count($u) === 1,
           count($u) . ' Zeilen statt 1');
    pruefe('bezahlt und offen getrennt gezaehlt',
           abs(($u[0]['bezahlt'] ?? 0) - 500.0) < 0.01 && abs(($u[0]['offen'] ?? 0) - 1190.0) < 0.01,
           'bezahlt=' . ($u[0]['bezahlt'] ?? '-') . ' offen=' . ($u[0]['offen'] ?? '-'));

    $p = zeit_je_projekt($pdo, 60.0);
    pruefe('Zeit je Projekt liefert das Projekt', count($p) === 1,
           count($p) . ' Zeilen statt 1');
    pruefe('erfasste und offene Minuten stimmen',
           ($p[0]['minuten'] ?? 0) === 180 && ($p[0]['offen'] ?? 0) === 120,
           'minuten=' . ($p[0]['minuten'] ?? '-') . ' offen=' . ($p[0]['offen'] ?? '-'));
} catch (Throwable $e) {
    pruefe('Ergebnisse der Auswertung', false, $e->getMessage());
}

// =====================================================================
// Ergebnis
// =====================================================================
echo "\n=== Zusammenfassung ===\n";
if ($fehler === 0) {
    echo "OK: Schema, $geprueft feste Abfragen und die Lesefunktionen laufen auf $version.\n";
    exit(0);
}
echo "FEHLGESCHLAGEN: $fehler Beanstandung(en) auf $version.\n";
exit(1);
