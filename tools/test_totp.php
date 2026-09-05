<?php
/**
 * Test fuer den zweiten Faktor.
 * Aufruf: php tools/test_totp.php
 *
 * Der wichtigste Teil steht ganz oben: die Rechnung wird gegen die
 * Pruefvektoren aus RFC 6238 Anhang B geprueft. Damit ist die Umsetzung
 * nachweisbar richtig und nicht nur "meine App hat es akzeptiert" -
 * eine falsche Implementierung, die zufaellig mit einer App
 * zusammenpasst, gaebe es sonst durchaus.
 *
 * Danach die Stellen, an denen ein zweiter Faktor gefaehrlich wird:
 * ein Ersatzcode, der zweimal gilt, und eine Einrichtung, die schon vor
 * der Bestaetigung greift - Letzteres sperrt bei einem Tippfehler beim
 * Abscannen den Benutzer aus.
 */

require_once __DIR__ . '/lib_sqlite_mirror.php';
require_once __DIR__ . '/../includes/totp.php';

$wurzel = dirname(__DIR__);
$pdo = new SqliteSpiegelPDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('PRAGMA foreign_keys = ON');
foreach (nach_sqlite(file_get_contents($wurzel . '/install/schema.sql')) as $anweisung) {
    $pdo->exec($anweisung);
}

$checks = [];

// =====================================================================
// Base32
// =====================================================================
// Die Vektoren aus RFC 4648 Abschnitt 10 - ohne Auffuellzeichen, so wie
// Authenticator-Apps das Geheimnis erwarten.
$checks['Base32 von "f"']      = totp_base32_encode('f')      === 'MY';
$checks['Base32 von "fo"']     = totp_base32_encode('fo')     === 'MZXQ';
$checks['Base32 von "foo"']    = totp_base32_encode('foo')    === 'MZXW6';
$checks['Base32 von "foob"']   = totp_base32_encode('foob')   === 'MZXW6YQ';
$checks['Base32 von "fooba"']  = totp_base32_encode('fooba')  === 'MZXW6YTB';
$checks['Base32 von "foobar"'] = totp_base32_encode('foobar') === 'MZXW6YTBOI';
$checks['Base32 von ""']       = totp_base32_encode('')       === '';

$checks['zurueck zu "foobar"'] = totp_base32_decode('MZXW6YTBOI') === 'foobar';
$checks['zurueck zu "f"']      = totp_base32_decode('MY') === 'f';
// Mit Auffuellzeichen und in Gruppen abgeschrieben - so kommt es aus
// einer App heraus.
$checks['Auffuellzeichen stoeren nicht'] = totp_base32_decode('MZXW6YTBOI======') === 'foobar';
$checks['Leerzeichen stoeren nicht']     = totp_base32_decode('MZXW 6YTB OI') === 'foobar';
$checks['Kleinschreibung stoert nicht']  = totp_base32_decode('mzxw6ytboi') === 'foobar';
// Ein Zeichen ausserhalb des Alphabets macht die Eingabe ungueltig -
// lieber nichts als ein falsches Geheimnis.
$checks['fremdes Zeichen gibt null'] = totp_base32_decode('MZXW6YTB01') === null;
$checks['leere Eingabe gibt null']   = totp_base32_decode('') === null;

// Rundlauf ueber Zufallsbytes.
$rund = true;
for ($i = 0; $i < 20; $i++) {
    $bytes = random_bytes(random_int(1, 40));
    if (totp_base32_decode(totp_base32_encode($bytes)) !== $bytes) { $rund = false; break; }
}
$checks['Rundlauf ueber Zufallsbytes'] = $rund;

// =====================================================================
// Die Pruefvektoren aus RFC 6238, Anhang B
// =====================================================================
// Geheimnis ist der ASCII-Text "12345678901234567890", als Base32.
$rfc = totp_base32_encode('12345678901234567890');
$checks['das RFC-Geheimnis kodiert richtig']
    = $rfc === 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';

// Der RFC nennt achtstellige Werte; die sechsstellige Anzeige sind
// deren letzte sechs Stellen.
$vektoren = [
    59          => '94287082',
    1111111109  => '07081804',
    1111111111  => '14050471',
    1234567890  => '89005924',
    2000000000  => '69279037',
    20000000000 => '65353130',
];
foreach ($vektoren as $zeit => $erwartet) {
    $checks["RFC 6238, T=$zeit (8 Stellen)"] = totp_code($rfc, $zeit, 8) === $erwartet;
    $checks["RFC 6238, T=$zeit (6 Stellen)"] = totp_code($rfc, $zeit, 6) === substr($erwartet, -6);
}

$checks['unbrauchbares Geheimnis gibt null'] = totp_code('nicht base32 !!', 59) === null;
$checks['leeres Geheimnis gibt null']        = totp_code('', 59) === null;

// =====================================================================
// Pruefen mit Zeitfenster
// =====================================================================
$jetzt = 1111111111;
$code  = totp_code($rfc, $jetzt);

$checks['der aktuelle Code stimmt']   = totp_pruefen($rfc, $code, $jetzt) === true;
// Ein Schritt in jede Richtung deckt Uhrabweichung und Tippzeit ab.
$checks['ein Schritt zurueck gilt']   = totp_pruefen($rfc, $code, $jetzt + TOTP_SCHRITT) === true;
$checks['ein Schritt vor gilt']       = totp_pruefen($rfc, $code, $jetzt - TOTP_SCHRITT) === true;
// Zwei nicht - sonst waere das Fenster ohne Not breiter.
$checks['zwei Schritte zurueck nicht'] = totp_pruefen($rfc, $code, $jetzt + 2 * TOTP_SCHRITT) === false;
$checks['zwei Schritte vor nicht']     = totp_pruefen($rfc, $code, $jetzt - 2 * TOTP_SCHRITT) === false;

$checks['falscher Code faellt durch']  = totp_pruefen($rfc, '000000', $jetzt) === false;
// Leerzeichen beim Abschreiben stoeren nicht.
$checks['Leerzeichen im Code stoeren nicht']
    = totp_pruefen($rfc, substr($code, 0, 3) . ' ' . substr($code, 3), $jetzt) === true;
// Alles, was nicht genau sechs Ziffern ist, wird gar nicht erst
// gerechnet.
$checks['zu kurz faellt durch']    = totp_pruefen($rfc, '12345', $jetzt) === false;
$checks['zu lang faellt durch']    = totp_pruefen($rfc, '1234567', $jetzt) === false;
$checks['Buchstaben fallen durch'] = totp_pruefen($rfc, 'abcdef', $jetzt) === false;
$checks['leer faellt durch']       = totp_pruefen($rfc, '', $jetzt) === false;

// =====================================================================
// Die Adresse fuer den QR-Code
// =====================================================================
$uri = totp_uri($rfc, 'chef@example.com', 'Meine Firma');
$checks['URI beginnt richtig']      = strpos($uri, 'otpauth://totp/') === 0;
$checks['Aussteller im Pfad']       = strpos($uri, 'Meine%20Firma:chef%40example.com') !== false;
$checks['Geheimnis als Parameter']  = strpos($uri, 'secret=' . $rfc) !== false;
$checks['Aussteller als Parameter'] = strpos($uri, 'issuer=Meine%20Firma') !== false;
$checks['Stellenzahl steht dabei']  = strpos($uri, 'digits=6') !== false;
$checks['Schrittlaenge steht dabei'] = strpos($uri, 'period=30') !== false;

// =====================================================================
// Ersatzcodes
// =====================================================================
$codes = totp_ersatzcodes_erzeugen();
$checks['acht Ersatzcodes']       = count($codes) === TOTP_ERSATZCODES;
$checks['Format XXXX-XXXX']       = preg_match('/^[0-9A-Z]{4}-[0-9A-Z]{4}$/', $codes[0]) === 1;
$checks['alle verschieden']       = count(array_unique($codes)) === count($codes);
// Keine verwechselbaren Zeichen: ein Ersatzcode wird auf Papier
// notiert, und 0/O oder 1/I helfen dort nicht.
$checks['keine verwechselbaren Zeichen']
    = preg_match('/[01OIL]/', implode('', $codes)) === 0;

$checks['Normalisierung entfernt den Strich'] = totp_ersatzcode_normalisieren('AB2C-D3EF') === 'AB2CD3EF';
$checks['und Leerzeichen']                    = totp_ersatzcode_normalisieren(' ab2c d3ef ') === 'AB2CD3EF';

// =====================================================================
// Einrichten, bestaetigen, einloesen
// =====================================================================
$pdo->prepare('INSERT INTO users (email, password_hash) VALUES (?, ?)')
    ->execute(['chef@example.com', password_hash('x', PASSWORD_BCRYPT, ['cost' => 4])]);
$user = (int) $pdo->lastInsertId();

$checks['zu Beginn nicht aktiv'] = totp_aktiv($pdo, $user) === false;
$checks['und kein Geheimnis']    = totp_geheimnis($pdo, $user) === null;

$geheimnis = totp_einrichten($pdo, $user);
$checks['Einrichten gibt ein Geheimnis'] = strlen($geheimnis) === 32;
$checks['es steht in der Datenbank']     = totp_geheimnis($pdo, $user) === $geheimnis;
// Der entscheidende Punkt: eingerichtet ist noch nicht aktiv. Sonst
// sperrt ein Tippfehler beim Abscannen den Benutzer aus.
$checks['eingerichtet ist noch nicht aktiv'] = totp_aktiv($pdo, $user) === false;

$zeit = time();
$checks['falscher Code bestaetigt nicht'] = totp_bestaetigen($pdo, $user, '000000', $zeit) === null;
$checks['und aktiviert nicht']            = totp_aktiv($pdo, $user) === false;

$ersatz = totp_bestaetigen($pdo, $user, totp_code($geheimnis, $zeit), $zeit);
$checks['richtiger Code bestaetigt']  = is_array($ersatz);
$checks['jetzt ist es aktiv']         = totp_aktiv($pdo, $user) === true;
$checks['und es gibt Ersatzcodes']    = count($ersatz) === TOTP_ERSATZCODES;
$checks['alle acht sind offen']       = totp_ersatzcodes_offen($pdo, $user) === TOTP_ERSATZCODES;

// Die Codes stehen gehasht in der Datenbank, nicht im Klartext.
$gespeichert = $pdo->query("SELECT code_hash FROM totp_backup_codes WHERE user_id = $user")
                   ->fetchAll(PDO::FETCH_COLUMN);
$klartext_gefunden = false;
foreach ($gespeichert as $hash) {
    foreach ($ersatz as $code) {
        if ($hash === $code || $hash === totp_ersatzcode_normalisieren($code)) { $klartext_gefunden = true; }
    }
}
$checks['kein Ersatzcode steht im Klartext'] = $klartext_gefunden === false;

// --- Einloesen ---------------------------------------------------------
$checks['ein Ersatzcode wird angenommen'] = totp_ersatzcode_einloesen($pdo, $user, $ersatz[0]) === true;
$checks['danach sind sieben offen']       = totp_ersatzcodes_offen($pdo, $user) === TOTP_ERSATZCODES - 1;
// Jeder gilt genau einmal.
$checks['derselbe Code nicht noch einmal'] = totp_ersatzcode_einloesen($pdo, $user, $ersatz[0]) === false;
// Auch in anderer Schreibweise.
$checks['auch nicht ohne Strich']
    = totp_ersatzcode_einloesen($pdo, $user, totp_ersatzcode_normalisieren($ersatz[0])) === false;
// Ein anderer schon.
$checks['ein anderer Code geht']  = totp_ersatzcode_einloesen($pdo, $user, $ersatz[1]) === true;
// In beliebiger Schreibweise.
$checks['Kleinschreibung geht']   = totp_ersatzcode_einloesen($pdo, $user, strtolower($ersatz[2])) === true;
$checks['erfundener Code nicht']  = totp_ersatzcode_einloesen($pdo, $user, 'AAAA-AAAA') === false;
$checks['leerer Code nicht']      = totp_ersatzcode_einloesen($pdo, $user, '') === false;

// --- Neu bestaetigen wirft die alten Codes weg -------------------------
// Sie gehoerten zu einem anderen Geheimnis.
$geheimnis2 = totp_einrichten($pdo, $user);
$ersatz2 = totp_bestaetigen($pdo, $user, totp_code($geheimnis2, $zeit), $zeit);
$checks['nach der Neueinrichtung wieder acht'] = totp_ersatzcodes_offen($pdo, $user) === TOTP_ERSATZCODES;
$checks['ein alter Code gilt nicht mehr']      = totp_ersatzcode_einloesen($pdo, $user, $ersatz[3]) === false;
$checks['ein neuer schon']                     = totp_ersatzcode_einloesen($pdo, $user, $ersatz2[0]) === true;

// --- Abschalten --------------------------------------------------------
totp_abschalten($pdo, $user);
$checks['abgeschaltet ist nicht aktiv'] = totp_aktiv($pdo, $user) === false;
$checks['das Geheimnis ist weg']        = totp_geheimnis($pdo, $user) === null;
$checks['die Ersatzcodes sind weg']     = totp_ersatzcodes_offen($pdo, $user) === 0;

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
