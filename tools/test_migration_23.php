<?php
/**
 * Prueft Migration 23 gegen den SQLite-Spiegel.
 * Aufruf: php tools/test_migration_23.php
 *
 * Zwei Dinge muessen stimmen: die beiden neuen Tabellen entstehen, und
 * ein Zustaendiger, der bis dahin nur in tasks.assigned_user_id stand,
 * steht danach als 'lead' in task_users. Ohne den zweiten Teil verloere
 * jede Installation mit gesetzter Spalte beim Update ihre Zuordnung.
 */
require_once __DIR__ . '/lib_sqlite_mirror.php';
require_once __DIR__ . '/../includes/migrations.php';

$wurzel = dirname(__DIR__);
$pdo = new SqliteSpiegelPDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('PRAGMA foreign_keys = ON');
foreach (nach_sqlite(file_get_contents($wurzel . '/install/schema.sql')) as $anweisung) {
    $pdo->exec($anweisung);
}
// Stand 22 nachstellen: die beiden Tabellen gibt es dort noch nicht.
$pdo->exec('DROP TABLE task_links');
$pdo->exec('DROP TABLE task_users');

$pdo->exec("INSERT INTO users (email, password_hash, name, role) VALUES ('a@example.test', 'x', 'Anna', 'admin')");
$anna = (int) $pdo->lastInsertId();
$pdo->exec("INSERT INTO tasks (title, status, assigned_user_id) VALUES ('Mit Zustaendigem', 'Offen', $anna)");
$mit = (int) $pdo->lastInsertId();
$pdo->exec("INSERT INTO tasks (title, status) VALUES ('Ohne', 'Offen')");
$ohne = (int) $pdo->lastInsertId();

/** Fuehrt eine Migrationsstufe gegen den Spiegel aus. */
function migration_ausfuehren(PDO $pdo, int $version): void
{
    foreach (migrations()[$version] as $anweisung) {
        // nach_sqlite() uebersetzt die CREATE-Anweisungen (KEY, ENGINE),
        // prepare() des Spiegels das INSERT IGNORE.
        foreach (nach_sqlite($anweisung) as $t) {
            $pdo->prepare($t)->execute();
        }
    }
}

$checks = [];
$checks['Version ist 23'] = SCHEMA_VERSION === 23;
$checks['Migration 23 ist eingetragen'] = isset(migrations()[23]);

migration_ausfuehren($pdo, 23);

$tabellen = $pdo->query("SELECT name FROM sqlite_master WHERE type='table'")->fetchAll(PDO::FETCH_COLUMN);
$checks['task_users entsteht'] = in_array('task_users', $tabellen, true);
$checks['task_links entsteht'] = in_array('task_links', $tabellen, true);

$st = $pdo->prepare('SELECT user_id, role FROM task_users WHERE task_id = ?');
$st->execute([$mit]);
$zeile = $st->fetch(PDO::FETCH_ASSOC);
$checks['bestehender Zustaendiger wird lead']
    = $zeile && (int) $zeile['user_id'] === $anna && $zeile['role'] === 'lead';
$st->execute([$ohne]);
$checks['Projekt ohne Zustaendigen bekommt keine Zeile'] = $st->fetch() === false;

// Zweiter Lauf legt nichts doppelt an (CREATE IF NOT EXISTS, INSERT IGNORE).
migration_ausfuehren($pdo, 23);
$checks['zweiter Lauf legt nichts doppelt an']
    = (int) $pdo->query('SELECT COUNT(*) FROM task_users')->fetchColumn() === 1;

// Kaskade: Projekt weg, Zuordnungen weg.
$pdo->exec("INSERT INTO task_links (task_id, linked_task_id, kind) VALUES ($ohne, $mit, 'follow_up')");
$pdo->exec("DELETE FROM tasks WHERE id = $mit");
$checks['Kaskade raeumt task_users'] = (int) $pdo->query('SELECT COUNT(*) FROM task_users')->fetchColumn() === 0;
$checks['Kaskade raeumt task_links'] = (int) $pdo->query('SELECT COUNT(*) FROM task_links')->fetchColumn() === 0;

$fail = 0;
foreach ($checks as $name => $ok) {
    echo ($ok ? 'PASS' : 'FAIL') . "  $name\n";
    if (!$ok) $fail = 1;
}
echo $fail === 0 ? 'OK: ' . count($checks) . " Pruefungen bestanden.\n" : "FEHLGESCHLAGEN.\n";
exit($fail);
