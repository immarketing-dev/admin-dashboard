<?php
/**
 * Demodaten für die öffentliche Demo-Version.
 *
 * Ersetzt install/seed_demo.sql. Diese Datei warnt selbst davor, dass sie
 * Schlüssel wie contact_id 1 fest verdrahtet und ihre Meilensteine und
 * Rechnungen stillschweigend an fremde Datensätze hängt, sobald die
 * Tabellen nicht mehr leer sind. Bei 23 Tabellen mit Querverweisen ist
 * das nicht zu halten - hier wird eingefügt und lastInsertId() gelesen,
 * dann stimmen die Verweise immer.
 *
 * Alle Zeitangaben sind relativ zu heute berechnet. Ein Seed mit festen
 * Daten sieht nach einem halben Jahr aus wie ein aufgegebenes System.
 *
 * Aufruf (nur über die Kommandozeile):
 *
 *     php tools/seed_demo.php --yes
 *     php tools/seed_demo.php --yes --db-user=admin --db-pass=geheim
 *
 * Die Schreibrechte braucht nur dieser Aufruf. Im laufenden Betrieb
 * genügt der Demo-Installation ein Datenbankbenutzer mit SELECT - siehe
 * docs/DEMO.md.
 *
 * ACHTUNG: das Skript leert die Tabellen, bevor es füllt. Es läuft
 * deshalb ausschließlich, wenn DEMO_MODE in der .env auf true steht.
 */

// ── Schutzvorrichtungen ─────────────────────────────────────────────
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit("Nur über die Kommandozeile.\n");
}

require_once dirname(__DIR__) . '/config.php';

if (!demo_mode()) {
    fwrite(STDERR,
        "ABBRUCH: DEMO_MODE steht nicht auf true.\n\n"
      . "Dieses Skript LEERT die Tabellen der eingestellten Datenbank. Es\n"
      . "läuft deshalb nur gegen eine Installation, die sich ausdrücklich\n"
      . "als Demo ausweist. Prüfe die .env - und vergewissere dich, dass\n"
      . "DB_NAME wirklich auf die Demo-Datenbank zeigt.\n");
    exit(1);
}

if (!in_array('--yes', $argv, true)) {
    fwrite(STDERR,
        "Dieses Skript leert die Demo-Datenbank '" . DB_NAME . "' und füllt sie neu.\n"
      . "Zum Ausführen: php tools/seed_demo.php --yes\n");
    exit(1);
}

// Optional mit anderen Zugangsdaten verbinden: so muss der privilegierte
// Benutzer nicht in der .env der öffentlichen Demo stehen.
$db_user = DB_USER;
$db_pass = DB_PASS;
foreach ($argv as $arg) {
    if (strpos($arg, '--db-user=') === 0) $db_user = substr($arg, 10);
    if (strpos($arg, '--db-pass=') === 0) $db_pass = substr($arg, 10);
}
if ($db_user !== DB_USER || $db_pass !== DB_PASS) {
    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
        $db_user, $db_pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
}


$start = microtime(true);
echo 'Demodaten werden erzeugt (Datenbank: ' . DB_NAME . "\n\n";

require __DIR__ . '/seed_demo_lib.php';
require __DIR__ . '/seed_demo_data.php';
