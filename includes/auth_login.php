<?php
require_once __DIR__ . '/logging.php';
require_once __DIR__ . '/totp.php';
require_once __DIR__ . '/users.php';

/**
 * Login-Logik. Ersetzt die früheren login_process.php-Varianten und die
 * settings-basierte Passwortprüfung in login.php.
 */

const AUTH_MAX_ATTEMPTS = 5;
const AUTH_LOCKOUT_MIN   = 15;

/**
 * Wie lange der Zwischenzustand zwischen Passwort und zweitem Faktor
 * gilt, in Minuten.
 *
 * Er ist ein halb geöffnetes Schloss: das Passwort stimmt, der zweite
 * Faktor fehlt noch. Ohne Frist bliebe eine Sitzung, in der jemand das
 * Passwort eingegeben und dann den Rechner verlassen hat, beliebig lange
 * für den nächsten offen, der eine Ziffernfolge errät.
 */
const AUTH_TOTP_FRIST_MIN = 10;

// Bcrypt-Kosten bewusst festgenagelt statt PASSWORD_DEFAULT: der
// Dummy-Hash in auth_attempt() muss dieselben Kosten haben wie ein
// echter, sonst verraet die Antwortzeit, ob die E-Mail existiert.
// PASSWORD_DEFAULT wandert zwischen PHP-Versionen und wuerde die
// Zeiten wieder auseinanderlaufen lassen.
const AUTH_HASH_ALGO    = PASSWORD_BCRYPT;
const AUTH_HASH_OPTIONS = ['cost' => 12];

function auth_is_first_run(PDO $pdo): bool
{
    return (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() === 0;
}

function auth_create_first_user(PDO $pdo, string $email, string $password): int
{
    // Der erste Benutzer ist Verwaltung - sonst gaebe es niemanden, der
    // weitere anlegen koennte.
    $pdo->prepare("INSERT INTO users (email, password_hash, role, is_active) VALUES (?, ?, 'admin', 1)")
        ->execute([$email, password_hash($password, AUTH_HASH_ALGO, AUTH_HASH_OPTIONS)]);

    $id = (int) $pdo->lastInsertId();

    log_event($pdo, 'USER_CREATED', 'Erster Benutzer angelegt: ' . $email);

    return $id;
}

function auth_is_locked(PDO $pdo, string $ip): bool
{
    // Exakter Spaltenvergleich statt LIKE auf die Beschreibung: die
    // Beschreibung enthaelt die vom Angreifer frei waehlbare E-Mail-
    // Adresse, ein LIKE-Muster darauf liesse sich mit einer praeparierten
    // Adresse im E-Mail-Feld vergiften und eine fremde Adresse sperren.
    // Die Frist steht als Konstante im Code und wird deshalb eingesetzt,
    // nicht gebunden. MySQL erwartet hinter INTERVAL einen Zahlenausdruck;
    // ein gebundener Parameter kommt dort als Zeichenkette an, was mit
    // echten Prepared Statements (siehe config.php) zur Auslegungssache
    // wird. Bei der Anmeldesperre ist das der falsche Ort dafuer: faellt
    // sie aus, laesst sich das Passwort unbegrenzt durchprobieren.
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM logs
          WHERE action_type = 'LOGIN_FAILED'
            AND ip = ?
            AND created_at > (NOW() - INTERVAL " . (int) AUTH_LOCKOUT_MIN . " MINUTE)"
    );
    $stmt->execute([$ip]);

    return (int) $stmt->fetchColumn() >= AUTH_MAX_ATTEMPTS;
}

function auth_note_lockout(PDO $pdo, string $ip): void
{
    // Nur einmal pro Sperrfenster protokollieren - sonst waechst logs bei
    // wiederholten Anfragen gegen ein bereits gesperrtes Formular
    // unbegrenzt, ohne dass der Angreifer je ein Passwort versuchen muss.
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM logs
          WHERE action_type = 'SYSTEM_LOCKOUT'
            AND ip = ?
            AND created_at > (NOW() - INTERVAL " . (int) AUTH_LOCKOUT_MIN . " MINUTE)"
    );
    $stmt->execute([$ip]);

    if ((int) $stmt->fetchColumn() > 0) {
        return;
    }

    // Die IP steht ausschliesslich in der ip-Spalte, nicht mehr im
    // Freitext - siehe auth_attempt() fuer die Begruendung.
    $pdo->prepare('INSERT INTO logs (action_type, description, ip) VALUES (?, ?, ?)')
        ->execute(['SYSTEM_LOCKOUT', 'Sicherheitssperre aktiv (zu viele Fehlversuche)', $ip]);
}

function auth_attempt(PDO $pdo, string $email, string $password, string $ip): bool
{
    // is_active mitlesen: ein abgeschalteter Benutzer soll sich nicht
    // anmelden koennen, aber die Pruefung darf erst NACH der
    // Passwortpruefung greifen - sonst verriete die Antwortzeit, welche
    // Adressen es gibt.
    $stmt = $pdo->prepare('SELECT id, email, password_hash, name, role, is_active FROM users WHERE email = ?');
    $stmt->execute([$email]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    // password_verify auch ohne Treffer aufrufen, damit die Antwortzeit
    // nicht verrät, ob die E-Mail existiert. is_array() statt ?? – auf
    // false wäre der Array-Zugriff eine Warnung. Der Dummy-Hash muss mit
    // AUTH_HASH_OPTIONS' Kosten (12) übereinstimmen, sonst braucht die
    // echte Prüfung messbar länger als die Dummy-Prüfung und die
    // Antwortzeit verrät doch, ob die E-Mail existiert. Das Klartext-
    // Passwort dahinter ('nobody') ist irrelevant – nur die Kosten zählen.
    $dummy = '$2y$12$Um0b6PPRFZ9Qrq9t/59jJOstN2Yl7FIHsy2MtEsZJzf2FKGWRT7/C';
    $hash  = is_array($user) ? $user['password_hash'] : $dummy;
    $ok    = password_verify($password, $hash) && is_array($user);

    // Bei einem Fehlversuch ist $email komplett unauthentifizierte,
    // unbegrenzte Nutzereingabe - fuer die Log-Beschreibung kappen, damit
    // kein beliebig langer Wert in die TEXT-Spalte laeuft.
    $emailForLog = mb_substr($email, 0, 190);

    $log = $pdo->prepare('INSERT INTO logs (action_type, description, ip) VALUES (?, ?, ?)');

    if (!$ok) {
        // Keine Adresse mehr im Freitext: die E-Mail hier ist
        // unauthentifizierte Nutzereingabe, ein Angreifer koennte sonst
        // per E-Mail-Feld frei waehlbaren, wie eine echte Adresse
        // aussehenden Text in die Beschreibung schmuggeln. Die Adresse
        // steht bereits verlaesslich in der ip-Spalte.
        $log->execute([
            'LOGIN_FAILED',
            'Fehlgeschlagener Login-Versuch für E-Mail: ' . $emailForLog,
            $ip,
        ]);
        return false;
    }

    // Abgeschaltet: dieselbe unspezifische Antwort wie bei einem
    // falschen Passwort. Wer geht, soll nicht erfahren, dass sein Konto
    // noch existiert.
    if ((int) ($user['is_active'] ?? 1) !== 1) {
        $log->execute([
            'LOGIN_DISABLED',
            'Anmeldung eines abgeschalteten Kontos: ' . $emailForLog,
            $ip,
        ]);
        return false;
    }

    if (password_needs_rehash($user['password_hash'], AUTH_HASH_ALGO, AUTH_HASH_OPTIONS)) {
        $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
            ->execute([password_hash($password, AUTH_HASH_ALGO, AUTH_HASH_OPTIONS), (int) $user['id']]);
    }

    // Session-Fixation: vor dem Setzen der Rechte eine neue ID vergeben.
    session_regenerate_id(true);

    // Ist ein zweiter Faktor eingerichtet, ist das Passwort erst die
    // halbe Anmeldung. Die Sitzung bekommt hier ausdruecklich KEIN
    // admin_logged_in - sonst waere der zweite Faktor eine Zierde, die
    // sich durch das blosse Verlassen der Seite umgehen liesse.
    if (totp_aktiv($pdo, (int) $user['id'])) {
        $_SESSION['totp_pending_user']  = (int) $user['id'];
        $_SESSION['totp_pending_email'] = $user['email'];
        $_SESSION['totp_pending_since'] = time();

        $log->execute([
            'LOGIN_PASSWORD_OK',
            'Passwort richtig, zweiter Faktor ausstehend: ' . $user['email'],
            $ip,
        ]);

        return true;
    }

    auth_anmelden($user);

    $log->execute([
        'LOGIN_SUCCESS',
        'Erfolgreicher Login von ' . $user['email'],
        $ip,
    ]);

    return true;
}

/**
 * Trägt die Anmeldung in die Sitzung ein.
 *
 * Eine Funktion, weil es jetzt zwei Wege dorthin gibt: ohne zweiten
 * Faktor gleich nach dem Passwort, mit zweitem Faktor erst danach.
 * Zwei Kopien dieser drei Zeilen wären genau die Stelle, an der später
 * eine davon einen Schlüssel vergisst.
 */
function auth_anmelden(array $user): void
{
    $_SESSION['admin_logged_in'] = true;
    $_SESSION['admin_id']        = (int) $user['id'];
    $_SESSION['admin_email']     = $user['email'];
    // Die Rolle steht in der Sitzung und wird bei jedem Seitenaufruf
    // gegen die Datenbank geprueft (includes/auth.php): sonst behielte
    // jemand, dem gerade die Rechte entzogen wurden, sie bis zum
    // naechsten Anmelden.
    $_SESSION['admin_role']      = rolle_gueltig((string) ($user['role'] ?? '')) ? $user['role'] : 'admin';
    $_SESSION['admin_name']      = (string) ($user['name'] ?? '');

    unset($_SESSION['totp_pending_user'], $_SESSION['totp_pending_email'], $_SESSION['totp_pending_since']);
}

/**
 * Der Benutzer, dessen zweiter Faktor gerade aussteht - oder null.
 *
 * Prüft die Frist gleich mit: ein abgelaufener Zwischenzustand wird
 * verworfen, nicht bloß ignoriert.
 *
 * @return array{id: int, email: string}|null
 */
function auth_totp_wartet(): ?array
{
    $id = (int) ($_SESSION['totp_pending_user'] ?? 0);
    if ($id <= 0) {
        return null;
    }

    $seit = (int) ($_SESSION['totp_pending_since'] ?? 0);
    if ($seit <= 0 || (time() - $seit) > AUTH_TOTP_FRIST_MIN * 60) {
        unset($_SESSION['totp_pending_user'], $_SESSION['totp_pending_email'], $_SESSION['totp_pending_since']);
        return null;
    }

    return ['id' => $id, 'email' => (string) ($_SESSION['totp_pending_email'] ?? '')];
}

/**
 * Prüft den zweiten Faktor und schließt die Anmeldung ab.
 *
 * Nimmt sowohl ein Einmalkennwort als auch einen Ersatzcode entgegen -
 * wer sein Telefon nicht hat, soll nicht erst herausfinden müssen, in
 * welches von zwei Feldern er tippt.
 *
 * Fehlversuche zählen auf dieselbe Sperre ein wie falsche Passwörter:
 * sechs Ziffern lassen sich sonst durchprobieren, und zwar schneller
 * als ein Passwort.
 */
function auth_totp_pruefen(PDO $pdo, int $user_id, string $eingabe, string $ip): bool
{
    $stmt = $pdo->prepare('SELECT id, email, name, role, is_active, totp_secret FROM users WHERE id = ?');
    $stmt->execute([$user_id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user || empty($user['totp_secret'])) {
        return false;
    }

    $eingabe = trim($eingabe);
    $ok = totp_pruefen((string) $user['totp_secret'], $eingabe, time())
       || totp_ersatzcode_einloesen($pdo, $user_id, $eingabe);

    $log = $pdo->prepare('INSERT INTO logs (action_type, description, ip) VALUES (?, ?, ?)');

    if (!$ok) {
        // Auf dieselbe Sperre wie ein falsches Passwort: LOGIN_FAILED
        // ist der Zaehler, den auth_is_locked() liest.
        $log->execute([
            'LOGIN_FAILED',
            'Falscher zweiter Faktor für ' . $user['email'],
            $ip,
        ]);
        return false;
    }

    session_regenerate_id(true);
    auth_anmelden($user);

    $log->execute(['LOGIN_SUCCESS', 'Erfolgreicher Login (zweiter Faktor) von ' . $user['email'], $ip]);

    return true;
}
