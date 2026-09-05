<?php
/**
 * Test fuer die Datenauskunft (Art. 15 DSGVO).
 * Aufruf: php tools/test_gdpr.php
 *
 * Eine Auskunft, in der ein Bereich fehlt, ist schlimmer als keine: sie
 * sieht vollstaendig aus. Geprueft wird deshalb vor allem, dass jede
 * Beziehung wirklich gefunden wird - auch die drei, die nicht
 * contact_id heissen (author_contact_id, uploaded_by_contact_id) und
 * der Weg ueber die E-Mail-Adresse in den Posteingang.
 *
 * Und die Gegenrichtung: die Daten einer ZWEITEN Person duerfen nicht
 * mitkommen. Eine Auskunft, die fremde Daten enthaelt, ist nicht nur
 * falsch, sie ist selbst eine Datenpanne.
 */

require_once __DIR__ . '/lib_sqlite_mirror.php';
require_once __DIR__ . '/../includes/gdpr.php';

$wurzel = dirname(__DIR__);
$pdo = new SqliteSpiegelPDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('PRAGMA foreign_keys = ON');
foreach (nach_sqlite(file_get_contents($wurzel . '/install/schema.sql')) as $a) {
    $pdo->exec($a);
}

$checks = [];

// =====================================================================
// Zwei Personen, damit sich Vermischung zeigt
// =====================================================================
$pdo->exec("INSERT INTO contacts (name, email, company) VALUES ('Lena Hofmann', 'lena@example.com', 'Hofmann & Partner')");
$lena = (int) $pdo->lastInsertId();
$pdo->exec("INSERT INTO contacts (name, email) VALUES ('Marco Brandt', 'marco@example.com')");
$marco = (int) $pdo->lastInsertId();

// Ein Projekt je Person.
$pdo->exec("INSERT INTO tasks (title, contact_id) VALUES ('Relaunch Lena', $lena)");
$t_lena = (int) $pdo->lastInsertId();
$pdo->exec("INSERT INTO tasks (title, contact_id) VALUES ('Shop Marco', $marco)");
$t_marco = (int) $pdo->lastInsertId();

// Die Beziehungen, die nicht contact_id heissen.
$pdo->exec("INSERT INTO project_comments (task_id, author_contact_id, author_name, message)
            VALUES ($t_marco, $lena, 'Lena Hofmann', 'Beitrag im fremden Projekt')");
$pdo->exec("INSERT INTO client_assets (task_id, file_name, file_path, uploaded_by, uploaded_by_contact_id, uploaded_by_name)
            VALUES ($t_lena, 'Datei.pdf', 'uploads/client_assets/Datei.pdf', 'client', $lena, 'Lena Hofmann')");
$pdo->exec("INSERT INTO task_contacts (task_id, contact_id, role) VALUES ($t_marco, $lena, 'Mitwirkung')");

// Geld, Angebote, Anfragen.
$pdo->exec("INSERT INTO finances (type, title, contact_id, amount, status, record_date)
            VALUES ('INCOME', 'Rechnung Lena', $lena, 100, 'Offen', '2026-01-01')");
$pdo->exec("INSERT INTO finances (type, title, contact_id, amount, status, record_date)
            VALUES ('INCOME', 'Rechnung Marco', $marco, 200, 'Offen', '2026-01-02')");
$pdo->exec("INSERT INTO quotes (quote_number, items, total_amount, contact_id) VALUES ('ANG-1', '[]', 500, $lena)");
$pdo->exec("INSERT INTO support_tickets (subject, message, contact_id) VALUES ('Frage', 'Text', $lena)");

// Der Weg ueber die Adresse: eine Anfrage, bevor daraus ein Kontakt wurde.
$pdo->exec("INSERT INTO leads_inbox (name, email, subject, message) VALUES ('Lena H.', 'lena@example.com', 'Erstanfrage', 'Hallo')");
$pdo->exec("INSERT INTO leads_inbox (name, email, subject, message) VALUES ('Marco B.', 'marco@example.com', 'Andere', 'Text')");

// Termin und Wiki-Freigabe.
$pdo->exec("INSERT INTO calendar_events (title, event_date) VALUES ('Jour fixe', '2026-03-01')");
$ev = (int) $pdo->lastInsertId();
$pdo->exec("INSERT INTO event_contacts (event_id, contact_id, invite_token) VALUES ($ev, $lena, 'tok1')");
$pdo->exec("INSERT INTO wiki_articles (title, content) VALUES ('Anleitung', 'Inhalt')");
$art = (int) $pdo->lastInsertId();
$pdo->exec("INSERT INTO wiki_client_shares (article_id, contact_id) VALUES ($art, $lena)");

// =====================================================================
// Die Auskunft
// =====================================================================
$daten = auskunft_daten($pdo, $lena);

$checks['Auskunft entsteht']       = is_array($daten);
$checks['Name steht darin']        = ($daten['kontakt']['name'] ?? '') === 'Lena Hofmann';
$checks['Hinweis zum Protokoll']   = strpos($daten['hinweis'] ?? '', 'Systemprotokoll') !== false;
$checks['alle Bereiche vorhanden'] = count($daten['bereiche']) === count(auskunft_abfragen());

$b = $daten['bereiche'];
$checks['Stammdaten dabei']        = $b['stammdaten']['anzahl'] === 1;
$checks['Projekt dabei']           = $b['projekte']['anzahl'] === 1;
$checks['Beteiligung dabei']       = $b['projektbeteiligung']['anzahl'] === 1;
$checks['Beitrag dabei']           = $b['projektbeitraege']['anzahl'] === 1;
$checks['Datei dabei']             = $b['dateien']['anzahl'] === 1;
$checks['Rechnung dabei']          = $b['rechnungen']['anzahl'] === 1;
$checks['Angebot dabei']           = $b['angebote']['anzahl'] === 1;
$checks['Anfrage dabei']           = $b['anfragen']['anzahl'] === 1;
$checks['Termin dabei']            = $b['termine']['anzahl'] === 1;
$checks['Wiki-Freigabe dabei']     = $b['wissen']['anzahl'] === 1;
$checks['Posteingang ueber Mail']  = $b['posteingang']['anzahl'] === 1;
$checks['Umfang stimmt']           = auskunft_umfang($daten) === 11;

// =====================================================================
// Nichts Fremdes
// =====================================================================
$json = auskunft_json($daten);
// 'Shop Marco' steht sehr wohl darin, und das ist richtig: Lena ist
// an diesem Projekt beteiligt und hat dort einen Beitrag geschrieben.
// Ihre Beteiligung ist ihr Datum, und ohne den Titel waere die
// Angabe wertlos. Nicht enthalten sein darf, was allein Marco
// betrifft - dafuer die drei Pruefungen darunter.
$checks['eigene Beteiligung mit Titel'] = strpos($json, 'Shop Marco') !== false;
$checks['fremdes Projekt nicht als eigenes'] = $b['projekte']['anzahl'] === 1
    && ($b['projekte']['zeilen'][0]['title'] ?? '') === 'Relaunch Lena';
$checks['fremde Rechnung nicht drin']  = strpos($json, 'Rechnung Marco') === false;
$checks['fremde Anfrage nicht drin']   = strpos($json, 'Andere') === false;
$checks['fremde Adresse nicht drin']   = strpos($json, 'marco@example.com') === false;
// Der Beitrag im fremden Projekt gehoert dagegen dazu - er stammt von ihr.
$checks['eigener Beitrag ist drin']    = strpos($json, 'Beitrag im fremden Projekt') !== false;

$checks['JSON ist gueltig']            = json_decode($json, true) !== null;
$checks['Umlaute bleiben lesbar']      = strpos($json, 'Hofmann & Partner') !== false;

// =====================================================================
// Randfaelle
// =====================================================================
$checks['unbekannter Kontakt: null'] = auskunft_daten($pdo, 99999) === null;

// Ein Kontakt ohne Adresse darf nicht jeden Posteingangs-Eintrag ohne
// Adresse einsammeln - eine Abfrage mit leerem Wert traefe genau die.
$pdo->exec("INSERT INTO leads_inbox (name, subject, message) VALUES ('Ohne Mail', 'X', 'Y')");
$pdo->exec("INSERT INTO contacts (name) VALUES ('Ohne Adresse')");
$ohne = (int) $pdo->lastInsertId();
$d2 = auskunft_daten($pdo, $ohne);
$checks['ohne Adresse kein Posteingang'] = $d2['bereiche']['posteingang']['anzahl'] === 0;
$checks['und der Grund steht dabei']     = isset($d2['bereiche']['posteingang']['hinweis']);
$checks['ohne Adresse nur Stammdaten']   = auskunft_umfang($d2) === 1;

// Der Dateiname traegt den Namen, aber nichts, was einen Pfad ergaebe.
$name = auskunft_dateiname($daten);
$checks['Dateiname nennt die Person'] = strpos($name, 'Lena_Hofmann') !== false;
$checks['Dateiname ohne Pfad']        = strpos($name, '/') === false && strpos($name, '\\') === false;
$checks['Dateiname endet auf json']   = substr($name, -5) === '.json';

$boese = auskunft_dateiname(['kontakt' => ['name' => '../../etc/passwd']]);
$checks['Rueckschritt im Namen weg']  = strpos($boese, '..') === false && strpos($boese, '/') === false;
$checks['leerer Name faellt zurueck'] = strpos(auskunft_dateiname(['kontakt' => ['name' => '']]), 'Kontakt') !== false;

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
