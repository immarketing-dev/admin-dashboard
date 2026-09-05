<?php
/**
 * Zweiter Faktor: zeitbasierte Einmalkennwörter (TOTP, RFC 6238).
 *
 * Die Anmeldung ist sorgfältig gebaut — Sperre nach fünf Versuchen über
 * die ip-Spalte, bcrypt mit festgenagelten Kosten, ein Dummy-Hash gegen
 * Zeitunterschiede — und hat seit Schemaversion 12 auch einen Weg
 * zurück. Ein zweiter Faktor fehlte.
 *
 * Ohne Abhängigkeit: TOTP ist HMAC-SHA1 über einen Zähler, und beides
 * bringt PHP mit. Der Rest ist Base32 (weil Authenticator-Apps das
 * Geheimnis so erwarten) und die abgeschnittene Darstellung aus RFC
 * 4226. tools/test_totp.php prüft die Rechnung gegen die Prüfvektoren
 * des RFC — die Umsetzung ist damit nachweisbar richtig und nicht nur
 * "funktioniert bei mir".
 *
 * ── Ersatzcodes sind kein Beiwerk ──────────────────────────────────
 * Ein zweiter Faktor, der beim Verlust des Telefons aussperrt, tauscht
 * ein Aussperrungsproblem gegen ein anderes. Deshalb entstehen bei der
 * Einrichtung acht Ersatzcodes, jeder einmal verwendbar, gehasht
 * gespeichert wie ein Passwort — sie sind Passwörter.
 */

require_once __DIR__ . '/logging.php';

/** Länge des Geheimnisses in Bytes; als Base32 achtmal so viele Zeichen. */
const TOTP_SECRET_BYTES = 20;

/** Stellen des Einmalkennworts. */
const TOTP_STELLEN = 6;

/** Länge eines Zeitschritts in Sekunden. */
const TOTP_SCHRITT = 30;

/**
 * Wie viele Schritte vor und zurück noch gelten.
 *
 * Eins in jede Richtung: das deckt eine Uhrabweichung von einer halben
 * Minute ab und die Sekunden, die zwischen Ablesen und Eintippen
 * vergehen. Mehr würde das Zeitfenster ohne Not verbreitern.
 */
const TOTP_TOLERANZ = 1;

/** Wie viele Ersatzcodes bei der Einrichtung entstehen. */
const TOTP_ERSATZCODES = 8;

// ---------------------------------------------------------------------
// Base32
// ---------------------------------------------------------------------

/** Das Alphabet aus RFC 4648, das Authenticator-Apps erwarten. */
const TOTP_BASE32_ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

/**
 * Kodiert Rohbytes als Base32, ohne Auffüllzeichen.
 *
 * Ohne "=" am Ende: Authenticator-Apps kommen damit zurecht, und der
 * QR-Code wird kürzer.
 */
function totp_base32_encode(string $bytes): string
{
    if ($bytes === '') {
        return '';
    }

    $bits = '';
    for ($i = 0, $n = strlen($bytes); $i < $n; $i++) {
        $bits .= str_pad(decbin(ord($bytes[$i])), 8, '0', STR_PAD_LEFT);
    }

    $aus = '';
    foreach (str_split($bits, 5) as $stueck) {
        // Das letzte Stück kann kürzer als fünf Bit sein; rechts mit
        // Nullen auffüllen, wie es der Standard vorsieht.
        $aus .= TOTP_BASE32_ALPHABET[bindec(str_pad($stueck, 5, '0', STR_PAD_RIGHT))];
    }
    return $aus;
}

/**
 * Dekodiert Base32 zu Rohbytes.
 *
 * Leerzeichen und Auffüllzeichen werden übergangen: ein Geheimnis, das
 * jemand aus einer App abgeschrieben hat, kommt oft in Vierergruppen.
 * Ein Zeichen außerhalb des Alphabets macht die Eingabe ungültig — dann
 * lieber gar nichts zurückgeben als ein falsches Geheimnis.
 */
function totp_base32_decode(string $text): ?string
{
    $text = strtoupper(preg_replace('/[\s=-]/', '', $text) ?? '');
    if ($text === '') {
        return null;
    }

    $bits = '';
    for ($i = 0, $n = strlen($text); $i < $n; $i++) {
        $pos = strpos(TOTP_BASE32_ALPHABET, $text[$i]);
        if ($pos === false) {
            return null;
        }
        $bits .= str_pad(decbin($pos), 5, '0', STR_PAD_LEFT);
    }

    $aus = '';
    foreach (str_split($bits, 8) as $stueck) {
        // Ein angebrochenes letztes Byte ist Auffüllung, kein Datum.
        if (strlen($stueck) === 8) {
            $aus .= chr(bindec($stueck));
        }
    }
    return $aus;
}

// ---------------------------------------------------------------------
// Die Rechnung
// ---------------------------------------------------------------------

/** Erzeugt ein neues Geheimnis in Base32. */
function totp_geheimnis_erzeugen(): string
{
    return totp_base32_encode(random_bytes(TOTP_SECRET_BYTES));
}

/**
 * Das Einmalkennwort zu einem Zeitpunkt.
 *
 * Der Zähler ist die Zahl der Zeitschritte seit der Epoche, als
 * 8 Byte in Netzwerkreihenfolge. Danach HMAC-SHA1 und die dynamische
 * Verkürzung aus RFC 4226 Abschnitt 5.3: die letzten vier Bit des
 * Ergebnisses zeigen auf die Stelle, ab der vier Byte gelesen werden;
 * das oberste Bit davon wird verworfen, damit die Zahl vorzeichenlos
 * bleibt.
 *
 * @return string|null null bei unbrauchbarem Geheimnis
 */
function totp_code(string $geheimnis_base32, int $zeit, int $stellen = TOTP_STELLEN): ?string
{
    $key = totp_base32_decode($geheimnis_base32);
    if ($key === null || $key === '') {
        return null;
    }

    $zaehler = intdiv($zeit, TOTP_SCHRITT);

    // pack('J') gibt es erst ab 64-Bit-Systemen und PHP 5.6; von Hand
    // gebaut ist es unabhaengig davon und laesst sich nachlesen.
    $bin = '';
    for ($i = 7; $i >= 0; $i--) {
        $bin .= chr(($zaehler >> ($i * 8)) & 0xFF);
    }

    $hash    = hash_hmac('sha1', $bin, $key, true);
    $versatz = ord($hash[19]) & 0x0F;

    $zahl = ((ord($hash[$versatz])     & 0x7F) << 24)
          | ((ord($hash[$versatz + 1]) & 0xFF) << 16)
          | ((ord($hash[$versatz + 2]) & 0xFF) << 8)
          |  (ord($hash[$versatz + 3]) & 0xFF);

    return str_pad((string) ($zahl % (10 ** $stellen)), $stellen, '0', STR_PAD_LEFT);
}

/**
 * Stimmt das eingegebene Kennwort?
 *
 * Prüft die Nachbarschritte mit, gegen Uhrabweichung und Tippzeit. Der
 * Vergleich läuft über hash_equals: die Antwortzeit soll nicht
 * verraten, wie viele Stellen stimmten.
 */
function totp_pruefen(string $geheimnis_base32, string $eingabe, int $zeit, int $toleranz = TOTP_TOLERANZ): bool
{
    $eingabe = preg_replace('/\s+/', '', $eingabe) ?? '';
    if (!preg_match('/^\d{' . TOTP_STELLEN . '}$/', $eingabe)) {
        return false;
    }

    for ($i = -$toleranz; $i <= $toleranz; $i++) {
        $code = totp_code($geheimnis_base32, $zeit + $i * TOTP_SCHRITT);
        if ($code !== null && hash_equals($code, $eingabe)) {
            return true;
        }
    }
    return false;
}

/**
 * Die Adresse, die als QR-Code in die Authenticator-App wandert.
 *
 * Format nach der verbreiteten "otpauth"-Übereinkunft. Der Aussteller
 * steht zweimal darin - im Pfad und als Parameter -, weil verschiedene
 * Apps verschiedene Stellen lesen.
 */
function totp_uri(string $geheimnis_base32, string $konto, string $aussteller): string
{
    $label = rawurlencode($aussteller) . ':' . rawurlencode($konto);

    return 'otpauth://totp/' . $label . '?' . http_build_query([
        'secret'    => $geheimnis_base32,
        'issuer'    => $aussteller,
        'algorithm' => 'SHA1',
        'digits'    => TOTP_STELLEN,
        'period'    => TOTP_SCHRITT,
    ], '', '&', PHP_QUERY_RFC3986);
}

// ---------------------------------------------------------------------
// Ersatzcodes
// ---------------------------------------------------------------------

/**
 * Erzeugt Ersatzcodes im Klartext.
 *
 * Sprechend gruppiert (XXXX-XXXX), damit sie sich abschreiben lassen.
 * Aus dem Alphabet fallen die Zeichen heraus, die sich in Handschrift
 * verwechseln lassen — 0/O und 1/I/L: ein Ersatzcode wird auf Papier
 * notiert, sonst hilft er im Ernstfall nicht.
 */
function totp_ersatzcodes_erzeugen(int $anzahl = TOTP_ERSATZCODES): array
{
    $alphabet = '23456789ABCDEFGHJKMNPQRSTUVWXYZ';
    $codes    = [];

    for ($i = 0; $i < $anzahl; $i++) {
        $roh = '';
        for ($j = 0; $j < 8; $j++) {
            $roh .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }
        $codes[] = substr($roh, 0, 4) . '-' . substr($roh, 4);
    }
    return $codes;
}

/** Vereinheitlicht einen eingegebenen Ersatzcode für den Vergleich. */
function totp_ersatzcode_normalisieren(string $code): string
{
    return strtoupper(preg_replace('/[^0-9A-Za-z]/', '', $code) ?? '');
}

// ---------------------------------------------------------------------
// Datenbank
// ---------------------------------------------------------------------

/** Ist der zweite Faktor für diesen Benutzer eingerichtet und bestätigt? */
function totp_aktiv(PDO $pdo, int $user_id): bool
{
    $stmt = $pdo->prepare(
        'SELECT 1 FROM users
          WHERE id = ? AND totp_secret IS NOT NULL AND totp_confirmed_at IS NOT NULL'
    );
    $stmt->execute([$user_id]);

    return (bool) $stmt->fetchColumn();
}

/** Das gespeicherte Geheimnis, auch ein noch unbestätigtes. */
function totp_geheimnis(PDO $pdo, int $user_id): ?string
{
    $stmt = $pdo->prepare('SELECT totp_secret FROM users WHERE id = ?');
    $stmt->execute([$user_id]);
    $wert = $stmt->fetchColumn();

    return ($wert === false || $wert === null || $wert === '') ? null : (string) $wert;
}

/**
 * Legt ein neues, noch unbestätigtes Geheimnis an.
 *
 * Unbestätigt, weil erst ein eingetippter Code beweist, dass die App
 * das Geheimnis wirklich hat. Würde die Einrichtung sofort greifen,
 * sperrte ein Tippfehler beim Abscannen den Benutzer aus.
 */
function totp_einrichten(PDO $pdo, int $user_id): string
{
    $geheimnis = totp_geheimnis_erzeugen();

    $pdo->prepare('UPDATE users SET totp_secret = ?, totp_confirmed_at = NULL WHERE id = ?')
        ->execute([$geheimnis, $user_id]);

    return $geheimnis;
}

/**
 * Bestätigt die Einrichtung und legt die Ersatzcodes an.
 *
 * Gibt die Codes im Klartext zurück — das ist der einzige Moment, in
 * dem sie existieren. Danach steht in der Datenbank nur ihr Hash.
 *
 * @return array<int, string>|null null, wenn der Code nicht stimmt
 */
function totp_bestaetigen(PDO $pdo, int $user_id, string $eingabe, int $zeit): ?array
{
    $geheimnis = totp_geheimnis($pdo, $user_id);
    if ($geheimnis === null || !totp_pruefen($geheimnis, $eingabe, $zeit)) {
        return null;
    }

    $pdo->prepare('UPDATE users SET totp_confirmed_at = NOW() WHERE id = ?')->execute([$user_id]);

    // Alte Ersatzcodes verfallen: sie gehörten zu einem anderen
    // Geheimnis.
    $pdo->prepare('DELETE FROM totp_backup_codes WHERE user_id = ?')->execute([$user_id]);

    $codes = totp_ersatzcodes_erzeugen();
    $ins = $pdo->prepare('INSERT INTO totp_backup_codes (user_id, code_hash) VALUES (?, ?)');
    foreach ($codes as $code) {
        // Wie ein Passwort gehasht, weil es eines ist: der Code ist
        // kurz genug, dass sich ein schneller Hash durchprobieren ließe.
        $ins->execute([$user_id, password_hash(totp_ersatzcode_normalisieren($code), PASSWORD_BCRYPT, ['cost' => 10])]);
    }

    log_event($pdo, 'TOTP_ENABLED', 'Zweiter Faktor eingerichtet für Benutzer ' . $user_id . '.');

    return $codes;
}

/** Schaltet den zweiten Faktor ab und entfernt die Ersatzcodes. */
function totp_abschalten(PDO $pdo, int $user_id): void
{
    $pdo->prepare('UPDATE users SET totp_secret = NULL, totp_confirmed_at = NULL WHERE id = ?')
        ->execute([$user_id]);
    $pdo->prepare('DELETE FROM totp_backup_codes WHERE user_id = ?')->execute([$user_id]);

    log_event($pdo, 'TOTP_DISABLED', 'Zweiter Faktor abgeschaltet für Benutzer ' . $user_id . '.');
}

/**
 * Löst einen Ersatzcode ein.
 *
 * Jeder gilt einmal. Verglichen wird gegen alle offenen Hashes — anders
 * geht es nicht, ein bcrypt-Hash ist nicht suchbar. Bei acht Codes ist
 * das eine überschaubare Zahl von Prüfungen.
 */
function totp_ersatzcode_einloesen(PDO $pdo, int $user_id, string $eingabe): bool
{
    $eingabe = totp_ersatzcode_normalisieren($eingabe);
    if ($eingabe === '') {
        return false;
    }

    $stmt = $pdo->prepare(
        'SELECT id, code_hash FROM totp_backup_codes WHERE user_id = ? AND used_at IS NULL'
    );
    $stmt->execute([$user_id]);

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $zeile) {
        if (!password_verify($eingabe, (string) $zeile['code_hash'])) {
            continue;
        }
        // "AND used_at IS NULL" auch hier: zwei gleichzeitige Anmeldungen
        // dürfen nicht beide denselben Code verbrauchen.
        $upd = $pdo->prepare('UPDATE totp_backup_codes SET used_at = NOW() WHERE id = ? AND used_at IS NULL');
        $upd->execute([(int) $zeile['id']]);

        if ($upd->rowCount() === 1) {
            log_event($pdo, 'TOTP_BACKUP_USED', 'Ersatzcode verwendet von Benutzer ' . $user_id . '.');
            return true;
        }
        return false;
    }
    return false;
}

/** Wie viele Ersatzcodes sind noch offen? */
function totp_ersatzcodes_offen(PDO $pdo, int $user_id): int
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM totp_backup_codes WHERE user_id = ? AND used_at IS NULL');
    $stmt->execute([$user_id]);

    return (int) $stmt->fetchColumn();
}
