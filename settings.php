<?php
require_once 'config.php';
require_once 'includes/auth.php';

// ==========================================
// SAVE ACTIONS
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    csrf_check();

    if ($_POST['action'] === 'save_design') {
        $cp = trim($_POST['color_primary'] ?? '');
        $cs = trim($_POST['color_sidebar'] ?? '');
        if (preg_match('/^#[0-9a-fA-F]{6}$/', $cp)) {
            $s = $pdo->prepare("INSERT INTO settings (k,v) VALUES ('color_primary',?) ON DUPLICATE KEY UPDATE v=?");
            $s->execute([$cp, $cp]);
        }
        if (preg_match('/^#[0-9a-fA-F]{6}$/', $cs)) {
            $s = $pdo->prepare("INSERT INTO settings (k,v) VALUES ('color_sidebar',?) ON DUPLICATE KEY UPDATE v=?");
            $s->execute([$cs, $cs]);
        }
        header("Location: settings?tab=design&saved=1"); exit();
    }

    if ($_POST['action'] === 'reset_design') {
        $pdo->exec("DELETE FROM settings WHERE k IN ('color_primary','color_sidebar')");
        header("Location: settings?tab=design&saved=1"); exit();
    }

    if ($_POST['action'] === 'save_company') {
        $keys = ['company_name','company_short','base_url','main_website','admin_email','support_email'];
        foreach ($keys as $k) {
            $v = trim($_POST[$k] ?? '');
            $s = $pdo->prepare("INSERT INTO settings (k,v) VALUES (?,?) ON DUPLICATE KEY UPDATE v=?");
            $s->execute([$k, $v, $v]);
        }
        header("Location: settings?tab=company&saved=1"); exit();
    }

    if ($_POST['action'] === 'save_notifications') {
        $keys = ['notify_milestone_email','notify_quote_email'];
        foreach ($keys as $k) {
            $v = isset($_POST[$k]) ? '1' : '0';
            $s = $pdo->prepare("INSERT INTO settings (k,v) VALUES (?,?) ON DUPLICATE KEY UPDATE v=?");
            $s->execute([$k, $v, $v]);
        }
        header("Location: settings?tab=notifications&saved=1"); exit();
    }

    if ($_POST['action'] === 'upload_logo') {
        if (isset($_FILES['company_logo']) && $_FILES['company_logo']['error'] === UPLOAD_ERR_OK) {
            // SVG bewusst NICHT erlaubt: SVGs koennen <script> und Event-Handler
            // enthalten und werden vom Portal aus dem eigenen Origin ausgeliefert -
            // ein SVG-Logo waere gespeichertes XSS. Die Endung wird zusaetzlich
            // gegen eine eigene Liste geprueft (nicht nur der MIME-Typ), da
            // $_FILES[...]['type'] vom Client kommt und sich faelschen laesst -
            // sonst koennte z.B. eine .svg-Datei mit vorgetaeuschtem
            // "image/png"-Typ trotzdem mit .svg-Endung gespeichert werden.
            $allowed = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
            $allowed_ext = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
            $f = $_FILES['company_logo'];
            $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
            if (in_array($f['type'], $allowed) && in_array($ext, $allowed_ext) && $f['size'] <= 2 * 1024 * 1024) {
                $logo_dir = __DIR__ . '/uploads/logos/';
                if (!is_dir($logo_dir)) mkdir($logo_dir, 0755, true);
                // 'svg' bleibt in dieser Loesch-Schleife: die Endung wird zwar
                // nicht mehr akzeptiert, aber ein vor diesem Fix (oder ueber
                // die frueher fehlende Endungspruefung) abgelegtes SVG-Logo
                // soll beim naechsten Upload trotzdem entfernt werden.
                foreach (['png','jpg','jpeg','gif','webp','svg'] as $e) @unlink($logo_dir . 'company_logo.' . $e);
                $rel = 'uploads/logos/company_logo.' . $ext;
                move_uploaded_file($f['tmp_name'], __DIR__ . '/' . $rel);
                $pdo->prepare("INSERT INTO settings (k,v) VALUES ('company_logo',?) ON DUPLICATE KEY UPDATE v=?")->execute([$rel, $rel]);
            }
        }
        header("Location: settings?tab=company&saved=1"); exit();
    }

    if ($_POST['action'] === 'delete_logo') {
        $lp = setting('company_logo', '');
        if ($lp) @unlink(__DIR__ . '/' . $lp);
        $pdo->exec("DELETE FROM settings WHERE k='company_logo'");
        header("Location: settings?tab=company&saved=1"); exit();
    }

    if ($_POST['action'] === 'upload_favicon') {
        if (isset($_FILES['favicon_file']) && $_FILES['favicon_file']['error'] === UPLOAD_ERR_OK) {
            // SVG bewusst NICHT erlaubt: SVGs koennen <script> und Event-Handler
            // enthalten und werden vom Portal aus dem eigenen Origin ausgeliefert -
            // ein SVG-Favicon waere gespeichertes XSS. Nicht wieder hinzufuegen,
            // ohne serverseitiges Sanitizing des SVG-Inhalts.
            $allowed_fav = ['image/x-icon', 'image/vnd.microsoft.icon', 'image/png'];
            $f = $_FILES['favicon_file'];
            $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
            $allowed_ext = ['ico', 'png'];
            // MIME-Typ UND Endung muessen passen (UND statt ODER): $_FILES[...]['type']
            // kommt vom Client und laesst sich faelschen - bei einer reinen
            // ODER-Verknuepfung koennte eine .svg-Datei mit vorgetaeuschtem
            // "image/png"-Typ die Pruefung umgehen und trotzdem mit .svg-Endung
            // gespeichert werden.
            if (in_array($f['type'], $allowed_fav) && in_array($ext, $allowed_ext)) {
                if ($f['size'] <= 512 * 1024) {
                    $fav_dir = __DIR__ . '/uploads/favicons/';
                    if (!is_dir($fav_dir)) mkdir($fav_dir, 0755, true);
                    // 'svg' bleibt in dieser Loesch-Schleife, obwohl SVG nicht mehr
                    // hochgeladen werden kann: so wird ein vor diesem Fix bereits
                    // hochgeladenes SVG-Favicon beim naechsten Upload entfernt und
                    // bleibt nicht verwaist auf der Platte liegen.
                    foreach (['ico','png','svg'] as $e) @unlink($fav_dir . 'favicon.' . $e);
                    $rel = 'uploads/favicons/favicon.' . $ext;
                    move_uploaded_file($f['tmp_name'], __DIR__ . '/' . $rel);
                    $pdo->prepare("INSERT INTO settings (k,v) VALUES ('favicon',?) ON DUPLICATE KEY UPDATE v=?")->execute([$rel, $rel]);
                }
            }
        }
        header("Location: settings?tab=company&saved=1"); exit();
    }

    if ($_POST['action'] === 'delete_favicon') {
        $fp = setting('favicon', '');
        if ($fp) @unlink(__DIR__ . '/' . $fp);
        $pdo->exec("DELETE FROM settings WHERE k='favicon'");
        header("Location: settings?tab=company&saved=1"); exit();
    }

    if ($_POST['action'] === 'save_system') {
        $ll = max(50, min(2000, (int)($_POST['log_limit'] ?? 200)));
        $s = $pdo->prepare("INSERT INTO settings (k,v) VALUES ('log_limit',?) ON DUPLICATE KEY UPDATE v=?");
        $s->execute([$ll, $ll]);
        header("Location: settings?tab=system&saved=1"); exit();
    }
}

$active_tab = $_GET['tab'] ?? 'design';
$saved = isset($_GET['saved']);

// Read current values (fall back to constants)
$s_color_primary  = setting('color_primary', COLOR_PRIMARY);
$s_color_sidebar  = setting('color_sidebar', COLOR_SIDEBAR);
$s_company_name   = setting('company_name', COMPANY_NAME);
$s_company_short  = setting('company_short', COMPANY_SHORT);
$s_base_url       = setting('base_url', BASE_URL);
$s_main_website   = setting('main_website', MAIN_WEBSITE);
$s_admin_email    = setting('admin_email', ADMIN_EMAIL);
$s_support_email  = setting('support_email', SUPPORT_EMAIL);
$s_notify_ms      = setting('notify_milestone_email', '1');
$s_notify_quote   = setting('notify_quote_email', '1');
$s_log_limit      = setting('log_limit', '200');
$s_company_logo   = setting('company_logo', '');
$s_favicon        = setting('favicon', '');

// System info
$php_version = PHP_VERSION;
try { $db_version = $pdo->query("SELECT VERSION()")->fetchColumn(); } catch(Exception $e) { $db_version = 'n/a'; }
$page_title   = 'Einstellungen';
$page_heading = 'Einstellungen';
$current_page = basename($_SERVER['PHP_SELF']);
$extra_head = <<<'CSS'
  <style>
    .settings-card { background: var(--surface-card); border-radius: var(--radius-lg); padding: 30px; box-shadow: var(--elev-rest); border-top: 3px solid var(--color-primary); }
    .settings-section-title { font-family: 'Poppins', sans-serif; font-weight: 600; color: var(--text-heading); font-size: 15px; border-bottom: 1px solid var(--border-subtle); padding-bottom: 10px; margin-bottom: 20px; }
    .color-preview { width: 38px; height: 38px; border-radius: 6px; border: 1px solid var(--border-base); cursor: pointer; flex-shrink: 0; }
    .color-preview::-webkit-color-swatch-wrapper { padding: 0; }
    .color-preview::-webkit-color-swatch { border: none; border-radius: 5px; }
    .color-row { display: flex; align-items: center; gap: 12px; }
    .live-preview-bar { height: 8px; border-radius: 4px; transition: background 0.3s; }
    .sidebar-preview { width: 120px; background: var(--color-sidebar); border-radius: 8px; padding: 12px 10px; transition: background 0.3s; }
    .sidebar-preview .sp-item { height: 8px; border-radius: 4px; background: rgba(255,255,255,0.15); margin-bottom: 8px; }
    .sidebar-preview .sp-active { background: var(--color-primary); }
  </style>
CSS;

require 'includes/head.php';
require 'includes/layout_start.php';
?>

    <?php if($saved): ?>
      <div class="alert alert-success alert-dismissible fade show mb-3" role="alert">
        <i class="bi bi-check-circle me-2"></i> Einstellungen gespeichert.
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
      </div>
    <?php endif; ?>

    <!-- Tab Navigation -->
    <ul class="nav nav-tabs mb-0" style="border-radius:10px 10px 0 0; background:var(--surface-card); padding:10px 10px 0; box-shadow:0 2px 10px rgba(0,0,0,0.02);">
      <li class="nav-item">
        <a class="nav-link <?= $active_tab==='design' ? 'active' : '' ?>" href="?tab=design">
          <i class="bi bi-palette me-1"></i> Darstellung
        </a>
      </li>
      <li class="nav-item">
        <a class="nav-link <?= $active_tab==='company' ? 'active' : '' ?>" href="?tab=company">
          <i class="bi bi-building me-1"></i> Firma
        </a>
      </li>
      <li class="nav-item">
        <a class="nav-link <?= $active_tab==='notifications' ? 'active' : '' ?>" href="?tab=notifications">
          <i class="bi bi-bell me-1"></i> Benachrichtigungen
        </a>
      </li>
      <li class="nav-item">
        <a class="nav-link <?= $active_tab==='system' ? 'active' : '' ?>" href="?tab=system">
          <i class="bi bi-gear me-1"></i> System
        </a>
      </li>
    </ul>

    <!-- ========== TAB: DARSTELLUNG ========== -->
    <?php if($active_tab === 'design'): ?>
    <div class="settings-card" style="border-radius:0 10px 10px 10px;">

      <!-- Dark Mode -->
      <div class="settings-section-title"><i class="bi bi-moon-stars me-2"></i>Dark Mode</div>
      <div class="d-flex align-items-center gap-3 mb-4">
        <div class="form-check form-switch mb-0">
          <input class="form-check-input" type="checkbox" id="darkModeToggle" role="switch" style="width:3em;height:1.5em;">
          <label class="form-check-label ms-2 fw-semibold" for="darkModeToggle">Dark Mode aktivieren</label>
        </div>
        <small class="text-muted">Wird lokal im Browser gespeichert.</small>
      </div>

      <!-- Colors -->
      <form method="POST" id="designForm">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save_design">

        <div class="settings-section-title mt-3"><i class="bi bi-droplet me-2"></i>Farben</div>
        <div class="row g-4 mb-4">
          <div class="col-md-6">
            <label class="form-label fw-semibold">Primärfarbe (Akzent)</label>
            <div class="color-row">
              <input type="color" name="color_primary" id="cpPicker" class="color-preview" value="<?= htmlspecialchars($s_color_primary) ?>" oninput="updatePreview()">
              <input type="text" id="cpHex" class="form-control form-control-sm" value="<?= htmlspecialchars($s_color_primary) ?>" maxlength="7" placeholder="#149ddd" oninput="syncHex('cpPicker','cpHex')">
            </div>
            <div class="mt-2 live-preview-bar" id="prevPrimary" style="background:<?= htmlspecialchars($s_color_primary) ?>;"></div>
          </div>
          <div class="col-md-6">
            <label class="form-label fw-semibold">Sidebar-Farbe</label>
            <div class="color-row">
              <input type="color" name="color_sidebar" id="csPicker" class="color-preview" value="<?= htmlspecialchars($s_color_sidebar) ?>" oninput="updatePreview()">
              <input type="text" id="csHex" class="form-control form-control-sm" value="<?= htmlspecialchars($s_color_sidebar) ?>" maxlength="7" placeholder="#040b14" oninput="syncHex('csPicker','csHex')">
            </div>
            <div class="mt-2 sidebar-preview" id="prevSidebar" style="background:<?= htmlspecialchars($s_color_sidebar) ?>;">
              <div class="sp-item sp-active"></div>
              <div class="sp-item"></div>
              <div class="sp-item"></div>
            </div>
          </div>
        </div>

        <div class="d-flex gap-2">
          <button type="submit" class="btn btn-primary px-4"><i class="bi bi-check2 me-1"></i> Farben speichern</button>
          <button type="submit" form="resetForm" class="btn btn-outline-secondary"><i class="bi bi-arrow-counterclockwise me-1"></i> Zurücksetzen</button>
        </div>
      </form>
      <form method="POST" id="resetForm" style="display:none">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="reset_design">
      </form>
    </div>

    <!-- ========== TAB: FIRMA ========== -->
    <?php elseif($active_tab === 'company'): ?>
    <div class="settings-card" style="border-radius:0 10px 10px 10px;">
      <form method="POST">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save_company">

        <div class="settings-section-title"><i class="bi bi-building me-2"></i>Unternehmensangaben</div>
        <div class="row g-3 mb-4">
          <div class="col-md-6">
            <label class="form-label">Vollständiger Name</label>
            <input type="text" name="company_name" class="form-control" value="<?= htmlspecialchars($s_company_name) ?>">
            <div class="form-text">Wird auf Rechnungen und im Footer verwendet.</div>
          </div>
          <div class="col-md-6">
            <label class="form-label">Kurzname</label>
            <input type="text" name="company_short" class="form-control" value="<?= htmlspecialchars($s_company_short) ?>">
            <div class="form-text">Wird im Seitentitel und Portal-Header verwendet.</div>
          </div>
          <div class="col-md-6">
            <label class="form-label">Admin-Panel URL</label>
            <input type="url" name="base_url" class="form-control" value="<?= htmlspecialchars($s_base_url) ?>">
            <div class="form-text">Wichtig für QR-Codes und Links.</div>
          </div>
          <div class="col-md-6">
            <label class="form-label">Hauptwebsite</label>
            <input type="url" name="main_website" class="form-control" value="<?= htmlspecialchars($s_main_website) ?>">
          </div>
          <div class="col-md-6">
            <label class="form-label">Admin-E-Mail</label>
            <input type="email" name="admin_email" class="form-control" value="<?= htmlspecialchars($s_admin_email) ?>">
          </div>
          <div class="col-md-6">
            <label class="form-label">Support-E-Mail</label>
            <input type="email" name="support_email" class="form-control" value="<?= htmlspecialchars($s_support_email) ?>">
          </div>
        </div>

        <div class="alert alert-info d-flex gap-2 align-items-start py-2" style="font-size:13px;">
          <i class="bi bi-info-circle-fill mt-1"></i>
          <span>Diese Werte überschreiben die Konstanten in <code>config.php</code> und werden sofort auf allen Seiten wirksam.</span>
        </div>

        <button type="submit" class="btn btn-primary px-4"><i class="bi bi-check2 me-1"></i> Speichern</button>
      </form>

      <!-- Logo Upload (eigenes Formular wegen enctype) -->
      <hr class="my-4">
      <div class="settings-section-title"><i class="bi bi-image me-2"></i>Firmenlogo (für Rechnungen)</div>
      <div class="row g-4 align-items-start">
        <div class="col-md-5">
          <?php if ($s_company_logo && file_exists(__DIR__ . '/' . $s_company_logo)): ?>
            <div class="p-3 border rounded-3 bg-subtle text-center mb-3" style="max-width:280px;">
              <img src="<?= htmlspecialchars($s_company_logo) ?>?v=<?= filemtime(__DIR__ . '/' . $s_company_logo) ?>"
                   alt="Firmenlogo" style="max-height:70px; max-width:100%; object-fit:contain;">
            </div>
            <form method="POST">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="delete_logo">
              <button type="submit" class="btn btn-outline-danger btn-sm">
                <i class="bi bi-trash3 me-1"></i>Logo entfernen
              </button>
            </form>
          <?php else: ?>
            <div class="text-muted small p-3 border rounded-3 bg-subtle" style="max-width:280px;">
              <i class="bi bi-image text-muted" style="font-size:2rem;display:block;margin-bottom:8px;"></i>
              Noch kein Logo hinterlegt. Ohne Logo erscheint nur der Firmenname auf Rechnungen.
            </div>
          <?php endif; ?>
        </div>
        <div class="col-md-7">
          <form method="POST" enctype="multipart/form-data">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="upload_logo">
            <label class="form-label fw-semibold">Logo hochladen</label>
            <input type="file" name="company_logo" class="form-control mb-2" accept="image/png,image/jpeg,image/gif,image/webp" required>
            <div class="form-text mb-3">PNG, JPG, GIF oder WebP · max. 2 MB · Empfehlung: transparenter Hintergrund, ca. 400×120 px</div>
            <button type="submit" class="btn btn-primary btn-sm px-3">
              <i class="bi bi-upload me-1"></i>Hochladen & speichern
            </button>
          </form>
        </div>
      </div>

      <!-- Favicon Upload -->
      <hr class="my-4">
      <div class="settings-section-title"><i class="bi bi-window me-2"></i>Tab-Icon (Favicon)</div>
      <div class="row g-4 align-items-start">
        <div class="col-md-5">
          <?php if ($s_favicon && file_exists(__DIR__ . '/' . $s_favicon)): ?>
            <div class="p-3 border rounded-3 bg-subtle text-center mb-3" style="max-width:280px;">
              <img src="<?= htmlspecialchars($s_favicon) ?>?v=<?= filemtime(__DIR__ . '/' . $s_favicon) ?>"
                   alt="Favicon" style="max-height:48px; max-width:96px; object-fit:contain; image-rendering:auto;">
              <div class="text-muted small mt-2"><?= htmlspecialchars(basename($s_favicon)) ?></div>
            </div>
            <form method="POST">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="delete_favicon">
              <button type="submit" class="btn btn-outline-danger btn-sm">
                <i class="bi bi-trash3 me-1"></i>Favicon entfernen
              </button>
            </form>
          <?php else: ?>
            <div class="text-muted small p-3 border rounded-3 bg-subtle" style="max-width:280px;">
              <i class="bi bi-window text-muted" style="font-size:2rem;display:block;margin-bottom:8px;"></i>
              Noch kein Favicon hinterlegt. Browser zeigen dann das Standard-Icon.
            </div>
          <?php endif; ?>
        </div>
        <div class="col-md-7">
          <form method="POST" enctype="multipart/form-data">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="upload_favicon">
            <label class="form-label fw-semibold">Favicon hochladen</label>
            <input type="file" name="favicon_file" class="form-control mb-2" accept=".ico,.png" required>
            <div class="form-text mb-3">ICO oder PNG · max. 512 KB · Empfehlung: 32×32 px oder 64×64 px</div>
            <button type="submit" class="btn btn-primary btn-sm px-3">
              <i class="bi bi-upload me-1"></i>Hochladen & speichern
            </button>
          </form>
        </div>
      </div>
    </div>

    <!-- ========== TAB: BENACHRICHTIGUNGEN ========== -->
    <?php elseif($active_tab === 'notifications'): ?>
    <div class="settings-card" style="border-radius:0 10px 10px 10px;">
      <form method="POST">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save_notifications">

        <div class="settings-section-title"><i class="bi bi-bell me-2"></i>E-Mail-Benachrichtigungen</div>
        <div class="d-flex flex-column gap-3 mb-4">

          <div class="d-flex align-items-start gap-3 p-3 border rounded-3">
            <div class="form-check form-switch mb-0">
              <input class="form-check-input" type="checkbox" name="notify_milestone_email" id="notMs" role="switch" style="width:3em;height:1.5em;" <?= $s_notify_ms === '1' ? 'checked' : '' ?>>
            </div>
            <div>
              <label class="fw-semibold form-check-label" for="notMs">Meilenstein-E-Mail-Bestätigung</label>
              <p class="text-muted mb-0" style="font-size:13px;">Beim Abschließen eines Meilensteins im Portal wird der Kunde per E-Mail gefragt, ob er den Meilenstein offiziell bestätigen möchte.</p>
            </div>
          </div>

          <div class="d-flex align-items-start gap-3 p-3 border rounded-3">
            <div class="form-check form-switch mb-0">
              <input class="form-check-input" type="checkbox" name="notify_quote_email" id="notQt" role="switch" style="width:3em;height:1.5em;" <?= $s_notify_quote === '1' ? 'checked' : '' ?>>
            </div>
            <div>
              <label class="fw-semibold form-check-label" for="notQt">Angebots-E-Mail beim Versand</label>
              <p class="text-muted mb-0" style="font-size:13px;">Beim Versand eines Angebots wird automatisch eine E-Mail an den Kunden generiert.</p>
            </div>
          </div>

        </div>
        <button type="submit" class="btn btn-primary px-4"><i class="bi bi-check2 me-1"></i> Speichern</button>
      </form>
    </div>

    <!-- ========== TAB: SYSTEM ========== -->
    <?php elseif($active_tab === 'system'): ?>
    <div class="settings-card" style="border-radius:0 10px 10px 10px;">

      <div class="settings-section-title"><i class="bi bi-sliders me-2"></i>Log-Einstellungen</div>
      <form method="POST" class="mb-4">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save_system">
        <div class="row g-3 align-items-end">
          <div class="col-md-4">
            <label class="form-label fw-semibold">Log-Anzeigelimit</label>
            <input type="number" name="log_limit" class="form-control" min="50" max="2000" step="50" value="<?= htmlspecialchars($s_log_limit) ?>">
            <div class="form-text">Maximale Anzahl Logs auf der Log-Seite (Standard: 200, max. 2000).</div>
          </div>
          <div class="col-md-2">
            <button type="submit" class="btn btn-primary w-100"><i class="bi bi-check2 me-1"></i> Speichern</button>
          </div>
        </div>
      </form>

      <div class="settings-section-title mt-2"><i class="bi bi-info-circle me-2"></i>Systeminfo</div>
      <table class="table table-borderless" style="max-width:400px;">
        <tbody>
          <tr>
            <td class="text-muted fw-semibold" style="width:160px;">PHP Version</td>
            <td><code><?= htmlspecialchars($php_version) ?></code></td>
          </tr>
          <tr>
            <td class="text-muted fw-semibold">MySQL Version</td>
            <td><code><?= htmlspecialchars($db_version) ?></code></td>
          </tr>
          <tr>
            <td class="text-muted fw-semibold">Server</td>
            <td><code><?= htmlspecialchars($_SERVER['SERVER_SOFTWARE'] ?? 'n/a') ?></code></td>
          </tr>
          <tr>
            <td class="text-muted fw-semibold">Zeitzone</td>
            <td><code><?= htmlspecialchars(date_default_timezone_get()) ?></code></td>
          </tr>
        </tbody>
      </table>

    </div>
    <?php endif; ?>


  <script>
    // Dark mode toggle
    const dmToggle = document.getElementById('darkModeToggle');
    if (dmToggle) {
        dmToggle.checked = localStorage.getItem('darkMode') === '1';
        dmToggle.addEventListener('change', function() {
            if (this.checked) {
                document.documentElement.setAttribute('data-theme', 'dark');
                localStorage.setItem('darkMode', '1');
            } else {
                document.documentElement.removeAttribute('data-theme');
                localStorage.setItem('darkMode', '0');
            }
        });
    }

    // Color pickers
    function syncHex(pickerId, hexId) {
        const hex = document.getElementById(hexId).value;
        if (/^#[0-9a-fA-F]{6}$/.test(hex)) {
            document.getElementById(pickerId).value = hex;
            updatePreview();
        }
    }
    function updatePreview() {
        const cp = document.getElementById('cpPicker')?.value;
        const cs = document.getElementById('csPicker')?.value;
        if (cp) {
            document.getElementById('cpHex').value = cp;
            document.getElementById('prevPrimary').style.background = cp;
        }
        if (cs) {
            document.getElementById('csHex').value = cs;
            document.getElementById('prevSidebar').style.background = cs;
        }
    }
  </script>
<?php require 'includes/layout_end.php'; ?>
