<?php
/**
 * install/preflight.php
 *
 * Installations-Pre-Flight-Check.
 *
 * Vor der eigentlichen Einrichtung auf den Server hochladen und im
 * Browser aufrufen (z. B. https://example.com/install/preflight.php).
 * Prueft, was der Server bereits mitbringt - noch bevor .env oder die
 * Datenbank ueberhaupt existieren. Nuetzlich sowohl fuer eine
 * Neuinstallation als auch fuer die Migration einer bestehenden
 * Installation auf diesen Codestand (Zeilenzahlen, schema_version,
 * users-Tabelle etc.).
 *
 * SICHERHEIT: Diese Datei liest Server- und Datenbankinterna aus und darf
 * nicht dauerhaft erreichbar bleiben. Nach Gebrauch loeschen. Sie loescht
 * sich bewusst NICHT selbst - ein sich selbst loeschendes Skript ist ein
 * eigenes Sicherheitsrisiko (siehe die Historie von clear_lockout.php in
 * diesem Projekt). Stattdessen verlangt sie vom Betreiber, sie manuell zu
 * entfernen, und verweigert den Dienst von selbst, sobald eine .env
 * vorhanden ist UND die users-Tabelle mindestens eine Zeile hat (siehe
 * Guard unten).
 *
 * Verarbeitet keinerlei Nutzereingabe ($_GET/$_POST) - reine Diagnose.
 * Muss laufen, bevor .env oder config.php funktionsfaehig sind, und darf
 * deshalb zu keinem Zeitpunkt fatal enden: jede Pruefung faengt ihren
 * eigenen Fehler ab.
 */

error_reporting(E_ALL);
ini_set('display_errors', '0'); // Eigene Meldungen reichen; keine PHP-Stacktraces mit Serverpfaden nach aussen.

$rootDir = dirname(__DIR__);

// ─────────────────────────────────────────────────────────────────────
// Kleine Hilfsfunktionen
// ─────────────────────────────────────────────────────────────────────

function pf_h($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function pf_format_bytes(int $bytes): string
{
    if ($bytes <= 0) {
        return '0 MB';
    }
    $mb = $bytes / (1024 * 1024);
    return number_format($mb, $mb < 10 ? 1 : 0, ',', '.') . ' MB';
}

function pf_ini_to_bytes(string $value): int
{
    $value = trim($value);
    if ($value === '' || $value === '-1') {
        return 0;
    }
    $unit = strtolower(substr($value, -1));
    $num  = (float) $value;
    switch ($unit) {
        case 'g':
            $num *= 1024 * 1024 * 1024;
            break;
        case 'm':
            $num *= 1024 * 1024;
            break;
        case 'k':
            $num *= 1024;
            break;
    }
    return (int) $num;
}

// Liest die tatsaechliche Upload-Obergrenze aus includes/upload_helper.php
// statt sie hier ein zweites Mal hart zu verdrahten (die beiden Werte
// koennten sonst auseinanderlaufen). Nur eine einfache Ganzzahl-
// Multiplikation wird ausgewertet - kein eval(), keine beliebige
// PHP-Auswertung. Liefert null, wenn die Datei fehlt oder das Muster
// nicht gefunden wird.
function pf_read_max_upload_bytes(string $rootDir): ?int
{
    $file = $rootDir . '/includes/upload_helper.php';
    if (!is_readable($file)) {
        return null;
    }
    $src = @file_get_contents($file);
    if ($src === false) {
        return null;
    }
    if (!preg_match('/MAX_UPLOAD_BYTES\s*=\s*([0-9\s*]+);/', $src, $m)) {
        return null;
    }
    $value = 1;
    foreach (explode('*', $m[1]) as $factor) {
        $value *= (int) trim($factor);
    }
    return $value > 0 ? $value : null;
}

// Entfernt DB_PASS/SMTP_PASS-Werte aus einer beliebigen Zeichenkette,
// falls sie dort auftauchen sollten. PDO-Fehlermeldungen enthalten das
// Passwort selbst normalerweise nicht (nur "using password: yes/no"),
// aber "niemals ein Zugangsdatum ausgeben" gilt ausnahmslos - deshalb
// zur Sicherheit trotzdem gefiltert.
function pf_mask_secrets(string $text): string
{
    if (!function_exists('env')) {
        return $text;
    }
    foreach (['DB_PASS', 'SMTP_PASS'] as $key) {
        $maskValue = env($key, '');
        if ($maskValue !== null && $maskValue !== '' && str_contains($text, $maskValue)) {
            $text = str_replace($maskValue, '••••••••', $text);
        }
    }
    return $text;
}

// ─────────────────────────────────────────────────────────────────────
// Ergebnis-Sammlung
// ─────────────────────────────────────────────────────────────────────

/** @var array<int, array{section:string, name:string, status:string, message:string}> */
$results = [];

function pf_add(string $section, string $name, string $status, string $message): void
{
    global $results;
    $results[] = ['section' => $section, 'name' => $name, 'status' => $status, 'message' => $message];
}

// ─────────────────────────────────────────────────────────────────────
// Guard: eine bereits eingerichtete Installation verweigert den Dienst.
//
// Muss VOR jeglicher Ausgabe laufen. Die hier aufgebaute Verbindung wird
// weiter unten im Datenbank-Abschnitt weiterverwendet, statt sie ein
// zweites Mal aufzubauen.
// ─────────────────────────────────────────────────────────────────────

$envFile         = $rootDir . '/.env';
$envExists       = is_readable($envFile);
$envLoaderMissing = false;
$dbName          = null;

$pdo            = null;
$dbConnectError = null; // bereinigte Meldung, enthaelt nie das Passwort

if ($envExists) {
    $envLoaderPath = $rootDir . '/includes/env.php';
    if (is_readable($envLoaderPath)) {
        require_once $envLoaderPath;
        env_load($envFile);
        $dbName = env('DB_NAME');

        if ($dbName !== null && $dbName !== '') {
            try {
                $pdo = new PDO(
                    'mysql:host=' . env('DB_HOST', 'localhost') . ';dbname=' . $dbName . ';charset=utf8mb4',
                    env('DB_USER', ''),
                    env('DB_PASS', ''),
                    [
                        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                        PDO::ATTR_TIMEOUT => 5,
                    ]
                );
            } catch (Throwable $e) {
                $pdo            = null;
                $dbConnectError = pf_mask_secrets($e->getMessage());
            }
        }
    } else {
        // Der .env-Loader selbst fehlt - unvollstaendiger Upload. Ohne ihn
        // kann .env nicht gelesen werden; der Datenbank-Abschnitt meldet
        // das weiter unten und wird uebersprungen.
        $envLoaderMissing = true;
    }
}

$dbUsersCount = null;
if ($pdo !== null) {
    try {
        $dbUsersCount = (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
    } catch (Throwable $e) {
        // users existiert vermutlich noch nicht (Schema noch nicht
        // importiert) - kein "bereits eingerichtet"-Zustand, normal
        // weiterlaufen lassen.
        $dbUsersCount = null;
    }
}

if ($envExists && $dbUsersCount !== null && $dbUsersCount > 0) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Installation bereits eingerichtet. Diese Datei bitte löschen.\n";
    exit;
}

// ─────────────────────────────────────────────────────────────────────
// Server
// ─────────────────────────────────────────────────────────────────────

if (version_compare(PHP_VERSION, '8.1.0', '<')) {
    pf_add('Server', 'PHP-Version', 'FAIL', 'PHP ' . PHP_VERSION . ' gefunden, mindestens 8.1 erforderlich. Darunter brechen Seiten mit einem Fatal Error ab.');
} else {
    pf_add('Server', 'PHP-Version', 'PASS', 'PHP ' . PHP_VERSION . ' (Mindestanforderung: 8.1).');
}

$requiredExtensions = [
    'pdo_mysql' => 'Ohne diese Erweiterung kann keine Datenbankverbindung aufgebaut werden - die Anwendung startet gar nicht erst.',
    'curl'      => 'Wird fuer ausgehende HTTP-Anfragen benoetigt.',
    'mbstring'  => 'Wird fuer mehrbyte-sichere Zeichenkettenverarbeitung (Umlaute, UTF-8) benoetigt.',
    'fileinfo'  => 'Wird zur Dateityp-Erkennung bei Uploads benoetigt (includes/upload_helper.php).',
];
foreach ($requiredExtensions as $ext => $why) {
    $loaded = extension_loaded($ext);
    pf_add('Server', "PHP-Erweiterung: $ext", $loaded ? 'PASS' : 'FAIL', $loaded ? 'Geladen.' : "Fehlt. $why");
}

$gdLoaded = extension_loaded('gd');
pf_add(
    'Server',
    'PHP-Erweiterung: gd',
    $gdLoaded ? 'PASS' : 'WARN',
    $gdLoaded
        ? 'Geladen.'
        : 'Fehlt. Wird nur gebraucht, wenn das Firmenlogo in Rechnungs-/Angebots-PDFs eine GIF- oder WebP-Datei ist (FPDF wandelt diese ueber gd um). PNG- und JPEG-Logos funktionieren auch ohne gd.'
);

$zlibLoaded = extension_loaded('zlib');
pf_add(
    'Server',
    'PHP-Erweiterung: zlib',
    $zlibLoaded ? 'PASS' : 'FAIL',
    $zlibLoaded ? 'Geladen.' : 'Fehlt. FPDF komprimiert PDF-Streams standardmaessig damit - Rechnungs- und Angebots-PDFs koennen ohne zlib nicht erzeugt werden.'
);

if (function_exists('apache_get_modules')) {
    $modules    = apache_get_modules();
    $hasRewrite = in_array('mod_rewrite', $modules, true);
    pf_add(
        'Server',
        'mod_rewrite',
        $hasRewrite ? 'PASS' : 'FAIL',
        $hasRewrite
            ? 'Aktiv.'
            : 'Nicht aktiv. Die Clean URLs (/tasks statt /tasks.php) funktionieren dann nicht; die Anwendung selbst laeuft trotzdem, nur mit .php in jeder URL.'
    );
} else {
    pf_add(
        'Server',
        'mod_rewrite',
        'UNKNOWN',
        'Von PHP aus nicht ermittelbar (kein Apache-Modul-PHP, z. B. PHP-FPM oder CGI - dort gibt es apache_get_modules() nicht). Bitte manuell testen: eine bereinigte URL wie /tasks direkt im Browser aufrufen.'
    );
}

$redirectHint = $_SERVER['REDIRECT_STATUS'] ?? $_SERVER['REDIRECT_URL'] ?? null;
if ($redirectHint !== null) {
    pf_add('Server', '.htaccess wirksam', 'PASS', 'Hinweis auf eine bereits verarbeitete Rewrite-Regel gefunden (REDIRECT_STATUS/REDIRECT_URL gesetzt).');
} else {
    pf_add(
        'Server',
        '.htaccess wirksam',
        'UNKNOWN',
        'Von PHP aus nicht zuverlaessig feststellbar - dieser Aufruf ging direkt auf install/preflight.php, ohne durch eine Rewrite-Regel zu laufen. Bitte manuell testen: eine bereinigte URL wie /tasks direkt im Browser aufrufen.'
    );
}

$rawSavePath = session_save_path();
$savePath    = ($rawSavePath !== false && $rawSavePath !== '') ? $rawSavePath : sys_get_temp_dir();
$pathParts   = explode(';', $savePath);
$actualPath  = trim((string) end($pathParts));
if ($actualPath === '') {
    $actualPath = sys_get_temp_dir();
}
$saveWritable = is_writable($actualPath);
pf_add(
    'Server',
    'session.save_path beschreibbar',
    $saveWritable ? 'PASS' : 'FAIL',
    $saveWritable
        ? '"' . $actualPath . '" ist beschreibbar.'
        : '"' . $actualPath . '" ist nicht beschreibbar. Login und jede angemeldete Sitzung schlagen fehl, da PHP keine Sitzungsdatei anlegen kann.'
);

$maxUploadBytes = pf_read_max_upload_bytes($rootDir);
if ($maxUploadBytes === null) {
    $maxUploadBytes = 20 * 1024 * 1024; // Fallback, falls upload_helper.php nicht lesbar ist
    $maxUploadNote  = ' (includes/upload_helper.php nicht lesbar, Wert 20 MB angenommen)';
} else {
    $maxUploadNote = '';
}

$phpIniLimits = [
    'upload_max_filesize' => pf_ini_to_bytes((string) ini_get('upload_max_filesize')),
    'post_max_size'       => pf_ini_to_bytes((string) ini_get('post_max_size')),
];
foreach ($phpIniLimits as $iniName => $bytes) {
    $ok = $bytes >= $maxUploadBytes;
    pf_add(
        'Server',
        "php.ini: $iniName",
        $ok ? 'PASS' : 'WARN',
        $ok
            ? pf_format_bytes($bytes) . ' erlaubt (Anwendung benoetigt bis zu ' . pf_format_bytes($maxUploadBytes) . ')' . $maxUploadNote . '.'
            : 'Nur ' . pf_format_bytes($bytes) . ' erlaubt, includes/upload_helper.php laesst aber Uploads bis ' . pf_format_bytes($maxUploadBytes) . ' zu' . $maxUploadNote . '. Groessere Dateien weist PHP selbst ab, bevor die Anwendung sie ueberhaupt sieht.'
    );
}

// ─────────────────────────────────────────────────────────────────────
// Dateisystem
// ─────────────────────────────────────────────────────────────────────

$uploadSubdirs = ['client_assets', 'favicons', 'invoices', 'logos', 'quotes', 'wiki'];
$uploadsRoot   = $rootDir . '/uploads';

if (!is_dir($uploadsRoot)) {
    pf_add('Dateisystem', 'uploads/', 'FAIL', 'Verzeichnis fehlt. Keinerlei Datei-Upload funktioniert ohne dieses Verzeichnis.');
} elseif (!is_writable($uploadsRoot)) {
    pf_add('Dateisystem', 'uploads/', 'FAIL', 'Verzeichnis existiert, ist aber nicht beschreibbar. Keinerlei Datei-Upload funktioniert.');
} else {
    pf_add('Dateisystem', 'uploads/', 'PASS', 'Existiert und ist beschreibbar.');
}

foreach ($uploadSubdirs as $sub) {
    $path  = $uploadsRoot . '/' . $sub;
    $label = "uploads/$sub/";
    if (!is_dir($path)) {
        pf_add('Dateisystem', $label, 'FAIL', 'Verzeichnis fehlt. Uploads in diesem Bereich schlagen fehl.');
    } elseif (!is_writable($path)) {
        pf_add('Dateisystem', $label, 'FAIL', 'Verzeichnis existiert, ist aber nicht beschreibbar. Uploads in diesem Bereich schlagen fehl.');
    } else {
        pf_add('Dateisystem', $label, 'PASS', 'Existiert und ist beschreibbar.');
    }
}

pf_add(
    'Dateisystem',
    '.env',
    $envExists ? 'PASS' : 'WARN',
    $envExists
        ? 'Vorhanden.'
        : 'Fehlt. config.php kann ohne .env keine Datenbankverbindung aufbauen. .env.example nach .env kopieren und die Zugangsdaten eintragen.'
);

$autoloadPath = $rootDir . '/vendor/autoload.php';
pf_add(
    'Dateisystem',
    'vendor/autoload.php',
    is_readable($autoloadPath) ? 'PASS' : 'FAIL',
    is_readable($autoloadPath)
        ? 'Vorhanden.'
        : 'Fehlt. PDF-Erzeugung (FPDF) und Mailversand (PHPMailer) stehen ohne die Composer-Abhaengigkeiten nicht zur Verfuegung - betroffene Seiten brechen ab.'
);

// ─────────────────────────────────────────────────────────────────────
// Datenbank - nur wenn .env vorhanden ist und sich lesen laesst.
// ─────────────────────────────────────────────────────────────────────

$existingTables = [];

// -- Vollstaendigkeit der Dateien --------------------------------------
// Wer per FTP hochlaedt, ueberschreibt geaenderte Dateien und uebersieht
// dabei leicht eine neu hinzugekommene. Das Ergebnis ist ein
// require_once auf eine Datei, die es nicht gibt: HTTP 500, weisse
// Seite, und zwar nur auf den Seiten, die sie brauchen - was die Suche
// in die falsche Richtung lenkt.
//
// Geprueft wird nicht gegen eine gepflegte Liste, sondern gegen das,
// was der Code selbst verlangt: jedes require auf eine Projektdatei.
$wurzel = dirname(__DIR__);
$fehlend = [];
$geprueft = 0;

foreach (array_merge(glob($wurzel . '/*.php') ?: [], glob($wurzel . '/includes/*.php') ?: []) as $datei) {
    $inhalt = (string) @file_get_contents($datei);
    if ($inhalt === '') continue;

    // require 'includes/x.php', require_once __DIR__ . '/includes/x.php'
    if (!preg_match_all(
        '/\brequire(?:_once)?\s*(?:\(\s*)?(?:__DIR__\s*\.\s*)?[\'"]([^\'"]+\.php)[\'"]/i',
        $inhalt, $treffer)) {
        continue;
    }

    foreach ($treffer[1] as $ziel) {
        // vendor/ kommt aus dem Paketverwalter und wird anderswo geprueft.
        if (strpos($ziel, 'vendor/') !== false) continue;

        // Zwei Schreibweisen, zwei Aufloesungen: __DIR__ . '/x.php' zeigt
        // neben die einbindende Datei, ein blosses 'x.php' loest PHP
        // gegen das Arbeitsverzeichnis auf - und das ist das Verzeichnis
        // der aufgerufenen Seite, also der Projektstamm. includes/auth.php
        // bindet so config.php ein. Wird nur die erste Lesart geprueft,
        // meldet die Pruefung eine Datei als fehlend, die es gibt.
        $kandidaten = $ziel[0] === '/'
            ? [dirname($datei) . $ziel]
            : [dirname($datei) . '/' . $ziel, $wurzel . '/' . $ziel];

        $geprueft++;
        $da = false;
        foreach ($kandidaten as $k) {
            if (is_file($k)) { $da = true; break; }
        }
        if (!$da) {
            $fehlend[basename($ziel) . ' (verlangt von ' . basename($datei) . ')'] = true;
        }
    }
}

if ($fehlend === []) {
    pf_add('Dateien', 'Eingebundene Dateien', 'PASS',
           $geprueft . ' Einbindung(en) geprueft, jede Datei ist vorhanden.');
} else {
    pf_add('Dateien', 'Eingebundene Dateien', 'FAIL',
           'Es fehlen: ' . implode(', ', array_keys($fehlend))
         . '. Jede Seite, die eine davon einbindet, endet mit HTTP 500. '
         . 'Vermutlich beim Hochladen uebersehen - neue Dateien werden '
         . 'nicht ueberschrieben, sondern muessen einzeln mit.');
}

if (!$envExists) {
    pf_add('Datenbank', 'Abschnitt uebersprungen', 'SKIP', 'Keine .env-Datei gefunden - Datenbankpruefung ist ohne Zugangsdaten nicht moeglich.');
} elseif ($envLoaderMissing) {
    pf_add('Datenbank', 'Abschnitt uebersprungen', 'SKIP', 'includes/env.php fehlt - Installation unvollstaendig, .env kann nicht gelesen werden.');
} elseif ($dbName === null || $dbName === '') {
    pf_add('Datenbank', 'Abschnitt uebersprungen', 'SKIP', '.env vorhanden, aber DB_NAME ist nicht gesetzt.');
} elseif ($pdo === null) {
    pf_add(
        'Datenbank',
        'Verbindung',
        'FAIL',
        'Verbindung fehlgeschlagen: ' . $dbConnectError . ' Ohne Datenbankverbindung kann die Anwendung nicht laufen.'
    );
    pf_add('Datenbank', 'weitere Pruefungen', 'SKIP', 'Uebersprungen - keine Datenbankverbindung.');
} else {
    $maskedDsn = 'mysql:host=' . env('DB_HOST', 'localhost') . ';dbname=' . $dbName . ';user=' . env('DB_USER', '') . ';password=••••••••';
    pf_add('Datenbank', 'Verbindung', 'PASS', 'Erfolgreich (' . $maskedDsn . ').');

    // -- Serverversion vs. Mindestanforderung ----------------------------
    try {
        $rawVersion = (string) $pdo->getAttribute(PDO::ATTR_SERVER_VERSION);
        $isMariaDb  = stripos($rawVersion, 'mariadb') !== false;
        // Manche MariaDB-Builds stellen aus Kompatibilitaetsgruenden ein
        // "5.5.5-"-Praefix voran (Protokollversion, nicht die echte Version).
        $clean = preg_replace('/^5\.5\.5-/', '', $rawVersion);
        $clean = $clean !== null ? $clean : $rawVersion;
        preg_match('/(\d+\.\d+\.\d+)/', $clean, $vm);
        $numeric = $vm[1] ?? $clean;
        $floor   = $isMariaDb ? '10.2.7' : '5.7.8';
        $vendor  = $isMariaDb ? 'MariaDB' : 'MySQL';
        $ok      = version_compare($numeric, $floor, '>=');
        pf_add(
            'Datenbank',
            'Server-Version',
            $ok ? 'PASS' : 'FAIL',
            "$vendor $numeric erkannt (Rohwert: $rawVersion). Mindestanforderung: $vendor $floor."
                . ($ok ? '' : ' quotes.items ist eine JSON-Spalte, die aeltere Server beim Import ablehnen.')
        );
    } catch (Throwable $e) {
        pf_add('Datenbank', 'Server-Version', 'FAIL', 'Konnte nicht ermittelt werden: ' . pf_mask_secrets($e->getMessage()));
    }

    // -- Tabellen: welche der erwarteten existieren -----------------------
    // Die Zahl kommt aus der Liste selbst. Sie stand dreimal als 21 im
    // Text, waehrend die Liste laengst 27 Eintraege hatte: eine
    // vollstaendige Datenbank meldete "21 von 21" und verschwieg sechs
    // Tabellen, eine unvollstaendige rechnete falsch.
    // task_contacts und project_comments standen hier lange nicht drin,
    // obwohl install/schema.sql sie anlegt: sie kamen ueber die
    // Migrationen 5 und 6 dazu, und diese Liste wurde nicht mitgezogen.
    // Einer frischen Installation, der eine der beiden fehlte, meldete
    // die Vorabpruefung trotzdem "vollstaendig".
    // Die Liste kommt aus install/schema.sql, nicht aus einer Kopie hier.
    //
    // Zweimal ist genau das schiefgegangen: erst kannte sie 21 von 23
    // Tabellen, weil zwei ueber Migrationen dazukamen und niemand die
    // Liste nachzog - einer frischen Installation, der eine davon fehlte,
    // meldete die Vorabpruefung trotzdem "vollstaendig". Danach stand
    // dreimal die Zahl 21 im Text, waehrend die Liste laengst 27 Eintraege
    // hatte. Eine Kopie einer Wahrheit, die eine Datei weiter steht,
    // laeuft ihr frueher oder spaeter davon.
    $expectedTables = [];
    $schemaPath = __DIR__ . '/schema.sql';
    if (is_readable($schemaPath)) {
        if (preg_match_all(
            '/CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?`?([a-z_][a-z0-9_]*)`?/i',
            (string) file_get_contents($schemaPath),
            $treffer
        )) {
            $expectedTables = array_values(array_unique(array_map('strtolower', $treffer[1])));
        }
    }
    if ($expectedTables === []) {
        pf_add('Datenbank', 'Tabellen', 'FAIL',
               'install/schema.sql fehlt oder enthaelt keine CREATE TABLE - '
             . 'ohne sie laesst sich nicht sagen, was fehlt.');
    }

    try {
        foreach ($pdo->query('SHOW TABLES') as $row) {
            $existingTables[] = strtolower((string) $row[0]);
        }
        $missing = array_values(array_diff($expectedTables, $existingTables));
        $present = array_values(array_intersect($expectedTables, $existingTables));
        if ($missing === []) {
            pf_add('Datenbank', 'Tabellen (' . count($expectedTables) . ' erwartet)', 'PASS',
                   'Alle ' . count($expectedTables) . ' erwarteten Tabellen vorhanden.');
        } else {
            pf_add(
                'Datenbank',
                'Tabellen (' . count($expectedTables) . ' erwartet)',
                'FAIL',
                count($present) . ' von ' . count($expectedTables) . ' vorhanden. Fehlend: ' . implode(', ', $missing) . '. Vorhanden: ' . implode(', ', $present) . '. install/schema.sql importieren.'
            );
        }
    } catch (Throwable $e) {
        pf_add('Datenbank', 'Tabellen', 'FAIL', 'Tabellenliste konnte nicht gelesen werden: ' . pf_mask_secrets($e->getMessage()));
        $existingTables = [];
    }

    // -- Schemastand ------------------------------------------------------
    // Die Migrationen laufen bei jedem Seitenaufruf und stempeln die
    // Version erst, wenn ALLE Schritte durchgelaufen sind. Steht hier eine
    // kleinere Zahl als erwartet, ist mindestens ein Schritt gescheitert -
    // und dann fehlt irgendwo eine Spalte oder eine Tabelle, waehrend der
    // Code sie schon benutzt. Genau das sieht man einer weissen Seite mit
    // HTTP 500 nicht an.
    $erwartete_version = null;
    $migPath = dirname(__DIR__) . '/includes/migrations.php';
    if (is_readable($migPath)
        && preg_match('/const\s+SCHEMA_VERSION\s*=\s*(\d+)/', (string) file_get_contents($migPath), $m)) {
        $erwartete_version = (int) $m[1];
    }

    try {
        $gespeichert = null;
        if (in_array('settings', $existingTables, true)) {
            $st = $pdo->query("SELECT v FROM settings WHERE k = 'schema_version'");
            $wert = $st->fetchColumn();
            if ($wert !== false) $gespeichert = (int) $wert;
        }

        if ($erwartete_version === null) {
            pf_add('Datenbank', 'Schemastand', 'SKIP',
                   'includes/migrations.php nicht lesbar - erwartete Version unbekannt.');
        } elseif ($gespeichert === null) {
            pf_add('Datenbank', 'Schemastand', 'WARN',
                   'Keine Version gespeichert. Bei einer frischen Datenbank normal - '
                 . 'der erste Seitenaufruf setzt sie auf ' . $erwartete_version . '.');
        } elseif ($gespeichert >= $erwartete_version) {
            pf_add('Datenbank', 'Schemastand', 'PASS',
                   'Version ' . $gespeichert . ', erwartet ' . $erwartete_version . '.');
        } else {
            pf_add('Datenbank', 'Schemastand', 'FAIL',
                   'Version ' . $gespeichert . ', erwartet ' . $erwartete_version . '. '
                 . 'Eine Migration ist nicht durchgelaufen; der Grund steht im '
                 . 'PHP-Fehlerprotokoll als "Migration N FEHLGESCHLAGEN". '
                 . 'Solange das so ist, benutzt der Code Spalten, die es noch nicht gibt.');
        }
    } catch (Throwable $e) {
        pf_add('Datenbank', 'Schemastand', 'FAIL',
               'Konnte nicht gelesen werden: ' . pf_mask_secrets($e->getMessage()));
    }

    // -- Zeilenzahlen: fuer den Vergleich vor/nach einer Migration --------
    foreach (['contacts', 'tasks', 'finances', 'support_tickets', 'wiki_articles', 'logs'] as $table) {
        if (!in_array($table, $existingTables, true)) {
            pf_add('Datenbank', "Zeilen: $table", 'SKIP', 'Tabelle nicht vorhanden.');
            continue;
        }
        try {
            $count = (int) $pdo->query("SELECT COUNT(*) FROM `$table`")->fetchColumn();
            pf_add('Datenbank', "Zeilen: $table", 'PASS', "$count Zeile(n).");
        } catch (Throwable $e) {
            pf_add('Datenbank', "Zeilen: $table", 'FAIL', 'Konnte nicht gezaehlt werden: ' . pf_mask_secrets($e->getMessage()));
        }
    }

    // -- users-Zeilenzahl: Konsequenz explizit benennen --------------------
    if (in_array('users', $existingTables, true)) {
        try {
            $usersCount = (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
            if ($usersCount === 0) {
                pf_add(
                    'Datenbank',
                    'users: Zeilen',
                    'WARN',
                    '0 Zeilen. Beim ersten Aufruf von login.php erscheint das Einrichtungsformular und legt einen neuen Administrator an.'
                );
            } else {
                pf_add(
                    'Datenbank',
                    'users: Zeilen',
                    'PASS',
                    "$usersCount Zeile(n). Anmeldung erfolgt mit einem bestehenden Konto - das Einrichtungsformular erscheint nicht."
                );
            }
        } catch (Throwable $e) {
            pf_add('Datenbank', 'users: Zeilen', 'FAIL', 'Konnte nicht gezaehlt werden: ' . pf_mask_secrets($e->getMessage()));
        }
    } else {
        pf_add('Datenbank', 'users: Zeilen', 'FAIL', 'Tabelle users fehlt.');
    }

    // -- Legacy-Login-Pfad und schema_version ------------------------------
    if (in_array('settings', $existingTables, true)) {
        try {
            $stmt = $pdo->prepare("SELECT 1 FROM settings WHERE k = 'admin_password_hash' LIMIT 1");
            $stmt->execute();
            if ($stmt->fetchColumn() !== false) {
                pf_add(
                    'Datenbank',
                    'settings.admin_password_hash',
                    'WARN',
                    'Vorhanden - das ist der alte Login-Pfad. Dieser Codestand verwendet ihn nicht mehr; stattdessen wird eine Zeile in der users-Tabelle benoetigt.'
                );
            } else {
                pf_add('Datenbank', 'settings.admin_password_hash', 'PASS', 'Nicht vorhanden - kein Alt-Login-Rest.');
            }
        } catch (Throwable $e) {
            pf_add('Datenbank', 'settings.admin_password_hash', 'FAIL', 'Konnte nicht geprueft werden: ' . pf_mask_secrets($e->getMessage()));
        }

        try {
            $stmt = $pdo->prepare("SELECT v FROM settings WHERE k = 'schema_version' LIMIT 1");
            $stmt->execute();
            $schemaVersion = $stmt->fetchColumn();
            if ($schemaVersion === false) {
                pf_add('Datenbank', 'settings.schema_version', 'WARN', 'Nicht gesetzt. Migrationen laufen beim ersten Seitenaufruf komplett von vorn durch.');
            } else {
                pf_add('Datenbank', 'settings.schema_version', 'PASS', "Aktueller Wert: $schemaVersion.");
            }
        } catch (Throwable $e) {
            pf_add('Datenbank', 'settings.schema_version', 'FAIL', 'Konnte nicht geprueft werden: ' . pf_mask_secrets($e->getMessage()));
        }
    } else {
        pf_add('Datenbank', 'settings.admin_password_hash', 'SKIP', 'Tabelle settings nicht vorhanden.');
        pf_add('Datenbank', 'settings.schema_version', 'SKIP', 'Tabelle settings nicht vorhanden.');
    }

    // -- logs.ip (von Migration 2 ergaenzte Spalte) ------------------------
    if (in_array('logs', $existingTables, true)) {
        try {
            $stmt = $pdo->prepare(
                'SELECT COUNT(*) FROM information_schema.columns '
                . 'WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?'
            );
            $stmt->execute(['logs', 'ip']);
            $hasIp = ((int) $stmt->fetchColumn()) > 0;
            pf_add(
                'Datenbank',
                'logs.ip-Spalte',
                $hasIp ? 'PASS' : 'WARN',
                $hasIp
                    ? 'Vorhanden.'
                    : 'Fehlt noch. Wird von includes/migrations.php (Migration 2) beim naechsten Seitenaufruf automatisch ergaenzt.'
            );
        } catch (Throwable $e) {
            pf_add('Datenbank', 'logs.ip-Spalte', 'FAIL', 'Konnte nicht geprueft werden: ' . pf_mask_secrets($e->getMessage()));
        }
    } else {
        pf_add('Datenbank', 'logs.ip-Spalte', 'SKIP', 'Tabelle logs nicht vorhanden.');
    }
}

// ─────────────────────────────────────────────────────────────────────
// Zusammenfassung
// ─────────────────────────────────────────────────────────────────────

$failCount = 0;
$warnCount = 0;
foreach ($results as $r) {
    if ($r['status'] === 'FAIL') {
        $failCount++;
    } elseif ($r['status'] === 'WARN') {
        $warnCount++;
    }
}
$ready = $failCount === 0;

$sectionOrder = ['Server', 'Dateisystem', 'Datenbank'];
$bySection    = [];
foreach ($results as $r) {
    $bySection[$r['section']][] = $r;
}

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Installations-Check</title>
<style>
  :root { color-scheme: light; }
  * { box-sizing: border-box; }
  body {
    font-family: -apple-system, "Segoe UI", Roboto, Arial, sans-serif;
    background: #f0f2f5;
    color: #1a1a1a;
    margin: 0;
    padding: 2rem 1rem 4rem;
    line-height: 1.45;
  }
  .wrap { max-width: 980px; margin: 0 auto; }
  h1 { font-size: 1.5rem; margin: 0 0 .25rem; }
  .subtitle { color: #555; margin: 0 0 1.5rem; font-size: .9rem; }
  .warning-banner {
    background: #fff3cd;
    border: 2px solid #d39e00;
    color: #664d03;
    padding: .9rem 1.1rem;
    border-radius: 8px;
    font-weight: 600;
    margin-bottom: 1.5rem;
    font-size: .95rem;
  }
  .warning-banner.bottom { margin-top: 2rem; margin-bottom: 0; }
  table {
    width: 100%;
    border-collapse: collapse;
    background: #fff;
    border-radius: 8px;
    overflow: hidden;
    box-shadow: 0 1px 4px rgba(0,0,0,.08);
    margin-bottom: 1.75rem;
  }
  caption {
    text-align: left;
    font-weight: 700;
    font-size: 1.05rem;
    padding: .8rem 1rem .55rem;
    background: #fff;
  }
  th, td { text-align: left; padding: .55rem .9rem; border-bottom: 1px solid #e5e7eb; vertical-align: top; font-size: .92rem; }
  th { background: #f8f9fb; font-size: .74rem; text-transform: uppercase; letter-spacing: .03em; color: #555; }
  tr:last-child td { border-bottom: none; }
  .status { display: inline-block; padding: .2rem .6rem; border-radius: 99px; font-weight: 700; font-size: .74rem; text-transform: uppercase; white-space: nowrap; }
  .status-PASS { background: #d1e7dd; color: #0a5132; }
  .status-WARN { background: #fff3cd; color: #7a5b00; }
  .status-FAIL { background: #f8d7da; color: #842029; }
  .status-SKIP, .status-UNKNOWN { background: #e2e3e5; color: #41464b; }
  .name { font-weight: 600; white-space: nowrap; }
  .summary { padding: 1.1rem 1.3rem; border-radius: 8px; font-weight: 700; font-size: 1.05rem; }
  .summary.ready { background: #d1e7dd; color: #0a5132; }
  .summary.not-ready { background: #f8d7da; color: #842029; }
  code { background: #eef0f3; padding: .1rem .35rem; border-radius: 4px; font-size: .85em; }
</style>
</head>
<body>
<div class="wrap">

  <div class="warning-banner">
    ⚠ Diese Datei liest Server- und Datenbankinterna aus. Nach Gebrauch unbedingt löschen: <code>install/preflight.php</code>.
  </div>

  <h1>Installations-Check</h1>
  <p class="subtitle">Geprüft am <?= pf_h(date('d.m.Y H:i:s')) ?> auf diesem Server.</p>

  <?php foreach ($sectionOrder as $sectionName): ?>
    <?php if (empty($bySection[$sectionName])) continue; ?>
    <table>
      <caption><?= pf_h($sectionName) ?></caption>
      <thead>
        <tr><th>Prüfung</th><th>Status</th><th>Hinweis</th></tr>
      </thead>
      <tbody>
        <?php foreach ($bySection[$sectionName] as $r): ?>
          <tr>
            <td class="name"><?= pf_h($r['name']) ?></td>
            <td><span class="status status-<?= pf_h($r['status']) ?>"><?= pf_h($r['status']) ?></span></td>
            <td><?= pf_h($r['message']) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endforeach; ?>

  <div class="summary <?= $ready ? 'ready' : 'not-ready' ?>">
    <?= $ready ? 'Bereit für die Einrichtung.' : 'Noch nicht bereit.' ?>
    — <?= $failCount ?> FAIL, <?= $warnCount ?> WARN.
  </div>

  <div class="warning-banner bottom">
    ⚠ Nach Abschluss der Einrichtung: <code>install/preflight.php</code> von diesem Server löschen. Die Datei löscht sich nicht von selbst — das ist Absicht, siehe Kommentar am Dateianfang.
  </div>

</div>
</body>
</html>
