<?php
/**
 * Test für das Projektbudget. Aufruf: php tools/test_task_budget.php
 *
 * Zwei Dinge sollen hier festliegen: dass „kein Budget" und „null Euro"
 * auseinandergehalten werden - das eine ist eine fehlende Angabe, das
 * andere die Aussage, dass ein Projekt nichts kosten darf -, und dass
 * die Warnschwelle nicht durch Runden erreicht wird. Ein Balken, der bei
 * 999,60 € von 1.000 € „100 %" anzeigt, nimmt der Anzeige den Sinn.
 */

require_once __DIR__ . '/lib_sqlite_mirror.php';
require_once __DIR__ . '/../includes/task_budget.php';

$wurzel = dirname(__DIR__);
$checks = [];

// =====================================================================
// Rechnen
// =====================================================================
$checks['ohne Budget nichts zu melden'] = budget_stand(0, 500)['gesetzt'] === false;
$checks['negatives Budget zaehlt nicht'] = budget_stand(-100, 50)['gesetzt'] === false;

$halb = budget_stand(2000.00, 1000.00);
$checks['die Haelfte sind 50 Prozent'] = $halb['prozent'] === 50 && $halb['stufe'] === 'ok';
$checks['Rest stimmt']                 = $halb['rest'] === 1000.00;

// Die Schwelle: 79 % sind noch ruhig, 80 % sind eine Warnung.
$checks['79 Prozent bleiben ruhig'] = budget_stand(1000.00, 799.00)['stufe'] === 'ok';
$checks['80 Prozent warnen']        = budget_stand(1000.00, 800.00)['stufe'] === 'warnung';

// Knapp darunter darf nicht auf 100 aufgerundet werden - sonst stuende
// der Balken voll, obwohl noch etwas uebrig ist.
$knapp = budget_stand(1000.00, 999.60);
$checks['knapp darunter sind 99 Prozent'] = $knapp['prozent'] === 99;
$checks['knapp darunter ist nicht drueber'] = $knapp['stufe'] === 'warnung';

// Genau aufgebraucht ist noch nicht ueberschritten.
$genau = budget_stand(1000.00, 1000.00);
$checks['genau aufgebraucht']       = $genau['prozent'] === 100 && $genau['stufe'] === 'warnung';
$checks['ein Cent darueber reicht'] = budget_stand(1000.00, 1000.01)['stufe'] === 'ueber';

$drueber = budget_stand(1000.00, 1250.00);
$checks['darueber sind 125 Prozent'] = $drueber['prozent'] === 125;
$checks['der Rest wird negativ']     = $drueber['rest'] === -250.00;

$checks['Farben je Stufe'] = budget_farbe('ok') === 'bg-success'
                          && budget_farbe('warnung') === 'bg-warning'
                          && budget_farbe('ueber') === 'bg-danger';

// =====================================================================
// Die Eingabe
// =====================================================================
$checks['leer bleibt leer']       = budget_eingabe('') === null;
$checks['null bleibt null']       = budget_eingabe(null) === null;
$checks['Leerzeichen sind leer']  = budget_eingabe('   ') === null;
$checks['Komma wird gelesen']     = budget_eingabe('1.234,50') === 1234.5 || budget_eingabe('1234,50') === 1234.5;
$checks['Punkt wird gelesen']     = budget_eingabe('1234.50') === 1234.5;
// Eine 0 ist keine Angabe eines Budgets, sondern das Fehlen einer.
$checks['null Euro ist kein Budget'] = budget_eingabe('0') === null;
$checks['negativ ist kein Budget']   = budget_eingabe('-500') === null;

// =====================================================================
// Der Verbrauch aus der Datenbank
// =====================================================================
$pdo = new SqliteSpiegelPDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('PRAGMA foreign_keys = ON');
foreach (nach_sqlite(file_get_contents($wurzel . '/install/schema.sql')) as $a) {
    $pdo->exec($a);
}

$pdo->exec("INSERT INTO contacts (id, name, hourly_rate) VALUES (1, 'Kunde mit Satz', 90.00)");
$pdo->exec("INSERT INTO contacts (id, name) VALUES (2, 'Kunde ohne Satz')");

// Der Satz kommt in derselben Reihenfolge zustande wie beim Abrechnen.
$pdo->exec("INSERT INTO tasks (id, title, contact_id, hourly_rate, budget_amount)
            VALUES (1, 'Eigener Satz', 1, 120.00, 2000.00)");
$pdo->exec("INSERT INTO tasks (id, title, contact_id, budget_amount)
            VALUES (2, 'Satz vom Kunden', 1, 1000.00)");
$pdo->exec("INSERT INTO tasks (id, title, contact_id) VALUES (3, 'Voreinstellung', 2)");
// Ein Satz von 0,00 ist eine Aussage und keine fehlende Angabe.
$pdo->exec("INSERT INTO tasks (id, title, contact_id, hourly_rate)
            VALUES (4, 'Kostenlos', 1, 0.00)");

foreach ([[1, 120], [2, 60], [3, 30], [4, 600]] as [$task, $minuten]) {
    $pdo->prepare('INSERT INTO time_entries (task_id, duration_minutes) VALUES (?, ?)')
        ->execute([$task, $minuten]);
}

$v = budget_verbrauch_je_projekt($pdo, 60.00);

$checks['Projektsatz schlaegt Kundensatz'] = abs($v[1]['wert'] - 240.00) < 0.005;   // 2 h * 120
$checks['Kundensatz schlaegt Voreinstellung'] = abs($v[2]['wert'] - 90.00) < 0.005; // 1 h * 90
$checks['Voreinstellung greift zuletzt'] = abs($v[3]['wert'] - 30.00) < 0.005;      // 0,5 h * 60
$checks['null Euro bleibt null Euro'] = abs($v[4]['wert'] - 0.00) < 0.005;          // 10 h * 0

// Ein Projekt ohne erfasste Zeit faellt nicht heraus - sonst stuende auf
// seiner Karte kein Balken, obwohl ein Budget gesetzt ist.
$pdo->exec("INSERT INTO tasks (id, title, budget_amount) VALUES (5, 'Noch nichts getan', 500.00)");
$v = budget_verbrauch_je_projekt($pdo, 60.00);
$checks['Projekt ohne Zeit ist dabei'] = isset($v[5]) && $v[5]['wert'] === 0.0;

// Ein geloeschtes Projekt nicht.
$pdo->exec("UPDATE tasks SET deleted_at = '2026-01-01' WHERE id = 5");
$v = budget_verbrauch_je_projekt($pdo, 60.00);
$checks['Papierkorb bleibt draussen'] = !isset($v[5]);

// Und das Zusammenspiel: 240 € Arbeit auf 2.000 € Budget.
$stand = budget_stand(2000.00, $v[1]['wert']);
$checks['Karte zeigt 12 Prozent'] = $stand['prozent'] === 12 && $stand['stufe'] === 'ok';

// =====================================================================
$fail = 0;
foreach ($checks as $name => $ok) {
    echo ($ok ? 'PASS' : 'FAIL') . "  $name\n";
    if (!$ok) $fail = 1;
}
exit($fail);
