<?php
/**
 * Prueft Migration 24 gegen den SQLite-Spiegel.
 * Aufruf: php tools/test_migration_24.php
 *
 * Die Vorgabe ist der springende Punkt: 1 heisst "bekommt Mail". Ein
 * Bestand, der nach dem Update stumm waere, waere die schlechtere
 * Ueberraschung - bei Meilenstein und Ticket ging bisher eine Mail
 * hinaus, und das soll so bleiben.
 */
require_once __DIR__ . '/lib_sqlite_mirror.php';
require_once __DIR__ . '/../includes/migrations.php';

$wurzel = dirname(__DIR__);
$pdo = new SqliteSpiegelPDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('PRAGMA foreign_keys = ON');
foreach (nach_sqlite(file_get_contents($wurzel . '/install/schema.sql')) as $anweisung) {
    $pdo->exec($anweisung);
}

$checks = [];
// Mindestens diese Version - eine spaetere Migration darf diese
// Pruefung nicht brechen.
$checks['Version ist mindestens 24'] = SCHEMA_VERSION >= 24;
$checks['Migration 24 ist eingetragen'] = isset(migrations()[24]);

// Die Spalte steht in der Schemadatei - eine Neuinstallation hat sie.
$spalten = array_column($pdo->query("PRAGMA table_info(contacts)")->fetchAll(PDO::FETCH_ASSOC), 'name');
$checks['Schemadatei bringt portal_notify'] = in_array('portal_notify', $spalten, true);

$pdo->exec("INSERT INTO contacts (name, contact_type) VALUES ('Ohne Angabe', 'Kunde')");
$wert = $pdo->query("SELECT portal_notify FROM contacts ORDER BY id DESC LIMIT 1")->fetchColumn();
$checks['Vorgabe ist 1'] = (int) $wert === 1;

// Und der Weg von Stand 23: Spalte weg, Migration laeuft.
$pdo2 = new SqliteSpiegelPDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo2->exec('PRAGMA foreign_keys = ON');
foreach (nach_sqlite(file_get_contents($wurzel . '/install/schema.sql')) as $anweisung) {
    // Die Spalte aus der CREATE-Anweisung schneiden - so sah contacts
    // auf Stand 23 aus.
    $anweisung = preg_replace('/\n\s*portal_notify[^,\n]*,/', '', $anweisung);
    $pdo2->exec($anweisung);
}
$vorher = array_column($pdo2->query("PRAGMA table_info(contacts)")->fetchAll(PDO::FETCH_ASSOC), 'name');
$checks['Ausgangslage hat die Spalte nicht'] = !in_array('portal_notify', $vorher, true);

$pdo2->exec("INSERT INTO contacts (name, contact_type) VALUES ('Bestand', 'Kunde')");
foreach (migrations()[24] as $anweisung) {
    foreach (nach_sqlite($anweisung) as $t) {
        $pdo2->prepare($t)->execute();
    }
}
$nachher = array_column($pdo2->query("PRAGMA table_info(contacts)")->fetchAll(PDO::FETCH_ASSOC), 'name');
$checks['Migration legt die Spalte an'] = in_array('portal_notify', $nachher, true);
$checks['Bestandszeile bekommt 1']
    = (int) $pdo2->query("SELECT portal_notify FROM contacts WHERE name = 'Bestand'")->fetchColumn() === 1;

$fail = 0;
foreach ($checks as $name => $ok) {
    echo ($ok ? 'PASS' : 'FAIL') . "  $name\n";
    if (!$ok) $fail = 1;
}
echo $fail === 0 ? 'OK: ' . count($checks) . " Pruefungen bestanden.\n" : "FEHLGESCHLAGEN.\n";
exit($fail);
