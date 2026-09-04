<?php
require_once 'config.php';
require_once 'includes/csrf.php';
require_once 'includes/auth_login.php';
require_once 'includes/auth_reset.php';

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
$hinweis   = '';
$ip        = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

// Welche der drei Ansichten zeigt die Seite? Anmelden ist der Normalfall;
// 'forgot' ist das Formular fuer die Adresse, 'reset' das fuer das neue
// Passwort. Letzteres nur mit gueltigem Token - ein abgelaufenes und ein
// erfundenes sind dabei nicht zu unterscheiden, und das ist richtig so.
$ansicht      = 'login';
$reset_token  = (string) ($_GET['reset'] ?? $_POST['reset_token'] ?? '');
$reset_gueltig = null;

if ($reset_token !== '' && !$first_run) {
    $reset_gueltig = reset_token_einloesen($pdo, $reset_token);
    $ansicht = $reset_gueltig ? 'reset' : 'reset_ungueltig';
} elseif (isset($_GET['forgot']) && !$first_run) {
    $ansicht = 'forgot';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';

    if ($first_run && $action === 'setup') {
        $email = trim($_POST['email'] ?? '');
        $pw    = $_POST['password']  ?? '';
        $pw2   = $_POST['password2'] ?? '';

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = t('Bitte eine gültige E-Mail-Adresse angeben.');
        } elseif (strlen($pw) < 12) {
            $error = t('Das Passwort muss mindestens 12 Zeichen lang sein.');
        } elseif ($pw !== $pw2) {
            $error = t('Die Passwörter stimmen nicht überein.');
        } else {
            $newId = auth_create_first_user($pdo, $email, $pw);
            session_regenerate_id(true);
            $_SESSION['admin_logged_in'] = true;
            $_SESSION['admin_id']        = $newId;
            $_SESSION['admin_email']     = $email;
            header('Location: index');
            exit();
        }
    } elseif (!$first_run && $action === 'request_reset') {
        $ergebnis = reset_anfordern(
            $pdo,
            (string) ($_POST['email'] ?? ''),
            $ip,
            BASE_URL !== '' ? BASE_URL : (($_SERVER['REQUEST_SCHEME'] ?? 'https') . '://' . ($_SERVER['HTTP_HOST'] ?? '')),
            setting('company_short', COMPANY_SHORT)
        );

        if ($ergebnis['gesperrt']) {
            $ansicht = 'forgot';
            $error   = t('Zu viele Anforderungen. Bitte später erneut versuchen.');
        } else {
            // Immer dieselbe Antwort, gleich ob die Adresse hinterlegt
            // ist. Sonst waere das Formular ein Verzeichnis der Konten.
            $ansicht = 'login';
            $hinweis = t('Falls diese Adresse hinterlegt ist, wurde eine E-Mail mit einem Link verschickt.');
        }
    } elseif (!$first_run && $action === 'do_reset') {
        $pw  = $_POST['password']  ?? '';
        $pw2 = $_POST['password2'] ?? '';

        if (!$reset_gueltig) {
            $ansicht = 'reset_ungueltig';
        } elseif (strlen($pw) < 12) {
            $ansicht = 'reset';
            $error   = t('Das Passwort muss mindestens 12 Zeichen lang sein.');
        } elseif ($pw !== $pw2) {
            $ansicht = 'reset';
            $error   = t('Die Passwörter stimmen nicht überein.');
        } elseif (reset_passwort_setzen($pdo, (int) $reset_gueltig['reset_id'], (int) $reset_gueltig['id'], $pw)) {
            // Nicht gleich anmelden: wer den Link aus einer fremden
            // Umgebung geoeffnet hat, soll das neue Passwort einmal
            // eingeben muessen - und dabei merken, ob er es kennt.
            $ansicht = 'login';
            $hinweis = t('Das Passwort wurde geändert. Sie können sich jetzt anmelden.');
        } else {
            // Das Token war zwischen Anzeige und Absenden verbraucht -
            // etwa weil dieselbe Mail zweimal geoeffnet wurde.
            $ansicht = 'reset_ungueltig';
        }
    } elseif (!$first_run && $action === 'login') {
        if (auth_is_locked($pdo, $ip)) {
            auth_note_lockout($pdo, $ip);
            $error = t('Zu viele Fehlversuche. Bitte in %d Minuten erneut versuchen.',
                        AUTH_LOCKOUT_MIN);
        } elseif (auth_attempt($pdo, trim($_POST['email'] ?? ''), $_POST['password'] ?? '', $ip)) {
            header('Location: index');
            exit();
        } else {
            // Bewusst unspezifisch: kein Rückschluss darauf, ob die
            // E-Mail existiert.
            $error = t('E-Mail-Adresse oder Passwort ist falsch.');
        }
    }
}
?>
<!DOCTYPE html>
<html lang="<?= lang() ?>">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= $ansicht === 'login' ? te('Login') : te('Passwort zurücksetzen') ?> | <?= htmlspecialchars(setting('company_short', COMPANY_SHORT)) ?> Admin</title>
  <link href="<?= asset('assets/vendor/bootstrap/bootstrap.min.css') ?>" rel="stylesheet">
  <link href="<?= asset('assets/vendor/bootstrap-icons/bootstrap-icons.css') ?>" rel="stylesheet">
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
        <p class="text-muted small mt-1"><?= te('Erster Start – bitte ein Admin-Passwort festlegen.') ?></p>
      <?php endif; ?>
    </div>

    <?php if ($error): ?>
      <div class="alert alert-danger py-2 small"><i class="bi bi-exclamation-triangle me-1"></i><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>
    <?php if ($hinweis): ?>
      <div class="alert alert-success py-2 small"><i class="bi bi-check-circle me-1"></i><?= htmlspecialchars($hinweis) ?></div>
    <?php endif; ?>

    <?php if ($first_run): ?>
    <form method="POST">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="setup">
      <div class="mb-3">
        <label class="form-label fw-semibold small"><?= te('E-Mail-Adresse') ?></label>
        <input type="email" name="email" class="form-control" required autofocus>
      </div>
      <div class="mb-3">
        <label class="form-label fw-semibold small"><?= te('Neues Passwort') ?></label>
        <input type="password" name="password" class="form-control" required placeholder="<?= te('Mindestens 12 Zeichen') ?>">
      </div>
      <div class="mb-4">
        <label class="form-label fw-semibold small"><?= te('Passwort wiederholen') ?></label>
        <input type="password" name="password2" class="form-control" required placeholder="<?= te('Passwort bestätigen') ?>">
      </div>
      <button type="submit" class="btn btn-primary w-100 fw-bold">
        <i class="bi bi-shield-lock me-1"></i> <?= te('Passwort setzen & einloggen') ?>
      </button>
    </form>
    <?php elseif ($ansicht === 'forgot'): ?>
    <p class="text-muted small mb-3">
      <?= te('Geben Sie die E-Mail-Adresse Ihres Zugangs an. Wir schicken einen Link, mit dem sich ein neues Passwort festlegen lässt.') ?>
    </p>
    <form method="POST">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="request_reset">
      <div class="mb-4">
        <label class="form-label fw-semibold small"><?= te('E-Mail-Adresse') ?></label>
        <input type="email" name="email" class="form-control" required autofocus>
      </div>
      <button type="submit" class="btn btn-primary w-100 fw-bold">
        <i class="bi bi-envelope me-1"></i> <?= te('Link anfordern') ?>
      </button>
      <a href="login" class="btn btn-link w-100 mt-2 small"><?= te('Zurück zur Anmeldung') ?></a>
    </form>

    <?php elseif ($ansicht === 'reset'): ?>
    <p class="text-muted small mb-3">
      <?= te('Legen Sie ein neues Passwort für %s fest.', htmlspecialchars($reset_gueltig['email'])) ?>
    </p>
    <form method="POST">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="do_reset">
      <?php /* Das Token wandert mit der Sendung, nicht nur in der Adresse:
               sonst ginge es beim Absenden verloren. */ ?>
      <input type="hidden" name="reset_token" value="<?= htmlspecialchars($reset_token, ENT_QUOTES) ?>">
      <div class="mb-3">
        <label class="form-label fw-semibold small"><?= te('Neues Passwort') ?></label>
        <input type="password" name="password" class="form-control" required autofocus placeholder="<?= te('Mindestens 12 Zeichen') ?>">
      </div>
      <div class="mb-4">
        <label class="form-label fw-semibold small"><?= te('Passwort wiederholen') ?></label>
        <input type="password" name="password2" class="form-control" required placeholder="<?= te('Passwort bestätigen') ?>">
      </div>
      <button type="submit" class="btn btn-primary w-100 fw-bold">
        <i class="bi bi-shield-lock me-1"></i> <?= te('Passwort speichern') ?>
      </button>
    </form>

    <?php elseif ($ansicht === 'reset_ungueltig'): ?>
    <?php /* Abgelaufen, schon benutzt oder erfunden - alle drei sehen
             gleich aus. Eine Unterscheidung waere eine Auskunft. */ ?>
    <div class="alert alert-warning py-2 small">
      <i class="bi bi-clock-history me-1"></i>
      <?= te('Dieser Link ist nicht mehr gültig. Fordern Sie einen neuen an.') ?>
    </div>
    <a href="login?forgot=1" class="btn btn-primary w-100 fw-bold"><?= te('Neuen Link anfordern') ?></a>
    <a href="login" class="btn btn-link w-100 mt-2 small"><?= te('Zurück zur Anmeldung') ?></a>

    <?php else: ?>
    <form method="POST">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="login">
      <div class="mb-3">
        <label class="form-label fw-semibold small"><?= te('E-Mail-Adresse') ?></label>
        <input type="email" name="email" class="form-control" required autofocus>
      </div>
      <div class="mb-4">
        <label class="form-label fw-semibold small"><?= te('Passwort') ?></label>
        <input type="password" name="password" class="form-control form-control-lg" required placeholder="••••••••">
      </div>
      <button type="submit" class="btn btn-primary w-100 fw-bold">
        <i class="bi bi-box-arrow-in-right me-1"></i> <?= te('Einloggen') ?>
      </button>
      <a href="login?forgot=1" class="btn btn-link w-100 mt-2 small"><?= te('Passwort vergessen?') ?></a>
    </form>
    <?php endif; ?>
  </div>
  <script src="<?= asset('assets/vendor/bootstrap/bootstrap.bundle.min.js') ?>"></script>
</body>
</html>
