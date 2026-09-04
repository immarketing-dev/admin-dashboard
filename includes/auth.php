<?php
// Session-Setup und Auth-Check – nach require_once 'config.php' einbinden
require_once __DIR__ . '/session.php';
app_session_start();

// Der Demo-Modus braucht demo_mode() und demo_guard(). config.php hat sie
// normalerweise längst geladen; die Zeile macht die Abhängigkeit sichtbar
// und erspart einen unverständlichen Fatal Error, falls diese Datei
// einmal früher eingebunden wird.
require_once __DIR__ . '/demo.php';

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
