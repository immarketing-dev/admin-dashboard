<?php
/**
 * Rendert portal.php wirklich - gegen den SQLite-Spiegel.
 * Aufruf: php tools/test_portal_render.php
 *
 * Das Portal ist die Seite, die der Kunde sieht, und die einzige, die
 * niemand von uns taeglich oeffnet. Ein falscher Array-Schluessel im
 * Terminbereich oder ein Knopf, der im falschen Zustand erscheint,
 * faellt hier auf - nicht beim Kunden.
 *
 * Ausgefuehrt wird der ECHTE Quelltext. Ersetzt sind nur die Zeilen, die
 * MySQL, eine Sitzung, den Mailer oder das Netz braeuchten.
 *
 * ── Warum ein Kindprozess je Aufruf ─────────────────────────────────
 * portal.php beendet sich an vielen Stellen mit exit(): bei ungueltigem
 * Token, nach jedem POST, auf der Abmeldeseite. Ein include im selben
 * Prozess risse den Test mit. Deshalb rendert jeder Aufruf in einem
 * eigenen PHP-Prozess, und die Datenbank liegt als Datei, die beide
 * sehen - dasselbe Verfahren wie in tools/test_settings_render.php.
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
define('SUPPORT_EMAIL', 'support@example.test');
define('MAIN_WEBSITE',  'https://example.test');
define('BASE_URL',      'https://example.test');

$EINSTELLUNGEN = ['company_short' => 'Testfirma'];
function setting(string $key, string $default = ''): string {
    global $EINSTELLUNGEN;
    return $EINSTELLUNGEN[$key] ?? $default;
}
function asset(string $pfad): string { return $pfad; }
function csrf_token(): string { return 'testtoken'; }
function csrf_field(): string { return ''; }
function csrf_check(): void {}
// Kein SMTP im Test - das Portal meldet dem Absender per Mail, wenn ein
// Angebot angenommen oder abgelehnt wird.
function mail_versenden(array $opt): array { return ['ok' => true, 'error' => '']; }
// portal.php ruft sie vor dem Laden des Kontakts auf. Bewusst ohne
// session_start(): das ueberschriebe $_SESSION mit dem leeren Inhalt
// der Datei-Sitzung, und der angemeldete Zustand waere wieder weg.
// Das Feld ist hier ein gewoehnliches Array.
function app_session_start(): void { $GLOBALS['_SESSION'] = $GLOBALS['_SESSION'] ?? []; }

require_once $wurzel . '/includes/demo.php';
require_once $wurzel . '/includes/i18n.php';
require_once $wurzel . '/includes/logging.php';
require_once $wurzel . '/includes/mail_log.php';
require_once $wurzel . '/includes/mail_templates.php';
require_once $wurzel . '/includes/upload_helper.php';
require_once $wurzel . '/includes/invoice_payments.php';
require_once $wurzel . '/includes/task_links.php';
require_once $wurzel . '/includes/portal_notify.php';

// ── Kind oder Eltern? ───────────────────────────────────────────────
$kind    = ($argv[1] ?? '') === '--rendern';
$db_pfad = $kind ? $argv[2] : sys_get_temp_dir() . '/adm_portal_' . getmypid() . '.sqlite';

$pdo = new SqliteSpiegelPDO('sqlite:' . $db_pfad, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('PRAGMA foreign_keys = ON');

if (!$kind) {
    register_shutdown_function(fn() => @unlink($db_pfad));
    foreach (nach_sqlite(file_get_contents($wurzel . '/install/schema.sql')) as $anweisung) {
        $pdo->exec($anweisung);
    }
}

/** Fuehrt portal.php aus und gibt das Markup aus (nur im Kindprozess). */
function seite_rendern(PDO $pdo, array $get, ?string $sprache = null): void
{
    $wurzel = dirname(__DIR__);
    $quelle = file_get_contents($wurzel . '/portal.php');

    // config.php braeuchte MySQL, session.php/csrf.php eine echte
    // Sitzung, vendor/autoload den Mailer. Die uebrigen Includes sind
    // oben schon geladen.
    $quelle = preg_replace(
        '/^\s*require_once .*(config\.php|auth\.php|session\.php|csrf\.php|logging\.php|mail_log\.php'
        . '|mail_templates\.php|upload_helper\.php|invoice_payments\.php|task_links\.php'
        . '|portal_notify\.php|autoload\.php).*$/m',
        '// (im Test ersetzt)',
        $quelle
    );
    $quelle = str_replace("require 'includes/", "require '" . $wurzel . "/includes/", $quelle);

    if ($sprache !== null) sprache_setzen($sprache);

    $_GET  = $get;
    $_POST = [];
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['SCRIPT_NAME'] = '/portal.php';
    $_SERVER['REQUEST_URI'] = '/portal';
    $_SERVER['QUERY_STRING'] = http_build_query($get);
    $_SERVER['HTTP_HOST'] = 'example.test';

    $tmp = tempnam(sys_get_temp_dir(), 'prt') . '.php';
    file_put_contents($tmp, $quelle);
    register_shutdown_function(fn() => @unlink($tmp));
    include $tmp;
}

if ($kind) {
    // Warnungen und Hinweise sind hier keine Nebensache: "Undefined
    // array key" heisst, dass im Browser nichts oder etwas Falsches
    // steht. Header-Warnungen sind bauartbedingt (kein Webserver).
    set_error_handler(function ($nr, $text, $datei, $zeile) {
        if (strpos($text, 'headers already sent') !== false) return true;
        if (strpos($text, 'Cannot modify header') !== false) return true;
        fwrite(STDERR, "PHP-MELDUNG: $text (Zeile $zeile)\n");
        return true;
    });
    $daten = json_decode(file_get_contents($argv[3]), true);
    if (!empty($daten['session'])) {
        foreach ($daten['session'] as $k => $v) $_SESSION[$k] = $v;
    }
    seite_rendern($pdo, $daten['get'] ?? [], $daten['sprache'] ?? null);
    exit(0);
}

// =====================================================================
// Elternprozess: die Faelle
// =====================================================================

/** Rendert einen Zustand im Kindprozess und liefert das Markup. */
function pruefe(string $db_pfad, array $get, string $name, array $session = [], ?string $sprache = null): ?string
{
    global $fehler;

    // Die Felder gehen ueber eine Datei statt ueber die Befehlszeile:
    // escapeshellarg() verstuemmelt unter Windows Anfuehrungszeichen.
    $daten = tempnam(sys_get_temp_dir(), 'pgt');
    file_put_contents($daten, json_encode(['get' => $get, 'session' => $session, 'sprache' => $sprache]));

    $befehl = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__)
            . ' --rendern ' . escapeshellarg($db_pfad) . ' ' . escapeshellarg($daten);

    $ausgabe = [];
    $rc = 0;
    exec($befehl . ' 2>&1', $ausgabe, $rc);
    @unlink($daten);

    $html = implode("\n", $ausgabe);
    if ($rc !== 0) {
        echo "FEHLER [$name]: Kindprozess endete mit $rc\n";
        foreach (array_slice($ausgabe, 0, 6) as $z) echo "  $z\n";
        $fehler++;
        return null;
    }
    // Meldungen aus dem Kind stehen mit im Text.
    foreach ($ausgabe as $z) {
        if (strpos($z, 'PHP-MELDUNG:') === 0) { echo "FEHLER [$name]: $z\n"; $fehler++; }
    }
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

// Der Token muss mindestens zehn Zeichen haben - portal.php weist
// kuerzere ab, bevor es die Datenbank fragt.
$TOK = 'tok-lena-0000';

// Mit gesetzter PIN: ohne sie zeigt das Portal die Zugangsseite,
// nicht das Portal selbst. $SITZUNG meldet den Kunden an.
$pdo->exec("INSERT INTO contacts (name, email, contact_type, portal_token, portal_notify, language, portal_pin)
            VALUES ('Lena Hofmann', 'lena@example.test', 'Kunde', '$TOK', 1, NULL, 'pin-hash-egal')");
$lena = (int) $pdo->lastInsertId();
$SITZUNG = ['portal_auth_' . $lena => true];
$pdo->exec("INSERT INTO tasks (title, status, contact_id) VALUES ('Relaunch', 'In Bearbeitung', $lena)");
$task = (int) $pdo->lastInsertId();
$pdo->exec("INSERT INTO task_contacts (task_id, contact_id, role) VALUES ($task, $lena, 'owner')");

$html = pruefe($db_pfad, ['token' => $TOK], 'kunde', $SITZUNG);
enthaelt($html, 'Relaunch', 'kunde: Projekt sichtbar');
enthaelt($html, 'name="portal_notify"', 'kunde: Abbestell-Schalter im Profil');
enthaelt($html, 'name="language"', 'kunde: Sprachfeld im Profil');
enthaelt($html, 'value="portal_logout"', 'kunde: mit PIN erscheint Abmelden');
enthaelt_nicht($html, 'data-tab="dates"', 'kunde: ohne Termine kein Terminbereich');

// ── Termine ─────────────────────────────────────────────────────────
$pdo->exec("INSERT INTO calendar_events (title, event_date, start_time, category, location)
            VALUES ('Abstimmung', DATE('now', '+7 day'), '10:00:00', 'Meeting', 'Online')");
$ev = (int) $pdo->lastInsertId();
$pdo->exec("INSERT INTO event_contacts (event_id, contact_id, invite_token) VALUES ($ev, $lena, 'inv-1')");

$html = pruefe($db_pfad, ['token' => $TOK], 'termin', $SITZUNG);
enthaelt($html, 'data-tab="dates"', 'termin: Bereich erscheint');
enthaelt($html, 'Abstimmung', 'termin: Titel');
enthaelt($html, 'event_ics?token=inv-1', 'termin: Kalender-Download');
enthaelt($html, 'Kommende Termine', 'termin: Ueberschrift');

// Ohne Einladungstoken kein Download - die Datei zeigte ins Leere.
$pdo->exec("INSERT INTO calendar_events (title, event_date, category) VALUES ('Ohne Token', DATE('now', '+8 day'), 'Termin')");
$ev2 = (int) $pdo->lastInsertId();
$pdo->exec("INSERT INTO event_contacts (event_id, contact_id, invite_token) VALUES ($ev2, $lena, NULL)");

$html = pruefe($db_pfad, ['token' => $TOK], 'termin ohne token', $SITZUNG);
enthaelt($html, 'Ohne Token', 'termin ohne token: Titel steht da');
if ($html !== null && substr_count($html, 'event_ics?token=') !== 1) {
    echo "FEHLER [termin ohne token]: genau ein Download-Knopf erwartet, gefunden: "
       . substr_count($html, 'event_ics?token=') . "\n";
    $fehler++;
}

// Ein vergangener Termin landet im eingeklappten Block.
$pdo->exec("INSERT INTO calendar_events (title, event_date, category) VALUES ('Kickoff', DATE('now', '-30 day'), 'Meeting')");
$ev3 = (int) $pdo->lastInsertId();
$pdo->exec("INSERT INTO event_contacts (event_id, contact_id, invite_token) VALUES ($ev3, $lena, 'inv-3')");

$html = pruefe($db_pfad, ['token' => $TOK], 'vergangener termin', $SITZUNG);
enthaelt($html, 'past-dates', 'vergangener termin: eingeklappter Block');
enthaelt($html, 'Kickoff', 'vergangener termin: Titel');

// ── Angebot: ablehnen nur, solange es offen ist ─────────────────────
$pdo->exec("INSERT INTO quotes (quote_number, subject, contact_id, status, total_amount, items)
            VALUES ('ANG-2026-001', 'Relaunch', $lena, 'Gesendet', 1000.00, '[]')");
$html = pruefe($db_pfad, ['token' => $TOK], 'angebot gesendet', $SITZUNG);
enthaelt($html, 'name="reject_quote"', 'angebot: Ablehnen-Knopf');
enthaelt($html, 'name="accept_quote"', 'angebot: Annehmen-Knopf');

$pdo->exec("UPDATE quotes SET status = 'Angenommen'");
$html = pruefe($db_pfad, ['token' => $TOK], 'angebot angenommen', $SITZUNG);
enthaelt_nicht($html, 'name="reject_quote"', 'angebot: kein Ablehnen nach Annahme');

// ── Ohne Sitzung: die Zugangsseite, nicht das Portal ────────────────
// Der Zugangslink allein reicht nicht - die PIN steht davor.
$html = pruefe($db_pfad, ['token' => $TOK], 'ohne sitzung');
enthaelt_nicht($html, 'value="portal_logout"', 'ohne sitzung: kein Portal');
enthaelt_nicht($html, 'data-tab="dates"', 'ohne sitzung: keine Termine');

// ── Die Abmeldeseite - ohne Token in der Adresse ────────────────────
$html = pruefe($db_pfad, ['logout' => '1'], 'abgemeldet');
enthaelt($html, 'abgemeldet', 'abgemeldet: Seite steht');
enthaelt_nicht($html, $TOK, 'abgemeldet: kein Token in der Seite');

// ── Auf englischer Oberflaeche ──────────────────────────────────────
$html = pruefe($db_pfad, ['token' => $TOK], 'englisch', $SITZUNG, 'en');
enthaelt($html, 'Sign out', 'englisch: Abmelden uebersetzt');
enthaelt($html, 'Upcoming dates', 'englisch: Termine uebersetzt');

// =====================================================================
if ($fehler === 0) {
    echo "OK: portal.php rendert in allen geprueften Zustaenden ohne Fehler.\n";
    exit(0);
}
echo "\nFEHLGESCHLAGEN: $fehler Beanstandung(en).\n";
exit(1);
