<?php
require_once 'config.php';

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

$_SESSION = [];

// Cookie im Browser explizit löschen (verhindert, dass veraltete Session-ID mitgeschickt wird)
$params = session_get_cookie_params();
setcookie(
    session_name(), '',
    time() - 86400,
    $params['path'],
    $params['domain'],
    $params['secure'],
    $params['httponly']
);

session_destroy();

header("Location: login");
exit();
