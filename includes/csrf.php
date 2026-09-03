<?php
function csrf_token(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_check(): void {
    $token = $_POST['csrf_token'] ?? '';
    $session_token = $_SESSION['csrf_token'] ?? '';
    // Beide leer wuerde hash_equals als Treffer werten - eine Anfrage
    // ohne Cookie (SameSite=Lax bei Cross-Site-POST) startet aber genau
    // so eine frische, leere Session. Deshalb der Leerheitstest zuerst.
    if ($session_token === '' || $token === '' || !hash_equals($session_token, $token)) {
        http_response_code(403);
        die("Sicherheitsfehler: Ungültiges oder fehlendes CSRF-Token.");
    }
}

function csrf_field(): string {
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(csrf_token(), ENT_QUOTES) . '">';
}
