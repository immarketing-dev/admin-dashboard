<?php
/**
 * Startet eine begehbare Demo-Instanz ohne MySQL-Server.
 *
 * Aufruf: php tools/serve_demo.php [--port=8099] [--lang=de]
 *
 * Wozu: Screenshots, ein Blick auf einen Zweig, ein Handtest — alles
 * Dinge, für die bisher ein MySQL-Server nötig war. Auf dieser
 * Entwicklungsmaschine gibt es keinen, und die öffentliche Demo hängt
 * naturgemäß dem Zweig hinterher, an dem man gerade arbeitet. Als
 * Screenshots für das README fällig waren, war beides gleichzeitig wahr:
 * lokal keine Datenbank, und in der Demo lief eine Seite auf HTTP 500,
 * weil dort noch das alte Schema stand.
 *
 * Wie: das Projekt wird in ein Wegwerf-Verzeichnis kopiert, dessen
 * config.php auf tools/lib_sqlite_mirror.php zeigt — dieselbe
 * Spiegelung, gegen die die Tests laufen. Das Repository selbst bleibt
 * unberührt; nichts davon geht je in eine Auslieferung.
 *
 * Was das NICHT ist: eine zweite unterstützte Betriebsart. Die
 * Spiegelung deckt ab, was die Seiten brauchen, nicht was MySQL kann.
 * Wenn hier etwas klemmt, ist zuerst die Spiegelung verdächtig und nicht
 * die Seite.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit("Nur ueber die Kommandozeile.\n");
}

$wurzel = dirname(__DIR__);

$port    = 8099;
$sprache = 'de';
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--port=(\d+)$/', $arg, $m))       $port    = (int) $m[1];
    if (preg_match('/^--lang=([a-z]{2})$/', $arg, $m))  $sprache = $m[1];
}

// Eigener Name: tools/seed_demo_data.php benutzt $ziel für seine
// Platzhalterdateien, und der require weiter unten läuft in diesem
// Gültigkeitsbereich. Eine Variable namens $ziel wäre danach der
// Pfad auf eine Textdatei — und php -S bekäme den statt des
// Verzeichnisses.
$instanz = sys_get_temp_dir() . '/admin-dashboard-demo-lokal';
$instanz = rtrim(str_replace('\\', '/', $instanz), '/');

echo "Demo-Instanz in $instanz\n";

// ── 1. Kopie ────────────────────────────────────────────────────────
function kopiere(string $von, string $nach, array $aus): int
{
    @mkdir($nach, 0777, true);
    $zahl = 0;
    foreach (scandir($von) ?: [] as $e) {
        if ($e === '.' || $e === '..' || in_array($e, $aus, true)) continue;
        $q = $von . '/' . $e;
        $z = $nach . '/' . $e;
        if (is_dir($q)) {
            $zahl += kopiere($q, $z, $aus);
        } elseif (copy($q, $z)) {
            $zahl++;
        }
    }
    return $zahl;
}

function weg(string $ordner): void
{
    foreach (glob($ordner . '/*') ?: [] as $d) {
        is_dir($d) ? weg($d) : @unlink($d);
    }
    foreach (glob($ordner . '/.[!.]*') ?: [] as $d) {
        is_dir($d) ? weg($d) : @unlink($d);
    }
    @rmdir($ordner);
}

if (is_dir($instanz)) {
    weg($instanz);
}
$anzahl = kopiere($wurzel, $instanz, ['.git', '.github', 'docs', '.superpowers']);
echo "  $anzahl Dateien kopiert\n";

// ── 2. .env ─────────────────────────────────────────────────────────
file_put_contents($instanz . '/.env', implode("\n", [
    'APP_NAME=Musterwerk Digital',
    'COMPANY_NAME=Musterwerk Digital',
    'COMPANY_SHORT=Musterwerk',
    'BASE_URL=http://127.0.0.1:' . $port,
    'DEMO_MODE=true',
    'DB_HOST=localhost',
    'DB_NAME=demo_sqlite',
    'DB_USER=demo',
    'DB_PASS=',
]) . "\n");

// ── 3. config.php auf die Spiegelung umbiegen ───────────────────────
$cfg    = file_get_contents($instanz . '/config.php');
$anfang = strpos($cfg, "try {\n    \$pdo = new PDO(");
$ende   = strpos($cfg, "require_once __DIR__ . '/includes/migrations.php';");
if ($anfang === false || $ende === false) {
    fwrite(STDERR, "config.php: Verbindungsblock nicht gefunden. Wurde er umgebaut?\n");
    exit(1);
}

$ersatz = <<<'PHP'
// ── Verbindung (LOKALE DEMO-INSTANZ, tools/serve_demo.php) ─────────
// Nur in dieser Wegwerf-Kopie. Statt MySQL die Spiegelung, gegen die
// auch die Tests laufen.
require_once __DIR__ . '/tools/lib_sqlite_mirror.php';
require_once __DIR__ . '/tools/serve_demo_compat.php';

$pdo = new SqliteSpiegelPDO('sqlite:' . __DIR__ . '/demo.sqlite', null, null, [
    PDO::ATTR_ERRMODE           => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_STRINGIFY_FETCHES => false,
]);
$pdo->exec('PRAGMA foreign_keys = ON');
serve_demo_funktionen($pdo);

PHP;

$cfg = substr($cfg, 0, $anfang) . $ersatz . substr($cfg, $ende);

// run_migrations() spricht MySQL-DDL. install/schema.sql stempelt den
// Stand ohnehin, hier ist also nichts zu tun.
$cfg = str_replace(
    "require_once __DIR__ . '/includes/migrations.php';\nrun_migrations(\$pdo);",
    "require_once __DIR__ . '/includes/migrations.php';\n"
  . "// Lokale Instanz: install/schema.sql hat den Stand gestempelt, und\n"
  . "// die Migrationen sprechen MySQL-DDL.\n"
  . "// run_migrations(\$pdo);",
    $cfg
);
file_put_contents($instanz . '/config.php', $cfg);

// ── 4. Die PIN-Abfrage des Portals überspringen ─────────────────────
// Sie ist ein POST, und ein Aufnahmelauf kann keinen abschicken.
$portal = file_get_contents($instanz . '/portal.php');
$portal = str_replace(
    "\$_sess_key  = 'portal_auth_' . \$client['id'];",
    "\$_sess_key  = 'portal_auth_' . \$client['id'];\n"
  . "// Lokale Demo-Instanz: die PIN-Abfrage ist ein POST.\n"
  . "\$_SESSION[\$_sess_key] = true;",
    $portal
);
file_put_contents($instanz . '/portal.php', $portal);

// ── 5. Hinweisblasen ausblenden ─────────────────────────────────────
// Sie verdecken rechts oben ein ganzes Widget, und ihr Schliessen-Knopf
// ist ein POST, den die Demo ablehnt. Siehe docs/screenshots/README.md.
file_put_contents($instanz . '/assets/css/app.css',
    "\n/* Lokale Demo-Instanz (tools/serve_demo.php). */\n"
  . ".toast-container, .toast { display: none !important; }\n",
    FILE_APPEND);

// ── 6. Datenbank ────────────────────────────────────────────────────
require_once __DIR__ . '/lib_sqlite_mirror.php';
require_once __DIR__ . '/serve_demo_compat.php';

$pdo = new SqliteSpiegelPDO('sqlite:' . $instanz . '/demo.sqlite', null, null,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('PRAGMA foreign_keys = ON');
serve_demo_funktionen($pdo);

foreach (nach_sqlite((string) file_get_contents($wurzel . '/install/schema.sql')) as $a) {
    $pdo->exec($a);
}

if (!defined('DEMO_MODE')) define('DEMO_MODE', true);
if (!defined('BASE_URL'))  define('BASE_URL', '');
require_once $wurzel . '/includes/env.php';
require_once $wurzel . '/includes/demo.php';

chdir($instanz);
$GLOBALS['pdo'] = $pdo;
require $instanz . '/tools/seed_demo_lib.php';
ob_start();
require $instanz . '/tools/seed_demo_data.php';
$seed_ausgabe = (string) ob_get_clean();

$pdo->prepare('UPDATE settings SET v = ? WHERE k = ?')->execute([$sprache, 'ui_language']);

foreach (explode("\n", trim($seed_ausgabe)) as $z) {
    if (trim($z) !== '' && strpos($z, 'http') === false) {
        echo '  ' . trim($z) . "\n";
    }
}

// ── 7. Router ───────────────────────────────────────────────────────
// Bildet die Rewrite-Regeln der .htaccess nach: /tasks -> tasks.php.
file_put_contents($instanz . '/router.php', <<<'PHP'
<?php
$pfad = rtrim((string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/');

if ($pfad === '') {
    $_SERVER['SCRIPT_NAME'] = '/index.php';
    require __DIR__ . '/index.php';
    return true;
}

$datei = __DIR__ . $pfad;
if (is_file($datei) && substr($datei, -4) !== '.php') {
    return false;   // statische Datei: der eingebaute Server liefert sie
}
if (is_file($datei)) {
    $_SERVER['SCRIPT_NAME'] = $pfad;
    require $datei;
    return true;
}
if (is_file($datei . '.php')) {
    $_SERVER['SCRIPT_NAME'] = $pfad . '.php';
    require $datei . '.php';
    return true;
}

http_response_code(404);
echo '404';
return true;
PHP);

// ── 8. Los ──────────────────────────────────────────────────────────
$token = hash('sha256', 'admin-dashboard-demo::hofmann');
echo "\nBereit:\n";
echo "  http://127.0.0.1:$port/\n";
echo "  http://127.0.0.1:$port/portal?token=$token\n";
echo "\nBeenden mit Strg+C. Das Verzeichnis bleibt liegen und wird beim\n";
echo "naechsten Aufruf neu gebaut.\n\n";

$befehl = escapeshellarg(PHP_BINARY) . ' -S 127.0.0.1:' . $port
        . ' -t ' . escapeshellarg($instanz) . ' ' . escapeshellarg($instanz . '/router.php');
passthru($befehl);
