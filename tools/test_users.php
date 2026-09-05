<?php
/**
 * Test fuer Benutzer und Rollen.
 * Aufruf: php tools/test_users.php
 *
 * Die README versprach ein Panel "for freelancers and small agencies".
 * users hatte vier Spalten - kein Name, keine Rolle, kein Zustand - und
 * eine Oberflaeche zum Anlegen eines zweiten Benutzers gab es nicht.
 *
 * Zwei Stellen entscheiden hier ueber mehr als Bequemlichkeit:
 *
 *  - Eine Seite, die in der Rechteliste FEHLT, muss gesperrt sein und
 *    nicht offen. Eine vergessene Seite, die niemand erreicht, faellt
 *    beim ersten Aufruf auf; eine vergessene Seite, die jeder sieht,
 *    faellt vielleicht nie auf.
 *  - Der letzte Verwalter darf sich nicht selbst entfernen. Sonst
 *    bleibt eine Installation ohne jemanden, der Benutzer anlegen kann,
 *    und der Weg zurueck fuehrt nur ueber die Datenbank.
 */

require_once __DIR__ . '/lib_sqlite_mirror.php';
require_once __DIR__ . '/../includes/users.php';

$wurzel = dirname(__DIR__);
$pdo = new SqliteSpiegelPDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('PRAGMA foreign_keys = ON');
foreach (nach_sqlite(file_get_contents($wurzel . '/install/schema.sql')) as $anweisung) {
    $pdo->exec($anweisung);
}

$checks = [];

// =====================================================================
// Die Rollen
// =====================================================================
$checks['drei Rollen']            = count(rollen()) === 3;
$checks['admin ist gueltig']      = rolle_gueltig('admin') === true;
$checks['staff ist gueltig']      = rolle_gueltig('staff') === true;
$checks['accounting ist gueltig'] = rolle_gueltig('accounting') === true;
$checks['erfundene Rolle nicht']  = rolle_gueltig('chef') === false;
$checks['leere Rolle nicht']      = rolle_gueltig('') === false;

// Jede Rolle hat eine Bezeichnung und eine Erklaerung - beides steht in
// der Oberflaeche.
$vollstaendig = true;
foreach (rollen() as $r) {
    if (trim($r['label']) === '' || trim($r['hint']) === '') { $vollstaendig = false; }
}
$checks['jede Rolle ist beschrieben'] = $vollstaendig;

// =====================================================================
// Wer darf was
// =====================================================================
// Die Verwaltung darf alles - auch eine Seite, die niemand eingetragen
// hat. Sonst sperrte eine neue Seite zuerst denjenigen aus, der sie
// freischalten muesste.
$checks['Verwaltung darf die Einstellungen'] = seite_erlaubt('admin', 'settings.php') === true;
$checks['Verwaltung darf die Finanzen']      = seite_erlaubt('admin', 'finances.php') === true;
$checks['Verwaltung darf Unbekanntes']       = seite_erlaubt('admin', 'gibtesnicht.php') === true;

// Mitarbeit: Projekte ja, Geld nein.
$checks['Mitarbeit darf Projekte']      = seite_erlaubt('staff', 'tasks.php') === true;
$checks['Mitarbeit darf das Board']     = seite_erlaubt('staff', 'board.php') === true;
$checks['Mitarbeit darf Tickets']       = seite_erlaubt('staff', 'tickets.php') === true;
$checks['Mitarbeit darf das Wiki']      = seite_erlaubt('staff', 'wiki.php') === true;
$checks['Mitarbeit darf Kontakte']      = seite_erlaubt('staff', 'contacts.php') === true;
$checks['Mitarbeit darf den Kalender']  = seite_erlaubt('staff', 'calendar.php') === true;
$checks['Mitarbeit darf das Dashboard'] = seite_erlaubt('staff', 'index.php') === true;

$checks['Mitarbeit darf KEINE Finanzen']      = seite_erlaubt('staff', 'finances.php') === false;
$checks['Mitarbeit darf KEINE Angebote']      = seite_erlaubt('staff', 'quotes.php') === false;
$checks['Mitarbeit darf KEINE Rechnungen']    = seite_erlaubt('staff', 'invoice.php') === false;
$checks['Mitarbeit darf KEINE Auswertungen']  = seite_erlaubt('staff', 'reports.php') === false;
$checks['Mitarbeit darf KEINE Einstellungen'] = seite_erlaubt('staff', 'settings.php') === false;
$checks['Mitarbeit darf KEIN Protokoll']      = seite_erlaubt('staff', 'systemlogs.php') === false;
$checks['Mitarbeit darf KEINEN Papierkorb']   = seite_erlaubt('staff', 'trash.php') === false;

// Buchhaltung: Geld ja, Projekte nein.
$checks['Buchhaltung darf Finanzen']       = seite_erlaubt('accounting', 'finances.php') === true;
$checks['Buchhaltung darf Angebote']       = seite_erlaubt('accounting', 'quotes.php') === true;
$checks['Buchhaltung darf Auswertungen']   = seite_erlaubt('accounting', 'reports.php') === true;
$checks['Buchhaltung darf Kontakte']       = seite_erlaubt('accounting', 'contacts.php') === true;

$checks['Buchhaltung darf KEINE Projekte']      = seite_erlaubt('accounting', 'tasks.php') === false;
$checks['Buchhaltung darf KEIN Board']          = seite_erlaubt('accounting', 'board.php') === false;
$checks['Buchhaltung darf KEINE Tickets']       = seite_erlaubt('accounting', 'tickets.php') === false;
$checks['Buchhaltung darf KEIN Wiki']           = seite_erlaubt('accounting', 'wiki.php') === false;
$checks['Buchhaltung darf KEINE Einstellungen'] = seite_erlaubt('accounting', 'settings.php') === false;

// Der Punkt, auf den es ankommt: was nicht eingetragen ist, ist zu.
$checks['unbekannte Seite ist fuer Mitarbeit zu']
    = seite_erlaubt('staff', 'gibtesnicht.php') === false;
$checks['unbekannte Seite ist fuer Buchhaltung zu']
    = seite_erlaubt('accounting', 'gibtesnicht.php') === false;
// Auch eine erfundene Rolle kommt nirgends hin.
$checks['erfundene Rolle darf nichts'] = seite_erlaubt('chef', 'index.php') === false;

// Jede Seite im Wurzelverzeichnis, die durch auth.php laeuft, sollte in
// der Liste stehen - sonst ist sie fuer alle ausser der Verwaltung
// gesperrt, und das faellt erst im Betrieb auf.
$ungelistet = [];
foreach (glob($wurzel . '/*.php') ?: [] as $pfad) {
    $name = basename($pfad);
    // Auf die require-Zeile geprueft, nicht auf die Erwaehnung: file.php
    // nennt includes/auth.php nur, um zu erklaeren, warum es die Datei
    // gerade NICHT einbindet.
    if (!preg_match('~require(_once)?\s+.{0,20}includes/auth\.php~', file_get_contents($pfad))) {
        continue;   // laeuft nicht durch den Riegel
    }
    if (!isset(seitenrechte()[$name])) {
        $ungelistet[] = $name;
    }
}
if ($ungelistet) {
    echo "  Nicht in seitenrechte(): " . implode(', ', $ungelistet) . "\n";
    echo "  Sie sind damit nur fuer die Verwaltung erreichbar.\n";
}
$checks['jede geschuetzte Seite ist eingeordnet'] = $ungelistet === [];

// =====================================================================
// Benutzer anlegen
// =====================================================================
$pdo->exec("INSERT INTO users (email, password_hash, name, role, is_active) VALUES ('chef@example.com', 'x', 'Chefin', 'admin', 1)");
$chef = (int) $pdo->lastInsertId();

$e = benutzer_anlegen($pdo, 'anna@example.com', 'Anna Beispiel', 'staff');
$checks['Anlegen meldet Erfolg'] = $e['ok'] === true && $e['id'] > 0;
$anna = $e['id'];

$u = benutzer($pdo, $anna);
$checks['der Name steht drin']   = $u['name'] === 'Anna Beispiel';
$checks['die Rolle steht drin']  = $u['role'] === 'staff';
$checks['er ist aktiv']          = (int) $u['is_active'] === 1;
// Kein Passwort: der Weg hinein fuehrt ueber "Passwort vergessen". Der
// Platzhalter darf zu keinem waehlbaren Passwort passen.
$checks['ein Platzhalter statt eines Passworts'] = !password_verify('', (string) $u['password_hash'])
                                                && !password_verify('passwort', (string) $u['password_hash']);

$checks['doppelte Adresse wird abgewiesen']
    = benutzer_anlegen($pdo, 'anna@example.com', 'Zweite', 'staff')['ok'] === false;
$checks['unbrauchbare Adresse wird abgewiesen']
    = benutzer_anlegen($pdo, 'keine-adresse', 'X', 'staff')['ok'] === false;
$checks['erfundene Rolle wird abgewiesen']
    = benutzer_anlegen($pdo, 'neu@example.com', 'X', 'chef')['ok'] === false;

// =====================================================================
// Aendern
// =====================================================================
$checks['Rolle laesst sich aendern'] = benutzer_aendern($pdo, $anna, 'Anna B.', 'accounting')['ok'] === true;
$u = benutzer($pdo, $anna);
$checks['die neue Rolle steht drin'] = $u['role'] === 'accounting';
$checks['der neue Name auch']        = $u['name'] === 'Anna B.';

$checks['unbekannte Rolle wird abgewiesen'] = benutzer_aendern($pdo, $anna, 'X', 'chef')['ok'] === false;
$checks['unbekannter Benutzer ebenso']      = benutzer_aendern($pdo, 99999, 'X', 'staff')['ok'] === false;

// =====================================================================
// Der letzte Verwalter
// =====================================================================
// Die einzige Regel, die dieses Modell unbedingt braucht.
$checks['die Chefin ist die letzte Verwaltung'] = letzter_verwalter($pdo, $chef) === true;
$checks['Anna ist es nicht']                    = letzter_verwalter($pdo, $anna) === false;

$e = benutzer_aendern($pdo, $chef, 'Chefin', 'staff');
$checks['sie kann sich nicht herabstufen'] = $e['ok'] === false;
$checks['und erfaehrt warum']              = strpos($e['fehler'], 'letzte Verwalter') !== false;
$checks['ihre Rolle ist unveraendert']     = benutzer($pdo, $chef)['role'] === 'admin';

$e = benutzer_umschalten($pdo, $chef, false);
$checks['sie kann sich nicht abschalten'] = $e['ok'] === false;
$checks['sie ist noch aktiv']             = (int) benutzer($pdo, $chef)['is_active'] === 1;

// Mit einem zweiten Verwalter geht beides.
$zweiter = benutzer_anlegen($pdo, 'zweite@example.com', 'Zweite', 'admin')['id'];
$checks['jetzt ist sie nicht mehr die letzte'] = letzter_verwalter($pdo, $chef) === false;
$checks['und kann sich herabstufen']           = benutzer_aendern($pdo, $chef, 'Chefin', 'staff')['ok'] === true;

$checks['ein Verwalter uebrig'] = anzahl_verwalter($pdo) === 1;

// Und der eine, der uebrig ist, kann sich jetzt seinerseits nicht mehr
// abschalten - die Regel wandert mit.
$checks['der Verbliebene ist nun der letzte'] = letzter_verwalter($pdo, $zweiter) === true;
$checks['und kann sich nicht abschalten']     = benutzer_umschalten($pdo, $zweiter, false)['ok'] === false;
$checks['er ist noch aktiv']                  = (int) benutzer($pdo, $zweiter)['is_active'] === 1;

// Ein abgeschalteter Verwalter zaehlt nicht mit. Dafuer braucht es
// einen dritten, den man abschalten darf.
$dritter = benutzer_anlegen($pdo, 'dritte@example.com', 'Dritte', 'admin')['id'];
$checks['jetzt sind es zwei'] = anzahl_verwalter($pdo) === 2;
benutzer_umschalten($pdo, $dritter, false);
$checks['abgeschaltet zaehlt nicht'] = anzahl_verwalter($pdo) === 1;

// =====================================================================
// Abschalten statt loeschen
// =====================================================================
// An einem Benutzer haengen Protokolleintraege und erfasste Zeiten. Wer
// geht, soll sich nicht mehr anmelden koennen - seine Spuren bleiben
// aber lesbar.
$checks['Freischalten geht wieder'] = benutzer_umschalten($pdo, $dritter, true)['ok'] === true;
$checks['und er ist wieder aktiv']  = (int) benutzer($pdo, $dritter)['is_active'] === 1;
$checks['die Zeile bleibt erhalten'] = (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() === 4;

// =====================================================================
// Der Anzeigename
// =====================================================================
$checks['der Name gewinnt']       = benutzer_anzeige(['name' => 'Anna B.', 'email' => 'a@example.com']) === 'Anna B.';
// "anna" ist immer noch besser als "anna@beispiel-firma-gmbh.example".
$checks['sonst der Teil vor dem @'] = benutzer_anzeige(['name' => '', 'email' => 'anna@example.com']) === 'anna';
$checks['Leerzeichen zaehlen als leer'] = benutzer_anzeige(['name' => '  ', 'email' => 'anna@example.com']) === 'anna';

// =====================================================================
// Die Liste
// =====================================================================
$liste = benutzer_liste($pdo);
$checks['die Liste zeigt alle']  = count($liste) === 4;
$checks['mit Rolle und Zustand'] = isset($liste[0]['role'], $liste[0]['is_active']);
// Das Passwort gehoert nicht in eine Liste, die auf den Bildschirm geht.
$checks['ohne Passwort-Hash']    = !isset($liste[0]['password_hash']);

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
