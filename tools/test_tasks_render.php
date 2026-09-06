<?php
/**
 * Rendert tasks.php wirklich - gegen den SQLite-Spiegel.
 * Aufruf: php tools/test_tasks_render.php
 *
 * test_task_members.php und test_task_links.php pruefen den Abgleich.
 * Beides sagt nicht, dass die Seite auch laeuft: ein falscher
 * Array-Schluessel im Kartenmarkup, eine Variable, die nur bei
 * vorhandenen Zustaendigen gesetzt wird - das sieht keiner der beiden.
 *
 * Deshalb wird hier der ECHTE Quelltext ausgefuehrt. Ersetzt werden
 * die Zeilen, die MySQL, eine Sitzung, den Mailer oder Netz braeuchten.
 */
require_once __DIR__ . '/lib_sqlite_mirror.php';

$wurzel = dirname(__DIR__);
$fehler = 0;

define('COMPANY_SHORT', 'Testfirma');
define('COMPANY_NAME',  'Testfirma GmbH');
define('COLOR_PRIMARY', '#149ddd');
define('COLOR_SIDEBAR', '#040b14');
define('DEMO_MODE',     false);
define('APP_NAME',      'Testpanel');
define('ADMIN_EMAIL',   'admin@example.test');
define('MAIN_WEBSITE',  'https://example.test');
define('SUPPORT_EMAIL', 'support@example.test');

$EINSTELLUNGEN = ['default_hourly_rate' => '75', 'notify_milestone_email' => '1'];
function setting(string $key, string $default = ''): string {
    global $EINSTELLUNGEN;
    return $EINSTELLUNGEN[$key] ?? $default;
}
function asset(string $pfad): string { return $pfad; }
function csrf_token(): string { return 'testtoken'; }
function csrf_field(): string { return ''; }
function csrf_check(): void {}
// Die Erreichbarkeitspruefung ginge ins Netz; hier antwortet sie stumm.
function uptime_messen(array $adressen): array { return []; }

$_SESSION = ['admin_id' => 1, 'admin_role' => 'admin', 'admin_name' => 'Test'];

require_once $wurzel . '/includes/demo.php';
require_once $wurzel . '/includes/i18n.php';
require_once $wurzel . '/includes/logging.php';
require_once $wurzel . '/includes/mail_log.php';
require_once $wurzel . '/includes/mail_templates.php';
require_once $wurzel . '/includes/task_budget.php';
require_once $wurzel . '/includes/filter_state.php';
require_once $wurzel . '/includes/task_members.php';
require_once $wurzel . '/includes/task_links.php';
require_once $wurzel . '/includes/upload_helper.php';
require_once $wurzel . '/includes/users.php';
require_once $wurzel . '/includes/numbering.php';

$pdo = new SqliteSpiegelPDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('PRAGMA foreign_keys = ON');
foreach (nach_sqlite(file_get_contents($wurzel . '/install/schema.sql')) as $anweisung) {
    $pdo->exec($anweisung);
}

/** Fuehrt tasks.php aus und gibt das Markup zurueck. */
function seite_rendern(PDO $pdo, array $get): string
{
    $wurzel = dirname(__DIR__);
    $quelle = file_get_contents($wurzel . '/tasks.php');

    // config.php braeuchte MySQL, auth.php eine Sitzung, uptime.php geht
    // ins Netz, vendor/autoload den Mailer. Die uebrigen Includes laufen
    // wie im Betrieb - relativ zum Wurzelverzeichnis.
    $quelle = preg_replace(
        '/^require_once .*(config\.php|auth\.php|uptime\.php|autoload\.php|logging\.php|mail_log\.php|mail_templates\.php|task_budget\.php|filter_state\.php|task_members\.php|task_links\.php|users\.php|upload_helper\.php|numbering\.php).*$/m',
        '// (im Test ersetzt)',
        $quelle
    );
    $quelle = str_replace("require 'includes/", "require '" . $wurzel . "/includes/", $quelle);

    // Die Seite definiert projekt_websites_pruefen() auf oberster Ebene.
    // Beim zweiten Rendern gaebe das "cannot redeclare" - die Definition
    // faellt dann weg, die Funktion aus dem ersten Lauf bleibt.
    //
    // Ersetzt wird sie durch GLEICH VIELE Leerzeilen: sonst zeigt jede
    // spaetere Fehlermeldung auf eine um den Block verschobene Zeile,
    // und man sucht die Fundstelle an der falschen Stelle der Datei.
    if (function_exists('projekt_websites_pruefen')) {
        $quelle = preg_replace_callback(
            '/^function projekt_websites_pruefen\(.*?\n\}\n/ms',
            fn($m) => str_repeat("\n", substr_count($m[0], "\n")),
            $quelle,
            1
        );
    }

    $_GET = $get;
    $_POST = [];
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['SCRIPT_NAME'] = '/tasks.php';
    $_SERVER['REQUEST_URI'] = '/tasks';
    $_SERVER['QUERY_STRING'] = http_build_query($get);

    $tmp = tempnam(sys_get_temp_dir(), 'tsk') . '.php';
    file_put_contents($tmp, $quelle);
    $ausfuehren = static function (string $datei, PDO $pdo): string {
        ob_start();
        try { include $datei; } catch (Throwable $e) { ob_end_clean(); throw $e; }
        return (string) ob_get_clean();
    };
    try { return $ausfuehren($tmp, $pdo); } finally { @unlink($tmp); }
}

function pruefe(PDO $pdo, array $get, string $name): ?string
{
    global $fehler;
    $gesammelt = [];
    set_error_handler(function ($nr, $text, $datei, $zeile) use (&$gesammelt) {
        $gesammelt[] = "$text (Zeile $zeile)";
        return true;
    });
    try {
        $html = seite_rendern($pdo, $get);
    } catch (Throwable $e) {
        restore_error_handler();
        echo "FEHLER [$name]: " . $e->getMessage() . ' in ' . basename($e->getFile()) . ':' . $e->getLine() . "\n";
        $fehler++;
        return null;
    }
    restore_error_handler();
    foreach ($gesammelt as $m) { echo "FEHLER [$name]: $m\n"; $fehler++; }
    return $html;
}
function enthaelt(?string $html, string $text, string $name): void
{
    global $fehler;
    if ($html === null) return;
    if (strpos($html, $text) === false) { echo "FEHLER [$name]: erwartet, aber nicht gefunden: $text\n"; $fehler++; }
}
function enthaelt_nicht(?string $html, string $text, string $name): void
{
    global $fehler;
    if ($html === null) return;
    if (strpos($html, $text) !== false) { echo "FEHLER [$name]: darf nicht vorkommen: $text\n"; $fehler++; }
}

// 1. Leer
$html = pruefe($pdo, [], 'leer');
enthaelt($html, 'Keine Projekte gefunden', 'leer');
enthaelt($html, 'Alle Zuständigen', 'leer');

// 2. Mit Daten
$pdo->exec("INSERT INTO users (id, email, password_hash, name, role) VALUES (1, 'k@example.test', 'x', 'Katrin', 'admin')");
$pdo->exec("INSERT INTO users (id, email, password_hash, name, role) VALUES (2, 'j@example.test', 'x', 'Jens', 'staff')");
$pdo->exec("INSERT INTO users (id, email, password_hash, name, role, is_active) VALUES (3, 'r@example.test', 'x', 'Ruth', 'accounting', 0)");
$pdo->exec("INSERT INTO contacts (name, company, contact_type, portal_token) VALUES ('Lena Hofmann', 'Hofmann & Partner', 'Kunde', 'tok1')");
$lena = (int) $pdo->lastInsertId();
$pdo->exec("INSERT INTO contacts (name, company, contact_type) VALUES ('Marc Krüger', 'Krüger Media', 'Geschäftspartner')");
$marc = (int) $pdo->lastInsertId();
$pdo->exec("INSERT INTO tasks (title, status, contact_id, start_date, deadline) VALUES ('Relaunch <Hofmann>', 'In Bearbeitung', $lena, '2026-01-10', '2026-12-31')");
$relaunch = (int) $pdo->lastInsertId();
$pdo->exec("INSERT INTO tasks (title, status, contact_id, start_date) VALUES ('Wartung', 'Offen', $lena, '2026-03-01')");
$wartung = (int) $pdo->lastInsertId();
$pdo->exec("INSERT INTO tasks (title, status, start_date) VALUES ('Storniert & weg', 'Storniert', '2025-01-01')");
$storno = (int) $pdo->lastInsertId();

task_members_abgleichen($pdo, $relaunch, $lena, [$marc]);
task_members_abgleichen($pdo, $wartung,  $lena, []);
task_users_abgleichen($pdo, $relaunch, 1, [2, 3]);
task_links_abgleichen($pdo, $wartung, [['task' => $relaunch, 'kind' => 'follow_up', 'note' => 'Pflege danach']]);
task_links_abgleichen($pdo, $relaunch, [['task' => $storno, 'kind' => 'related', 'note' => '']]);

$html = pruefe($pdo, [], 'daten');
enthaelt($html, 'Relaunch &lt;Hofmann&gt;', 'daten: Titel maskiert');
enthaelt($html, '+1 <abbr', 'daten: Partner-Kennzeichen am Zaehler');
enthaelt($html, 'assignee-dot is-lead', 'daten: Lead-Kreis');
enthaelt($html, 'is-inactive', 'daten: inaktiver Zustaendiger');
enthaelt($html, 'Folgeprojekt von <a href="tasks?highlight=' . $relaunch . '"', 'daten: Vorwaertsverweis');
enthaelt($html, 'Fortgesetzt in <a href="tasks?highlight=' . $wartung . '"', 'daten: Rueckverweis');
enthaelt($html, 'text-decoration-line-through text-muted">Storniert &amp; weg', 'daten: storniertes Gegenueber durchgestrichen');
enthaelt($html, 'const TASK_USERS', 'daten: JSON fuer Fenster');
enthaelt($html, 'name="lead_user_id"', 'daten: Lead-Auswahl');
enthaelt($html, 'data-link-template', 'daten: Verknuepfungsvorlage');
enthaelt($html, 'data-member-group', 'daten: Gruppen im Picker');

// 3. Filter
// Auf die Karten-Kennung pruefen, nicht auf den Titel: der Titel von
// "Wartung" steht als Rueckverweis auch auf der Relaunch-Karte.
$html = pruefe($pdo, ['user' => '2'], 'filter user');
enthaelt($html, 'id="task-' . $relaunch . '"', 'filter user: Jens ist an Relaunch');
enthaelt_nicht($html, 'id="task-' . $wartung . '"', 'filter user: Wartung hat keinen Zustaendigen');

$html = pruefe($pdo, ['contact' => (string) $marc], 'filter beteiligter');
enthaelt($html, 'id="task-' . $relaunch . '"', 'filter beteiligter: Partner findet sein Projekt');
enthaelt_nicht($html, 'id="task-' . $wartung . '"', 'filter beteiligter: Wartung ohne Marc');

pruefe($pdo, ['user' => 'abc', 'contact' => 'xyz', 'status' => 'quatsch'], 'unsinnige Parameter');

// 4. Englisch
sprache_setzen('en');
$html = pruefe($pdo, [], 'englisch');
enthaelt($html, 'Follow-up to', 'englisch: Lesart uebersetzt');
enthaelt($html, 'All assignees', 'englisch: Filter uebersetzt');
sprache_setzen('de');

if ($fehler === 0) { echo "OK: tasks.php rendert in allen geprueften Zustaenden ohne Fehler.\n"; exit(0); }
echo "\nFEHLGESCHLAGEN: $fehler Beanstandung(en).\n";
exit(1);
