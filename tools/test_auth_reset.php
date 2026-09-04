<?php
/**
 * Test fuer die Passwort-Zuruecksetzung.
 * Aufruf: php tools/test_auth_reset.php
 *
 * Es gab keinen Weg zurueck ins eigene Panel: wer sein Passwort verlor,
 * brauchte Zugriff auf die Datenbank.
 *
 * Die Zusagen, auf die es hier ankommt, sind alle Verneinungen:
 *
 *  - Ein Token gilt genau einmal. Zwei gleichzeitige Aufrufe desselben
 *    Links duerfen nicht beide durchgehen.
 *  - Ein Token laeuft ab.
 *  - Ein neues Token entwertet das alte.
 *  - In der Datenbank steht nie das Token, nur sein Hash. Wer einen
 *    Auszug liest, haelt keinen Anmeldeweg in der Hand.
 *  - Die Antwort verraet nie, ob eine Adresse existiert.
 *
 * Nicht geprueft: der Mailversand. reset_anfordern() ruft PHPMailer,
 * und ein Test, der Mails verschickt, ist keiner.
 */

require_once __DIR__ . '/lib_sqlite_mirror.php';

// setting() und die Konstanten kommen sonst aus config.php.
if (!function_exists('setting')) {
    function setting(string $key, string $default = ''): string { return $default; }
}
foreach (['SMTP_HOST' => '', 'SMTP_USER' => '', 'SMTP_PASS' => '', 'SMTP_PORT' => 587,
          'ADMIN_EMAIL' => 'admin@example.com', 'COMPANY_NAME' => 'Testfirma',
          // mail_frame() gestaltet den Rahmen und greift dafuer auf die
          // Farben und die Anschrift zu.
          'COMPANY_SHORT' => 'Testfirma', 'COLOR_PRIMARY' => '#149ddd',
          'COLOR_SIDEBAR' => '#040b14', 'MAIN_WEBSITE' => 'https://example.com'] as $k => $v) {
    if (!defined($k)) define($k, $v);
}

// reset_anfordern() protokolliert einen fehlgeschlagenen Mailversand
// ueber error_log(). Ohne SMTP-Server ist das hier der Normalfall und
// wuerde die Testausgabe mit einer Zeile verunreinigen, die nach einem
// Fehler aussieht. Umgeleitet statt abgeschaltet: echte Fehler stehen
// dann in der Datei und sind nachlesbar.
ini_set('error_log', tempnam(sys_get_temp_dir(), 'resettest'));

require_once __DIR__ . '/../includes/auth_reset.php';

$wurzel = dirname(__DIR__);
$pdo = new SqliteSpiegelPDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('PRAGMA foreign_keys = ON');
foreach (nach_sqlite(file_get_contents($wurzel . '/install/schema.sql')) as $anweisung) {
    $pdo->exec($anweisung);
}

$checks = [];

// --- Ein Benutzer -----------------------------------------------------
$pdo->prepare('INSERT INTO users (email, password_hash) VALUES (?, ?)')
    ->execute(['chef@example.com', password_hash('altes-passwort-123', PASSWORD_BCRYPT, ['cost' => 4])]);
$user = (int) $pdo->lastInsertId();

$pdo->prepare('INSERT INTO users (email, password_hash) VALUES (?, ?)')
    ->execute(['zweiter@example.com', password_hash('x', PASSWORD_BCRYPT, ['cost' => 4])]);
$user2 = (int) $pdo->lastInsertId();

// =====================================================================
// Der Hash
// =====================================================================
$checks['Hash ist 64 Hexzeichen']  = preg_match('/^[0-9a-f]{64}$/', reset_token_hash('abc')) === 1;
$checks['gleiches Token, gleicher Hash'] = reset_token_hash('abc') === reset_token_hash('abc');
$checks['anderes Token, anderer Hash']   = reset_token_hash('abc') !== reset_token_hash('abd');

// =====================================================================
// Erzeugen
// =====================================================================
$token = reset_token_erzeugen($pdo, $user);

$checks['Token ist 64 Hexzeichen'] = preg_match('/^[0-9a-f]{64}$/', $token) === 1;

// Der entscheidende Punkt: in der Tabelle steht der Hash, nicht das
// Token. Sonst haette jeder Datenbankauszug einen Anmeldeweg dabei.
$gespeichert = $pdo->query('SELECT token_hash FROM password_resets')->fetchColumn();
$checks['die Datenbank kennt das Token nicht'] = $gespeichert !== $token;
$checks['sie kennt seinen Hash']               = $gespeichert === reset_token_hash($token);

$checks['zwei Aufrufe geben verschiedene Token'] = reset_token_erzeugen($pdo, $user2) !== $token;

// =====================================================================
// Einloesen
// =====================================================================
$gefunden = reset_token_einloesen($pdo, $token);
$checks['gueltiges Token findet den Benutzer'] = is_array($gefunden) && $gefunden['id'] === $user;
$checks['die Adresse kommt mit']               = ($gefunden['email'] ?? '') === 'chef@example.com';

$checks['erfundenes Token findet nichts'] = reset_token_einloesen($pdo, str_repeat('a', 64)) === null;
$checks['leeres Token findet nichts']    = reset_token_einloesen($pdo, '') === null;
$checks['Leerzeichen findet nichts']     = reset_token_einloesen($pdo, '   ') === null;

// =====================================================================
// Ein neues Token entwertet das alte
// =====================================================================
// Wer ein neues anfordert, hat das alte ueblicherweise nicht bekommen -
// zwei gueltige Schluessel sind einer zu viel.
$token_neu = reset_token_erzeugen($pdo, $user);
$checks['das alte Token gilt nicht mehr'] = reset_token_einloesen($pdo, $token) === null;
$checks['das neue gilt']                  = is_array(reset_token_einloesen($pdo, $token_neu));

// =====================================================================
// Ablauf
// =====================================================================
$abgelaufen = reset_token_erzeugen($pdo, $user2);
$pdo->prepare("UPDATE password_resets SET expires_at = '2020-01-01 00:00:00' WHERE token_hash = ?")
    ->execute([reset_token_hash($abgelaufen)]);
$checks['abgelaufenes Token findet nichts'] = reset_token_einloesen($pdo, $abgelaufen) === null;

// =====================================================================
// Einloesen setzt das Passwort - und nur einmal
// =====================================================================
$vorher = $pdo->query("SELECT password_hash FROM users WHERE id = $user")->fetchColumn();
$daten  = reset_token_einloesen($pdo, $token_neu);

$checks['Setzen meldet Erfolg']
    = reset_passwort_setzen($pdo, (int) $daten['reset_id'], (int) $daten['id'], 'ein-neues-passwort-1') === true;

$nachher = $pdo->query("SELECT password_hash FROM users WHERE id = $user")->fetchColumn();
$checks['das Passwort hat sich geaendert'] = $vorher !== $nachher;
$checks['das neue Passwort passt']         = password_verify('ein-neues-passwort-1', $nachher);
$checks['das alte passt nicht mehr']       = !password_verify('altes-passwort-123', $nachher);

// Der zweite Aufruf mit derselben Kennung greift ins Leere. Das ist die
// Absicherung gegen zwei gleichzeitige Aufrufe desselben Links: nur der
// Lauf, der die Zeile wirklich entwertet, setzt auch das Passwort.
$checks['zweites Setzen wird abgewiesen']
    = reset_passwort_setzen($pdo, (int) $daten['reset_id'], (int) $daten['id'], 'noch-ein-passwort-9') === false;

$danach = $pdo->query("SELECT password_hash FROM users WHERE id = $user")->fetchColumn();
$checks['und aendert nichts'] = $danach === $nachher;

// Und das verbrauchte Token ist nicht mehr einloesbar.
$checks['verbrauchtes Token findet nichts'] = reset_token_einloesen($pdo, $token_neu) === null;

// =====================================================================
// Die Sperre gegen zu viele Anforderungen
// =====================================================================
// Ohne sie waere das Formular eine Versandmaschine: jede Anforderung
// erzeugt eine Mail an eine Adresse, die der Anfordernde nicht kennen
// muss.
$ip = '203.0.113.7';
$checks['zu Beginn nicht gesperrt'] = reset_zu_haeufig($pdo, $ip) === false;

$log = $pdo->prepare("INSERT INTO logs (action_type, description, ip) VALUES ('PASSWORD_RESET_REQUESTED', 'Test', ?)");
for ($i = 0; $i < RESET_MAX_PRO_STUNDE - 1; $i++) {
    $log->execute([$ip]);
}
$checks['knapp darunter noch frei'] = reset_zu_haeufig($pdo, $ip) === false;
$log->execute([$ip]);
$checks['an der Grenze gesperrt']   = reset_zu_haeufig($pdo, $ip) === true;

// Eine andere IP ist davon nicht betroffen - die Sperre haengt an der
// Herkunft, nicht an der Adresse im Formular.
$checks['andere IP bleibt frei'] = reset_zu_haeufig($pdo, '198.51.100.4') === false;

// Alte Eintraege zaehlen nicht mehr mit.
$pdo->exec("UPDATE logs SET created_at = '2020-01-01 00:00:00' WHERE action_type = 'PASSWORD_RESET_REQUESTED'");
$checks['alte Anforderungen zaehlen nicht'] = reset_zu_haeufig($pdo, $ip) === false;

// =====================================================================
// Anfordern verraet nichts
// =====================================================================
// Beide Aufrufe muessen dasselbe melden - der eine mit, der andere ohne
// hinterlegte Adresse. Ohne SMTP_HOST verschickt mail_versenden() nichts
// und meldet das nur ins Protokoll; fuer diese Pruefung genuegt das.
$mit  = reset_anfordern($pdo, 'chef@example.com',      '198.51.100.9', 'https://example.com', 'Testfirma');
$ohne = reset_anfordern($pdo, 'gibtesnicht@example.com', '198.51.100.9', 'https://example.com', 'Testfirma');

$checks['bekannte Adresse: ok']    = $mit  === ['ok' => true, 'gesperrt' => false];
$checks['unbekannte Adresse: ok']  = $ohne === ['ok' => true, 'gesperrt' => false];
$checks['die Antwort ist dieselbe'] = $mit === $ohne;

// Fuer die bekannte Adresse ist trotzdem ein Token entstanden, fuer die
// unbekannte nicht.
$offene = (int) $pdo->query(
    "SELECT COUNT(*) FROM password_resets WHERE user_id = $user AND used_at IS NULL"
)->fetchColumn();
$checks['fuer die bekannte Adresse entstand ein Token'] = $offene === 1;

// =====================================================================
// Aufraeumen
// =====================================================================
$pdo->exec(
    "INSERT INTO password_resets (user_id, token_hash, expires_at, created_at)
     VALUES ($user2, '" . str_repeat('b', 64) . "', '2020-01-01 00:00:00', '2020-01-01 00:00:00')"
);
$vorher_anzahl = (int) $pdo->query('SELECT COUNT(*) FROM password_resets')->fetchColumn();
$weg = reset_token_aufraeumen($pdo);
$nachher_anzahl = (int) $pdo->query('SELECT COUNT(*) FROM password_resets')->fetchColumn();

$checks['Aufraeumen entfernt Altes'] = $weg > 0 && $nachher_anzahl < $vorher_anzahl;
// Das frische Token von eben muss bleiben.
$checks['das frische Token bleibt']
    = (int) $pdo->query("SELECT COUNT(*) FROM password_resets WHERE user_id = $user AND used_at IS NULL")->fetchColumn() === 1;

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
