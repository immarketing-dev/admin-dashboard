<?php
/**
 * Passwort zurücksetzen.
 *
 * Es gab keinen Weg zurück. Kein "Passwort vergessen", nichts im
 * Anmeldebild außer E-Mail und Passwort. Wer sein Passwort verlor,
 * brauchte Zugriff auf die Datenbank — bei einer Installation, die
 * jemand bei einem Massenhoster betreibt, ist das der Punkt, an dem das
 * Panel aufgegeben wird.
 *
 * Die Mechanik ist die von sso.php: ein einmaliges Token mit Ablauf,
 * das beim Einlösen entwertet wird. Drei Unterschiede zum dortigen
 * Vorbild, alle absichtlich:
 *
 *  - Gespeichert wird der Hash des Tokens, nicht das Token. Wer die
 *    Datenbank liest — ein Backup, ein Auszug, eine Einschleusung —
 *    hätte sonst einen gültigen Anmeldeweg in der Hand.
 *  - Es läuft ab. Eine Stunde ist lang genug, um eine Mail zu lesen,
 *    und kurz genug, dass ein vergessener Link im Postfach kein
 *    dauerhafter Zweitschlüssel wird.
 *  - Die Antwort verrät nie, ob eine Adresse existiert. Sonst wäre das
 *    Formular ein Verzeichnis der Benutzerkonten.
 */

require_once __DIR__ . '/logging.php';
require_once __DIR__ . '/mailer.php';
require_once __DIR__ . '/mail_templates.php';
require_once __DIR__ . '/auth_login.php';

/** Wie lange ein Link gilt, in Minuten. */
const RESET_GUELTIG_MINUTEN = 60;

/**
 * Wie viele Anforderungen eine IP je Stunde stellen darf.
 *
 * Ohne Grenze ließe sich das Formular als Versandmaschine benutzen: jede
 * Anforderung erzeugt eine Mail an eine Adresse, die der Anfordernde
 * nicht kennen muss. Der Wert ist bewusst niedrig — wer sein Passwort
 * vergessen hat, braucht keine zwanzig Versuche.
 */
const RESET_MAX_PRO_STUNDE = 5;

/** Länge des Tokens in Bytes; als Hex doppelt so viele Zeichen. */
const RESET_TOKEN_BYTES = 32;

/**
 * Der gespeicherte Hash zu einem Token.
 *
 * SHA-256 und nicht bcrypt: bcrypt ist gegen das Durchprobieren
 * schwacher Geheimnisse gebaut und entsprechend langsam. Ein Token aus
 * 32 zufälligen Bytes lässt sich nicht durchprobieren — hier zählt nur,
 * dass aus dem gespeicherten Wert nicht auf das Token zu schließen ist.
 */
function reset_token_hash(string $token): string
{
    return hash('sha256', $token);
}

/**
 * Hat diese IP zu oft angefordert?
 *
 * Zählt über das Protokoll, wie die Anmeldesperre in auth_login.php —
 * dieselbe Tabelle, dieselbe ip-Spalte, kein zweiter Mechanismus.
 */
function reset_zu_haeufig(PDO $pdo, string $ip): bool
{
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM logs
          WHERE action_type = 'PASSWORD_RESET_REQUESTED'
            AND ip = ?
            AND created_at > (NOW() - INTERVAL 60 MINUTE)"
    );
    $stmt->execute([$ip]);

    return (int) $stmt->fetchColumn() >= RESET_MAX_PRO_STUNDE;
}

/**
 * Legt ein Token an und gibt es im Klartext zurück.
 *
 * Nur hier existiert das Token im Klartext — danach steht in der
 * Datenbank ausschließlich sein Hash, und der Klartext geht in genau
 * eine Mail.
 *
 * Ältere, noch offene Token desselben Benutzers werden entwertet: wer
 * ein neues anfordert, hat das alte üblicherweise nicht bekommen, und
 * zwei gültige Schlüssel sind einer zu viel.
 */
function reset_token_erzeugen(PDO $pdo, int $user_id): string
{
    $pdo->prepare(
        'UPDATE password_resets SET used_at = NOW()
          WHERE user_id = ? AND used_at IS NULL'
    )->execute([$user_id]);

    $token = bin2hex(random_bytes(RESET_TOKEN_BYTES));

    $pdo->prepare(
        'INSERT INTO password_resets (user_id, token_hash, expires_at)
         VALUES (?, ?, (NOW() + INTERVAL ' . (int) RESET_GUELTIG_MINUTEN . ' MINUTE))'
    )->execute([$user_id, reset_token_hash($token)]);

    return $token;
}

/**
 * Sucht den Benutzer zu einem Token — oder null.
 *
 * Prüft Ablauf und Verbrauch gleich mit. Ein abgelaufenes und ein
 * erfundenes Token sind von außen nicht zu unterscheiden, und das ist
 * richtig so.
 *
 * @return array{id: int, email: string}|null
 */
function reset_token_einloesen(PDO $pdo, string $token): ?array
{
    // Ein leeres Token wäre sonst eine Anfrage nach dem Hash des leeren
    // Strings - der steht nie in der Tabelle, aber die Abfrage soll gar
    // nicht erst laufen.
    if (trim($token) === '') {
        return null;
    }

    $stmt = $pdo->prepare(
        'SELECT pr.id, u.id AS user_id, u.email
           FROM password_resets pr
           JOIN users u ON u.id = pr.user_id
          WHERE pr.token_hash = ?
            AND pr.used_at IS NULL
            AND pr.expires_at > NOW()'
    );
    $stmt->execute([reset_token_hash($token)]);
    $zeile = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$zeile) {
        return null;
    }

    return ['id' => (int) $zeile['user_id'], 'email' => (string) $zeile['email'], 'reset_id' => (int) $zeile['id']];
}

/**
 * Setzt das Passwort und entwertet das Token.
 *
 * Beides zusammen, und das Entwerten mit "AND used_at IS NULL": zwei
 * gleichzeitige Aufrufe desselben Links dürfen nicht beide durchgehen.
 * Nur der Lauf, der die Zeile wirklich ändert, setzt auch das Passwort.
 */
function reset_passwort_setzen(PDO $pdo, int $reset_id, int $user_id, string $passwort): bool
{
    $entwerten = $pdo->prepare(
        'UPDATE password_resets SET used_at = NOW() WHERE id = ? AND used_at IS NULL'
    );
    $entwerten->execute([$reset_id]);

    if ($entwerten->rowCount() !== 1) {
        return false;
    }

    $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
        ->execute([password_hash($passwort, AUTH_HASH_ALGO, AUTH_HASH_OPTIONS), $user_id]);

    log_event($pdo, 'PASSWORD_RESET_DONE', 'Passwort zurückgesetzt für Benutzer ' . $user_id . '.');

    return true;
}

/**
 * Nimmt eine Anforderung entgegen und verschickt gegebenenfalls die Mail.
 *
 * Gibt IMMER dasselbe zurück, gleich ob die Adresse existiert. Die
 * einzige Ausnahme ist die Sperre bei zu vielen Anforderungen — die darf
 * sichtbar sein, weil sie an der IP hängt und nicht an der Adresse.
 *
 * @return array{ok: bool, gesperrt: bool}
 */
function reset_anfordern(PDO $pdo, string $email, string $ip, string $basis_url, string $firma): array
{
    if (reset_zu_haeufig($pdo, $ip)) {
        return ['ok' => false, 'gesperrt' => true];
    }

    // Vor der Suche protokollieren, nicht danach: sonst zählt die Sperre
    // nur die Treffer, und wer Adressen durchprobiert, bleibt ungezählt.
    log_event($pdo, 'PASSWORD_RESET_REQUESTED', 'Passwort-Zurücksetzung angefordert.');

    $stmt = $pdo->prepare('SELECT id, email FROM users WHERE email = ?');
    $stmt->execute([trim($email)]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        // Kein Hinweis nach außen. Die Antwort ist dieselbe wie im
        // Erfolgsfall - sonst wäre das Formular ein Verzeichnis der
        // Benutzerkonten.
        return ['ok' => true, 'gesperrt' => false];
    }

    $token = reset_token_erzeugen($pdo, (int) $user['id']);
    $link  = rtrim($basis_url, '/') . '/login?reset=' . urlencode($token);

    $mail = mail_render('password_reset', [
        'link'    => $link,
        'minuten' => (string) RESET_GUELTIG_MINUTEN,
        'firma'   => $firma,
    ], $link);

    $ergebnis = mail_versenden([
        'to'       => (string) $user['email'],
        'subject'  => $mail['subject'],
        'body'     => $mail['text'] !== '' ? $mail['text'] : strip_tags($mail['html']),
        'pdo'      => $pdo,
        'template' => 'password_reset',
        'context'  => 'Benutzer ' . (int) $user['id'],
    ]);

    if (!$ergebnis['ok']) {
        // Der Fehler gehört ins Protokoll, nicht auf den Bildschirm: dort
        // würde er verraten, dass die Adresse existiert.
        error_log('Passwort-Zurücksetzung: Mailversand fehlgeschlagen - ' . $ergebnis['error']);
        log_event($pdo, 'PASSWORD_RESET_FAILED', 'Mailversand fehlgeschlagen: ' . $ergebnis['error']);
    }

    return ['ok' => true, 'gesperrt' => false];
}

/**
 * Räumt abgelaufene und verbrauchte Token weg.
 *
 * Läuft im Cron-Lauf mit. Eine verbrauchte Zeile hat nur noch
 * dokumentarischen Wert, und den trägt bereits das Protokoll.
 */
function reset_token_aufraeumen(PDO $pdo): int
{
    $stmt = $pdo->prepare(
        'DELETE FROM password_resets
          WHERE (used_at IS NOT NULL AND used_at   < (NOW() - INTERVAL 7 DAY))
             OR (used_at IS NULL     AND expires_at < (NOW() - INTERVAL 7 DAY))'
    );
    $stmt->execute();

    return $stmt->rowCount();
}
