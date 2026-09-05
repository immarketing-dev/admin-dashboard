<?php
/**
 * Test fuer die Datensicherung.
 * Aufruf: php tools/test_backup.php
 *
 * Eine Sicherung wird an genau einem Tag gebraucht, und an dem muss sie
 * stimmen. Geprueft wird deshalb nicht nur, dass eine Datei entsteht,
 * sondern dass ihr Inhalt sich zurueckspielen laesst: der Abzug wird in
 * eine zweite Datenbank importiert und Zeile fuer Zeile verglichen.
 *
 * Die Struktur (SHOW CREATE TABLE) gibt es hier nicht - SQLite kennt das
 * nicht. Genau dafuer ist der Rueckfall gebaut, und der Test deckt ihn
 * mit ab: die Datei muss dann im Kopf sagen, dass install/schema.sql
 * zuerst einzuspielen ist.
 */

require_once __DIR__ . '/lib_sqlite_mirror.php';
require_once __DIR__ . '/../includes/backup.php';

$wurzel = dirname(__DIR__);
$checks = [];

// Ein eigenes Spielfeld ausserhalb des Projekts - tools/check.sh meldet
// zu Recht jede Datei, die im Arbeitsverzeichnis liegenbleibt.
// Normalisiert, weil sicherung_verzeichnis() das ebenfalls tut -
// unter Windows kaeme sonst ein Vergleich mit Backslashes heraus.
$spiel = rtrim(str_replace('\\', '/', sys_get_temp_dir()), '/') . '/backup_test_' . getmypid();
@mkdir($spiel, 0777, true);

// sicherung_verzeichnis() faellt auf ein Verzeichnis neben dem
// Projekt zurueck, wenn der angegebene Pfad nicht taugt. Der Test
// loest das absichtlich aus - und raeumt es hinterher weg, sofern
// er es selbst angelegt hat. Ein Testlauf hat im Dateisystem des
// Entwicklers nichts zu hinterlassen.
$rueckfall     = dirname(str_replace('\\', '/', $wurzel)) . '/backups';
$rueckfall_gab = is_dir($rueckfall);

function aufraeumen(string $ordner): void
{
    foreach (glob($ordner . '/*') ?: [] as $d) {
        if (is_dir($d)) { aufraeumen($d); @rmdir($d); } else { @unlink($d); }
    }
    foreach (glob($ordner . '/.*') ?: [] as $d) {
        if (is_file($d)) @unlink($d);
    }
}

// =====================================================================
// Werte als SQL
// =====================================================================
$checks['NULL bleibt NULL']       = sicherung_wert(null) === 'NULL';
$checks['Zahl ohne Quotes']       = sicherung_wert(42) === '42';
$checks['Komma statt Punkt nein'] = sicherung_wert(12.5) === '12.5';
$checks['Text in Quotes']         = sicherung_wert('abc') === "'abc'";
$checks['Apostroph maskiert']     = sicherung_wert("O'Neill") === "'O\\'Neill'";
$checks['Backslash maskiert']     = sicherung_wert('a\\b') === "'a\\\\b'";
$checks['Umbruch maskiert']       = sicherung_wert("a\nb") === "'a\\nb'";
$checks['Nullbyte maskiert']      = sicherung_wert("a\x00b") === "'a\\0b'";
// Eine Postleitzahl darf ihre fuehrende Null nicht verlieren.
$checks['fuehrende Null bleibt']  = sicherung_wert('04109') === "'04109'";

// =====================================================================
// Tabellen und Dateiname
// =====================================================================
$tabellen = sicherung_tabellen($wurzel . '/install/schema.sql');
$checks['Tabellen aus dem Schema'] = count($tabellen) >= 27;
$checks['settings ist dabei']      = in_array('settings', $tabellen, true);
$checks['nichts Erfundenes']       = !in_array('create', $tabellen, true);
$checks['fehlendes Schema: leer']  = sicherung_tabellen($spiel . '/gibtesnicht.sql') === [];

$name = sicherung_dateiname('2026-09-05 14:30:00');
$checks['Dateiname mit Zeit']      = $name === 'sicherung_2026-09-05_143000.sql';
$checks['Dateiname sortiert sich'] = strcmp(sicherung_dateiname('2026-09-06 01:00:00'), $name) > 0;

// =====================================================================
// Das Verzeichnis
// =====================================================================
// Ein angegebener Pfad ausserhalb des Webstamms wird genommen, und dort
// braucht es keine Sperre.
$aussen = $spiel . '/aussen';
[$dir, $ist_aussen] = sicherung_verzeichnis($wurzel, $aussen);
$checks['angegebener Pfad wird genommen'] = $dir === $aussen;
$checks['ausserhalb erkannt']             = $ist_aussen === true;
$checks['dort keine Sperre noetig']       = !is_file($aussen . '/.htaccess');

// Ein Pfad INNERHALB des Projekts bekommt die Sperre, auch wenn er von
// Hand eingetragen wurde - die Eingabe sagt nichts darueber, ob der
// Ordner ueber den Webserver erreichbar ist.
$innen = $wurzel . '/uploads/backups_test';
[$dir2, $ist_aussen2] = sicherung_verzeichnis($wurzel, $innen);
$checks['Pfad im Webstamm erkannt'] = $ist_aussen2 === false;
$checks['Sperre wird angelegt']     = is_file($innen . '/.htaccess');
$checks['Sperre sperrt wirklich']   = strpos((string) @file_get_contents($innen . '/.htaccess'), 'Require all denied') !== false;
aufraeumen($innen);
@rmdir($innen);

// =====================================================================
// Schreiben und zurueckspielen
// =====================================================================
$pdo = new SqliteSpiegelPDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('PRAGMA foreign_keys = ON');
foreach (nach_sqlite(file_get_contents($wurzel . '/install/schema.sql')) as $a) {
    $pdo->exec($a);
}

// Zwei Sorten Daten, aus einem Grund getrennt:
//
// Der Abzug ist fuer MySQL bestimmt und maskiert dessen Weise - ein
// Apostroph als \', ein Umbruch als \n. SQLite kennt den Backslash in
// Zeichenketten gar nicht; dort waere 'O\'Neill' ein Syntaxfehler.
// Ein Rundlauf durch SQLite kann solche Werte also nicht tragen, und
// ihn trotzdem zu erzwingen hiesse, eine Umschreibung zu testen statt
// des Abzugs.
//
// Also: der Rundlauf laeuft mit Werten, die in beiden Welten gleich
// aussehen (Umlaute, fuehrende Null, gewoehnlicher Text). Die
// heiklen Zeichen werden dort geprueft, wo die Antwort eindeutig ist -
// am erzeugten Text.
$ins = $pdo->prepare("INSERT INTO contacts (name, company, notes, zip) VALUES (?,?,?,?)");
$ins->execute(['Sonja Weiß', 'Weiß Naturkosmetik', 'Größe: 20 Zeichen', '04109']);
$ins->execute(['Marco Brandt', 'Brandt GmbH', 'Ganz gewöhnlicher Text', '10623']);
$pdo->exec("INSERT INTO settings (k,v) VALUES ('company_name','Musterwerk')");

$datei = $spiel . '/' . sicherung_dateiname('now');
$erg   = sicherung_schreiben($pdo, $datei, $tabellen);

$checks['Datei entsteht']        = is_file($datei);
// Zwei Kontakte plus zwei Zeilen in settings: company_name von oben
// und schema_version, die install/schema.sql selbst anlegt.
$checks['Zeilen gezaehlt']       = $erg['zeilen'] === 4;
$checks['Groesse gemeldet']      = $erg['bytes'] > 100;
$checks['ohne SHOW CREATE TABLE'] = $erg['struktur'] === false;

$inhalt = (string) file_get_contents($datei);
$checks['Kopf nennt schema.sql'] = strpos($inhalt, 'install/schema.sql') !== false;
$checks['Kopf nennt uploads/']   = strpos($inhalt, 'uploads/') !== false;
$checks['Fremdschluessel aus']   = strpos($inhalt, 'SET FOREIGN_KEY_CHECKS = 0;') !== false;
$checks['und wieder an']         = strpos($inhalt, 'SET FOREIGN_KEY_CHECKS = 1;') !== false;
$checks['leere Tabelle ohne INSERT'] = strpos($inhalt, 'INSERT INTO `logs`') === false;
// Ohne Struktur muss vor den Daten ein DELETE stehen, sonst kollidiert
// der Abzug mit dem, was install/schema.sql selbst anlegt.
$checks['Datenabzug leert vorher']   = strpos($inhalt, 'DELETE FROM `settings`;') !== false;

// --- Der Rundlauf: einspielen und vergleichen ----------------------
$zurueck = new SqliteSpiegelPDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
foreach (nach_sqlite(file_get_contents($wurzel . '/install/schema.sql')) as $a2) {
    $zurueck->exec($a2);
}
$fehler_import = '';
foreach (nach_sqlite($inhalt) as $anweisung) {
    if (trim($anweisung) === '') continue;
    try {
        $zurueck->exec($anweisung);
    } catch (Throwable $ex) {
        $fehler_import = $ex->getMessage();
        break;
    }
}
$checks['Abzug laesst sich einspielen'] = $fehler_import === '';
if ($fehler_import !== '') {
    echo "  Importfehler: $fehler_import\n";
}

$vorher  = $pdo->query('SELECT name, company, notes, zip FROM contacts ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
$nachher = $zurueck->query('SELECT name, company, notes, zip FROM contacts ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
$checks['gleich viele Zeilen zurueck'] = count($vorher) === count($nachher);
$checks['Werte identisch']             = $vorher === $nachher;
$checks['Umlaut ueberlebt']            = ($nachher[0]['name'] ?? '') === 'Sonja Weiß';
$checks['fuehrende Null ueberlebt']    = ($nachher[0]['zip'] ?? '') === '04109';
// settings kommt vollstaendig zurueck, schema_version eingeschlossen.
$checks['Einstellungen zurueck']       = (int) $zurueck->query("SELECT COUNT(*) FROM settings")->fetchColumn() === 2;

// --- Die heiklen Zeichen, am Text geprueft -------------------------
$heikel = new SqliteSpiegelPDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
foreach (nach_sqlite(file_get_contents($wurzel . '/install/schema.sql')) as $a3) {
    $heikel->exec($a3);
}
$heikel->prepare("INSERT INTO contacts (name, company, notes) VALUES (?,?,?)")
       ->execute(["O'Neill", 'Pfad C:\\Temp', "Zeile eins\nZeile zwei"]);

$datei2 = $spiel . '/heikel.sql';
sicherung_schreiben($heikel, $datei2, ['contacts']);
$text = (string) file_get_contents($datei2);

$checks['Apostroph im Abzug maskiert'] = strpos($text, "'O\\'Neill'") !== false;
$checks['Backslash im Abzug maskiert'] = strpos($text, "'Pfad C:\\\\Temp'") !== false;
$checks['Umbruch im Abzug maskiert']   = strpos($text, "'Zeile eins\\nZeile zwei'") !== false;
// Und kein echter Umbruch mitten in einer Zeichenkette - der wuerde die
// Anweisung beim Einspielen zerreissen.
$checks['kein roher Umbruch im Wert']  = strpos($text, "'Zeile eins\nZeile zwei'") === false;

// =====================================================================
// Auflisten und Aufraeumen
// =====================================================================
foreach (['2026-01-01_120000', '2026-02-01_120000', '2026-03-01_120000', '2026-04-01_120000'] as $t) {
    file_put_contents($spiel . '/' . SICHERUNG_PRAEFIX . $t . '.sql', '-- x');
}
// Eine fremde Datei darf weder gezaehlt noch entfernt werden.
file_put_contents($spiel . '/nicht_meine.sql', '-- fremd');

$liste = sicherungen_auflisten($spiel);
$checks['nur eigene Staende gezaehlt'] = count($liste) === 5;
$checks['neueste zuerst']              = strpos($liste[0]['name'], '2026-04-01') !== false
                                      || strpos($liste[0]['name'], date('Y-m-d')) !== false;

$weg = sicherungen_aufraeumen($spiel, 2);
$checks['aeltere entfernt']            = $weg === 3;
$checks['gewuenschte Zahl bleibt']     = count(sicherungen_auflisten($spiel)) === 2;
$checks['fremde Datei bleibt']         = is_file($spiel . '/nicht_meine.sql');
$checks['behalten mindestens eins']    = sicherungen_aufraeumen($spiel, 0) === 1;

// =====================================================================
// Der vollstaendige Lauf
// =====================================================================
$lauf = sicherung_laufen($pdo, $wurzel, $spiel . '/lauf', 3);
$checks['Lauf meldet Erfolg']   = $lauf['ok'] === true;
$checks['Lauf nennt die Datei'] = is_file($lauf['datei']);
$checks['Lauf warnt vor Struktur'] = strpos($lauf['meldung'], 'schema.sql') !== false;

// Ein Pfad, unter dem kein Verzeichnis entstehen kann - hier eine
// vorhandene Datei -, darf nicht als Ziel durchgehen. Der Rueckfall
// auf die eigenen Kandidaten ist gewollt: lieber woanders sichern als
// gar nicht.
[$dir3, ] = sicherung_verzeichnis($wurzel, $spiel . '/nicht_meine.sql');
$checks['Datei wird nicht Zielordner'] = $dir3 !== $spiel . '/nicht_meine.sql';

// =====================================================================
// Ergebnis
// =====================================================================
aufraeumen($spiel);
@rmdir($spiel);
if (!$rueckfall_gab && is_dir($rueckfall)) {
    aufraeumen($rueckfall);
    @rmdir($rueckfall);
}

$fehler = 0;
foreach ($checks as $name => $ok) {
    if (!$ok) {
        echo "FEHLER: $name\n";
        $fehler++;
    }
}

if ($fehler === 0) {
    echo 'OK: ' . count($checks) . " Pruefungen bestanden.\n";
    exit(0);
}
echo "\nFEHLGESCHLAGEN: $fehler von " . count($checks) . " Pruefungen.\n";
exit(1);
