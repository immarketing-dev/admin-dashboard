<?php
require_once 'config.php';
require_once 'includes/csrf.php';
require_once 'includes/auth_login.php';

require_once 'includes/session.php';
app_session_start();

// In der Demo gibt es kein Anmeldeformular - und damit auch nichts, an
// dem jemand Passwörter durchprobieren könnte.
if (demo_mode()) {
    $_SESSION['admin_logged_in'] = true;
    header('Location: index');
    exit();
}

if (!empty($_SESSION['admin_logged_in'])) {
    header('Location: index');
    exit();
}

$first_run = auth_is_first_run($pdo);
$error     = '';
$ip        = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';

    if ($first_run && $action === 'setup') {
        $email = trim($_POST['email'] ?? '');
        $pw    = $_POST['password']  ?? '';
        $pw2   = $_POST['password2'] ?? '';

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Bitte eine gültige E-Mail-Adresse angeben.';
        } elseif (strlen($pw) < 12) {
            $error = 'Das Passwort muss mindestens 12 Zeichen lang sein.';
        } elseif ($pw !== $pw2) {
            $error = 'Die Passwörter stimmen nicht überein.';
        } else {
            $newId = auth_create_first_user($pdo, $email, $pw);
            session_regenerate_id(true);
            $_SESSION['admin_logged_in'] = true;
            $_SESSION['admin_id']        = $newId;
            $_SESSION['admin_email']     = $email;
            header('Location: index');
            exit();
        }
    } elseif (!$first_run && $action === 'login') {
        if (auth_is_locked($pdo, $ip)) {
            auth_note_lockout($pdo, $ip);
            $error = 'Zu viele Fehlversuche. Bitte in '
                   . AUTH_LOCKOUT_MIN . ' Minuten erneut versuchen.';
        } elseif (auth_attempt($pdo, trim($_POST['email'] ?? ''), $_POST['password'] ?? '', $ip)) {
            header('Location: index');
            exit();
        } else {
            // Bewusst unspezifisch: kein Rückschluss darauf, ob die
            // E-Mail existiert.
            $error = 'E-Mail-Adresse oder Passwort ist falsch.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Login | <?= htmlspecialchars(setting('company_short', COMPANY_SHORT)) ?> Admin</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">
  <?php require_once 'includes/theme.php'; ?>
  <style>
    body { background: #f0f2f5; display: flex; align-items: center; justify-content: center; min-height: 100vh; }
    .login-card { background: #fff; border-radius: 16px; box-shadow: 0 8px 40px rgba(0,0,0,0.10); padding: 2.5rem 2rem; width: 100%; max-width: 400px; }
    .login-logo { font-size: 2.5rem; color: var(--color-primary); }
    .login-title { font-weight: 700; font-size: 1.4rem; color: #173b6c; }
  </style>
</head>
<body>
  <div class="login-card">
    <div class="text-center mb-4">
      <div class="login-logo"><i class="bi bi-grid-1x2-fill"></i></div>
      <div class="login-title mt-2"><?= htmlspecialchars(setting('company_short', COMPANY_SHORT)) ?> Admin</div>
      <?php if ($first_run): ?>
        <p class="text-muted small mt-1">Erster Start – bitte ein Admin-Passwort festlegen.</p>
      <?php endif; ?>
    </div>

    <?php if ($error): ?>
      <div class="alert alert-danger py-2 small"><i class="bi bi-exclamation-triangle me-1"></i><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <?php if ($first_run): ?>
    <form method="POST">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="setup">
      <div class="mb-3">
        <label class="form-label fw-semibold small">E-Mail-Adresse</label>
        <input type="email" name="email" class="form-control" required autofocus>
      </div>
      <div class="mb-3">
        <label class="form-label fw-semibold small">Neues Passwort</label>
        <input type="password" name="password" class="form-control" required placeholder="Mindestens 12 Zeichen">
      </div>
      <div class="mb-4">
        <label class="form-label fw-semibold small">Passwort wiederholen</label>
        <input type="password" name="password2" class="form-control" required placeholder="Passwort bestätigen">
      </div>
      <button type="submit" class="btn btn-primary w-100 fw-bold">
        <i class="bi bi-shield-lock me-1"></i> Passwort setzen & einloggen
      </button>
    </form>
    <?php else: ?>
    <form method="POST">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="login">
      <div class="mb-3">
        <label class="form-label fw-semibold small">E-Mail-Adresse</label>
        <input type="email" name="email" class="form-control" required autofocus>
      </div>
      <div class="mb-4">
        <label class="form-label fw-semibold small">Passwort</label>
        <input type="password" name="password" class="form-control form-control-lg" required placeholder="••••••••">
      </div>
      <button type="submit" class="btn btn-primary w-100 fw-bold">
        <i class="bi bi-box-arrow-in-right me-1"></i> Einloggen
      </button>
    </form>
    <?php endif; ?>
  </div>
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
