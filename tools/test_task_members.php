<?php
/**
 * Test fuer den Abgleich der Projektbeteiligten.
 * Aufruf: php tools/test_task_members.php
 *
 * task_members_abgleichen() traegt jetzt beide Wege - das Bearbeiten-
 * Fenster und das Fenster "Beteiligte am Projekt". Ein Fehler darin
 * entzieht jemandem den Portalzugang oder gibt ihn jemandem, der ihn
 * nicht haben soll; beides faellt im Betrieb erst spaet auf.
 *
 * Laeuft gegen den SQLite-Spiegel von install/schema.sql, also gegen die
 * echten Tabellen mit ihren Fremdschluesseln - eine Attrappe wuerde
 * gerade die Faelle verschweigen, auf die es ankommt.
 */

require_once __DIR__ . '/lib_sqlite_mirror.php';
require_once __DIR__ . '/../includes/task_members.php';

$wurzel = dirname(__DIR__);
$pdo = new SqliteSpiegelPDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('PRAGMA foreign_keys = ON');
foreach (nach_sqlite(file_get_contents($wurzel . '/install/schema.sql')) as $anweisung) {
    $pdo->exec($anweisung);
}

// --- Ausgangslage -----------------------------------------------------
$namen = ['Anna', 'Bruno', 'Carla', 'Doris', 'Emil'];
$kontakt = [];
$ins = $pdo->prepare("INSERT INTO contacts (name, email, contact_type) VALUES (?, ?, 'Kunde')");
foreach ($namen as $n) {
    $ins->execute([$n, strtolower($n) . '@example.test']);
    $kontakt[$n] = (int) $pdo->lastInsertId();
}
$pdo->prepare("INSERT INTO tasks (title, status, contact_id) VALUES ('Projekt', 'Offen', ?)")
    ->execute([$kontakt['Anna']]);
$task = (int) $pdo->lastInsertId();

/** Wer ist gerade beteiligt, mit Rolle? */
function stand(PDO $pdo, int $task): array
{
    $st = $pdo->prepare("SELECT c.name, tc.role FROM task_contacts tc
                         JOIN contacts c ON c.id = tc.contact_id
                         WHERE tc.task_id = ? ORDER BY c.name");
    $st->execute([$task]);
    $r = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $z) $r[$z['name']] = $z['role'];
    return $r;
}

$checks = [];

// --- Anlegen ----------------------------------------------------------
task_members_abgleichen($pdo, $task, $kontakt['Anna'], [$kontakt['Bruno'], $kontakt['Carla']]);
$checks['drei Beteiligte angelegt']
    = stand($pdo, $task) === ['Anna' => 'owner', 'Bruno' => 'member', 'Carla' => 'member'];

// --- Entfernen und Hinzufuegen in einem Zug ---------------------------
task_members_abgleichen($pdo, $task, $kontakt['Anna'], [$kontakt['Carla'], $kontakt['Doris']]);
$checks['Bruno raus, Doris rein']
    = stand($pdo, $task) === ['Anna' => 'owner', 'Carla' => 'member', 'Doris' => 'member'];

// --- Der Hauptansprechpartner laesst sich nicht abwaehlen -------------
// Das Kaestchen ist gesperrt und sendet daher nichts - er muss trotzdem
// dabei bleiben, sonst verlaere der Kunde den Zugang zum eigenen Projekt.
task_members_abgleichen($pdo, $task, $kontakt['Anna'], [$kontakt['Carla']]);
$checks['Hauptkontakt bleibt, auch wenn er nicht gesendet wird']
    = stand($pdo, $task) === ['Anna' => 'owner', 'Carla' => 'member'];

// --- Bestehende behalten ihren Eintrag --------------------------------
// Abgleich statt Neuschreiben: added_at traegt den Verlauf im Portal.
$vorher = $pdo->query("SELECT added_at FROM task_contacts WHERE task_id = $task
                       AND contact_id = {$kontakt['Carla']}")->fetchColumn();
$pdo->exec("UPDATE task_contacts SET added_at = '2020-01-01 10:00:00'
            WHERE task_id = $task AND contact_id = {$kontakt['Carla']}");
task_members_abgleichen($pdo, $task, $kontakt['Anna'], [$kontakt['Carla'], $kontakt['Emil']]);
$nachher = $pdo->query("SELECT added_at FROM task_contacts WHERE task_id = $task
                        AND contact_id = {$kontakt['Carla']}")->fetchColumn();
$checks['unveraenderter Beteiligter behaelt added_at'] = $nachher === '2020-01-01 10:00:00';

// --- Hauptkontakt wechseln --------------------------------------------
// Danach darf genau einer 'owner' sein - der alte muss zurueckgestuft
// werden, sonst haetten zwei Personen die Rolle.
task_members_abgleichen($pdo, $task, $kontakt['Carla'], [$kontakt['Anna'], $kontakt['Emil']]);
$s = stand($pdo, $task);
$checks['neuer Hauptkontakt traegt owner'] = ($s['Carla'] ?? '') === 'owner';
$checks['alter Hauptkontakt wird member']  = ($s['Anna'] ?? '') === 'member';
$checks['genau ein owner'] = count(array_filter($s, fn($r) => $r === 'owner')) === 1;

// --- Rohe Eingaben ----------------------------------------------------
// Was hier ankommt, stammt aus einer Sendung: Text, Nullen, Doppelte.
task_members_abgleichen($pdo, $task, $kontakt['Anna'],
    [$kontakt['Bruno'], (string) $kontakt['Bruno'], '0', 0, -5, '', 'abc', null]);
$s = stand($pdo, $task);
$checks['doppelte und unbrauchbare Werte fallen weg']
    = $s === ['Anna' => 'owner', 'Bruno' => 'member'];

$anzahl = (int) $pdo->query("SELECT COUNT(*) FROM task_contacts WHERE task_id = $task")->fetchColumn();
$checks['keine Doppeleintraege in der Tabelle'] = $anzahl === 2;

// --- Leere Auswahl ----------------------------------------------------
task_members_abgleichen($pdo, $task, $kontakt['Anna'], []);
$checks['leere Auswahl laesst nur den Hauptkontakt']
    = stand($pdo, $task) === ['Anna' => 'owner'];

// --- Projekt ohne Hauptkontakt ----------------------------------------
$pdo->exec("INSERT INTO tasks (title, status, contact_id) VALUES ('Ohne Kunde', 'Offen', NULL)");
$task2 = (int) $pdo->lastInsertId();
task_members_abgleichen($pdo, $task2, 0, [$kontakt['Doris']]);
$checks['ohne Hauptkontakt gibt es keinen owner']
    = stand($pdo, $task2) === ['Doris' => 'member'];

task_members_abgleichen($pdo, $task2, 0, []);
$checks['ohne Hauptkontakt laesst sich alles leeren'] = stand($pdo, $task2) === [];

// --- Unsinnige Projekt-Kennung ----------------------------------------
$vor = (int) $pdo->query("SELECT COUNT(*) FROM task_contacts")->fetchColumn();
task_members_abgleichen($pdo, 0, $kontakt['Anna'], [$kontakt['Bruno']]);
task_members_abgleichen($pdo, -3, $kontakt['Anna'], [$kontakt['Bruno']]);
$checks['Projekt 0 oder negativ aendert nichts']
    = (int) $pdo->query("SELECT COUNT(*) FROM task_contacts")->fetchColumn() === $vor;

// --- Das andere Projekt bleibt unberuehrt -----------------------------
task_members_abgleichen($pdo, $task2, 0, [$kontakt['Emil']]);
task_members_abgleichen($pdo, $task, $kontakt['Anna'], [$kontakt['Carla']]);
$checks['Projekte beeinflussen sich nicht']
    = stand($pdo, $task2) === ['Emil' => 'member']
   && stand($pdo, $task)  === ['Anna' => 'owner', 'Carla' => 'member'];

// --- Das Markup der Auswahl -------------------------------------------
// te() steht hier nicht zur Verfuegung; die Auswahl braucht es aber.
if (!function_exists('te')) { function te(string $s): string { return $s; } }
$html = task_members_auswahl([
    ['id' => 7, 'name' => 'Zoe <script>', 'company' => 'Beispiel & Co', 'contact_type' => 'Geschäftspartner', 'portal_token' => null],
    ['id' => 8, 'name' => 'Yann',         'company' => '',              'contact_type' => 'Kunde',            'portal_token' => 'abc'],
], 'test');

$checks['je Kontakt ein Kaestchen'] = substr_count($html, 'name="member_ids[]"') === 2;
$checks['Werte sind die Kontakt-Kennungen']
    = strpos($html, 'value="7"') !== false && strpos($html, 'value="8"') !== false;
$checks['Name wird maskiert']
    = strpos($html, '<script>') === false && strpos($html, '&lt;script&gt;') !== false;
$checks['Firma wird maskiert'] = strpos($html, 'Beispiel &amp; Co') !== false;
$checks['fehlender Portalzugang steht dran'] = strpos($html, 'kein Portal-Zugang') !== false;
$checks['vorhandener Portalzugang wird nicht erwaehnt']
    = substr_count($html, 'kein Portal-Zugang') === 1;
$checks['Partner wird ausgewiesen'] = strpos($html, 'Partner') !== false;
$checks['Suchfeld ist dabei'] = strpos($html, 'data-member-filter') !== false;
$checks['ids tragen den Praefix'] = strpos($html, 'id="test_m7"') !== false;

$leer = task_members_auswahl([], 'x');
$checks['leere Kontaktliste sagt es']
    = strpos($leer, 'Keine Kontakte vorhanden.') !== false
   && strpos($leer, 'name="member_ids[]"') === false;

// ----------------------------------------------------------------------
$fail = 0;
foreach ($checks as $name => $ok) {
    echo ($ok ? 'PASS' : 'FAIL') . "  $name\n";
    if (!$ok) $fail = 1;
}
echo $fail === 0
    ? 'OK: ' . count($checks) . " Pruefungen bestanden.\n"
    : "FEHLGESCHLAGEN.\n";
exit($fail);
