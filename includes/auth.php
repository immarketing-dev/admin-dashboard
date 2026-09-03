<?php
// Session-Setup und Auth-Check – nach require_once 'config.php' einbinden
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => isset($_SERVER['HTTPS']),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header("Location: login");
    exit();
}

require_once __DIR__ . '/csrf.php';
csrf_token(); // Token bei jeder authentifizierten Anfrage initialisieren
