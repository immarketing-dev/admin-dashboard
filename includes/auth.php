<?php
// Session-Setup und Auth-Check – nach require_once 'config.php' einbinden
// Vor dem Sitzungsstart: app_session_start() waehlt den Cookie-Namen
// abhaengig vom Demo-Modus, braucht demo_mode() also bereits.
require_once __DIR__ . '/demo.php';
require_once __DIR__ . '/session.php';
app_session_start();

// Im Demo-Modus entfällt die Anmeldung - dafür ist ab hier nichts mehr
// änderbar. Der Riegel steht vor jedem Handler, und das genügt, weil jede
// zustandsändernde Aktion in diesem Projekt ein POST ist.
//
// Zwei Stellen schrieben allerdings schon beim blossen Anzeigen: das
// automatische Aufräumen in trash.php und die Tokenbereinigung in
// sso.php. Beide sind eigens abgeschaltet, und tools/check_demo.php hält
// nach, dass keine dritte dazukommt.
if (demo_mode()) {
    $_SESSION['admin_logged_in'] = true;
    demo_guard();
} elseif (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header("Location: login");
    exit();
}

require_once __DIR__ . '/csrf.php';
csrf_token(); // Token bei jeder authentifizierten Anfrage initialisieren

// ── Rolle: bei jedem Aufruf frisch, nicht aus der Sitzung geglaubt ──
//
// Die Sitzung haelt die Rolle fuer die Anzeige, aber entscheiden darf
// sie nicht allein: wem gerade die Rechte entzogen oder das Konto
// abgeschaltet wurde, behielte sie sonst bis zum naechsten Anmelden -
// und das kann Tage dauern.
//
// Im Demo-Modus entfaellt das: dort gibt es keinen Benutzer, und der
// Besucher soll alles ansehen koennen.
require_once __DIR__ . '/users.php';

if (!demo_mode()) {
    $_auth_stmt = $pdo->prepare('SELECT role, is_active, name FROM users WHERE id = ?');
    $_auth_stmt->execute([(int) ($_SESSION['admin_id'] ?? 0)]);
    $_auth_user = $_auth_stmt->fetch(PDO::FETCH_ASSOC);

    if (!$_auth_user || (int) $_auth_user['is_active'] !== 1) {
        // Konto weg oder abgeschaltet: Sitzung beenden statt sie
        // weiterlaufen zu lassen.
        $_SESSION = [];
        session_destroy();
        header('Location: login');
        exit();
    }

    $_SESSION['admin_role'] = rolle_gueltig((string) $_auth_user['role']) ? $_auth_user['role'] : 'admin';
    $_SESSION['admin_name'] = (string) $_auth_user['name'];

    $_auth_seite = basename($_SERVER['PHP_SELF'] ?? '');
    if (!seite_erlaubt($_SESSION['admin_role'], $_auth_seite)) {
        // 403 und keine Weiterleitung: eine Weiterleitung auf das
        // Dashboard sieht aus wie ein Fehler, und der Benutzer probiert
        // es noch dreimal.
        http_response_code(403);
        require __DIR__ . '/kein_zugriff.php';
        exit();
    }
}

// Einmal taeglich das Protokoll kuerzen. Nicht in der Demo: dort darf
// nichts geschrieben werden, und der Datenbankbenutzer darf es auch nicht.
if (!demo_mode()) {
    require_once __DIR__ . '/logging.php';
    logs_aufraeumen($pdo);
}
