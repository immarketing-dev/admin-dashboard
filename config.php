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

// ── Verbindung ─────────────────────────────────────────────────────
try {
    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
        DB_USER,
        DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
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
        } catch (PDOException $e) {}
    }
    return $cache[$key] ?? $default;
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
