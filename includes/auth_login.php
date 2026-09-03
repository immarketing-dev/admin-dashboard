<?php
/**
 * Login-Logik. Ersetzt die früheren login_process.php-Varianten und die
 * settings-basierte Passwortprüfung in login.php.
 */

const AUTH_MAX_ATTEMPTS = 5;
const AUTH_LOCKOUT_MIN   = 15;

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
    $pdo->prepare('INSERT INTO users (email, password_hash) VALUES (?, ?)')
        ->execute([$email, password_hash($password, AUTH_HASH_ALGO, AUTH_HASH_OPTIONS)]);

    $id = (int) $pdo->lastInsertId();

    $pdo->prepare('INSERT INTO logs (action_type, description) VALUES (?, ?)')
        ->execute(['USER_CREATED', 'Erster Benutzer angelegt: ' . $email]);

    return $id;
}

function auth_is_locked(PDO $pdo, string $ip): bool
{
    // Exakter Spaltenvergleich statt LIKE auf die Beschreibung: die
    // Beschreibung enthaelt die vom Angreifer frei waehlbare E-Mail-
    // Adresse, ein LIKE-Muster darauf liesse sich mit einer praeparierten
    // Adresse im E-Mail-Feld vergiften und eine fremde Adresse sperren.
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM logs
          WHERE action_type = 'LOGIN_FAILED'
            AND ip = ?
            AND created_at > (NOW() - INTERVAL ? MINUTE)"
    );
    $stmt->execute([$ip, AUTH_LOCKOUT_MIN]);

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
            AND created_at > (NOW() - INTERVAL ? MINUTE)"
    );
    $stmt->execute([$ip, AUTH_LOCKOUT_MIN]);

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
    $stmt = $pdo->prepare('SELECT id, email, password_hash FROM users WHERE email = ?');
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

    if (password_needs_rehash($user['password_hash'], AUTH_HASH_ALGO, AUTH_HASH_OPTIONS)) {
        $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
            ->execute([password_hash($password, AUTH_HASH_ALGO, AUTH_HASH_OPTIONS), (int) $user['id']]);
    }

    // Session-Fixation: vor dem Setzen der Rechte eine neue ID vergeben.
    session_regenerate_id(true);

    $_SESSION['admin_logged_in'] = true;
    $_SESSION['admin_id']        = (int) $user['id'];
    $_SESSION['admin_email']     = $user['email'];

    $log->execute([
        'LOGIN_SUCCESS',
        'Erfolgreicher Login von ' . $user['email'],
        $ip,
    ]);

    return true;
}
