<?php
/**
 * Schreibt die Demodaten als importierbare MySQL-Datei heraus.
 *
 * tools/seed_demo.php braucht eine Kommandozeile auf dem Server. Auf
 * einfachen Hosting-Paketen gibt es die nicht - dort steht nur
 * phpMyAdmin zur Verfuegung, und das importiert .sql-Dateien.
 *
 * Dieses Skript laesst den Seed hier gegen SQLite laufen (dieselbe
 * Spiegelung wie tools/test_seed_demo.php) und schreibt das Ergebnis als
 * INSERT-Anweisungen heraus. Alle Verweise sind darin bereits aufgeloest.
 *
 * ACHTUNG - die Zeitangaben frieren ein. Der Seed rechnet alle Daten
 * relativ zu "heute"; in der erzeugten Datei stehen feste Werte vom Tag
 * der Erzeugung. Nach einigen Monaten wirkt die Demo dadurch aelter, als
 * sie soll. Dann einfach neu erzeugen und erneut importieren.
 *
 * Aufruf:
 *   php tools/export_demo_sql.php ../demo_data.sql
 *   php tools/export_demo_sql.php ../demo_data.sql --uploads=../admin-dashboard-demo/uploads
 *
 * Die Zieldatei gehoert NICHT ins Webverzeichnis - eine .sql-Datei dort
 * waere abrufbar.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit("Nur ueber die Kommandozeile.\n");
}

$wurzel = dirname(__DIR__);
$ziel   = null;
$uploads_ziel = null;

foreach (array_slice($argv, 1) as $arg) {
    if (strpos($arg, '--uploads=') === 0) { $uploads_ziel = substr($arg, 10); continue; }
    if ($arg[0] !== '-') { $ziel = $arg; }
}

if ($ziel === null) {
    fwrite(STDERR,
        "Aufruf: php tools/export_demo_sql.php <zieldatei.sql> [--uploads=<verzeichnis>]\n\n"
      . "Die Zieldatei gehoert nicht ins Webverzeichnis.\n");
    exit(1);
}

// Als Konstanten, nicht als Variablen: seed_demo_data.php wird weiter
// unten in genau diesen Gueltigkeitsbereich eingebunden und benutzt
// selbst ein $ziel (fuer die Platzhalterdateien). Beim ersten Lauf
// schrieb der Export deshalb in uploads/client_assets/ statt in die
// angegebene Datei. Eine Konstante kann das Eingebundene nicht ueberschreiben.
define('EXPORT_ZIEL', $ziel);
define('EXPORT_UPLOADS', $uploads_ziel);

// ── Spiegel aufbauen ────────────────────────────────────────────────
require_once __DIR__ . '/lib_sqlite_mirror.php';

$pdo = new SqliteSpiegelPDO('sqlite::memory:', null, null,
                            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('PRAGMA foreign_keys = ON');

foreach (nach_sqlite(file_get_contents($wurzel . '/install/schema.sql')) as $anweisung) {
    $pdo->exec($anweisung);
}

// ── Seed ausfuehren ─────────────────────────────────────────────────
require_once $wurzel . '/includes/env.php';
env_load($wurzel . '/.env');                 // fuer DEMO_PORTAL_PIN, falls vorhanden
if (!defined('DEMO_MODE')) define('DEMO_MODE', true);
if (!defined('BASE_URL'))  define('BASE_URL', '');
require_once $wurzel . '/includes/demo.php';
require_once __DIR__ . '/seed_demo_lib.php';

// Merken, was vor dem Lauf in uploads/ lag - der Seed legt dort
// Platzhalterdateien an, und die gehoeren nicht ins Repository.
$upload_verzeichnisse = [
    $wurzel . '/uploads/client_assets',
    $wurzel . '/uploads/wiki',
    // Seit die Demo Belege zu Ausgaben führt, entstehen auch hier
    // Platzhalter. Fehlten sie im Ziel, liefe in der Demo jeder Klick
    // auf einen Beleg ins Leere.
    $wurzel . '/uploads/receipts',
];
$vorher = [];
foreach ($upload_verzeichnisse as $dir) {
    $vorher[$dir] = is_dir($dir) ? array_diff(scandir($dir), ['.', '..']) : null;
}

/**
 * Raeumt die Platzhalterdateien wieder weg und liefert, was sie waren.
 *
 * Als Abschlussfunktion registriert, nicht am Ende aufgerufen: bricht die
 * Gegenprobe weiter unten mit exit(1) ab, bleiben die Dateien sonst im
 * Arbeitsverzeichnis liegen - und tools/check.sh meldet zu Recht Dateien
 * in uploads/, die dort nicht hingehoeren. Genau das ist beim Entwickeln
 * dieses Skripts passiert.
 */
function platzhalter_aufraeumen(): array
{
    static $erledigt = false;
    global $upload_verzeichnisse, $vorher;

    $kopiert = 0;
    $entfernt = 0;
    if ($erledigt) return [0, 0];
    $erledigt = true;

    foreach ($upload_verzeichnisse as $dir) {
        if (!is_dir($dir)) continue;
        $jetzt = array_diff(scandir($dir), ['.', '..']);
        $neu   = $vorher[$dir] === null ? $jetzt : array_diff($jetzt, $vorher[$dir]);

        foreach ($neu as $datei) {
            $quelle = $dir . '/' . $datei;
            if (!is_file($quelle)) continue;

            if (EXPORT_UPLOADS !== null) {
                $ablage = rtrim(EXPORT_UPLOADS, '/\\') . '/' . basename($dir);
                if (!is_dir($ablage)) mkdir($ablage, 0755, true);
                copy($quelle, $ablage . '/' . $datei);
                $kopiert++;
            }
            unlink($quelle);
            $entfernt++;
        }
        if ($vorher[$dir] === null && is_dir($dir)
            && array_diff(scandir($dir), ['.', '..']) === []) {
            rmdir($dir);
        }
    }
    return [$kopiert, $entfernt];
}
register_shutdown_function('platzhalter_aufraeumen');

ob_start();
require __DIR__ . '/seed_demo_data.php';
$seed_ausgabe = ob_get_clean();

// ── Werte fuer MySQL formatieren ────────────────────────────────────

/** Ein Wert als MySQL-Literal. */
function sql_wert($wert): string
{
    if ($wert === null)   return 'NULL';
    if (is_int($wert))    return (string) $wert;
    if (is_float($wert))  return rtrim(rtrim(sprintf('%.4F', $wert), '0'), '.');

    $text = (string) $wert;
    // Rein numerische Werte bleiben trotzdem in Anfuehrungszeichen: MySQL
    // wandelt sie beim Einfuegen selbst um, und eine Postleitzahl mit
    // fuehrender Null darf sie nicht verlieren.
    // Das doppelte Anfuehrungszeichen braucht innerhalb von '...' keine
    // Maskierung - sie waere nur zusaetzlicher Ballast in der Datei.
    $ersetzt = strtr($text, [
        "\\"   => "\\\\",
        "'"    => "\\'",
        "\n"   => "\\n",
        "\r"   => "\\r",
        "\x00" => "\\0",
        "\x1a" => "\\Z",
    ]);
    return "'" . $ersetzt . "'";
}

/** Spaltennamen einer Tabelle, in Definitionsreihenfolge. */
function spalten(PDO $pdo, string $tabelle): array
{
    $aus = [];
    foreach ($pdo->query('PRAGMA table_info(' . $tabelle . ')') as $r) {
        $aus[] = $r['name'];
    }
    return $aus;
}

// ── Herausschreiben ─────────────────────────────────────────────────
// Reihenfolge: Eltern vor Kindern. Die Fremdschluesselpruefung ist
// waehrend des Imports zwar aus, aber eine sinnvolle Reihenfolge macht
// die Datei lesbar und erlaubt einen Import auch ohne das SET.
$reihenfolge = [
    'settings', 'users', 'totp_backup_codes', 'contacts', 'leads_inbox',
    'tasks', 'task_milestones', 'task_contacts', 'milestone_comments',
    'project_comments', 'client_assets', 'time_entries', 'finances', 'quotes',
    'support_tickets', 'ticket_notes', 'wiki_articles', 'wiki_attachments',
    'wiki_client_shares', 'calendar_events', 'event_contacts',
    'monitored_urls', 'url_checks', 'logs', 'mail_log',
];

// Die Liste oben wird von Hand gepflegt, und das ist die Schwachstelle:
// eine vergessene Tabelle fehlt einfach in der Ausgabedatei - ohne
// Fehler, ohne Warnung. Auffallen würde es erst in der Demo, an einer
// leeren Seite. Also lieber hier abbrechen.
$vergessen = [];
foreach ($pdo->query("SELECT name FROM sqlite_master WHERE type = 'table'")
              ->fetchAll(PDO::FETCH_COLUMN) as $t) {
    if (strpos($t, 'sqlite_') === 0 || in_array($t, $reihenfolge, true)) {
        continue;
    }
    if ((int) $pdo->query('SELECT COUNT(*) FROM ' . $t)->fetchColumn() > 0) {
        $vergessen[] = $t;
    }
}
if ($vergessen !== []) {
    platzhalter_aufraeumen();
    fwrite(STDERR,
        "Der Seed füllt Tabellen, die der Export nicht kennt: "
        . implode(', ', $vergessen) . "\n"
        . "Sie gehören in \$reihenfolge in " . basename(__FILE__)
        . " - sonst fehlen sie lautlos in der erzeugten Datei.\n");
    exit(1);
}

$out = fopen(EXPORT_ZIEL, 'w');
if (!$out) { fwrite(STDERR, "Zieldatei nicht beschreibbar: " . EXPORT_ZIEL . "\n"); exit(1); }

$kopf = "-- ---------------------------------------------------------------------\n"
      . "-- Demodaten fuer das Admin-Dashboard\n"
      . "-- Erzeugt am " . date('d.m.Y H:i') . "\n"
      . "--\n"
      . "-- Import NUR in die Demo-Datenbank, und erst NACH install/schema.sql.\n"
      . "-- Die Datei leert die Tabellen, bevor sie sie fuellt.\n"
      . "--\n"
      . "-- Alle Namen, Firmen und Adressen sind erfunden; die Domains liegen\n"
      . "-- im laut RFC 2606 reservierten Namensraum .example.\n"
      . "--\n"
      . "-- Die Zeitangaben sind beim Erzeugen eingefroren. Wirkt die Demo in\n"
      . "-- einigen Monaten veraltet, mit tools/export_demo_sql.php neu\n"
      . "-- erzeugen und erneut importieren.\n"
      . "-- ---------------------------------------------------------------------\n\n"
      . "SET NAMES utf8mb4;\n"
      . "SET FOREIGN_KEY_CHECKS = 0;\n\n";
fwrite($out, $kopf);

// Leeren. settings behaelt schema_version - ohne die Zeile liefe
// run_migrations() beim naechsten Seitenaufruf von vorne los.
fwrite($out, "-- Bestand entfernen\n");
foreach (array_reverse($reihenfolge) as $t) {
    fwrite($out, $t === 'settings'
        ? "DELETE FROM `settings` WHERE `k` <> 'schema_version';\n"
        : "DELETE FROM `$t`;\n");
}
fwrite($out, "\n");

$gesamt = 0;
foreach ($reihenfolge as $tabelle) {
    $spalten = spalten($pdo, $tabelle);
    $zeilen  = $pdo->query('SELECT * FROM ' . $tabelle)->fetchAll(PDO::FETCH_ASSOC);
    if ($zeilen === []) continue;

    // settings: schema_version nicht mitschreiben, die steht schon.
    if ($tabelle === 'settings') {
        $zeilen = array_values(array_filter($zeilen, fn($r) => $r['k'] !== 'schema_version'));
        if ($zeilen === []) continue;
    }

    fwrite($out, '-- ' . $tabelle . ' (' . count($zeilen) . ")\n");
    $spaltenliste = '`' . implode('`, `', $spalten) . '`';

    // In Bloecken zu 50: eine einzelne Riesenanweisung laeuft je nach
    // Servereinstellung gegen max_allowed_packet.
    foreach (array_chunk($zeilen, 50) as $block) {
        $werte = [];
        foreach ($block as $zeile) {
            $einzeln = [];
            foreach ($spalten as $s) $einzeln[] = sql_wert($zeile[$s] ?? null);
            $werte[] = '(' . implode(', ', $einzeln) . ')';
        }
        fwrite($out, "INSERT INTO `$tabelle` ($spaltenliste) VALUES\n"
                   . implode(",\n", $werte) . ";\n");
    }
    fwrite($out, "\n");
    $gesamt += count($zeilen);
}

fwrite($out, "SET FOREIGN_KEY_CHECKS = 1;\n");
fclose($out);

// ── Gegenprobe: die Datei wieder einlesen ───────────────────────────
// Eine erzeugte .sql ist nur so viel wert wie ihr Import. Sie wird hier
// gegen einen frischen Spiegel ausgefuehrt und mit dem Original
// verglichen - das faengt falsch maskierte Anfuehrungszeichen, verlorene
// Umlaute und abgeschnittene Zeilenumbrueche, also genau die Fehler, die
// man einer .sql-Datei nicht ansieht.
$probe = new SqliteSpiegelPDO('sqlite::memory:', null, null,
                              [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
foreach (nach_sqlite(file_get_contents($wurzel . '/install/schema.sql')) as $anweisung) {
    $probe->exec($anweisung);
}

$puffer = '';
$nr = 0;
foreach (preg_split('/\R/', file_get_contents(EXPORT_ZIEL)) as $zeile) {
    $nr++;
    $roh = trim($zeile);
    if ($roh === '' || strpos($roh, '--') === 0) continue;
    // SET NAMES kennt SQLite nicht; SET FOREIGN_KEY_CHECKS faengt der Aufsatz.
    if (stripos($roh, 'SET NAMES') === 0) continue;

    $puffer .= $zeile . "\n";
    // Ein Zeilenumbruch innerhalb eines Wertes kann nicht vorkommen - der
    // steht in der Datei als \n. Eine Zeile, die auf ; endet, ist deshalb
    // immer ein Anweisungsende.
    if (substr($roh, -1) !== ';') continue;

    // MySQL kennt den Backslash als Fluchtzeichen in Zeichenketten,
    // SQLite nicht - dort steht danach wortwoertlich \n statt eines
    // Umbruchs. Fuer die Gegenprobe werden die Fluchtzeichen deshalb in
    // die SQLite-Schreibweise uebersetzt. Ausserhalb von Zeichenketten
    // kommt in dieser Datei kein Backslash vor, ein globaler Durchlauf
    // ist also unbedenklich.
    $fuer_sqlite = preg_replace_callback('/\\\\(.)/s', static function (array $m): string {
        switch ($m[1]) {
            case 'n':  return "\n";
            case 'r':  return "\r";
            case '0':  return "\0";
            case 'Z':  return "\x1a";
            case "'":  return "''";     // SQLite verdoppelt statt zu maskieren
            case '\\': return '\\';
            default:   return $m[1];
        }
    }, $puffer);

    try {
        $probe->exec(rtrim(trim($fuer_sqlite), ';'));
    } catch (PDOException $e) {
        fwrite(STDERR, "FEHLER beim Zurueckleseng (Zeile ~$nr): " . $e->getMessage() . "\n"
                     . '  ' . substr(preg_replace('/\s+/', ' ', $puffer), 0, 140) . "\n");
        exit(1);
    }
    $puffer = '';
}

// Vollstaendig vergleichen, nicht stichprobenartig. Der erste Anlauf
// pruefte vier ausgewaehlte Felder - und uebersah einen fehlenden
// Backslash, weil der in einem anderen Datensatz stand als die Stichprobe.
// Bei 450 Zeilen kostet der vollstaendige Vergleich nichts.
$abweichungen = [];
$verglichen = 0;
foreach ($reihenfolge as $tabelle) {
    $a = $pdo->query("SELECT * FROM $tabelle")->fetchAll(PDO::FETCH_ASSOC);
    $b = $probe->query("SELECT * FROM $tabelle")->fetchAll(PDO::FETCH_ASSOC);

    if (count($a) !== count($b)) {
        $abweichungen[] = "$tabelle: " . count($a) . ' erzeugt, ' . count($b) . ' zurueckgelesen';
        continue;
    }

    foreach ($a as $i => $zeile) {
        $verglichen++;
        foreach ($zeile as $spalte => $wert) {
            $zurueck = $b[$i][$spalte] ?? null;
            // SQLite gibt Zahlen je nach Weg als int oder string zurueck;
            // verglichen wird der Textwert.
            if ((string) $wert === (string) $zurueck) continue;

            $abweichungen[] = "$tabelle.$spalte (Zeile " . ($i + 1) . ")\n"
                            . '      erzeugt:        ' . substr(var_export($wert, true), 0, 70) . "\n"
                            . '      zurueckgelesen: ' . substr(var_export($zurueck, true), 0, 70);
            if (count($abweichungen) > 8) break 3;
        }
    }
}

if ($abweichungen !== []) {
    fwrite(STDERR, "FEHLER: die erzeugte Datei liest sich nicht identisch zurueck.\n");
    foreach ($abweichungen as $x) fwrite(STDERR, "  - $x\n");
    exit(1);
}

// ── Platzhalterdateien ──────────────────────────────────────────────
// Der eigentliche Lauf; die Abschlussfunktion oben faengt nur die
// Abbruchfaelle ab.
[$kopiert, $entfernt] = platzhalter_aufraeumen();

// ── Bericht ─────────────────────────────────────────────────────────
echo "Geschrieben: " . EXPORT_ZIEL . "\n";
printf("  %d Zeilen in %d Tabellen, %s\n", $gesamt,
       count(array_filter($reihenfolge, fn($t) => (int) $pdo->query("SELECT COUNT(*) FROM $t")->fetchColumn() > 0)),
       number_format(filesize(EXPORT_ZIEL) / 1024, 0, ',', '.') . ' KB');

if (EXPORT_UPLOADS !== null) {
    echo "  $kopiert Platzhalterdatei(en) nach " . EXPORT_UPLOADS . " kopiert\n";
} elseif ($entfernt > 0) {
    echo "  Hinweis: $entfernt Platzhalterdatei(en) wurden erzeugt und wieder entfernt.\n";
    echo "  Fuer die Portal-Downloads --uploads=<verzeichnis> angeben.\n";
}

// Die Portal-Links stehen in der Seed-Ausgabe.
$zeilen_aus = preg_split('/\R/', trim($seed_ausgabe));
$ab = false;
echo "\n";
foreach ($zeilen_aus as $z) {
    if (strpos($z, 'Portal-Zug') !== false) $ab = true;
    if ($ab && trim($z) !== '') echo $z . "\n";
}
