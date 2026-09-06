<?php
/**
 * Test fuer die Portal-Benachrichtigungen.
 * Aufruf: php tools/test_portal_notify.php
 *
 * Zwei Bremsen muessen einzeln und gemeinsam greifen: der globale
 * Schalter je Ereignisart und das Haekchen am Kontakt. Ein Fehler darin
 * schickt entweder gar nichts - dann wartet der Kunde vergeblich - oder
 * an jemanden, der abbestellt hat.
 *
 * Der Versand selbst wird nicht ausgefuehrt: mail_versenden() ist hier
 * durch eine Aufzeichnung ersetzt. Geprueft wird, WER eine Mail bekommt
 * und WAS darin steht, nicht ob PHPMailer laeuft.
 */
require_once __DIR__ . '/lib_sqlite_mirror.php';

$wurzel = dirname(__DIR__);

define('COMPANY_SHORT', 'Testfirma');
define('COMPANY_NAME',  'Testfirma GmbH');
define('ADMIN_EMAIL',   'admin@example.test');
define('SUPPORT_EMAIL', 'support@example.test');
define('MAIN_WEBSITE',  'https://example.test');
define('COLOR_PRIMARY',  '#149ddd');
define('COLOR_SIDEBAR',  '#040b14');

$EINSTELLUNGEN = [];
function setting(string $key, string $default = ''): string {
    global $EINSTELLUNGEN;
    return $EINSTELLUNGEN[$key] ?? $default;
}

// Der Versand wird aufgezeichnet statt ausgefuehrt. Diese Attrappe traegt
// denselben Namen wie die Funktion in includes/mailer.php und muss vor
// dem Einbinden stehen - portal_notify.php bindet mailer.php ein, und
// dort schuetzt ein function_exists-Wall die Definition.
$GESENDET = [];
$SCHEITERT = '';
function mail_versenden(array $opt): array {
    global $GESENDET, $SCHEITERT;
    if ($SCHEITERT !== '' && ($opt['to'] ?? '') === $SCHEITERT) {
        $GESENDET[] = $opt + ['ok' => false];
        return ['ok' => false, 'error' => 'SMTP-Fehler (Test)'];
    }
    $GESENDET[] = $opt + ['ok' => true];
    return ['ok' => true, 'error' => ''];
}

require_once $wurzel . '/includes/demo.php';
require_once $wurzel . '/includes/i18n.php';
require_once $wurzel . '/includes/mail_templates.php';
require_once $wurzel . '/includes/logging.php';
require_once $wurzel . '/includes/portal_notify.php';

$pdo = new SqliteSpiegelPDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('PRAGMA foreign_keys = ON');
foreach (nach_sqlite(file_get_contents($wurzel . '/install/schema.sql')) as $anweisung) {
    $pdo->exec($anweisung);
}

// Fuenf Beteiligte an einem Projekt, mit unterschiedlichen Eigenschaften.
$ins = $pdo->prepare("INSERT INTO contacts (name, email, contact_type, language, portal_notify, portal_token)
                      VALUES (?, ?, 'Kunde', ?, ?, ?)");
$k = [];
foreach ([
    ['Anna',  'anna@example.test',  null, 1, 'tok-anna'],
    ['Bruno', 'bruno@example.test', 'en', 1, 'tok-bruno'],
    ['Carla', 'carla@example.test', null, 0, 'tok-carla'],   // abbestellt
    ['Dirk',  '',                   null, 1, 'tok-dirk'],    // keine Adresse
    ['Emil',  'emil@example.test',  null, 1, null],          // kein Portalzugang
] as [$n, $m, $l, $notify, $tok]) {
    $ins->execute([$n, $m, $l, $notify, $tok]);
    $k[$n] = (int) $pdo->lastInsertId();
}
$pdo->prepare("INSERT INTO tasks (title, status, contact_id) VALUES ('Relaunch', 'Offen', ?)")
    ->execute([$k['Anna']]);
$task = (int) $pdo->lastInsertId();
$tc = $pdo->prepare("INSERT INTO task_contacts (task_id, contact_id, role) VALUES (?, ?, ?)");
$tc->execute([$task, $k['Anna'], 'owner']);
foreach (['Bruno', 'Carla', 'Dirk', 'Emil'] as $n) $tc->execute([$task, $k[$n], 'member']);

/** Adressen der letzten Sendungen, sortiert. */
function adressen(): array {
    global $GESENDET;
    $a = array_map(fn($m) => $m['to'], $GESENDET);
    sort($a);
    return $a;
}
function zuruecksetzen(): void { global $GESENDET; $GESENDET = []; }

$checks = [];

// --- Die Arten ---------------------------------------------------------
$arten = portal_notify_arten();
$checks['sechs Arten bekannt'] = count($arten) === 6;
$checks['jede Art nennt Schalter und Vorlage']
    = count(array_filter($arten, fn($a) => isset($a['setting'], $a['template']))) === count($arten);
$checks['Arten decken die Ereignisse ab']
    = array_keys($arten) === ['project_reply', 'milestone_comment', 'asset_upload',
                              'milestone', 'ticket_reply', 'invoice_created'];

// --- Globaler Schalter aus --------------------------------------------
$EINSTELLUNGEN['notify_project_reply'] = '0';
zuruecksetzen();
$n = portal_benachrichtigen($pdo, 'project_reply', $task, ['projekt' => 'Relaunch', 'nachricht' => 'Hallo']);
$checks['globaler Schalter aus: keine Mail'] = $n === 0 && adressen() === [];

// --- Globaler Schalter an ---------------------------------------------
$EINSTELLUNGEN['notify_project_reply'] = '1';
zuruecksetzen();
$n = portal_benachrichtigen($pdo, 'project_reply', $task, ['projekt' => 'Relaunch', 'nachricht' => 'Hallo']);
$checks['Anna, Bruno und Emil bekommen Mail']
    = $n === 3 && adressen() === ['anna@example.test', 'bruno@example.test', 'emil@example.test'];
$checks['Abbestellter bekommt nichts'] = !in_array('carla@example.test', adressen(), true);
$checks['ohne Adresse keine Mail']     = !in_array('', adressen(), true) && count($GESENDET) === 3;

// --- Inhalt und Sprache ------------------------------------------------
$anna  = current(array_filter($GESENDET, fn($m) => $m['to'] === 'anna@example.test'));
$bruno = current(array_filter($GESENDET, fn($m) => $m['to'] === 'bruno@example.test'));
$checks['Betreff auf Deutsch fuer Anna'] = strpos($anna['subject'], 'Antwort') !== false;
$checks['Betreff auf Englisch fuer Bruno']
    = $bruno['subject'] !== $anna['subject'] && stripos($bruno['subject'], 'reply') !== false;
$checks['Projektname steht drin']  = strpos($anna['body'], 'Relaunch') !== false;
$checks['Nachricht steht drin']    = strpos($anna['body'], 'Hallo') !== false;
$checks['eigener Portal-Link']     = strpos($anna['body'], 'tok-anna') !== false
                                  && strpos($bruno['body'], 'tok-bruno') !== false;
$checks['Vorlage wird protokolliert'] = ($anna['template'] ?? '') === 'project_reply';
$checks['pdo wird durchgereicht']     = isset($anna['pdo']);

// Wer keinen Portal-Zugang hat, bekommt die Nachricht trotzdem - nur
// ohne Knopf, der ins Leere zeigte.
$emil = current(array_filter($GESENDET, fn($m) => $m['to'] === 'emil@example.test'));
$checks['ohne Portal-Zugang kein Knopf']
    = strpos($emil['body'], '/portal?token=') === false
   && strpos($emil['body'], 'Relaunch') !== false;

// --- Ein Fehlschlag nimmt die uebrigen nicht mit -----------------------
$SCHEITERT = 'anna@example.test';
zuruecksetzen();
$n = portal_benachrichtigen($pdo, 'project_reply', $task, ['projekt' => 'Relaunch', 'nachricht' => 'x']);
$checks['Fehlschlag zaehlt nicht als gesendet'] = $n === 2;
$checks['die uebrigen gehen trotzdem raus']     = count($GESENDET) === 3;
$SCHEITERT = '';

// --- Unbekannte Art, unbekanntes Projekt -------------------------------
zuruecksetzen();
$checks['unbekannte Art: keine Mail'] = portal_benachrichtigen($pdo, 'quatsch', $task, []) === 0 && $GESENDET === [];
$checks['Projekt 0: keine Mail']      = portal_benachrichtigen($pdo, 'project_reply', 0, []) === 0;

// --- Projekt ohne Beteiligte -------------------------------------------
$pdo->exec("INSERT INTO tasks (title, status) VALUES ('Allein', 'Offen')");
$leer = (int) $pdo->lastInsertId();
zuruecksetzen();
$checks['Projekt ohne Beteiligte: keine Mail']
    = portal_benachrichtigen($pdo, 'project_reply', $leer, ['projekt' => 'Allein', 'nachricht' => 'x']) === 0;

// --- Geloeschter Kontakt ------------------------------------------------
$pdo->exec("UPDATE contacts SET deleted_at = '2026-01-01 00:00:00' WHERE id = {$k['Bruno']}");
zuruecksetzen();
$n = portal_benachrichtigen($pdo, 'project_reply', $task, ['projekt' => 'Relaunch', 'nachricht' => 'x']);
$checks['geloeschter Kontakt bekommt nichts']
    = $n === 2 && adressen() === ['anna@example.test', 'emil@example.test'];
$pdo->exec("UPDATE contacts SET deleted_at = NULL WHERE id = {$k['Bruno']}");

// --- Einzelempfaenger (Ticket, Rechnung) --------------------------------
$EINSTELLUNGEN['notify_invoice_created'] = '1';
zuruecksetzen();
$n = portal_benachrichtigen_kontakt($pdo, 'invoice_created', $k['Anna'],
        ['nummer' => 'RE-2026-001', 'betrag' => '119,00', 'faellig' => '30.09.2026']);
$checks['Einzelempfaenger bekommt Mail'] = $n === 1 && adressen() === ['anna@example.test'];

zuruecksetzen();
$checks['Einzelempfaenger abbestellt: nichts']
    = portal_benachrichtigen_kontakt($pdo, 'invoice_created', $k['Carla'], ['nummer' => 'RE-2026-002']) === 0;

$EINSTELLUNGEN['notify_invoice_created'] = '0';
zuruecksetzen();
$checks['Einzelempfaenger, Schalter aus: nichts']
    = portal_benachrichtigen_kontakt($pdo, 'invoice_created', $k['Anna'], ['nummer' => 'RE-2026-003']) === 0;

// --- Empfaengerliste als eigene Funktion --------------------------------
$e = portal_empfaenger($pdo, $task);
$checks['Empfaengerliste hat drei Eintraege'] = count($e) === 3;
$checks['Empfaengerliste traegt Sprache und Token']
    = array_key_exists('language', $e[0]) && array_key_exists('portal_token', $e[0])
   && isset($e[0]['email'], $e[0]['name']);

// ------------------------------------------------------------------------
$fail = 0;
foreach ($checks as $name => $ok) {
    echo ($ok ? 'PASS' : 'FAIL') . "  $name\n";
    if (!$ok) $fail = 1;
}
echo $fail === 0 ? 'OK: ' . count($checks) . " Pruefungen bestanden.\n" : "FEHLGESCHLAGEN.\n";
exit($fail);
