<?php
require_once 'config.php';

if (!SSO_ENABLED) {
    http_response_code(404);
    exit();
}

// Alte Token bereinigen (älter als 5 Minuten)
$pdo->exec("DELETE FROM sso_tokens WHERE created_at < NOW() - INTERVAL 5 MINUTE");

$token = trim($_GET['token'] ?? '');

// Token muss 64 hex-Zeichen sein
if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
    header("Location: login?error=1");
    exit();
}

// Token atomar entwerten: nur genau eine Anfrage darf gewinnen. Ein reines
// SELECT-dann-UPDATE liesse zwei gleichzeitige Anfragen mit demselben
// Token beide durch die Pruefung kommen (Replay-Race).
$consume = $pdo->prepare(
    "UPDATE sso_tokens SET used = 1
      WHERE token = ? AND used = 0
        AND created_at > NOW() - INTERVAL 5 MINUTE"
);
$consume->execute([$token]);
if ($consume->rowCount() !== 1) {
    header("Location: login?error=1");
    exit();
}

// Lokale Session setzen
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

// Session-Fixation: session_start() uebernimmt eine vom Client
// mitgeschickte PHPSESSID. Vor dem Setzen der Rechte eine neue ID
// vergeben, sonst koennte eine vorab platzierte Session-ID nach
// erfolgreichem SSO als authentifizierter Admin wiederverwendet werden.
session_regenerate_id(true);

$_SESSION['admin_logged_in'] = true;

header("Location: index");
exit();
