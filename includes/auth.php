<?php
// Session-Setup und Auth-Check – nach require_once 'config.php' einbinden
require_once __DIR__ . '/session.php';
app_session_start();

if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header("Location: login");
    exit();
}

require_once __DIR__ . '/csrf.php';
csrf_token(); // Token bei jeder authentifizierten Anfrage initialisieren
