<?php
/**
 * Test fuer die Zugriffspruefung der Dateiauslieferung.
 * Aufruf: php tools/test_file_access.php
 *
 * Bis hierher lagen Rechnungen, Angebote, Projektdateien und
 * Wiki-Anhaenge unter uploads/ und wurden vom Webserver an jeden
 * ausgeliefert, der den Pfad kannte. Bei Rechnungen war der Pfad nicht
 * einmal zu raten: "uploads/invoices/Rechnung_RE-2026-001.pdf" laesst
 * sich durchzaehlen. Jetzt entscheidet datei_zugriff(), wer was bekommt.
 *
 * Diese Funktion ist die einzige Stelle, die zwischen einer fremden und
 * einer eigenen Rechnung unterscheidet - ein Fehler darin gibt Kunden
 * die Unterlagen anderer Kunden. Deshalb laeuft der Test gegen den
 * SQLite-Spiegel von install/schema.sql, also gegen die echten Tabellen
 * samt Fremdschluesseln.
 */

require_once __DIR__ . '/lib_sqlite_mirror.php';
require_once __DIR__ . '/../includes/file_access.php';

$wurzel = dirname(__DIR__);
$pdo = new SqliteSpiegelPDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('PRAGMA foreign_keys = ON');
foreach (nach_sqlite(file_get_contents($wurzel . '/install/schema.sql')) as $anweisung) {
    $pdo->exec($anweisung);
}

// --- Ausgangslage -----------------------------------------------------
// Anna ist Kundin mit einem Projekt, Bruno ist Beteiligter an demselben
// Projekt, Carla hat mit beidem nichts zu tun.
$kontakt = [];
$ins = $pdo->prepare("INSERT INTO contacts (name, email, contact_type) VALUES (?, ?, 'Kunde')");
foreach (['Anna', 'Bruno', 'Carla'] as $n) {
    $ins->execute([$n, strtolower($n) . '@example.test']);
    $kontakt[$n] = (int) $pdo->lastInsertId();
}

$pdo->prepare("INSERT INTO tasks (title, status, contact_id) VALUES ('Webseite', 'Offen', ?)")
    ->execute([$kontakt['Anna']]);
$projekt = (int) $pdo->lastInsertId();
$tc = $pdo->prepare("INSERT INTO task_contacts (task_id, contact_id, role) VALUES (?, ?, ?)");
$tc->execute([$projekt, $kontakt['Anna'], 'owner']);
$tc->execute([$projekt, $kontakt['Bruno'], 'member']);

$pdo->prepare("INSERT INTO client_assets (task_id, file_name, file_path) VALUES (?, 'Entwurf.pdf', 'uploads/client_assets/1_entwurf.pdf')")
    ->execute([$projekt]);
$asset = (int) $pdo->lastInsertId();

$fin = $pdo->prepare("INSERT INTO finances (type, title, contact_id, amount, invoice_number, invoice_pdf_path)
                      VALUES ('INCOME', ?, ?, 100, ?, ?)");
$fin->execute(['Rechnung Anna', $kontakt['Anna'], 'RE-2026-001', 'uploads/invoices/Rechnung_RE-2026-001.pdf']);
$rechnung_anna = (int) $pdo->lastInsertId();
$fin->execute(['Rechnung Carla', $kontakt['Carla'], 'RE-2026-002', 'uploads/invoices/Rechnung_RE-2026-002.pdf']);
$rechnung_carla = (int) $pdo->lastInsertId();

$ang = $pdo->prepare("INSERT INTO quotes (quote_number, contact_id, status, items, total_amount, quote_pdf_path)
                      VALUES (?, ?, ?, '[]', 100, ?)");
$ang->execute(['ANG-2026-001', $kontakt['Anna'], 'Gesendet', 'uploads/quotes/Angebot_ANG-2026-001.pdf']);
$angebot_gesendet = (int) $pdo->lastInsertId();
$ang->execute(['ANG-2026-002', $kontakt['Anna'], 'Entwurf', 'uploads/quotes/Angebot_ANG-2026-002.pdf']);
$angebot_entwurf = (int) $pdo->lastInsertId();

$pdo->exec("INSERT INTO wiki_articles (title, content) VALUES ('Handbuch', 'Text')");
$artikel_geteilt = (int) $pdo->lastInsertId();
$pdo->exec("INSERT INTO wiki_articles (title, content) VALUES ('Intern', 'Text')");
$artikel_intern = (int) $pdo->lastInsertId();
$pdo->prepare("INSERT INTO wiki_client_shares (article_id, contact_id) VALUES (?, ?)")
    ->execute([$artikel_geteilt, $kontakt['Anna']]);
$wa = $pdo->prepare("INSERT INTO wiki_attachments (article_id, file_name, file_path) VALUES (?, ?, ?)");
$wa->execute([$artikel_geteilt, 'Handbuch.pdf', 'uploads/wiki/1_handbuch.pdf']);
$anhang_geteilt = (int) $pdo->lastInsertId();
$wa->execute([$artikel_intern, 'Intern.pdf', 'uploads/wiki/1_intern.pdf']);
$anhang_intern = (int) $pdo->lastInsertId();

$checks = [];

// --- Der Verwalter bekommt alles --------------------------------------
$t = datei_zugriff($pdo, 'asset', $asset, null);
$checks['Verwalter bekommt eine Projektdatei']
    = is_array($t) && $t['pfad'] === 'uploads/client_assets/1_entwurf.pdf' && $t['name'] === 'Entwurf.pdf';
$checks['Verwalter bekommt jede Rechnung']
    = is_array(datei_zugriff($pdo, 'invoice', $rechnung_carla, null));
$checks['Verwalter bekommt einen Angebotsentwurf']
    = is_array(datei_zugriff($pdo, 'quote', $angebot_entwurf, null));
$checks['Verwalter bekommt einen ungeteilten Wiki-Anhang']
    = is_array(datei_zugriff($pdo, 'wiki', $anhang_intern, null));

// --- Projektdateien: Mitgliedschaft entscheidet ------------------------
$checks['Hauptkontakt bekommt die Datei seines Projekts']
    = is_array(datei_zugriff($pdo, 'asset', $asset, $kontakt['Anna']));
$checks['Beteiligter bekommt sie ebenfalls']
    = is_array(datei_zugriff($pdo, 'asset', $asset, $kontakt['Bruno']));
$checks['Unbeteiligter bekommt sie nicht']
    = datei_zugriff($pdo, 'asset', $asset, $kontakt['Carla']) === null;

// --- Rechnungen: nur die eigenen --------------------------------------
$checks['Kunde bekommt seine eigene Rechnung']
    = is_array(datei_zugriff($pdo, 'invoice', $rechnung_anna, $kontakt['Anna']));
$checks['Kunde bekommt die Rechnung eines anderen nicht']
    = datei_zugriff($pdo, 'invoice', $rechnung_carla, $kontakt['Anna']) === null;

// --- Angebote: Entwuerfe gehen den Empfaenger nichts an ----------------
$checks['Kunde bekommt sein gesendetes Angebot']
    = is_array(datei_zugriff($pdo, 'quote', $angebot_gesendet, $kontakt['Anna']));
$checks['Kunde bekommt den Entwurf nicht']
    = datei_zugriff($pdo, 'quote', $angebot_entwurf, $kontakt['Anna']) === null;

// --- Wiki: nur ausdruecklich freigegebene Artikel ----------------------
$checks['Kunde bekommt den freigegebenen Anhang']
    = is_array(datei_zugriff($pdo, 'wiki', $anhang_geteilt, $kontakt['Anna']));
$checks['Kunde bekommt den nicht freigegebenen Anhang nicht']
    = datei_zugriff($pdo, 'wiki', $anhang_intern, $kontakt['Anna']) === null;
$checks['Fremder bekommt auch den freigegebenen Anhang nicht']
    = datei_zugriff($pdo, 'wiki', $anhang_geteilt, $kontakt['Carla']) === null;

// --- Geloeschtes bleibt dem Kunden verborgen ---------------------------
// Der Verwalter muss weiter herankommen: der Papierkorb stellt wieder
// her, und dazu gehoert die Datei.
$pdo->exec("UPDATE finances SET deleted_at = '2026-01-01 00:00:00' WHERE id = $rechnung_anna");
$checks['Kunde bekommt eine geloeschte Rechnung nicht']
    = datei_zugriff($pdo, 'invoice', $rechnung_anna, $kontakt['Anna']) === null;
$checks['Verwalter bekommt sie noch']
    = is_array(datei_zugriff($pdo, 'invoice', $rechnung_anna, null));

// --- Unbekanntes ------------------------------------------------------
// ── Belege zu Ausgaben ───────────────────────────────────────────────
// Die wichtigste Zusage dieses Typs ist eine Verneinung: ein
// Ausgabenbeleg ist die Rechnung eines Dritten an DICH - Hostinganbieter,
// Versicherung, Bahn. Er geht keinen Kunden etwas an, auch nicht den,
// dem die Ausgabe zugeordnet ist. Deshalb wird hier eine Ausgabe
// ausdruecklich einem Kontakt zugeordnet und dann geprueft, dass genau
// dieser Kontakt sie trotzdem nicht bekommt.
$aus = $pdo->prepare(
    "INSERT INTO finances (type, title, contact_id, amount, receipt_path)
     VALUES ('EXPENSE', ?, ?, 29.00, ?)"
);
$aus->execute(['Serverkosten', $kontakt['Anna'], 'uploads/receipts/1780000000_Serverrechnung.pdf']);
$beleg_anna = (int) $pdo->lastInsertId();

$checks['Verwalter bekommt den Beleg']
    = is_array(datei_zugriff($pdo, 'receipt', $beleg_anna, null));
$checks['der zugeordnete Kunde bekommt ihn NICHT']
    = datei_zugriff($pdo, 'receipt', $beleg_anna, $kontakt['Anna']) === null;
$checks['ein anderer Kunde erst recht nicht']
    = datei_zugriff($pdo, 'receipt', $beleg_anna, $kontakt['Carla']) === null;

// Eine Ausgabe ohne Beleg hat nichts auszuliefern.
$aus->execute(['Kaffee', null, null]);
$checks['Ausgabe ohne Beleg gibt nichts heraus']
    = datei_zugriff($pdo, 'receipt', (int) $pdo->lastInsertId(), null) === null;

// Auch fuer Belege gilt die Pfadschranke.
$pdo->exec("UPDATE finances SET receipt_path = '../../.env' WHERE id = $beleg_anna");
$checks['Beleg ausserhalb von uploads wird abgewiesen']
    = datei_zugriff($pdo, 'receipt', $beleg_anna, null) === null;

$checks['unbekannte Art wird abgewiesen']
    = datei_zugriff($pdo, 'passwort', 1, null) === null;
$checks['unbekannte Kennung wird abgewiesen']
    = datei_zugriff($pdo, 'invoice', 99999, null) === null;

// --- Pfadausbruch -----------------------------------------------------
// Der Pfad kommt aus der Datenbank, nicht aus der Anfrage - aber er ist
// dort einmal als Text gelandet und soll auch dann nicht aus uploads/
// herausfuehren, wenn ihn jemand veraendert hat.
$pdo->exec("UPDATE finances SET invoice_pdf_path = '../.env' WHERE id = $rechnung_carla");
$checks['Pfad ausserhalb von uploads wird abgewiesen']
    = datei_zugriff($pdo, 'invoice', $rechnung_carla, null) === null;
$pdo->exec("UPDATE finances SET invoice_pdf_path = 'uploads/invoices/../../config.php' WHERE id = $rechnung_carla");
$checks['Pfad mit Rueckschritt wird abgewiesen']
    = datei_zugriff($pdo, 'invoice', $rechnung_carla, null) === null;
$pdo->exec("UPDATE finances SET invoice_pdf_path = '' WHERE id = $rechnung_carla");
$checks['leerer Pfad wird abgewiesen']
    = datei_zugriff($pdo, 'invoice', $rechnung_carla, null) === null;

// --- Auslieferung: was darf im Browser aufgehen? ----------------------
// Alles, was der Browser als HTML deuten koennte, muss als Download
// herausgehen. Es kaeme sonst aus demselben Origin wie das Panel - ein
// Skript darin liefe mit der Sitzung des Angemeldeten.
$pdf = datei_auslieferung('Rechnung.pdf');
$checks['PDF geht im Browser auf']
    = $pdf['typ'] === 'application/pdf' && $pdf['disposition'] === 'inline';
$checks['Bild geht im Browser auf']
    = datei_auslieferung('Foto.png')['disposition'] === 'inline';
$checks['Endung wird unabhaengig von Gross-/Kleinschreibung erkannt']
    = datei_auslieferung('RECHNUNG.PDF')['disposition'] === 'inline';

$svg = datei_auslieferung('logo.svg');
$checks['SVG wird zum Download gezwungen']
    = $svg['disposition'] === 'attachment' && $svg['typ'] === 'application/octet-stream';
$html = datei_auslieferung('seite.html');
$checks['HTML wird zum Download gezwungen']
    = $html['disposition'] === 'attachment' && $html['typ'] === 'application/octet-stream';
$checks['die letzte Endung entscheidet']
    = datei_auslieferung('Rechnung.pdf.html')['disposition'] === 'attachment';
$checks['Datei ohne Endung wird zum Download']
    = datei_auslieferung('LIESMICH')['disposition'] === 'attachment';
$checks['Office-Datei wird zum Download']
    = datei_auslieferung('Vertrag.docx')['disposition'] === 'attachment';

$checks['Download laesst sich erzwingen']
    = datei_auslieferung('Rechnung.pdf', true)['disposition'] === 'attachment';
$checks['erzwungener Download behaelt den Typ']
    = datei_auslieferung('Rechnung.pdf', true)['typ'] === 'application/pdf';

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
