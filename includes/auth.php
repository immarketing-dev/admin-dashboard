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

// Einmal taeglich das Protokoll kuerzen. Nicht in der Demo: dort darf
// nichts geschrieben werden, und der Datenbankbenutzer darf es auch nicht.
if (!demo_mode()) {
    require_once __DIR__ . '/logging.php';
    logs_aufraeumen($pdo);
}
