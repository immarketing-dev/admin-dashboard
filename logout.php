<?php
require_once 'config.php';

require_once 'includes/session.php';
app_session_start();

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
