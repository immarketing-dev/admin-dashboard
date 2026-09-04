<?php
/**
 * Rendert die zweisprachigen Bausteine und vergleicht das Ergebnis.
 *
 * tools/check_i18n.php prueft die Tabelle. Dieser Test prueft die
 * Verdrahtung: kommt die Uebersetzung tatsaechlich in der Ausgabe an, und
 * bleibt sie stehen, wenn die Sprache umgeschaltet wird? Eine korrekte
 * Tabelle nuetzt nichts, wenn t() im Markup an der falschen Stelle steht.
 *
 * Aufruf: php tools/test_i18n.php
 */

$wurzel = dirname(__DIR__);
require_once $wurzel . '/includes/i18n.php';

$fehler = [];
$n = 0;

function pruefe(string $was, bool $ok, string $detail = ''): void
{
    global $fehler, $n;
    $n++;
    if (!$ok) $fehler[] = $was . ($detail !== '' ? " ($detail)" : '');
}

/** Rendert eine Vorlage mit den noetigen Platzhaltern. */
function rendere(string $datei, array $umgebung = []): string
{
    extract($umgebung);
    ob_start();
    include $datei;
    return ob_get_clean();
}

// Die Seitenleiste ermittelt ihre Abzeichen selbst per Abfrage. Statt das
// wegzustubben bekommt sie eine echte Datenbank - dieselbe SQLite-
// Spiegelung, mit der auch der Seed geprueft wird. Damit laeuft die Datei
// so, wie sie im Betrieb laeuft.
require_once __DIR__ . '/lib_sqlite_mirror.php';

$pdo = new SqliteSpiegelPDO('sqlite::memory:', null, null,
                            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
foreach (nach_sqlite(file_get_contents($wurzel . '/install/schema.sql')) as $anweisung) {
    $pdo->exec($anweisung);
}
// Ein paar Zeilen, damit die Abzeichen erscheinen und der Platzhaltertext
// im title-Attribut wirklich gefuellt wird.
$pdo->exec("INSERT INTO leads_inbox (name) VALUES ('A'), ('B'), ('C')");
$pdo->exec("INSERT INTO support_tickets (subject, status) VALUES ('X', 'Offen')");

if (!function_exists('setting')) {
    function setting(string $k, string $standard = ''): string { return $standard; }
}
foreach (['COMPANY_SHORT' => 'Testfirma', 'APP_NAME' => 'Admin Panel',
          'COMPANY_NAME' => 'Testfirma GmbH'] as $name => $wert) {
    if (!defined($name)) define($name, $wert);
}

$umgebung = ['pdo' => $pdo, 'current_page' => 'index.php'];

// ─────────────────────────────────────────────────────────────────────
echo "=== Pruefung 1: Deutsch ist der Standard ===\n";
pruefe('Standardsprache ist Deutsch', lang() === 'de', 'ergab ' . lang());

$de_sidebar = rendere($wurzel . '/includes/sidebar.php', $umgebung);
foreach (['Übersicht', 'Kalender', 'Papierkorb', 'Einstellungen'] as $wort) {
    pruefe("Seitenleiste enthaelt '$wort'", strpos($de_sidebar, $wort) !== false);
}
echo "OK: die deutsche Fassung erscheint unveraendert.\n\n";

// ─────────────────────────────────────────────────────────────────────
echo "=== Pruefung 2: Englisch schlaegt durch ===\n";
sprache_setzen('en');
pruefe('Sprache umgeschaltet', lang() === 'en', 'ergab ' . lang());

$en_sidebar = rendere($wurzel . '/includes/sidebar.php', $umgebung);
$erwartet = ['Overview', 'Calendar', 'Trash', 'Settings', 'Projects &amp; tasks',
             'Support tickets', 'Log out'];
foreach ($erwartet as $wort) {
    pruefe("Seitenleiste enthaelt '$wort'", strpos($en_sidebar, $wort) !== false,
           'nicht gefunden');
}
// Und die deutschen Fassungen sind verschwunden.
foreach (['>Übersicht<', '>Papierkorb<', '>Einstellungen<'] as $wort) {
    pruefe("Seitenleiste ohne '$wort'", strpos($en_sidebar, $wort) === false,
           'deutscher Text steht noch da');
}
echo "OK: " . count($erwartet) . " Bezeichnungen auf Englisch, deutsche Fassung ersetzt.\n\n";

// ─────────────────────────────────────────────────────────────────────
echo "=== Pruefung 3: das kaufmaennische Und bleibt gueltiges HTML ===\n";
// te() maskiert - stuende dort ein rohes &, waere das Markup ungueltig.
pruefe('Projects &amp; tasks ist maskiert',
       strpos($en_sidebar, 'Projects &amp; tasks') !== false);
pruefe('Kein rohes & im Navigationstext',
       !preg_match('/<span class="nav-text">[^<]*&(?!amp;|nbsp;|#)/', $en_sidebar));
echo "OK.\n\n";

// ─────────────────────────────────────────────────────────────────────
echo "=== Pruefung 4: Zahlen und Datumsangaben folgen der Sprache ===\n";
$faelle = [
    ['de', 'fmt_datum',  ['2026-09-04'],               '04.09.2026'],
    ['en', 'fmt_datum',  ['2026-09-04'],               'Sep 4, 2026'],
    ['de', 'fmt_datum',  ['2026-09-04 14:30', 'datum_zeit'], '04.09.2026 14:30'],
    ['en', 'fmt_datum',  ['2026-09-04 14:30', 'datum_zeit'], 'Sep 4, 2026 2:30 PM'],
    ['de', 'fmt_betrag', [1234.5],                     '1.234,50 €'],
    ['en', 'fmt_betrag', [1234.5],                     '€1,234.50'],
    ['de', 'fmt_zahl',   [23550],                      '23.550'],
    ['en', 'fmt_zahl',   [23550],                      '23,550'],
];
foreach ($faelle as [$sprache, $fn, $args, $erwartetes]) {
    sprache_setzen($sprache);
    $ist = $fn(...$args);
    pruefe("$fn($sprache)", $ist === $erwartetes, "ergab '$ist', erwartet '$erwartetes'");
}
echo 'OK: ' . count($faelle) . " Formatierungen stimmen.\n\n";

// ─────────────────────────────────────────────────────────────────────
echo "=== Pruefung 5: leere Datumsangaben bleiben leer ===\n";
// Ein NULL-Datum aus der Datenbank darf nicht als 01.01.1970 erscheinen.
foreach ([null, '', '0000-00-00'] as $leer) {
    pruefe('Leeres Datum ergibt leere Zeichenkette',
           fmt_datum($leer) === '', var_export($leer, true) . ' -> ' . fmt_datum($leer));
}
echo "OK.\n\n";

// ─────────────────────────────────────────────────────────────────────
echo "=== Pruefung 6: Datenbankwerte werden nur angezeigt, nie ersetzt ===\n";
sprache_setzen('en');
pruefe("datenwert('Offen') ist uebersetzt",  datenwert('Offen') === 'Open');
pruefe("datenwert('Bezahlt') ist uebersetzt", datenwert('Bezahlt') === 'Paid');
// Unbekanntes kommt unveraendert zurueck, statt zu verschwinden.
pruefe('Unbekannter Wert bleibt erhalten',
       datenwert('Wartet auf Rückmeldung') === 'Wartet auf Rückmeldung');
sprache_setzen('de');
pruefe('Auf Deutsch bleibt der Wert unveraendert', datenwert('Offen') === 'Offen');
echo "OK: Anzeige uebersetzt, Wert unangetastet.\n\n";

// ─────────────────────────────────────────────────────────────────────
echo "=== Pruefung 7: unbekannte Sprache wird abgewiesen ===\n";
sprache_setzen('de');
sprache_setzen('fr');   // gibt es nicht
pruefe('Unbekannte Sprache aendert nichts', lang() === 'de', 'ergab ' . lang());
echo "OK.\n\n";

echo "=== Zusammenfassung ===\n";
if ($fehler === []) {
    echo "OK: $n Pruefungen bestanden.\n";
    exit(0);
}
echo 'FEHLGESCHLAGEN: ' . count($fehler) . " von $n Pruefungen.\n";
foreach ($fehler as $f) echo '  - ' . $f . "\n";
exit(1);
