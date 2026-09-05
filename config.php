<?php
require_once __DIR__ . '/includes/env.php';
env_load(__DIR__ . '/.env');

// Ohne .env kann nichts laufen – mit einer Anleitung statt einer
// nichtssagenden Fehlermeldung abbrechen.
if (env('DB_NAME') === null) {
    http_response_code(500);
    die('<h1>Setup erforderlich</h1>'
      . '<p>Es wurde keine <code>.env</code> gefunden. Kopiere '
      . '<code>.env.example</code> nach <code>.env</code> und trage deine '
      . 'Datenbankzugangsdaten ein. Die vollständige Anleitung steht in der '
      . '<code>README.md</code>.</p>');
}

// ── Datenbank ──────────────────────────────────────────────────────
define('DB_HOST', env('DB_HOST', 'localhost'));
define('DB_NAME', env('DB_NAME'));
define('DB_USER', env('DB_USER', ''));
define('DB_PASS', env('DB_PASS', ''));

// ── Unternehmen & System ───────────────────────────────────────────
define('APP_NAME',      env('APP_NAME', 'Admin Panel'));
define('COMPANY_NAME',  env('COMPANY_NAME', 'Your Company'));
define('COMPANY_SHORT', env('COMPANY_SHORT', 'Your Company'));
define('BASE_URL',      rtrim(env('BASE_URL', ''), '/'));
define('MAIN_WEBSITE',  rtrim(env('MAIN_WEBSITE', ''), '/'));
define('ADMIN_EMAIL',   env('ADMIN_EMAIL', ''));
define('SUPPORT_EMAIL', env('SUPPORT_EMAIL', ''));

// ── Design ─────────────────────────────────────────────────────────
define('COLOR_PRIMARY', env('COLOR_PRIMARY', '#149ddd'));
define('COLOR_SIDEBAR', env('COLOR_SIDEBAR', '#040b14'));

// ── E-Mail ─────────────────────────────────────────────────────────
define('SMTP_HOST', env('SMTP_HOST', ''));
define('SMTP_USER', env('SMTP_USER', ''));
define('SMTP_PASS', env('SMTP_PASS', ''));
define('SMTP_PORT', (int) env('SMTP_PORT', '587'));

// ── Demo-Modus (standardmäßig aus) ─────────────────────────────────
// Öffentlich erreichbares, schreibgeschütztes Panel ohne Anmeldung.
// Nur über die .env schaltbar - siehe includes/demo.php.
define('DEMO_MODE', env_bool('DEMO_MODE', false));
require_once __DIR__ . '/includes/demo.php';
demo_send_headers();

// ── Cross-Domain-SSO (standardmäßig aus) ───────────────────────────
// In der Demo zwingend aus, unabhängig von der .env: sso.php entwertet
// Token in der Datenbank, noch bevor ein POST im Spiel ist. Das wäre ein
// Schreibzugriff auf einem GET - und ein Anmeldeweg, den eine öffentlich
// begehbare Demo ohnehin nicht braucht.
define('SSO_ENABLED', env_bool('SSO_ENABLED', false) && !DEMO_MODE);

// ── Fehlerausgabe ──────────────────────────────────────────────────
// Details gehören ins Log, nicht in den Browser.
ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

// Ohne Auffangnetz endete jeder unbehandelte Fehler in einer leeren
// Seite - ohne Hinweis, ohne Nummer, ohne etwas, wonach sich im
// Protokoll suchen liesse.
require_once __DIR__ . '/includes/errors.php';
fehler_handler_einrichten();

// ── Verbindung ─────────────────────────────────────────────────────
try {
    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,

            // Echte Prepared Statements statt der Nachbildung im Treiber.
            // Bei der Nachbildung baut PDO die Abfrage selbst zusammen und
            // maskiert die Werte dabei - richtig, aber es bleibt
            // Zeichenkettenarbeit, und der Server sieht am Ende doch eine
            // fertige Abfrage. Echte Prepares trennen Anweisung und Werte
            // bis in den Server hinein; eine Einschleusung ueber den Wert
            // ist dann von der Bauart her ausgeschlossen.
            //
            // Voraussetzung dafuer war, die drei "INTERVAL ?" loszuwerden
            // (includes/auth_login.php, systemlogs.php): dort erwartet
            // MySQL einen Zahlenausdruck, und gebundene Werte kommen als
            // Zeichenkette an.
            PDO::ATTR_EMULATE_PREPARES => false,

            // Ohne das liefert der Treiber jede Spalte als Zeichenkette,
            // auch INT und DECIMAL. Der Code vergleicht an vielen Stellen
            // mit === gegen Zahlen.
            PDO::ATTR_STRINGIFY_FETCHES => false,
        ]
    );
} catch (PDOException $e) {
    error_log('DB connection failed: ' . $e->getMessage());
    http_response_code(500);
    die('Kritischer Systemfehler: Verbindung zur Datenbank fehlgeschlagen. '
      . 'Details stehen im Server-Fehlerlog.');
}

require_once __DIR__ . '/includes/migrations.php';
run_migrations($pdo);

require_once __DIR__ . '/includes/i18n.php';

// Cached settings helper – available on every page
function setting(string $key, string $default = ''): string {
    global $pdo;
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        try {
            foreach ($pdo->query("SELECT k, v FROM settings")->fetchAll(PDO::FETCH_ASSOC) as $r)
                $cache[$r['k']] = $r['v'];
        } catch (PDOException $e) {
            // Weiterlaufen mit Vorgabewerten ist richtig - eine Seite,
            // die wegen der Firmenanschrift abbricht, hilft niemandem.
            // Ohne diese Zeile blieb aber offen, warum ploetzlich
            // ueberall die Vorgaben stehen.
            error_log('Einstellungen konnten nicht gelesen werden: ' . $e->getMessage());
        }
    }
    return $cache[$key] ?? $default;
}

/**
 * Adresse einer eigenen Auslieferungsdatei, mit Zeitstempel.
 *
 * assets/.htaccess laesst den Browser alles unter assets/ ein Jahr lang
 * behalten. Das geht nur gut, solange sich die Adresse aendert, sobald
 * sich die Datei aendert - sonst erreicht eine Korrektur an app.css
 * niemanden mehr, der die Seite schon einmal geladen hat.
 *
 * filemtime() statt einer Versionsnummer von Hand: tools/deploy.php
 * kopiert die Dateien, der Zeitstempel wandert dabei mit, und niemand
 * muss an eine Nummer denken.
 */
function asset(string $pfad): string {
    static $cache = [];
    if (!isset($cache[$pfad])) {
        $zeit = @filemtime(__DIR__ . '/' . $pfad);
        $cache[$pfad] = $pfad . ($zeit ? '?v=' . $zeit : '');
    }
    return $cache[$pfad];
}

function status_badge(string $status, string $extra_classes = ''): string {
    static $map = [
        'Offen'          => 'status-offen',
        'In Bearbeitung' => 'status-in-bearbeitung',
        'Erledigt'       => 'status-erledigt',
        'Storniert'      => 'status-storniert',
        'Bezahlt'        => 'status-bezahlt',
        'Überfällig'     => 'status-ueberfaellig',
    ];
    $cls = $map[$status] ?? 'status-offen';
    return '<span class="status-badge ' . $cls . ($extra_classes ? ' ' . $extra_classes : '') . '">' . htmlspecialchars($status) . '</span>';
}
