<?php
/**
 * Test fuer die Anfrage-Schnittstelle.
 * Aufruf: php tools/test_api_leads.php
 *
 * Die README erklaerte zur Anbindung des Kontaktformulars ein
 * INSERT INTO leads_inbox - also aus der Website heraus direkt in die
 * Panel-Datenbank zu schreiben. Das setzt voraus, dass beide auf
 * derselben Maschine liegen, und verteilt die Zugangsdaten auf ein
 * zweites Projekt.
 *
 * Die heikelste Stelle ist nicht das Schreiben, sondern das
 * Zurueckweisen: eine Schnittstelle, die ohne eingerichteten Schluessel
 * durchlaesst, waere ein offener Schreibzugang auf jeder Installation,
 * die nichts eingestellt hat.
 *
 * Geprueft wird die Entscheidungsschicht (includes/api_leads.php), nicht
 * api/leads.php selbst - die braucht HTTP.
 */

require_once __DIR__ . '/lib_sqlite_mirror.php';

$EINSTELLUNGEN = [];
if (!function_exists('setting')) {
    function setting(string $key, string $default = ''): string {
        global $EINSTELLUNGEN;
        return $EINSTELLUNGEN[$key] ?? $default;
    }
}

require_once __DIR__ . '/../includes/api_keys.php';
require_once __DIR__ . '/../includes/api_leads.php';

$wurzel = dirname(__DIR__);
$pdo = new SqliteSpiegelPDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('PRAGMA foreign_keys = ON');
foreach (nach_sqlite(file_get_contents($wurzel . '/install/schema.sql')) as $anweisung) {
    $pdo->exec($anweisung);
}

$checks = [];

// =====================================================================
// Der Schluessel
// =====================================================================
// Kein Schluessel eingerichtet heisst: zu, nicht offen. Dasselbe
// Prinzip wie beim CRON_TOKEN.
$checks['ohne Einstellung ist der Schluessel leer'] = api_schluessel('leads') === '';

$EINSTELLUNGEN['api_key_leads'] = '  ';
$checks['Leerzeichen zaehlen als leer'] = api_schluessel('leads') === '';

$erzeugt = api_schluessel_erzeugen();
$checks['erzeugter Schluessel ist 48 Hexzeichen'] = preg_match('/^[0-9a-f]{48}$/', $erzeugt) === 1;
$checks['zwei Aufrufe geben verschiedene']        = api_schluessel_erzeugen() !== $erzeugt;

$EINSTELLUNGEN['api_key_leads'] = $erzeugt;
$checks['der eingestellte kommt zurueck'] = api_schluessel('leads') === $erzeugt;

// --- Aus der Anfrage lesen ---------------------------------------------
$checks['X-Api-Key wird gelesen']
    = api_schluessel_aus_anfrage(['HTTP_X_API_KEY' => 'abc123']) === 'abc123';
$checks['Bearer wird gelesen']
    = api_schluessel_aus_anfrage(['HTTP_AUTHORIZATION' => 'Bearer abc123']) === 'abc123';
$checks['Bearer ist unabhaengig von der Schreibweise']
    = api_schluessel_aus_anfrage(['HTTP_AUTHORIZATION' => 'bearer abc123']) === 'abc123';
$checks['X-Api-Key hat Vorrang']
    = api_schluessel_aus_anfrage(['HTTP_X_API_KEY' => 'eins', 'HTTP_AUTHORIZATION' => 'Bearer zwei']) === 'eins';
$checks['ohne Header nichts']       = api_schluessel_aus_anfrage([]) === '';
// Basic ist kein Bearer - eine Anmeldung mit Benutzername gilt hier nicht.
$checks['Basic wird nicht gelesen']
    = api_schluessel_aus_anfrage(['HTTP_AUTHORIZATION' => 'Basic abc123']) === '';

// =====================================================================
// Der Rumpf
// =====================================================================
$json = '{"name":"Anna","email":"anna@example.com"}';
$checks['JSON wird gelesen']
    = api_rumpf($json, 'application/json', [])['name'] === 'Anna';
$checks['JSON mit Zeichensatz im Typ']
    = api_rumpf($json, 'application/json; charset=utf-8', [])['email'] === 'anna@example.com';
// Formularfelder, weil ein Formularanbieter oft so sendet.
$checks['Formularfelder werden gelesen']
    = api_rumpf('', 'application/x-www-form-urlencoded', ['name' => 'Bruno'])['name'] === 'Bruno';
// Kaputtes JSON gibt ein leeres Feld statt eines Fehlers - die Pruefung
// darunter beanstandet dann den fehlenden Namen, und das ist die
// verstaendlichere Antwort.
$checks['kaputtes JSON gibt leer'] = api_rumpf('{kaputt', 'application/json', []) === [];
$checks['JSON-Liste gibt eine Liste'] = api_rumpf('[1,2]', 'application/json', []) === [1, 2];

// =====================================================================
// Pruefen und saeubern
// =====================================================================
$gut = api_leads_pruefen([
    'name' => '  Anna Beispiel  ', 'email' => 'anna@example.com',
    'subject' => 'Anfrage', 'message' => 'Guten Tag', 'source' => 'Kontaktformular',
]);
$checks['gueltige Anfrage geht durch'] = $gut['ok'] === true;
$checks['Leerzeichen fallen weg']      = $gut['werte']['name'] === 'Anna Beispiel';
$checks['die Quelle wird uebernommen'] = $gut['werte']['source'] === 'Kontaktformular';

// Ohne Quelle steht 'API' da - sonst weiss man spaeter nicht, wo die
// Anfrage herkam.
$ohne_quelle = api_leads_pruefen(['name' => 'Anna', 'email' => 'a@example.com']);
$checks['ohne Quelle steht API'] = $ohne_quelle['werte']['source'] === 'API';

// --- Was fehlen darf und was nicht -------------------------------------
$checks['ohne Namen: abgelehnt']
    = api_leads_pruefen(['email' => 'a@example.com'])['ok'] === false;
$checks['nur Leerzeichen als Name: abgelehnt']
    = api_leads_pruefen(['name' => '   ', 'email' => 'a@example.com'])['ok'] === false;

// Ohne jede Rueckrufmoeglichkeit ist die Anfrage wertlos - man kann
// nicht antworten.
$checks['ohne Kontaktweg: abgelehnt']
    = api_leads_pruefen(['name' => 'Anna'])['ok'] === false;
$checks['Telefon allein genuegt']
    = api_leads_pruefen(['name' => 'Anna', 'phone' => '030 123456'])['ok'] === true;
$checks['E-Mail allein genuegt']
    = api_leads_pruefen(['name' => 'Anna', 'email' => 'a@example.com'])['ok'] === true;

$checks['unbrauchbare Adresse: abgelehnt']
    = api_leads_pruefen(['name' => 'Anna', 'email' => 'keine-adresse'])['ok'] === false;

// --- Der Honigtopf ------------------------------------------------------
// Ein Feld, das kein Mensch ausfuellt, weil es im Formular unsichtbar
// ist. Ein ausgefuelltes stammt von einem Skript.
$spam = api_leads_pruefen(['name' => 'Bot', 'email' => 'bot@example.com', 'website' => 'http://spam.example']);
$checks['ausgefuellter Honigtopf faellt auf'] = $spam['ok'] === false;
$checks['und ist als solcher erkennbar']      = $spam['fehler'] === ['spam'];
// Leer gelassen stoert er nicht - so kommt das Feld aus jedem Formular.
$checks['leerer Honigtopf stoert nicht']
    = api_leads_pruefen(['name' => 'Anna', 'email' => 'a@example.com', 'website' => ''])['ok'] === true;

// --- Laengen -----------------------------------------------------------
// Gekuerzt statt abgelehnt: eine zu lange Betreffzeile ist kein Grund,
// eine Kundenanfrage wegzuwerfen.
$lang = api_leads_pruefen([
    'name'    => str_repeat('A', 400),
    'email'   => 'a@example.com',
    'subject' => str_repeat('B', 400),
    'phone'   => str_repeat('9', 100),
    'message' => str_repeat('C', 9000),
    'source'  => str_repeat('D', 300),
]);
$checks['zu lang wird gekuerzt, nicht abgelehnt'] = $lang['ok'] === true;
$checks['Name auf 255']    = mb_strlen($lang['werte']['name']) === 255;
$checks['Betreff auf 255'] = mb_strlen($lang['werte']['subject']) === 255;
$checks['Telefon auf 50']  = mb_strlen($lang['werte']['phone']) === 50;
$checks['Nachricht auf 5000'] = mb_strlen($lang['werte']['message']) === 5000;
$checks['Quelle auf 100']  = mb_strlen($lang['werte']['source']) === 100;

// Leere Felder werden null, nicht "" - sonst stuende in der Liste eine
// leere Betreffzeile statt gar keiner.
$knapp = api_leads_pruefen(['name' => 'Anna', 'email' => 'a@example.com', 'subject' => '   ']);
$checks['leerer Betreff wird null'] = $knapp['werte']['subject'] === null;
$checks['fehlende Nachricht wird null'] = $knapp['werte']['message'] === null;

// =====================================================================
// Speichern
// =====================================================================
$id = api_leads_speichern($pdo, $gut['werte']);
$checks['der Eintrag entsteht'] = $id > 0;

$zeile = $pdo->query("SELECT * FROM leads_inbox WHERE id = $id")->fetch(PDO::FETCH_ASSOC);
$checks['der Name steht drin']     = $zeile['name'] === 'Anna Beispiel';
$checks['die Adresse steht drin']  = $zeile['email'] === 'anna@example.com';
$checks['die Quelle steht drin']   = $zeile['source'] === 'Kontaktformular';
$checks['der Zeitpunkt wird gesetzt'] = !empty($zeile['created_at']);

// =====================================================================
// Die Bremse
// =====================================================================
// Ohne sie liesse sich der Eingang fluten - und jede Anfrage erzeugt
// ein Abzeichen in der Seitenleiste.
$ip = '203.0.113.99';
$checks['zu Beginn frei'] = api_zu_haeufig($pdo, $ip, 'API_LEAD', API_LEADS_MAX_PRO_STUNDE) === false;

$log = $pdo->prepare("INSERT INTO logs (action_type, description, ip) VALUES ('API_LEAD', 'Test', ?)");
for ($i = 0; $i < API_LEADS_MAX_PRO_STUNDE - 1; $i++) {
    $log->execute([$ip]);
}
$checks['knapp darunter noch frei'] = api_zu_haeufig($pdo, $ip, 'API_LEAD', API_LEADS_MAX_PRO_STUNDE) === false;
$log->execute([$ip]);
$checks['an der Grenze gebremst']   = api_zu_haeufig($pdo, $ip, 'API_LEAD', API_LEADS_MAX_PRO_STUNDE) === true;
$checks['andere IP bleibt frei']    = api_zu_haeufig($pdo, '198.51.100.1', 'API_LEAD', API_LEADS_MAX_PRO_STUNDE) === false;

$pdo->exec("UPDATE logs SET created_at = '2020-01-01 00:00:00' WHERE action_type = 'API_LEAD'");
$checks['alte Anfragen zaehlen nicht'] = api_zu_haeufig($pdo, $ip, 'API_LEAD', API_LEADS_MAX_PRO_STUNDE) === false;

// =====================================================================
// Ergebnis
// =====================================================================
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
