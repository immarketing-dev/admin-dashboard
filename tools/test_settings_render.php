<?php
/**
 * Rendert den Mail-Reiter der Einstellungen wirklich - gegen den
 * SQLite-Spiegel. Aufruf: php tools/test_settings_render.php
 *
 * tools/test_mail_templates.php prueft die Vorlagen fuer sich: welcher
 * Schluessel zu welcher Sprache gehoert, was mail_in_sprache()
 * wiederherstellt, welcher Standard greift. Das sagt noch nicht, dass
 * der Editor laeuft. Der holt seine vier Werte in einem Zug aus einem
 * Abschluss und zerlegt das Ergebnis in eine Liste - ein Dreher darin
 * ist im Browser eine weisse Seite, und keine der anderen Pruefungen
 * sieht ihn.
 *
 * Ausgefuehrt wird der ECHTE Quelltext von settings.php. Ersetzt sind
 * nur die Zeilen, die MySQL und eine Sitzung braeuchten. Wird die Seite
 * umgebaut, laeuft dieser Test ueber den neuen Stand.
 *
 * Der zweite Teil prueft das Speichern. Das ist die Stelle, an der ein
 * Fehler still bliebe: schriebe der Editor eine englische Anpassung
 * unter den deutschen Schluessel, ueberschriebe er den deutschen Text
 * des Betreibers, und zu sehen waere das erst in der naechsten Mail.
 * Weil settings.php nach dem Speichern mit exit() endet, laeuft jeder
 * dieser Faelle in einem eigenen Prozess gegen eine Datei-Datenbank.
 */

require_once __DIR__ . '/lib_sqlite_mirror.php';

$wurzel = dirname(__DIR__);
$fehler = 0;

// ── Umgebung, die sonst config.php stellt ────────────────────────────
// Dieselben Namen wie in config.php, ohne die vier DB_-Zugangsdaten:
// die Seite laeuft hier gegen den SQLite-Spiegel.
define('APP_NAME',      'Testpanel');
define('BASE_URL',      'https://panel.example.com');
define('COMPANY_NAME',  'Testfirma GmbH');
define('COMPANY_SHORT', 'Testfirma');
define('COLOR_PRIMARY', '#149ddd');
define('COLOR_SIDEBAR', '#040b14');
define('ADMIN_EMAIL',   'admin@example.com');
define('SUPPORT_EMAIL', 'support@example.com');
define('MAIN_WEBSITE',  'https://example.com');
define('DEMO_MODE',     false);
define('SSO_ENABLED',   false);
define('SMTP_HOST',     '');
define('SMTP_PORT',     587);
define('SMTP_USER',     '');
define('SMTP_PASS',     '');

// Das Sicherungsverzeichnis legt die Seite an, wenn es fehlt. Ohne
// Angabe waere das ein Ordner neben dem Projekt; der Test bekommt
// stattdessen einen eigenen und raeumt ihn am Ende weg.
$sicherungen = sys_get_temp_dir() . '/adm_test_backups_' . getmypid();
$EINSTELLUNGEN = ['backup_dir' => $sicherungen];
register_shutdown_function(function () use ($sicherungen) {
    foreach (glob($sicherungen . '/*') ?: [] as $d) @unlink($d);
    @rmdir($sicherungen);
});

function setting(string $key, string $default = ''): string {
    global $EINSTELLUNGEN;
    return $EINSTELLUNGEN[$key] ?? $default;
}

/**
 * Holt die Einstellungen aus der Datenbank.
 *
 * Das tut sonst config.php zu Beginn jeder Anfrage. Ohne diesen Schritt
 * zeigte der Editor nach dem Speichern weiter den alten Text - der
 * Schreibvorgang laeuft ja in einem anderen Prozess.
 */
function einstellungen_laden(PDO $pdo): void
{
    global $EINSTELLUNGEN, $sicherungen;

    $EINSTELLUNGEN = [];
    foreach ($pdo->query('SELECT k, v FROM settings')->fetchAll(PDO::FETCH_KEY_PAIR) as $k => $v) {
        $EINSTELLUNGEN[(string) $k] = (string) $v;
    }
    // Das Sicherungsverzeichnis gehoert dem Test, nicht der Datenbank.
    $EINSTELLUNGEN['backup_dir'] = $sicherungen;
}
function asset(string $pfad): string { return $pfad; }
function csrf_token(): string { return 'testtoken'; }
function csrf_field(): string { return ''; }
function csrf_check(): void {}

require_once $wurzel . '/includes/migrations.php';
require_once $wurzel . '/includes/logging.php';
require_once $wurzel . '/includes/demo.php';
require_once $wurzel . '/includes/i18n.php';

// Angemeldet als Verwaltung. Die Rolle steht in der Sitzung; daraus
// leitet ist_verwaltung() in includes/users.php ab, wer die
// Benutzerverwaltung zu sehen bekommt.
$_SESSION = ['admin_id' => 1, 'admin_email' => 'admin@example.com', 'admin_role' => 'admin'];

// ── Datenbank ────────────────────────────────────────────────────────
// Als Datei, nicht im Arbeitsspeicher: die Faelle zum Speichern laufen
// in einem Kindprozess, und der muss dieselbe Datenbank sehen.
$kind    = ($argv[1] ?? '') === '--speichern';
$db_pfad = $kind ? $argv[2] : sys_get_temp_dir() . '/adm_settings_' . getmypid() . '.sqlite';

$pdo = new SqliteSpiegelPDO('sqlite:' . $db_pfad, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('PRAGMA foreign_keys = ON');

if (!$kind) {
    register_shutdown_function(fn() => @unlink($db_pfad));
    foreach (nach_sqlite(file_get_contents($wurzel . '/install/schema.sql')) as $anweisung) {
        $pdo->exec($anweisung);
    }
    $pdo->exec("INSERT INTO users (id, email, password_hash, name, role, is_active)
                VALUES (1, 'admin@example.com', 'x', 'Test', 'admin', 1)");
}

/**
 * Fuehrt settings.php aus und gibt das erzeugte Markup zurueck.
 *
 * Zwei require-Zeilen am Kopf fallen weg: config.php braeuchte MySQL,
 * auth.php eine Sitzung. Der Rest bleibt und laeuft mit -
 * mail_templates.php ist der Gegenstand, backup.php bringt Konstanten
 * mit, die die Seite ausgibt, und logging.php schreibt gegen den
 * Spiegel wie im Betrieb.
 */
function seite_rendern(PDO $pdo, array $get, ?array $post = null): string
{
    $wurzel = dirname(__DIR__);
    $quelle = file_get_contents($wurzel . '/settings.php');

    $quelle = preg_replace(
        '/^require_once .*(config\.php|auth\.php).*$/m',
        '// (im Test ersetzt)',
        $quelle
    );
    $quelle = str_replace("require 'includes/", "require '" . $wurzel . "/includes/", $quelle);
    $quelle = str_replace("require_once 'includes/", "require_once '" . $wurzel . "/includes/", $quelle);
    // __DIR__ meint in settings.php durchweg das Wurzelverzeichnis -
    // Einbindungen, Logo- und Favicon-Pfade, das Sicherungsverzeichnis.
    // Aus dem Temp-Verzeichnis heraus zeigte es ins Leere.
    $quelle = str_replace('__DIR__', var_export($wurzel, true), $quelle);

    einstellungen_laden($pdo);

    $_GET  = $get;
    $_POST = $post ?? [];
    $_SERVER['REQUEST_METHOD'] = $post === null ? 'GET' : 'POST';
    $_SERVER['SCRIPT_NAME']    = '/settings.php';
    $_SERVER['REQUEST_URI']    = '/settings';
    $_SERVER['QUERY_STRING']   = http_build_query($get);

    $tmp = tempnam(sys_get_temp_dir(), 'set') . '.php';
    file_put_contents($tmp, $quelle);

    $ausfuehren = static function (string $datei, PDO $pdo): string {
        ob_start();
        try {
            include $datei;
        } catch (Throwable $e) {
            ob_end_clean();
            throw $e;
        }
        return (string) ob_get_clean();
    };

    try {
        return $ausfuehren($tmp, $pdo);
    } finally {
        @unlink($tmp);
    }
}

// Im Kindprozess wird genau ein Formular abgeschickt. settings.php
// beendet sich danach selbst - deshalb steht hier nichts mehr.
if ($kind) {
    seite_rendern($pdo, [], json_decode(file_get_contents($argv[3]), true));
    exit(0);
}

/** Prueft eine Seite auf Fehlerspuren und liefert das Markup. */
function pruefe(PDO $pdo, array $get, string $name): ?string
{
    global $fehler;

    // "Undefined array key" heisst hier: an dieser Stelle steht im
    // Browser nichts oder etwas Falsches. Also ein Fehler, kein Rauschen.
    $gesammelt = [];
    set_error_handler(function ($nr, $text, $datei, $zeile) use (&$gesammelt) {
        $gesammelt[] = "$text (Zeile $zeile)";
        return true;
    });

    try {
        $html = seite_rendern($pdo, $get);
    } catch (Throwable $e) {
        restore_error_handler();
        echo "FEHLER [$name]: " . $e->getMessage() . "\n";
        $fehler++;
        return null;
    }
    restore_error_handler();

    foreach ($gesammelt as $m) {
        echo "FEHLER [$name]: $m\n";
        $fehler++;
    }
    if ($html === '' ) {
        echo "FEHLER [$name]: leere Seite\n";
        $fehler++;
        return null;
    }
    return $html;
}

/** Ist die Schaltflaeche dieser Sprache die hervorgehobene? */
function markiert(string $html, string $sprache): bool
{
    return (bool) preg_match('/tpllang=' . $sprache . '"\s+class="btn btn-primary"/', $html);
}

function behaupte(string $name, bool $ok): void
{
    global $fehler;
    echo ($ok ? 'PASS' : 'FAIL') . "  $name\n";
    if (!$ok) $fehler++;
}

// ── Der Mail-Reiter in beiden Sprachen ───────────────────────────────
$de = pruefe($pdo, ['tab' => 'mail', 'tpl' => 'portal_access', 'tpllang' => 'de'], 'mail/de');
$en = pruefe($pdo, ['tab' => 'mail', 'tpl' => 'portal_access', 'tpllang' => 'en'], 'mail/en');

if ($de !== null && $en !== null) {
    behaupte('deutsche Fassung zeigt den deutschen Betreff',
        strpos($de, 'Ihr Zugang zum Projekt-Portal') !== false);
    behaupte('englische Fassung zeigt den englischen Betreff',
        strpos($en, 'Your access to the project portal') !== false);
    behaupte('englische Fassung ohne deutschen Vorlagentext',
        strpos($en, 'Ihr Zugang zum Projekt-Portal') === false);

    // Der Umschalter ist da und weiss, wo er steht.
    behaupte('Umschalter zeigt beide Sprachen',
        strpos($de, 'Deutsch') !== false && strpos($de, 'English') !== false);
    behaupte('bearbeitete Sprache ist markiert', markiert($en, 'en'));
    behaupte('die andere Sprache ist es nicht', !markiert($en, 'de'));

    // Die Sprache haengt an jedem Weg zurueck in den Editor, sonst
    // faellt man beim Wechsel der Vorlage in die andere Fassung.
    behaupte('Vorlagenliste behaelt die Sprache',
        substr_count($en, 'tpllang=en') >= count(mail_templates()));
    behaupte('Formular gibt die Sprache mit',
        strpos($en, 'name="tpl_lang" value="en"') !== false);

    // Die Vorschau steht im iframe und traegt die Sprache im html-Tag.
    behaupte('Vorschau in der bearbeiteten Sprache',
        strpos($en, 'lang=&quot;en&quot;') !== false);
}

// ── Ohne Angabe: die Sprache des Panels ──────────────────────────────
$ohne = pruefe($pdo, ['tab' => 'mail'], 'mail/ohne Angabe');
if ($ohne !== null) {
    behaupte('ohne Angabe die Panelsprache', markiert($ohne, 'de'));
}

// Ein erfundenes Kuerzel darf nicht durchschlagen - lang() suchte sonst
// eine Sprachdatei, die es nicht gibt.
$quatsch = pruefe($pdo, ['tab' => 'mail', 'tpllang' => 'kl'], 'mail/unbekannte Sprache');
if ($quatsch !== null) {
    behaupte('unbekannte Sprache faellt auf die Panelsprache zurueck', markiert($quatsch, 'de'));
}

// ── Die uebrigen Reiter laufen weiter ────────────────────────────────
// Der Umbau lag im Mail-Reiter, aber die Seite ist eine Datei. Ein
// Klammerfehler dort nimmt alle anderen mit.
foreach (['company', 'design', 'system', 'users', 'security'] as $reiter) {
    pruefe($pdo, ['tab' => $reiter], "tab/$reiter");
}

// =====================================================================
// Speichern
// =====================================================================
// Hier bliebe ein Fehler still: schriebe der Editor eine englische
// Anpassung unter den deutschen Schluessel, ueberschriebe er den
// deutschen Text des Betreibers - sichtbar erst in der naechsten Mail.

/** Schickt ein Formular ab; settings.php beendet dabei den Kindprozess. */
function absenden(string $db_pfad, array $post): void
{
    global $fehler;

    // Die Felder gehen ueber eine Datei statt ueber die Befehlszeile:
    // escapeshellarg() verstuemmelt unter Windows Anfuehrungszeichen,
    // und JSON besteht groesstenteils daraus.
    $daten = tempnam(sys_get_temp_dir(), 'pst');
    file_put_contents($daten, json_encode($post));

    $befehl = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__)
            . ' --speichern ' . escapeshellarg($db_pfad) . ' ' . escapeshellarg($daten);

    $ausgabe = [];
    $rc = 0;
    exec($befehl . ' 2>&1', $ausgabe, $rc);
    @unlink($daten);

    if ($rc !== 0) {
        echo "FEHLER [speichern]: Kindprozess endete mit $rc\n";
        foreach ($ausgabe as $z) echo "  $z\n";
        $fehler++;
    }
}

/** Der gespeicherte Wert eines Einstellungsschluessels, oder null. */
function gespeichert(PDO $pdo, string $k): ?string
{
    $s = $pdo->prepare('SELECT v FROM settings WHERE k = ?');
    $s->execute([$k]);
    $v = $s->fetchColumn();
    return $v === false ? null : (string) $v;
}

$de_key    = 'mailtpl_portal_access_subject';
$en_key    = 'mailtpl_portal_access_en_subject';
$de_body   = 'mailtpl_portal_access_body';
$standard  = mail_templates()['portal_access'];
$standard_en = mail_in_sprache('en', fn() => mail_templates())['portal_access'];

// --- Eine englische Anpassung landet unter dem englischen Schluessel ---
absenden($db_pfad, [
    'action'      => 'save_mail_template',
    'tpl_key'     => 'portal_access',
    'tpl_lang'    => 'en',
    'tpl_subject' => 'English subject',
    'tpl_body'    => 'English body',
]);
behaupte('englische Anpassung unter dem englischen Schluessel',
    gespeichert($pdo, $en_key) === 'English subject');
behaupte('der deutsche Schluessel bleibt unberuehrt',
    gespeichert($pdo, $de_key) === null);

// --- Und die deutsche daneben, ohne sich zu stossen ---
absenden($db_pfad, [
    'action'      => 'save_mail_template',
    'tpl_key'     => 'portal_access',
    'tpl_lang'    => 'de',
    'tpl_subject' => 'Deutscher Betreff',
    'tpl_body'    => 'Deutscher Text',
]);
behaupte('deutsche Anpassung ohne Kuerzel',
    gespeichert($pdo, $de_key) === 'Deutscher Betreff');
behaupte('die englische steht weiter daneben',
    gespeichert($pdo, $en_key) === 'English subject');

// --- Der Standard wird nicht gespeichert ---
// Wer den unveraenderten Text abschickt, soll keine Kopie hinterlassen:
// sonst liefe die Vorlage kuenftigen Aenderungen am Standard davon. Der
// Vergleich muss dafuer gegen den Standard DER BEARBEITETEN Sprache
// laufen, nicht gegen den deutschen.
absenden($db_pfad, [
    'action'      => 'save_mail_template',
    'tpl_key'     => 'portal_access',
    'tpl_lang'    => 'en',
    'tpl_subject' => $standard_en['subject'],
    'tpl_body'    => $standard_en['body'],
]);
behaupte('englischer Standard wird nicht gespeichert',
    gespeichert($pdo, $en_key) === null);
behaupte('die deutsche Anpassung ueberlebt das',
    gespeichert($pdo, $de_key) === 'Deutscher Betreff');

// --- Zuruecksetzen betrifft nur die bearbeitete Sprache ---
absenden($db_pfad, [
    'action'      => 'save_mail_template',
    'tpl_key'     => 'portal_access',
    'tpl_lang'    => 'en',
    'tpl_subject' => 'English again',
    'tpl_body'    => 'English body again',
]);
absenden($db_pfad, [
    'action'   => 'reset_mail_template',
    'tpl_key'  => 'portal_access',
    'tpl_lang' => 'en',
]);
behaupte('Zuruecksetzen raeumt die englische Fassung weg',
    gespeichert($pdo, $en_key) === null);
behaupte('Zuruecksetzen laesst die deutsche stehen',
    gespeichert($pdo, $de_key) === 'Deutscher Betreff'
    && gespeichert($pdo, $de_body) === 'Deutscher Text');

// --- Ohne Angabe: die Sprache des Panels, nicht irgendeine ---
absenden($db_pfad, [
    'action'      => 'save_mail_template',
    'tpl_key'     => 'milestone',
    'tpl_subject' => 'Ohne Sprachangabe',
    'tpl_body'    => 'Text',
]);
behaupte('ohne Angabe unter dem deutschen Schluessel',
    gespeichert($pdo, 'mailtpl_milestone_subject') === 'Ohne Sprachangabe'
    && gespeichert($pdo, 'mailtpl_milestone_en_subject') === null);

// --- Der Editor zeigt hinterher, was gespeichert wurde ---
$nachher = pruefe($pdo, ['tab' => 'mail', 'tpl' => 'portal_access', 'tpllang' => 'de'], 'mail/nach dem Speichern');
if ($nachher !== null) {
    behaupte('gespeicherter Text steht im Feld',
        strpos($nachher, 'Deutscher Betreff') !== false);
    behaupte('als angepasst gekennzeichnet',
        strpos($nachher, 'Auf Standard zurücksetzen') !== false);
}

echo $fehler === 0 ? "\nOK: Einstellungen rendern und speichern.\n" : "\nFEHLGESCHLAGEN: $fehler Problem(e).\n";
exit($fehler === 0 ? 0 : 1);
