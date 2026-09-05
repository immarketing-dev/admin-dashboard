<?php
/**
 * Test fuer die Erreichbarkeitsueberwachung.
 * Aufruf: php tools/test_uptime.php
 *
 * Vorher wurde bei jedem Dashboardaufruf gemessen und das Ergebnis
 * weggeworfen: keine Quote, kein Verlauf, keine Nachricht bei Ausfall.
 * Ein Kundenserver konnte drei Tage weg sein, ohne dass es jemand
 * erfuhr.
 *
 * Die heikelste Stelle ist die Meldung. Sie muss genau dann kommen,
 * wenn sich der Zustand aendert - kommt sie bei jeder Messung, wird sie
 * nach zwei Tagen ignoriert; kommt sie nie, ist die ganze Uebung
 * umsonst. Besonders die ERSTE Messung: dort gibt es keinen Vorzustand,
 * und eine Meldung waere falsch.
 *
 * Nicht geprueft: uptime_messen() selbst. Sie greift nach aussen, und
 * ein Test, der das Netz braucht, ist keiner.
 */

require_once __DIR__ . '/lib_sqlite_mirror.php';

// demo_mode() und die Konstanten kommen sonst aus config.php.
if (!function_exists('demo_mode')) {
    function demo_mode(): bool { return false; }
}
if (!function_exists('setting')) {
    function setting(string $key, string $default = ''): string { return $default; }
}
foreach (['SMTP_HOST' => '', 'SMTP_USER' => '', 'SMTP_PASS' => '', 'SMTP_PORT' => 587,
          'ADMIN_EMAIL' => 'admin@example.com', 'COMPANY_NAME' => 'Testfirma'] as $k => $v) {
    if (!defined($k)) define($k, $v);
}

require_once __DIR__ . '/../includes/uptime.php';

$wurzel = dirname(__DIR__);
$pdo = new SqliteSpiegelPDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('PRAGMA foreign_keys = ON');
foreach (nach_sqlite(file_get_contents($wurzel . '/install/schema.sql')) as $anweisung) {
    $pdo->exec($anweisung);
}

$checks = [];

// =====================================================================
// Der Zustand einer Messung
// =====================================================================
// Drei Stufen, weil zwei zu wenig sind: eine Seite, die nach vier
// Sekunden antwortet, ist nicht "in Ordnung".
$checks['schnell ist online']  = uptime_zustand(['online' => true, 'time' => 200])  === 'online';
$checks['langsam ist slow']    = uptime_zustand(['online' => true, 'time' => 3000]) === 'slow';
$checks['an der Grenze noch online'] = uptime_zustand(['online' => true, 'time' => UPTIME_LANGSAM_MS]) === 'online';
$checks['knapp darueber slow'] = uptime_zustand(['online' => true, 'time' => UPTIME_LANGSAM_MS + 1]) === 'slow';
$checks['nicht erreichbar ist offline'] = uptime_zustand(['online' => false, 'time' => 0]) === 'offline';
$checks['fehlende Angaben sind offline'] = uptime_zustand([]) === 'offline';

// =====================================================================
// Wann wird gemeldet?
// =====================================================================
// Ohne Vorzustand nicht: sonst schickt die erste Messung nach dem
// Einrichten fuer jede gerade stille Adresse eine Nachricht, ohne dass
// sich etwas geaendert haette.
$checks['erste Messung meldet nie']       = uptime_meldenswert(null, 'offline') === false;
$checks['auch nicht bei online']          = uptime_meldenswert(null, 'online') === false;

$checks['Ausfall wird gemeldet']          = uptime_meldenswert('online', 'offline') === true;
$checks['Rueckkehr wird gemeldet']        = uptime_meldenswert('offline', 'online') === true;
$checks['Rueckkehr auf langsam ebenso']   = uptime_meldenswert('offline', 'slow') === true;
$checks['Ausfall von langsam ebenso']     = uptime_meldenswert('slow', 'offline') === true;

// Kein Wechsel, keine Meldung. Und "langsam" allein ist kein Vorfall -
// eine Meldung, die zu oft kommt, wird nicht mehr gelesen.
$checks['gleichbleibend online: still']   = uptime_meldenswert('online', 'online') === false;
$checks['gleichbleibend offline: still']  = uptime_meldenswert('offline', 'offline') === false;
$checks['online zu langsam: still']       = uptime_meldenswert('online', 'slow') === false;
$checks['langsam zu online: still']       = uptime_meldenswert('slow', 'online') === false;

// =====================================================================
// Der Text der Meldung
// =====================================================================
$url = ['url_name' => 'Kundenseite', 'url_link' => 'https://example.com'];

$aus = uptime_meldung($url, 'offline', ['online' => false, 'code' => 502, 'time' => 0, 'error' => ''], 'Testfirma');
$checks['Ausfall im Betreff']    = strpos($aus['subject'], 'nicht erreichbar') !== false;
$checks['Name im Betreff']       = strpos($aus['subject'], 'Kundenseite') !== false;
$checks['Firma im Betreff']      = strpos($aus['subject'], 'Testfirma') !== false;
$checks['HTTP-Code als Grund']   = strpos($aus['body'], 'HTTP 502') !== false;
$checks['Adresse im Text']       = strpos($aus['body'], 'https://example.com') !== false;

// Ohne HTTP-Code steht die Fehlermeldung von curl da - "HTTP 0" waere
// keine Auskunft.
$aus = uptime_meldung($url, 'offline', ['online' => false, 'code' => 0, 'time' => 0,
                                        'error' => 'Could not resolve host'], 'Testfirma');
$checks['ohne Code die Fehlermeldung'] = strpos($aus['body'], 'Could not resolve host') !== false;

$aus = uptime_meldung($url, 'offline', ['online' => false, 'code' => 0, 'time' => 0, 'error' => ''], 'Testfirma');
$checks['ohne beides ein Ersatztext'] = strpos($aus['body'], 'keine Antwort') !== false;

$aus = uptime_meldung($url, 'online', ['online' => true, 'code' => 200, 'time' => 340, 'error' => ''], 'Testfirma');
$checks['Rueckkehr im Betreff']     = strpos($aus['subject'], 'wieder erreichbar') !== false;
$checks['Antwortzeit im Text']      = strpos($aus['body'], '340 ms') !== false;

// =====================================================================
// Aufzeichnen und wiederfinden
// =====================================================================
$pdo->exec("INSERT INTO monitored_urls (url_name, url_link) VALUES ('Seite A', 'https://a.example.com')");
$a = (int) $pdo->lastInsertId();
$pdo->exec("INSERT INTO monitored_urls (url_name, url_link) VALUES ('Seite B', 'https://b.example.com')");
$b = (int) $pdo->lastInsertId();

uptime_aufzeichnen($pdo, $a, ['online' => true, 'code' => 200, 'time' => 120, 'error' => '']);
uptime_aufzeichnen($pdo, $b, ['online' => false, 'code' => 0, 'time' => 0, 'error' => 'timeout']);

$letzte = uptime_letzte($pdo);
$checks['beide Messungen sind da']  = count($letzte) === 2;
$checks['A ist online']             = $letzte[$a]['status'] === 'online';
$checks['B ist offline']            = $letzte[$b]['status'] === 'offline';
$checks['Antwortzeit wird notiert'] = (int) $letzte[$a]['response_ms'] === 120;
$checks['Fehlertext wird notiert']  = $letzte[$b]['error'] === 'timeout';

// Eine zweite Messung derselben Adresse ersetzt die erste als "letzte".
// Ueber die hoechste Kennung, nicht ueber den Zeitstempel: ein
// Cron-Lauf misst mehrere Adressen in derselben Sekunde.
uptime_aufzeichnen($pdo, $a, ['online' => false, 'code' => 500, 'time' => 80, 'error' => '']);
$letzte = uptime_letzte($pdo);
$checks['die neuere Messung gilt'] = $letzte[$a]['status'] === 'offline';
$checks['und zaehlt nur einmal']   = count($letzte) === 2;

// =====================================================================
// Frisch genug?
// =====================================================================
$urls = [['id' => $a], ['id' => $b]];
$jetzt = date('Y-m-d H:i:s');

$checks['gerade gemessen ist frisch'] = uptime_frisch(uptime_letzte($pdo), $urls, $jetzt) === true;

// Eine Adresse ohne jede Messung macht den Bestand unfrisch - sonst
// bliebe eine neu eingetragene URL bis zum naechsten Cron-Lauf ohne
// Zustand.
$pdo->exec("INSERT INTO monitored_urls (url_name, url_link) VALUES ('Seite C', 'https://c.example.com')");
$c = (int) $pdo->lastInsertId();
$checks['neue Adresse macht unfrisch']
    = uptime_frisch(uptime_letzte($pdo), [['id' => $a], ['id' => $b], ['id' => $c]], $jetzt) === false;

// Und eine zu alte Messung ebenso.
$alt = date('Y-m-d H:i:s', strtotime('-3 hours'));
$pdo->prepare("UPDATE url_checks SET checked_at = ? WHERE url_id = ?")->execute([$alt, $a]);
$checks['alte Messung macht unfrisch'] = uptime_frisch(uptime_letzte($pdo), $urls, $jetzt) === false;

// Ohne ueberwachte Adressen ist nichts zu tun - und das gilt als frisch,
// sonst misst die Seite bei leerer Liste jedes Mal "alles".
$checks['leere Liste ist frisch'] = uptime_frisch([], [], $jetzt) === true;

// =====================================================================
// Quote und Verlauf
// =====================================================================
$pdo->exec('DELETE FROM url_checks');

// Zwoelf Messungen fuer A: neun online, eine langsam, zwei offline.
$ins = $pdo->prepare(
    'INSERT INTO url_checks (url_id, status, http_code, response_ms, checked_at) VALUES (?, ?, 200, 100, ?)'
);
$folge = ['online','online','online','offline','offline','online','slow','online','online','online','online','online'];
foreach ($folge as $i => $status) {
    $ins->execute([$a, $status, date('Y-m-d H:i:s', strtotime('-' . (12 - $i) . ' hours'))]);
}

$verlauf = uptime_verlauf($pdo);

// Zehn von zwoelf sind nicht offline - "langsam" zaehlt als erreichbar,
// denn eine langsame Antwort ist eine Antwort.
$checks['Quote wird berechnet']       = abs($verlauf[$a]['quote'] - 83.3) < 0.05;
$checks['alle Messungen gezaehlt']    = $verlauf[$a]['messungen'] === 12;
$checks['der Verlauf hat Punkte']     = count($verlauf[$a]['punkte']) === 12;
$checks['Reihenfolge stimmt']         = $verlauf[$a]['punkte'][3] === 'offline';
$checks['ohne Messungen kein Eintrag'] = !isset($verlauf[$b]);

// Die Sparkline zeigt hoechstens die letzten Punkte.
for ($i = 0; $i < 40; $i++) {
    $ins->execute([$b, 'online', date('Y-m-d H:i:s', strtotime('-' . (40 - $i) . ' minutes'))]);
}
$verlauf = uptime_verlauf($pdo);
$checks['Sparkline wird gedeckelt'] = count($verlauf[$b]['punkte']) === UPTIME_VERLAUF_PUNKTE;
$checks['die Quote zaehlt trotzdem alle'] = $verlauf[$b]['messungen'] === 40;

// =====================================================================
// Aufraeumen
// =====================================================================
$ins->execute([$a, 'online', '2024-01-01 00:00:00']);
$vorher = (int) $pdo->query('SELECT COUNT(*) FROM url_checks')->fetchColumn();
$weg = uptime_aufraeumen($pdo, 30);
$nachher = (int) $pdo->query('SELECT COUNT(*) FROM url_checks')->fetchColumn();

$checks['alte Messungen fallen weg'] = $weg === 1 && $nachher === $vorher - 1;
$checks['zweiter Lauf raeumt nichts'] = uptime_aufraeumen($pdo, 30) === 0;

// =====================================================================
// Der Verlauf geht mit der Adresse
// =====================================================================
// ON DELETE CASCADE: wird eine Adresse aus der Ueberwachung genommen,
// hat ihr Verlauf keinen Adressaten mehr.
$pdo->exec("DELETE FROM monitored_urls WHERE id = $b");
$checks['Verlauf verschwindet mit der Adresse']
    = (int) $pdo->query("SELECT COUNT(*) FROM url_checks WHERE url_id = $b")->fetchColumn() === 0;
$checks['der andere Verlauf bleibt']
    = (int) $pdo->query("SELECT COUNT(*) FROM url_checks WHERE url_id = $a")->fetchColumn() > 0;

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
