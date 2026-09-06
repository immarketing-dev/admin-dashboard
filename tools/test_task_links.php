<?php
/**
 * Test fuer die Verknuepfungen zwischen Projekten.
 * Aufruf: php tools/test_task_links.php
 *
 * Laeuft gegen den SQLite-Spiegel von install/schema.sql, also gegen die
 * echten Tabellen mit ihren Fremdschluesseln.
 */
require_once __DIR__ . '/lib_sqlite_mirror.php';
// lang() in i18n.php liest die eingestellte Sprache ueber setting();
// ohne config.php gibt es die Funktion nicht.
if (!function_exists('setting')) {
    function setting(string $key, string $default = ''): string { return $default; }
}
require_once __DIR__ . '/../includes/demo.php';
require_once __DIR__ . '/../includes/i18n.php';
require_once __DIR__ . '/../includes/task_links.php';

$wurzel = dirname(__DIR__);
$pdo = new SqliteSpiegelPDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('PRAGMA foreign_keys = ON');
foreach (nach_sqlite(file_get_contents($wurzel . '/install/schema.sql')) as $anweisung) {
    $pdo->exec($anweisung);
}

// log_event() schreibt in logs; die Tabelle gibt es im Spiegel. Ohne
// Sitzung ist user_id NULL - das ist erlaubt.
$pdo->exec("INSERT INTO contacts (name, contact_type) VALUES ('Lena', 'Kunde')");
$lena = (int) $pdo->lastInsertId();
$pins = $pdo->prepare("INSERT INTO tasks (title, status, contact_id, start_date) VALUES (?, 'Offen', ?, ?)");
$p = [];
foreach ([['Relaunch', '2025-03-01'], ['Wartung', '2026-01-10'], ['Broschuere', '2026-02-01'], ['Alt', '2024-01-01']] as [$t, $d]) {
    $pins->execute([$t, $lena, $d]);
    $p[$t] = (int) $pdo->lastInsertId();
}
$pdo->exec("UPDATE tasks SET deleted_at = '2026-01-01 00:00:00' WHERE id = {$p['Alt']}");

/** Alle Zeilen der Tabelle, lesbar. */
function zeilen(PDO $pdo): array
{
    $r = [];
    foreach ($pdo->query("SELECT task_id, linked_task_id, kind, note FROM task_links ORDER BY task_id, linked_task_id") as $z) {
        $r[] = $z['task_id'] . '>' . $z['linked_task_id'] . ':' . $z['kind'] . ($z['note'] !== '' ? '(' . $z['note'] . ')' : '');
    }
    return $r;
}

$checks = [];

// --- Arten --------------------------------------------------------------
$arten = task_link_arten();
$checks['drei Arten'] = array_keys($arten) === ['follow_up', 'part_of', 'related'];
$checks['jede Art hat label, vor, zurueck']
    = count(array_filter($arten, fn($a) => isset($a['label'], $a['vor'], $a['zurueck']) && strpos($a['vor'], '%s') !== false)) === 3;

// --- Aus dem Formular ---------------------------------------------------
$links = task_links_aus_formular([
    'link_task' => ['5', '', '7', 'abc'],
    'link_kind' => ['follow_up', 'related', 'quatsch'],
    'link_note' => ['Notiz', 'x', str_repeat('n', 300)],
]);
$checks['leere und unbrauchbare Zeilen fallen weg'] = count($links) === 2;
$checks['Zeilen bleiben zugeordnet'] = $links[0] === ['task' => 5, 'kind' => 'follow_up', 'note' => 'Notiz'];
$checks['unbekannte Art wird related'] = $links[1]['kind'] === 'related';
$checks['Notiz wird gekuerzt'] = mb_strlen($links[1]['note']) === 255;
$checks['ohne Felder leere Liste'] = task_links_aus_formular([]) === [];

// --- Anlegen ------------------------------------------------------------
task_links_abgleichen($pdo, $p['Wartung'], [
    ['task' => $p['Relaunch'], 'kind' => 'follow_up', 'note' => 'Pflege'],
]);
$checks['Verknuepfung angelegt'] = zeilen($pdo) === ["{$p['Wartung']}>{$p['Relaunch']}:follow_up(Pflege)"];

// --- Aendern behaelt die Zeile -----------------------------------------
$id_vor = (int) $pdo->query("SELECT id FROM task_links")->fetchColumn();
task_links_abgleichen($pdo, $p['Wartung'], [
    ['task' => $p['Relaunch'], 'kind' => 'related', 'note' => ''],
]);
$checks['Art geaendert'] = zeilen($pdo) === ["{$p['Wartung']}>{$p['Relaunch']}:related"];
$checks['id bleibt beim Aendern'] = (int) $pdo->query("SELECT id FROM task_links")->fetchColumn() === $id_vor;

// --- Selbstverweis, geloeschtes und unbekanntes Ziel -------------------
task_links_abgleichen($pdo, $p['Wartung'], [
    ['task' => $p['Wartung'],  'kind' => 'related',   'note' => ''],
    ['task' => $p['Alt'],      'kind' => 'related',   'note' => ''],
    ['task' => 999999,         'kind' => 'related',   'note' => ''],
    ['task' => $p['Relaunch'], 'kind' => 'follow_up', 'note' => ''],
]);
$checks['Selbstverweis, geloeschtes und unbekanntes Ziel werden verworfen']
    = zeilen($pdo) === ["{$p['Wartung']}>{$p['Relaunch']}:follow_up"];
$abgewiesen = (int) $pdo->query("SELECT COUNT(*) FROM logs WHERE action_type = 'TASK_LINK_REJECTED'")->fetchColumn();
$checks['Abweisungen stehen im Protokoll'] = $abgewiesen === 3;

// --- Doppeltes Ziel in einer Sendung: erstes gewinnt --------------------
task_links_abgleichen($pdo, $p['Wartung'], [
    ['task' => $p['Relaunch'], 'kind' => 'part_of', 'note' => 'eins'],
    ['task' => $p['Relaunch'], 'kind' => 'related', 'note' => 'zwei'],
]);
$checks['doppeltes Ziel: erstes gewinnt'] = zeilen($pdo) === ["{$p['Wartung']}>{$p['Relaunch']}:part_of(eins)"];

// --- Gegenverweis wird ersetzt -----------------------------------------
task_links_abgleichen($pdo, $p['Relaunch'], [
    ['task' => $p['Wartung'], 'kind' => 'follow_up', 'note' => ''],
]);
$checks['Gegenverweis ersetzt den bestehenden']
    = zeilen($pdo) === ["{$p['Relaunch']}>{$p['Wartung']}:follow_up"];

// --- Nicht mehr gesendet = geloescht -----------------------------------
task_links_abgleichen($pdo, $p['Relaunch'], []);
$checks['leere Liste loescht alle eigenen'] = zeilen($pdo) === [];

// --- Fremde Verknuepfungen bleiben --------------------------------------
task_links_abgleichen($pdo, $p['Broschuere'], [['task' => $p['Relaunch'], 'kind' => 'follow_up', 'note' => '']]);
task_links_abgleichen($pdo, $p['Wartung'],    [['task' => $p['Relaunch'], 'kind' => 'follow_up', 'note' => '']]);
task_links_abgleichen($pdo, $p['Broschuere'], []);
$checks['fremde Verknuepfungen bleiben stehen']
    = zeilen($pdo) === ["{$p['Wartung']}>{$p['Relaunch']}:follow_up"];

// --- Laden, beide Richtungen -------------------------------------------
task_links_abgleichen($pdo, $p['Broschuere'], [['task' => $p['Relaunch'], 'kind' => 'related', 'note' => 'n']]);
$geladen = task_links_laden($pdo, [$p['Relaunch'], $p['Wartung'], $p['Broschuere'], $p['Alt']]);
$checks['Relaunch sieht zwei Rueckverweise']
    = count($geladen[$p['Relaunch']] ?? []) === 2
   && count(array_filter($geladen[$p['Relaunch']], fn($l) => $l['richtung'] === 'zurueck')) === 2;
$checks['Wartung sieht einen Vorwaertsverweis']
    = ($geladen[$p['Wartung']][0]['richtung'] ?? '') === 'vor'
   && ($geladen[$p['Wartung']][0]['titel'] ?? '') === 'Relaunch'
   && ($geladen[$p['Wartung']][0]['kunde'] ?? '') === 'Lena';
$checks['Notiz kommt mit'] = ($geladen[$p['Broschuere']][0]['note'] ?? '') === 'n';
$checks['leere Kennungsliste ergibt leeres Ergebnis'] = task_links_laden($pdo, []) === [];

// Geloeschtes Gegenueber wird nicht geliefert, kehrt aber zurueck.
$pdo->exec("UPDATE tasks SET deleted_at = '2026-01-01 00:00:00' WHERE id = {$p['Wartung']}");
$g2 = task_links_laden($pdo, [$p['Relaunch']]);
$checks['geloeschtes Gegenueber fehlt'] = count($g2[$p['Relaunch']] ?? []) === 1;
$pdo->exec("UPDATE tasks SET deleted_at = NULL WHERE id = {$p['Wartung']}");
$g3 = task_links_laden($pdo, [$p['Relaunch']]);
$checks['wiederhergestelltes Gegenueber ist zurueck'] = count($g3[$p['Relaunch']] ?? []) === 2;

// --- Text ---------------------------------------------------------------
$checks['Text vorwaerts'] = task_link_text('follow_up', 'vor', '<a>X</a>') === 'Folgeprojekt von <a>X</a>';
$checks['Text rueckwaerts'] = task_link_text('follow_up', 'zurueck', 'Y') === 'Fortgesetzt in Y';
$checks['unbekannte Art faellt auf related'] = task_link_text('quatsch', 'vor', 'Z') === 'Verwandt mit Z';

// --- Kaskade ------------------------------------------------------------
$pdo->exec("DELETE FROM tasks WHERE id = {$p['Relaunch']}");
$checks['Kaskade raeumt Verknuepfungen'] = zeilen($pdo) === [];

// --- Das Markup der Auswahl --------------------------------------------
$html = task_links_auswahl([
    ['id' => 1, 'title' => 'A <b>', 'start_date' => '2025-01-01', 'contact_id' => $lena, 'deleted_at' => null],
    ['id' => 2, 'title' => 'B',     'start_date' => null,         'contact_id' => null,  'deleted_at' => null],
    ['id' => 3, 'title' => 'Weg',   'start_date' => null,         'contact_id' => null,  'deleted_at' => '2026-01-01'],
], [$lena => ['name' => 'Lena', 'company' => 'Lena & Co']], 'e');
$checks['Vorlage ist dabei']         = strpos($html, 'data-link-template') !== false;
$checks['Projekte als Optionen']     = substr_count($html, 'data-link-option') === 2;
$checks['geloeschtes Projekt fehlt'] = strpos($html, 'Weg') === false;
$checks['Titel maskiert']            = strpos($html, '<b>') === false && strpos($html, 'A &lt;b&gt;') !== false;
$checks['Firma und Jahr stehen dran'] = strpos($html, 'Lena &amp; Co · 2025') !== false;
$checks['drei Arten als Optionen']   = substr_count($html, 'name="link_kind[]"') === 1 && substr_count($html, '<option value="follow_up"') === 1;
$checks['Felder heissen richtig']    = strpos($html, 'name="link_task[]"') !== false && strpos($html, 'name="link_note[]"') !== false;

// ------------------------------------------------------------------------
$fail = 0;
foreach ($checks as $name => $ok) {
    echo ($ok ? 'PASS' : 'FAIL') . "  $name\n";
    if (!$ok) $fail = 1;
}
echo $fail === 0 ? 'OK: ' . count($checks) . " Pruefungen bestanden.\n" : "FEHLGESCHLAGEN.\n";
exit($fail);
