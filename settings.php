<?php
require_once 'config.php';
require_once __DIR__ . '/includes/logging.php';
require_once 'includes/auth.php';
require_once 'includes/mail_templates.php';

// ==========================================
// SAVE ACTIONS
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    csrf_check();

    if ($_POST['action'] === 'save_language') {
        $sprache = $_POST['ui_language'] ?? 'de';
        // Nur bekannte Sprachen: ein fremder Wert wuerde lang() dazu
        // bringen, eine nicht vorhandene Datei zu suchen.
        if (in_array($sprache, SPRACHEN, true)) {
            if (demo_mode()) {
                demo_einstellung_setzen('ui_language', $sprache);
            } else {
                $pdo->prepare("INSERT INTO settings (k,v) VALUES ('ui_language',?) ON DUPLICATE KEY UPDATE v=?")
                    ->execute([$sprache, $sprache]);
                log_event($pdo, 'SETTINGS_LANG', "Sprache der Oberfläche auf '$sprache' gesetzt.");
            }
        }
        header("Location: settings?tab=design&saved=1"); exit();
    }

    if ($_POST['action'] === 'save_design') {
        $cp = trim($_POST['color_primary'] ?? '');
        $cs = trim($_POST['color_sidebar'] ?? '');
        foreach (['color_primary' => $cp, 'color_sidebar' => $cs] as $schluessel => $wert) {
            if (!preg_match('/^#[0-9a-fA-F]{6}$/', $wert)) continue;
            if (demo_mode()) {
                demo_einstellung_setzen($schluessel, $wert);
                continue;
            }
            $pdo->prepare("INSERT INTO settings (k,v) VALUES (?,?) ON DUPLICATE KEY UPDATE v=?")
                ->execute([$schluessel, $wert, $wert]);
        }
        if (!demo_mode()) {
            log_event($pdo, 'SETTINGS_DESIGN', "Farben geändert: Primär $cp, Seitenleiste $cs.");
        }
        header("Location: settings?tab=design&saved=1"); exit();
    }

    if ($_POST['action'] === 'reset_design') {
        if (demo_mode()) {
            demo_einstellung_loeschen('color_primary');
            demo_einstellung_loeschen('color_sidebar');
        } else {
            $pdo->exec("DELETE FROM settings WHERE k IN ('color_primary','color_sidebar')");
            log_event($pdo, 'SETTINGS_DESIGN', 'Farben auf Standard zurückgesetzt.');
        }
        header("Location: settings?tab=design&saved=1"); exit();
    }

    if ($_POST['action'] === 'save_company') {
        $keys = ['company_name','company_short','base_url','main_website','admin_email','support_email',
                 'bank_holder','bank_iban','bank_bic','payment_note','default_hourly_rate',
                 // Anschrift und Steuerangaben. company_street und
                 // company_city wurden von der PDF-Erzeugung schon gelesen,
                 // aber nie gespeichert - es gab kein Feld dafuer.
                 'company_street','company_zip','company_city','company_country',
                 'company_vat_id','company_tax_number'];
        foreach ($keys as $k) {
            $v = trim($_POST[$k] ?? '');
            $s = $pdo->prepare("INSERT INTO settings (k,v) VALUES (?,?) ON DUPLICATE KEY UPDATE v=?");
            $s->execute([$k, $v, $v]);
        }
        log_event($pdo, 'SETTINGS_COMPANY', 'Unternehmensangaben und Bankverbindung gespeichert.');
        header("Location: settings?tab=company&saved=1"); exit();
    }

    if ($_POST['action'] === 'save_notifications') {
        $keys = ['notify_milestone_email','notify_quote_email'];
        foreach ($keys as $k) {
            $v = isset($_POST[$k]) ? '1' : '0';
            $s = $pdo->prepare("INSERT INTO settings (k,v) VALUES (?,?) ON DUPLICATE KEY UPDATE v=?");
            $s->execute([$k, $v, $v]);
        }
        log_event($pdo, 'SETTINGS_NOTIFY', 'Benachrichtigungen gespeichert.');
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
        log_event($pdo, 'SETTINGS_LOGO', 'Firmenlogo hochgeladen.');
        header("Location: settings?tab=company&saved=1"); exit();
    }

    if ($_POST['action'] === 'delete_logo') {
        $lp = setting('company_logo', '');
        if ($lp) @unlink(__DIR__ . '/' . $lp);
        $pdo->exec("DELETE FROM settings WHERE k='company_logo'");
        log_event($pdo, 'SETTINGS_LOGO', 'Firmenlogo entfernt.');
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
        log_event($pdo, 'SETTINGS_FAVICON', 'Favicon hochgeladen.');
        header("Location: settings?tab=company&saved=1"); exit();
    }

    if ($_POST['action'] === 'delete_favicon') {
        $fp = setting('favicon', '');
        if ($fp) @unlink(__DIR__ . '/' . $fp);
        $pdo->exec("DELETE FROM settings WHERE k='favicon'");
        log_event($pdo, 'SETTINGS_FAVICON', 'Favicon entfernt.');
        header("Location: settings?tab=company&saved=1"); exit();
    }

    // Rahmen aller Mails: Fußzeile und Signatur. Logo, Firmenname und
    // Farben stehen bereits unter ihren eigenen Schlüsseln und werden
    // von mail_frame() mitbenutzt.
    if ($_POST['action'] === 'save_mail_frame') {
        $st = $pdo->prepare("INSERT INTO settings (k,v) VALUES (?,?) ON DUPLICATE KEY UPDATE v=?");
        foreach (['mail_footer', 'mail_signature'] as $k) {
            $v = trim($_POST[$k] ?? '');
            $st->execute([$k, $v, $v]);
        }
        log_event($pdo, 'SETTINGS_MAIL_FRAME', 'Rahmen der E-Mails geändert.');
        header("Location: settings?tab=mail&saved=1"); exit();
    }

    // Eine einzelne Vorlage. Stimmt der Text mit dem Standard überein,
    // wird der Eintrag gelöscht statt gespeichert - dann zieht die Vorlage
    // künftige Änderungen am Standard automatisch mit.
    if ($_POST['action'] === 'save_mail_template') {
        $key  = $_POST['tpl_key'] ?? '';
        $alle = mail_templates();
        if (isset($alle[$key])) {
            $subject = trim($_POST['tpl_subject'] ?? '');
            $body    = trim($_POST['tpl_body'] ?? '');
            $del = $pdo->prepare("DELETE FROM settings WHERE k = ?");
            $set = $pdo->prepare("INSERT INTO settings (k,v) VALUES (?,?) ON DUPLICATE KEY UPDATE v=?");

            foreach ([['subject', $subject], ['body', $body]] as [$feld, $wert]) {
                $sk = 'mailtpl_' . $key . '_' . $feld;
                if ($wert === '' || $wert === trim($alle[$key][$feld])) {
                    $del->execute([$sk]);
                } else {
                    $set->execute([$sk, $wert, $wert]);
                }
            }
        }
        log_event($pdo, 'SETTINGS_MAIL_TPL', "E-Mail-Vorlage '$key' geändert.");
        header("Location: settings?tab=mail&tpl=" . urlencode($key) . "&saved=1"); exit();
    }

    // Zurück auf den Standard: die gespeicherte Fassung wird entfernt.
    if ($_POST['action'] === 'reset_mail_template') {
        $key = $_POST['tpl_key'] ?? '';
        if (isset(mail_templates()[$key])) {
            $pdo->prepare("DELETE FROM settings WHERE k IN (?, ?)")
                ->execute(['mailtpl_' . $key . '_subject', 'mailtpl_' . $key . '_body']);
        }
        log_event($pdo, 'SETTINGS_MAIL_TPL', "E-Mail-Vorlage '$key' auf Standard zurückgesetzt.");
        header("Location: settings?tab=mail&tpl=" . urlencode($key) . "&saved=1"); exit();
    }

    // ── Benutzer ─────────────────────────────────────────────────────
    // Nur die Verwaltung. Der Riegel in includes/auth.php sperrt die
    // ganze Seite fuer andere Rollen; die Pruefung hier ist die zweite
    // Tuer - falls jemand die Seitenrechte spaeter lockert, bleibt
    // wenigstens die Benutzerverwaltung zu.
    if (in_array($_POST['action'], ['user_add', 'user_edit', 'user_toggle'], true)) {
        require_once __DIR__ . '/includes/users.php';

        if (!ist_verwaltung()) {
            http_response_code(403);
            exit('Nicht berechtigt.');
        }

        if ($_POST['action'] === 'user_add') {
            $e = benutzer_anlegen(
                $pdo,
                (string) ($_POST['email'] ?? ''),
                (string) ($_POST['name'] ?? ''),
                (string) ($_POST['role'] ?? 'staff')
            );
            header('Location: settings?tab=users&' . ($e['ok'] ? 'saved=1&newuser=' . $e['id'] : 'uerr=' . urlencode($e['fehler'])));
            exit();
        }

        if ($_POST['action'] === 'user_edit') {
            $e = benutzer_aendern(
                $pdo,
                (int) ($_POST['user_id'] ?? 0),
                (string) ($_POST['name'] ?? ''),
                (string) ($_POST['role'] ?? 'staff')
            );
            header('Location: settings?tab=users&' . ($e['ok'] ? 'saved=1' : 'uerr=' . urlencode($e['fehler'])));
            exit();
        }

        $e = benutzer_umschalten(
            $pdo,
            (int) ($_POST['user_id'] ?? 0),
            ($_POST['aktiv'] ?? '0') === '1'
        );
        header('Location: settings?tab=users&' . ($e['ok'] ? 'saved=1' : 'uerr=' . urlencode($e['fehler'])));
        exit();
    }

    // ── Zweiter Faktor ───────────────────────────────────────────────
    // Drei Schritte, absichtlich getrennt: erzeugen, bestaetigen,
    // abschalten. Zwischen den ersten beiden liegt das Abscannen - und
    // solange es nicht bestaetigt ist, aendert sich an der Anmeldung
    // nichts. Ein Fehler beim Abscannen sperrt so niemanden aus.
    if ($_POST['action'] === 'totp_start') {
        require_once __DIR__ . '/includes/totp.php';
        totp_einrichten($pdo, (int) ($_SESSION['admin_id'] ?? 0));
        header("Location: settings?tab=system&totp=setup"); exit();
    }

    if ($_POST['action'] === 'totp_confirm') {
        require_once __DIR__ . '/includes/totp.php';

        $codes = totp_bestaetigen(
            $pdo,
            (int) ($_SESSION['admin_id'] ?? 0),
            (string) ($_POST['code'] ?? ''),
            time()
        );

        if ($codes === null) {
            header("Location: settings?tab=system&totp=setup&err=code"); exit();
        }

        // Die Ersatzcodes existieren nur in diesem Augenblick im
        // Klartext. Sie wandern durch die Sitzung zur naechsten Seite -
        // nicht in die Adresse, wo sie im Verlauf und in Protokollen
        // stuenden.
        $_SESSION['totp_neue_codes'] = $codes;
        header("Location: settings?tab=system&totp=done"); exit();
    }

    if ($_POST['action'] === 'totp_off') {
        require_once __DIR__ . '/includes/totp.php';
        totp_abschalten($pdo, (int) ($_SESSION['admin_id'] ?? 0));
        header("Location: settings?tab=system&saved=1"); exit();
    }

    // Der Schluessel fuer die Anfrage-Schnittstelle (api/leads.php).
    //
    // Erzeugt und entzogen, nicht eingetippt: ein von Hand gewaehlter
    // Schluessel ist im Zweifel "geheim123". Angezeigt wird er nur
    // einmal nach dem Erzeugen - danach steht er in der Einstellung und
    // laesst sich dort nachsehen, aber der Weg dahin ist bewusst der
    // laengere.
    if ($_POST['action'] === 'generate_api_key' || $_POST['action'] === 'revoke_api_key') {
        require_once __DIR__ . '/includes/api_keys.php';

        // Je Zweck ein eigener Schluessel: sie gehen an verschiedene
        // Dienste - das Kontaktformular der Website, den Maildienst -,
        // und wer einen davon wechselt, soll nicht den anderen
        // mitentziehen muessen.
        $zweck = in_array($_POST['zweck'] ?? '', ['leads', 'tickets'], true) ? $_POST['zweck'] : 'leads';

        if ($_POST['action'] === 'generate_api_key') {
            $key = api_schluessel_erzeugen();
            $s = $pdo->prepare("INSERT INTO settings (k,v) VALUES (?,?) ON DUPLICATE KEY UPDATE v=?");
            $s->execute(['api_key_' . $zweck, $key, $key]);

            log_event($pdo, 'SETTINGS_API_KEY', "Neuer API-Schlüssel erzeugt ($zweck).");
            header("Location: settings?tab=system&saved=1&newkey=$zweck"); exit();
        }

        $pdo->prepare("DELETE FROM settings WHERE k = ?")->execute(['api_key_' . $zweck]);
        log_event($pdo, 'SETTINGS_API_KEY', "API-Schlüssel entzogen ($zweck); die Schnittstelle ist wieder zu.");
        header("Location: settings?tab=system&saved=1"); exit();
    }

    // Zahlungserinnerungen. Die Stufen sind Tage nach Faelligkeit, als
    // Liste: "7, 21" heisst eine Erinnerung nach einer Woche und eine
    // zweite nach drei. Leer bedeutet: keine Automatik - das ist der
    // Auslieferungszustand, damit ein Update keine Installation dazu
    // bringt, unaufgefordert Mails an ihre Kunden zu schicken.
    if ($_POST['action'] === 'save_reminders') {
        require_once __DIR__ . '/includes/reminders.php';

        // Ueber mahnstufen() normalisiert und zurueckgeschrieben: was in
        // der Einstellung steht, ist danach genau das, wonach sich der
        // Cron-Lauf richtet - sortiert, ohne Dubletten, ohne Unsinn. Wer
        // "21,7,abc" eintippt, sieht anschliessend "7, 21" und weiss,
        // woran er ist.
        $stufen = mahnstufen((string) ($_POST['reminder_days'] ?? ''));
        $wert   = implode(', ', $stufen);

        $s = $pdo->prepare("INSERT INTO settings (k,v) VALUES ('reminder_days',?) ON DUPLICATE KEY UPDATE v=?");
        $s->execute([$wert, $wert]);

        log_event($pdo, 'SETTINGS_REMINDERS', $wert === ''
            ? 'Automatische Zahlungserinnerungen abgeschaltet.'
            : "Mahnstufen gesetzt: $wert Tage nach Fälligkeit.");
        header("Location: settings?tab=system&saved=1"); exit();
    }

    if ($_POST['action'] === 'save_system') {
        $ll = max(50, min(2000, (int)($_POST['log_limit'] ?? 200)));
        $s = $pdo->prepare("INSERT INTO settings (k,v) VALUES ('log_limit',?) ON DUPLICATE KEY UPDATE v=?");
        $s->execute([$ll, $ll]);
        log_event($pdo, 'SETTINGS_SYSTEM', 'Systemeinstellungen gespeichert.');
        header("Location: settings?tab=system&saved=1"); exit();
    }
}

$active_tab = $_GET['tab'] ?? 'design';
$saved = isset($_GET['saved']);

// Read current values (fall back to constants)
// demo_einstellung() liefert ausserhalb der Demo den uebergebenen Wert.
$s_ui_language    = demo_einstellung('ui_language',   setting('ui_language', 'de'));
$s_color_primary  = demo_einstellung('color_primary', setting('color_primary', COLOR_PRIMARY));
$s_color_sidebar  = demo_einstellung('color_sidebar', setting('color_sidebar', COLOR_SIDEBAR));
$s_company_name   = setting('company_name', COMPANY_NAME);
$s_company_short  = setting('company_short', COMPANY_SHORT);
// Stundensatz, der gilt, wenn weder Projekt noch Kunde einen eigenen
// tragen - siehe stundensatz() in includes/time_billing.php.
$s_hourly_rate    = setting('default_hourly_rate', '60');
$s_base_url       = setting('base_url', BASE_URL);
$s_main_website   = setting('main_website', MAIN_WEBSITE);
$s_admin_email    = setting('admin_email', ADMIN_EMAIL);
$s_support_email  = setting('support_email', SUPPORT_EMAIL);
$s_notify_ms      = setting('notify_milestone_email', '1');
$s_notify_quote   = setting('notify_quote_email', '1');
$s_log_limit      = setting('log_limit', '200');
// Tage nach Faelligkeit, an denen automatisch erinnert wird. Leer =
// keine Automatik; der Knopf in der Rechnungsliste geht trotzdem.
$s_reminder_days  = setting('reminder_days', '');
// Der Schluessel der Anfrage-Schnittstelle. Leer = die Schnittstelle
// ist zu, nicht offen.
$s_api_keys       = ['leads' => setting('api_key_leads', ''), 'tickets' => setting('api_key_tickets', '')];

// Zweiter Faktor. Im Demo-Modus gibt es keinen angemeldeten Benutzer,
// dessen Faktor man einrichten koennte - dort bleibt der Abschnitt weg.
require_once __DIR__ . '/includes/totp.php';
require_once __DIR__ . '/includes/users.php';
// Die Benutzerliste nur, wenn der Reiter sie zeigt.
$s_benutzer = $active_tab === 'users' ? benutzer_liste($pdo) : [];
$s_user_id     = (int) ($_SESSION['admin_id'] ?? 0);
$s_totp_aktiv  = $s_user_id > 0 && totp_aktiv($pdo, $s_user_id);
$s_totp_offen  = $s_totp_aktiv ? totp_ersatzcodes_offen($pdo, $s_user_id) : 0;
$s_totp_setup  = ($_GET['totp'] ?? '') === 'setup' && $s_user_id > 0;
$s_totp_gehei  = $s_totp_setup ? totp_geheimnis($pdo, $s_user_id) : null;
// Einmal anzeigen, dann aus der Sitzung nehmen: ein Neuladen der Seite
// soll die Codes nicht noch einmal hervorholen.
$s_totp_codes  = $_SESSION['totp_neue_codes'] ?? null;
unset($_SESSION['totp_neue_codes']);
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
        <i class="bi bi-check-circle me-2"></i> <?= te('Einstellungen gespeichert.') ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
      </div>
    <?php endif; ?>

    <!-- Tab Navigation -->
    <ul class="nav nav-tabs mb-0" style="border-radius:10px 10px 0 0; background:var(--surface-card); padding:10px 10px 0; box-shadow:0 2px 10px rgba(0,0,0,0.02);">
      <li class="nav-item">
        <a class="nav-link <?= $active_tab==='design' ? 'active' : '' ?>" href="?tab=design">
          <i class="bi bi-palette me-1"></i> <?= te('Darstellung') ?>
        </a>
      </li>
      <li class="nav-item">
        <a class="nav-link <?= $active_tab==='company' ? 'active' : '' ?>" href="?tab=company">
          <i class="bi bi-building me-1"></i> <?= te('Firma') ?>
        </a>
      </li>
      <li class="nav-item">
        <a class="nav-link <?= $active_tab==='notifications' ? 'active' : '' ?>" href="?tab=notifications">
          <i class="bi bi-bell me-1"></i> <?= te('Benachrichtigungen') ?>
        </a>
      </li>
      <li class="nav-item">
        <a class="nav-link <?= $active_tab==='mail' ? 'active' : '' ?>" href="?tab=mail">
          <i class="bi bi-envelope-paper me-1"></i> <?= te('E-Mail-Vorlagen') ?>
        </a>
      </li>
      <?php /* Der Reiter nur fuer die Verwaltung: der Riegel in
               includes/auth.php sperrt die Seite ohnehin, aber ein
               Reiter, der zur Sperrseite fuehrt, sieht aus wie ein
               Fehler. */ ?>
      <?php if (ist_verwaltung() || demo_mode()): ?>
      <li class="nav-item">
        <a class="nav-link <?= $active_tab==='users' ? 'active' : '' ?>" href="?tab=users">
          <i class="bi bi-people me-1"></i> <?= te('Benutzer') ?>
        </a>
      </li>
      <?php endif; ?>
      <li class="nav-item">
        <a class="nav-link <?= $active_tab==='system' ? 'active' : '' ?>" href="?tab=system">
          <i class="bi bi-gear me-1"></i> <?= te('System') ?>
        </a>
      </li>
    </ul>

    <!-- ========== TAB: DARSTELLUNG ========== -->
    <?php if($active_tab === 'design'): ?>
    <div class="settings-card" style="border-radius:0 10px 10px 10px;">

<?php if (demo_mode()):  ?>
      <div class="demo-hinweis mb-4" role="status">
        <i class="bi bi-info-circle" aria-hidden="true"></i>
        <span><?= te('Sprache, Farben und Thema gelten nur für Ihren Besuch. Andere Besucher sehen weiterhin die Vorgaben, und beim nächsten Aufruf beginnt alles von vorn.') ?></span>
      </div>
<?php endif;  ?>

      <!-- Sprache -->
      <div class="settings-section-title"><i class="bi bi-translate me-2"></i><?= te('Sprache') ?></div>
      <form method="POST" class="mb-4">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save_language">
        <div class="row g-3 align-items-end">
          <div class="col-md-5">
            <label class="form-label fw-semibold" for="uiLanguage"><?= te('Sprache der Oberfläche') ?></label>
            <select name="ui_language" id="uiLanguage" class="form-select">
              <option value="de" <?= $s_ui_language === 'de' ? 'selected' : '' ?>><?= te('Deutsch') ?></option>
              <option value="en" <?= $s_ui_language === 'en' ? 'selected' : '' ?>><?= te('Englisch') ?></option>
            </select>
          </div>
          <div class="col-md-auto">
            <button type="submit" class="btn btn-primary px-4">
              <i class="bi bi-check2 me-1"></i> <?= te('Sprache speichern') ?>
            </button>
          </div>
        </div>
        <small class="text-muted d-block mt-2">
          <?= te('Gilt für das gesamte Panel. Das Kundenportal folgt der Sprache des jeweiligen Kontakts.') ?>
        </small>
      </form>

      <!-- Dark Mode -->
      <div class="settings-section-title"><i class="bi bi-moon-stars me-2"></i><?= te('Dark Mode') ?></div>
      <div class="d-flex align-items-center gap-3 mb-4">
        <div class="form-check form-switch mb-0">
          <input class="form-check-input" type="checkbox" id="darkModeToggle" role="switch" style="width:3em;height:1.5em;">
          <label class="form-check-label ms-2 fw-semibold" for="darkModeToggle"><?= te('Dark Mode aktivieren') ?></label>
        </div>
        <small class="text-muted"><?= te('Wird lokal im Browser gespeichert.') ?></small>
      </div>

      <!-- Colors -->
      <form method="POST" id="designForm">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save_design">

        <div class="settings-section-title mt-3"><i class="bi bi-droplet me-2"></i><?= te('Farben') ?></div>
        <div class="row g-4 mb-4">
          <div class="col-md-6">
            <label class="form-label fw-semibold"><?= te('Primärfarbe (Akzent)') ?></label>
            <div class="color-row">
              <input type="color" name="color_primary" id="cpPicker" class="color-preview" value="<?= htmlspecialchars($s_color_primary) ?>" oninput="updatePreview()">
              <input type="text" id="cpHex" class="form-control form-control-sm" value="<?= htmlspecialchars($s_color_primary) ?>" maxlength="7" placeholder="#149ddd" oninput="syncHex('cpPicker','cpHex')">
            </div>
            <div class="mt-2 live-preview-bar" id="prevPrimary" style="background:<?= htmlspecialchars($s_color_primary) ?>;"></div>
          </div>
          <div class="col-md-6">
            <label class="form-label fw-semibold"><?= te('Sidebar-Farbe') ?></label>
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
          <button type="submit" class="btn btn-primary px-4"><i class="bi bi-check2 me-1"></i> <?= te('Farben speichern') ?></button>
          <button type="submit" form="resetForm" class="btn btn-outline-secondary"><i class="bi bi-arrow-counterclockwise me-1"></i> <?= te('Zurücksetzen') ?></button>
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

        <div class="settings-section-title"><i class="bi bi-building me-2"></i><?= te('Unternehmensangaben') ?></div>
        <div class="row g-3 mb-4">
          <div class="col-md-6">
            <label class="form-label"><?= te('Vollständiger Name') ?></label>
            <input type="text" name="company_name" class="form-control" value="<?= htmlspecialchars($s_company_name) ?>">
            <div class="form-text"><?= te('Wird auf Rechnungen und im Footer verwendet.') ?></div>
          </div>
          <div class="col-md-6">
            <label class="form-label"><?= te('Kurzname') ?></label>
            <input type="text" name="company_short" class="form-control" value="<?= htmlspecialchars($s_company_short) ?>">
          </div>

          <?php /* Diese Felder wurden von der PDF-Erzeugung schon gelesen
                   (company_street, company_city), aber nirgends gespeichert -
                   es gab kein Eingabefeld dafuer. Fuer eine elektronische
                   Rechnung sind sie Pflicht. */ ?>
          <div class="col-md-6">
            <label class="form-label"><?= te('Straße und Hausnummer') ?></label>
            <input type="text" name="company_street" class="form-control" value="<?= htmlspecialchars(setting('company_street', '')) ?>">
            <div class="form-text"><?= te('Steht auf Rechnungs-PDFs und ist für die XRechnung Pflicht.') ?></div>
          </div>
          <div class="col-md-3">
            <label class="form-label"><?= te('PLZ') ?></label>
            <input type="text" name="company_zip" class="form-control" maxlength="20" value="<?= htmlspecialchars(setting('company_zip', '')) ?>">
          </div>
          <div class="col-md-3">
            <label class="form-label"><?= te('Ort') ?></label>
            <input type="text" name="company_city" class="form-control" value="<?= htmlspecialchars(setting('company_city', '')) ?>">
          </div>
          <div class="col-md-2">
            <label class="form-label"><?= te('Land') ?></label>
            <input type="text" name="company_country" class="form-control" maxlength="2"
                   value="<?= htmlspecialchars(setting('company_country', 'DE')) ?>" placeholder="DE">
            <div class="form-text"><?= te('Zwei Buchstaben') ?></div>
          </div>
          <div class="col-md-5">
            <label class="form-label"><?= te('USt-IdNr.') ?></label>
            <input type="text" name="company_vat_id" class="form-control" maxlength="30"
                   value="<?= htmlspecialchars(setting('company_vat_id', '')) ?>" placeholder="DE123456789">
          </div>
          <div class="col-md-5">
            <label class="form-label"><?= te('Steuernummer') ?></label>
            <input type="text" name="company_tax_number" class="form-control" maxlength="30"
                   value="<?= htmlspecialchars(setting('company_tax_number', '')) ?>">
            <div class="form-text"><?= te('Eines von beiden genügt für die XRechnung.') ?></div>
          </div>

          <div class="mb-3">
            <label class="form-label small fw-bold"><?= te('Stundensatz (Voreinstellung)') ?></label>
            <input type="number" step="0.01" min="0" name="default_hourly_rate" class="form-control"
                   value="<?= htmlspecialchars($s_hourly_rate) ?>">
            <div class="form-text small"><?= te('Gilt, wenn weder das Projekt noch der Kunde einen eigenen Satz hat.') ?></div>
            <div class="form-text"><?= te('Wird im Seitentitel und Portal-Header verwendet.') ?></div>
          </div>
          <div class="col-md-6">
            <label class="form-label"><?= te('Admin-Panel URL') ?></label>
            <input type="url" name="base_url" class="form-control" value="<?= htmlspecialchars($s_base_url) ?>">
            <div class="form-text"><?= te('Wichtig für QR-Codes und Links.') ?></div>
          </div>
          <div class="col-md-6">
            <label class="form-label"><?= te('Hauptwebsite') ?></label>
            <input type="url" name="main_website" class="form-control" value="<?= htmlspecialchars($s_main_website) ?>">
          </div>
          <div class="col-md-6">
            <label class="form-label"><?= te('Admin-E-Mail') ?></label>
            <input type="email" name="admin_email" class="form-control" value="<?= htmlspecialchars($s_admin_email) ?>">
          </div>
          <div class="col-md-6">
            <label class="form-label"><?= te('Support-E-Mail') ?></label>
            <input type="email" name="support_email" class="form-control" value="<?= htmlspecialchars($s_support_email) ?>">
          </div>
        </div>

        <div class="alert alert-info d-flex gap-2 align-items-start py-2" style="font-size:13px;">
          <i class="bi bi-info-circle-fill mt-1"></i>
          <span><?= te('Diese Werte überschreiben die Konstanten in') ?> <code>config.php</code> <?= te('und werden sofort auf allen Seiten wirksam.') ?></span>
        </div>

        <div class="settings-section-title mt-4"><i class="bi bi-bank me-2"></i><?= te('Bankverbindung') ?></div>
        <p class="text-muted small mb-3">
          Erscheint im Kundenportal bei offenen Rechnungen, zusammen mit einem
          Überweisungs-Code zum Scannen. Leer lassen blendet den Bereich aus.
          Die Daten verlassen den eigenen Server nicht — der Code wird im
          Browser des Kunden erzeugt.
        </p>
        <div class="row g-3 mb-2">
          <div class="col-md-6">
            <label class="fw-bold small mb-1" for="bank_holder"><?= te('Kontoinhaber') ?></label>
            <input type="text" name="bank_holder" id="bank_holder" class="form-control" maxlength="70"
                   value="<?= htmlspecialchars(setting('bank_holder', '')) ?>"
                   placeholder="<?= htmlspecialchars(setting('company_name', COMPANY_NAME)) ?>">
          </div>
          <div class="col-md-6">
            <label class="fw-bold small mb-1" for="bank_iban">IBAN</label>
            <input type="text" name="bank_iban" id="bank_iban" class="form-control" maxlength="42"
                   value="<?= htmlspecialchars(setting('bank_iban', '')) ?>"
                   placeholder="DE00 0000 0000 0000 0000 00">
          </div>
          <div class="col-md-6">
            <label class="fw-bold small mb-1" for="bank_bic">BIC <span class="text-muted fw-normal"><?= te('(optional)') ?></span></label>
            <input type="text" name="bank_bic" id="bank_bic" class="form-control" maxlength="11"
                   value="<?= htmlspecialchars(setting('bank_bic', '')) ?>">
          </div>
          <div class="col-md-6">
            <label class="fw-bold small mb-1" for="payment_note"><?= te('Hinweis zur Zahlung') ?> <span class="text-muted fw-normal"><?= te('(optional)') ?></span></label>
            <input type="text" name="payment_note" id="payment_note" class="form-control" maxlength="140"
                   value="<?= htmlspecialchars(setting('payment_note', '')) ?>"
                   placeholder="<?= te('z. B. Zahlbar innerhalb von 14 Tagen ohne Abzug.') ?>">
          </div>
        </div>
        <button type="submit" class="btn btn-primary px-4"><i class="bi bi-check2 me-1"></i> <?= te('Speichern') ?></button>
      </form>

      <!-- Logo Upload (eigenes Formular wegen enctype) -->
      <hr class="my-4">
      <div class="settings-section-title"><i class="bi bi-image me-2"></i><?= te('Firmenlogo (für Rechnungen)') ?></div>
      <div class="row g-4 align-items-start">
        <div class="col-md-5">
          <?php if ($s_company_logo && file_exists(__DIR__ . '/' . $s_company_logo)): ?>
            <div class="p-3 border rounded-3 bg-subtle text-center mb-3" style="max-width:280px;">
              <img src="<?= htmlspecialchars($s_company_logo) ?>?v=<?= filemtime(__DIR__ . '/' . $s_company_logo) ?>"
                   alt="<?= te('Firmenlogo') ?>" style="max-height:70px; max-width:100%; object-fit:contain;">
            </div>
            <form method="POST">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="delete_logo">
              <button type="submit" class="btn btn-outline-danger btn-sm">
                <i class="bi bi-trash3 me-1"></i><?= te('Logo entfernen') ?>
              </button>
            </form>
          <?php else: ?>
            <div class="text-muted small p-3 border rounded-3 bg-subtle" style="max-width:280px;">
              <i class="bi bi-image text-muted" style="font-size:2rem;display:block;margin-bottom:8px;"></i>
              <?= te('Noch kein Logo hinterlegt. Ohne Logo erscheint nur der Firmenname auf Rechnungen.') ?>
            </div>
          <?php endif; ?>
        </div>
        <div class="col-md-7">
          <form method="POST" enctype="multipart/form-data">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="upload_logo">
            <label class="form-label fw-semibold"><?= te('Logo hochladen') ?></label>
            <input type="file" name="company_logo" class="form-control mb-2" accept="image/png,image/jpeg,image/gif,image/webp" required>
            <div class="form-text mb-3"><?= te('PNG, JPG, GIF oder WebP · max. 2 MB · Empfehlung: transparenter Hintergrund, ca. 400×120 px') ?></div>
            <button type="submit" class="btn btn-primary btn-sm px-3">
              <i class="bi bi-upload me-1"></i><?= te('Hochladen & speichern') ?>
            </button>
          </form>
        </div>
      </div>

      <!-- Favicon Upload -->
      <hr class="my-4">
      <div class="settings-section-title"><i class="bi bi-window me-2"></i><?= te('Tab-Icon (Favicon)') ?></div>
      <div class="row g-4 align-items-start">
        <div class="col-md-5">
          <?php if ($s_favicon && file_exists(__DIR__ . '/' . $s_favicon)): ?>
            <div class="p-3 border rounded-3 bg-subtle text-center mb-3" style="max-width:280px;">
              <img src="<?= htmlspecialchars($s_favicon) ?>?v=<?= filemtime(__DIR__ . '/' . $s_favicon) ?>"
                   alt="<?= te('Favicon') ?>" style="max-height:48px; max-width:96px; object-fit:contain; image-rendering:auto;">
              <div class="text-muted small mt-2"><?= htmlspecialchars(basename($s_favicon)) ?></div>
            </div>
            <form method="POST">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="delete_favicon">
              <button type="submit" class="btn btn-outline-danger btn-sm">
                <i class="bi bi-trash3 me-1"></i><?= te('Favicon entfernen') ?>
              </button>
            </form>
          <?php else: ?>
            <div class="text-muted small p-3 border rounded-3 bg-subtle" style="max-width:280px;">
              <i class="bi bi-window text-muted" style="font-size:2rem;display:block;margin-bottom:8px;"></i>
              <?= te('Noch kein Favicon hinterlegt. Browser zeigen dann das Standard-Icon.') ?>
            </div>
          <?php endif; ?>
        </div>
        <div class="col-md-7">
          <form method="POST" enctype="multipart/form-data">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="upload_favicon">
            <label class="form-label fw-semibold"><?= te('Favicon hochladen') ?></label>
            <input type="file" name="favicon_file" class="form-control mb-2" accept=".ico,.png" required>
            <div class="form-text mb-3"><?= te('ICO oder PNG · max. 512 KB · Empfehlung: 32×32 px oder 64×64 px') ?></div>
            <button type="submit" class="btn btn-primary btn-sm px-3">
              <i class="bi bi-upload me-1"></i><?= te('Hochladen & speichern') ?>
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

        <div class="settings-section-title"><i class="bi bi-bell me-2"></i><?= te('E-Mail-Benachrichtigungen') ?></div>
        <div class="d-flex flex-column gap-3 mb-4">

          <div class="d-flex align-items-start gap-3 p-3 border rounded-3">
            <div class="form-check form-switch mb-0">
              <input class="form-check-input" type="checkbox" name="notify_milestone_email" id="notMs" role="switch" style="width:3em;height:1.5em;" <?= $s_notify_ms === '1' ? 'checked' : '' ?>>
            </div>
            <div>
              <label class="fw-semibold form-check-label" for="notMs"><?= te('Meilenstein-E-Mail-Bestätigung') ?></label>
              <p class="text-muted mb-0" style="font-size:13px;"><?= te('Beim Abschließen eines Meilensteins im Portal wird der Kunde per E-Mail gefragt, ob er den Meilenstein offiziell bestätigen möchte.') ?></p>
            </div>
          </div>

          <div class="d-flex align-items-start gap-3 p-3 border rounded-3">
            <div class="form-check form-switch mb-0">
              <input class="form-check-input" type="checkbox" name="notify_quote_email" id="notQt" role="switch" style="width:3em;height:1.5em;" <?= $s_notify_quote === '1' ? 'checked' : '' ?>>
            </div>
            <div>
              <label class="fw-semibold form-check-label" for="notQt"><?= te('Angebots-E-Mail beim Versand') ?></label>
              <p class="text-muted mb-0" style="font-size:13px;"><?= te('Beim Versand eines Angebots wird automatisch eine E-Mail an den Kunden generiert.') ?></p>
            </div>
          </div>

        </div>
        <button type="submit" class="btn btn-primary px-4"><i class="bi bi-check2 me-1"></i> <?= te('Speichern') ?></button>
      </form>
    </div>

    <!-- ========== TAB: SYSTEM ========== -->
    <?php elseif($active_tab === 'mail'): ?>
    <?php
      $tpls    = mail_templates();
      $tpl_key = $_GET['tpl'] ?? array_key_first($tpls);
      if (!isset($tpls[$tpl_key])) $tpl_key = array_key_first($tpls);
      $tpl     = $tpls[$tpl_key];
      // Ist die Vorlage angepasst oder läuft sie noch auf dem Standard?
      $ist_angepasst = setting('mailtpl_' . $tpl_key . '_subject', '') !== ''
                    || setting('mailtpl_' . $tpl_key . '_body', '') !== '';
      $vorschau = mail_render($tpl_key, mail_preview_vars(), 'https://example.com/portal');
    ?>
    <div class="settings-card" style="border-radius:0 10px 10px 10px;">

      <!-- ── Rahmen: gilt für alle HTML-Mails ── -->
      <div class="settings-section-title"><i class="bi bi-window-sidebar me-2"></i><?= te('Rahmen aller E-Mails') ?></div>
      <p class="text-muted small mb-3">
        <?= te('Kopfbereich, Farben und Logo kommen aus') ?> <a href="?tab=design"><?= te('Darstellung') ?></a> <?= te('und') ?>
        <a href="?tab=company"><?= te('Firma') ?></a>. <?= te('Hier stellen Sie ein, was unter jeder Nachricht steht.') ?>
      </p>
      <form method="POST" class="row g-3 mb-4">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save_mail_frame">
        <div class="col-md-6">
          <label class="fw-bold small mb-1" for="mail_signature"><?= te('Signatur') ?></label>
          <textarea class="form-control" id="mail_signature" name="mail_signature" rows="3"
                    placeholder="Mit freundlichen Grüßen&#10;David Imminger"><?= htmlspecialchars(setting('mail_signature', '')) ?></textarea>
          <div class="form-text"><?= te('Steht am Ende jeder Nachricht, vor der Fußzeile.') ?></div>
        </div>
        <div class="col-md-6">
          <label class="fw-bold small mb-1" for="mail_footer"><?= te('Fußzeile') ?></label>
          <textarea class="form-control" id="mail_footer" name="mail_footer" rows="3"
                    placeholder="<?= htmlspecialchars(setting('company_name', COMPANY_NAME) . ' · ' . setting('main_website', MAIN_WEBSITE)) ?>"><?= htmlspecialchars(setting('mail_footer', '')) ?></textarea>
          <div class="form-text"><?= te('Die kleine Zeile ganz unten. Leer lassen für Firmenname und Website.') ?></div>
        </div>
        <div class="col-12">
          <button class="btn btn-primary btn-sm fw-bold"><i class="bi bi-save me-1"></i><?= te('Rahmen speichern') ?></button>
        </div>
      </form>

      <!-- ── Vorlagen ── -->
      <div class="settings-section-title"><i class="bi bi-envelope-paper me-2"></i><?= te('Vorlagen') ?></div>

      <div class="row g-4">
        <!-- Liste -->
        <div class="col-lg-4">
          <div class="list-group">
            <?php foreach($tpls as $k => $t):
              $angepasst = setting('mailtpl_' . $k . '_subject', '') !== ''
                        || setting('mailtpl_' . $k . '_body', '') !== '';
            ?>
            <a href="?tab=mail&tpl=<?= urlencode($k) ?>"
               class="list-group-item list-group-item-action d-flex justify-content-between align-items-center gap-2<?= $k === $tpl_key ? ' active' : '' ?>">
              <span class="text-truncate">
                <span class="d-block fw-semibold"><?= htmlspecialchars($t['label']) ?></span>
                <span class="d-block small<?= $k === $tpl_key ? '' : ' text-muted' ?>" style="font-size:var(--text-2xs);">
                  <?= !empty($t['plaintext']) ? te('Vorbelegung, reiner Text') : te('HTML mit Rahmen') ?>
                </span>
              </span>
              <?php if($angepasst): ?>
                <span class="badge <?= $k === $tpl_key ? 'bg-light text-dark' : 'bg-primary' ?>" title="<?= te('Von Ihnen angepasst') ?>"><?= te('angepasst') ?></span>
              <?php endif; ?>
            </a>
            <?php endforeach; ?>
          </div>
        </div>

        <!-- Editor -->
        <div class="col-lg-8">
          <form method="POST">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="save_mail_template">
            <input type="hidden" name="tpl_key" value="<?= htmlspecialchars($tpl_key, ENT_QUOTES) ?>">

            <div class="d-flex justify-content-between align-items-start gap-2 mb-2 flex-wrap">
              <div>
                <div class="fw-bold"><?= htmlspecialchars($tpl['label']) ?></div>
                <div class="text-muted small"><?= htmlspecialchars($tpl['hint']) ?></div>
              </div>
              <?php if($ist_angepasst): ?>
              <button type="submit" form="reset_tpl_form" class="btn btn-outline-secondary btn-sm"
                      onclick="return confirm('Diese Vorlage auf den Standardtext zurücksetzen?')">
                <i class="bi bi-arrow-counterclockwise me-1"></i><?= te('Auf Standard zurücksetzen') ?>
              </button>
              <?php endif; ?>
            </div>

            <div class="mb-3">
              <label class="fw-bold small mb-1" for="tpl_subject"><?= te('Betreff') ?></label>
              <input type="text" class="form-control" id="tpl_subject" name="tpl_subject"
                     value="<?= htmlspecialchars(mail_template_subject($tpl_key)) ?>">
            </div>

            <div class="mb-2">
              <label class="fw-bold small mb-1" for="tpl_body"><?= te('Nachricht') ?></label>
              <textarea class="form-control" id="tpl_body" name="tpl_body" rows="11"
                        style="font-family:var(--font-mono);font-size:13px;line-height:1.6;"><?= htmlspecialchars(mail_template_body($tpl_key)) ?></textarea>
              <div class="form-text">
                Eine Leerzeile trennt Absätze. Enthält eine Zeile nur einen Platzhalter,
                der leer bleibt, entfällt sie ganz.
              </div>
            </div>

            <div class="mb-3">
              <div class="label-xs mb-1"><?= te('Platzhalter — anklicken zum Einfügen') ?></div>
              <div class="d-flex flex-wrap gap-1">
                <?php foreach($tpl['vars'] as $v): ?>
                <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-2 tpl-var"
                        data-var="{{<?= htmlspecialchars($v, ENT_QUOTES) ?>}}"
                        style="font-family:var(--font-mono);font-size:var(--text-2xs);">{{<?= htmlspecialchars($v) ?>}}</button>
                <?php endforeach; ?>
              </div>
            </div>

            <button class="btn btn-primary btn-sm fw-bold"><i class="bi bi-save me-1"></i><?= te('Vorlage speichern') ?></button>
          </form>

          <form method="POST" id="reset_tpl_form" class="d-none">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="reset_mail_template">
            <input type="hidden" name="tpl_key" value="<?= htmlspecialchars($tpl_key, ENT_QUOTES) ?>">
          </form>

          <!-- Vorschau -->
          <div class="settings-section-title mt-4"><i class="bi bi-eye me-2"></i><?= te('Vorschau mit Beispieldaten') ?></div>
          <div class="mb-2 small"><span class="text-muted"><?= te('Betreff:') ?></span>
            <span class="fw-semibold"><?= htmlspecialchars($vorschau['subject']) ?></span></div>
          <?php if($vorschau['html'] !== ''): ?>
            <iframe title="<?= te('Vorschau der E-Mail') ?>" style="width:100%;height:520px;border:1px solid var(--border-base);border-radius:var(--radius-md);background:#fff;"
                    srcdoc="<?= htmlspecialchars($vorschau['html'], ENT_QUOTES) ?>"></iframe>
            <div class="form-text">
              Die Vorschau zeigt die Mail so, wie sie beim Empfänger ankommt — immer hell,
              unabhängig vom Design des Panels.
            </div>
          <?php else: ?>
            <pre class="bg-subtle border border-subtle-c rounded-3 p-3 mb-0"
                 style="white-space:pre-wrap;font-size:13px;"><?= htmlspecialchars($vorschau['text']) ?></pre>
            <div class="form-text">
              Diese Vorlage füllt nur das Versandfenster vor. Vor dem Absenden können Sie
              den Text dort noch ändern.
            </div>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <?php elseif($active_tab === 'users'): ?>
    <div class="settings-card" style="border-radius:0 10px 10px 10px;">

      <div class="settings-section-title"><i class="bi bi-people me-2"></i><?= te('Benutzer') ?></div>

      <?php if (isset($_GET['uerr'])): ?>
        <div class="alert alert-danger py-2 small">
          <i class="bi bi-exclamation-triangle me-1"></i><?= htmlspecialchars($_GET['uerr']) ?>
        </div>
      <?php endif; ?>
      <?php if (isset($_GET['newuser'])): ?>
        <div class="alert alert-success py-2 small">
          <i class="bi bi-check-circle me-1"></i>
          <?= te('Der Benutzer wurde angelegt. Er vergibt sein Passwort selbst über „Passwort vergessen“ im Anmeldebild.') ?>
        </div>
      <?php endif; ?>

      <div class="table-responsive mb-4">
        <table class="table table-sm align-middle mb-0">
          <thead>
            <tr class="table-label">
              <th><?= te('Name') ?></th>
              <th><?= te('E-Mail-Adresse') ?></th>
              <th><?= te('Rolle') ?></th>
              <th class="text-center"><?= te('Zwei Faktoren') ?></th>
              <th class="text-center"><?= te('Zustand') ?></th>
              <th></th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($s_benutzer as $_u): ?>
            <tr<?= (int) $_u['is_active'] !== 1 ? ' class="text-muted"' : '' ?>>
              <td class="fw-semibold text-strong-c"><?= htmlspecialchars(benutzer_anzeige($_u)) ?></td>
              <td class="small"><?= htmlspecialchars($_u['email']) ?></td>
              <td>
                <form method="POST" class="d-flex gap-1 align-items-center">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="user_edit">
                  <input type="hidden" name="user_id" value="<?= (int) $_u['id'] ?>">
                  <input type="hidden" name="name" value="<?= htmlspecialchars($_u['name'], ENT_QUOTES) ?>">
                  <select name="role" class="form-select form-select-sm" style="width:auto;" onchange="this.form.submit()">
                    <?php foreach (rollen() as $_key => $_r): ?>
                      <option value="<?= $_key ?>" <?= $_u['role'] === $_key ? 'selected' : '' ?>><?= htmlspecialchars(datenwert($_r['label'])) ?></option>
                    <?php endforeach; ?>
                  </select>
                </form>
              </td>
              <td class="text-center">
                <?= $_u['totp']
                    ? '<i class="bi bi-shield-check text-success" title="' . te('Aktiv') . '"></i>'
                    : '<span class="text-muted">–</span>' ?>
              </td>
              <td class="text-center">
                <?= (int) $_u['is_active'] === 1
                    ? '<span class="badge bg-success">' . te('Aktiv') . '</span>'
                    : '<span class="badge bg-secondary">' . te('Abgeschaltet') . '</span>' ?>
              </td>
              <td class="text-end">
                <form method="POST" class="d-inline">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="user_toggle">
                  <input type="hidden" name="user_id" value="<?= (int) $_u['id'] ?>">
                  <input type="hidden" name="aktiv" value="<?= (int) $_u['is_active'] === 1 ? '0' : '1' ?>">
                  <button type="submit" class="btn btn-sm btn-outline-<?= (int) $_u['is_active'] === 1 ? 'danger' : 'success' ?>">
                    <?= (int) $_u['is_active'] === 1 ? te('Abschalten') : te('Freischalten') ?>
                  </button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <div class="settings-section-title"><i class="bi bi-person-plus me-2"></i><?= te('Benutzer hinzufügen') ?></div>
      <p class="text-muted small">
        <?= te('Kein Passwort nötig: der neue Benutzer vergibt es selbst über „Passwort vergessen“. Ein vom Verwalter vergebenes müsste über einen Kanal übermittelt werden, der es preisgibt – und würde erfahrungsgemäß nie geändert.') ?>
      </p>
      <form method="POST" class="row g-3 align-items-end mb-4">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="user_add">
        <div class="col-md-4">
          <label class="form-label"><?= te('Name') ?></label>
          <input type="text" name="name" class="form-control" maxlength="255">
        </div>
        <div class="col-md-4">
          <label class="form-label"><?= te('E-Mail-Adresse') ?></label>
          <input type="email" name="email" class="form-control" required>
        </div>
        <div class="col-md-2">
          <label class="form-label"><?= te('Rolle') ?></label>
          <select name="role" class="form-select">
            <?php foreach (rollen() as $_key => $_r): ?>
              <option value="<?= $_key ?>" <?= $_key === 'staff' ? 'selected' : '' ?>><?= htmlspecialchars(datenwert($_r['label'])) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-2">
          <button type="submit" class="btn btn-primary w-100"><i class="bi bi-plus-lg me-1"></i><?= te('Anlegen') ?></button>
        </div>
      </form>

      <div class="settings-section-title"><i class="bi bi-info-circle me-2"></i><?= te('Was die Rollen dürfen') ?></div>
      <dl class="row small mb-0">
        <?php foreach (rollen() as $_r): ?>
          <?php // datenwert(): beides kommt aus rollen() und ist hier eine Variable. ?>
          <dt class="col-sm-3"><?= htmlspecialchars(datenwert($_r['label'])) ?></dt>
          <dd class="col-sm-9 text-muted"><?= htmlspecialchars(datenwert($_r['hint'])) ?></dd>
        <?php endforeach; ?>
      </dl>

    </div>

    <?php elseif($active_tab === 'system'): ?>
    <div class="settings-card" style="border-radius:0 10px 10px 10px;">

      <div class="settings-section-title"><i class="bi bi-sliders me-2"></i><?= te('Log-Einstellungen') ?></div>
      <form method="POST" class="mb-4">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save_system">
        <div class="row g-3 align-items-end">
          <div class="col-md-4">
            <label class="form-label fw-semibold"><?= te('Log-Anzeigelimit') ?></label>
            <input type="number" name="log_limit" class="form-control" min="50" max="2000" step="50" value="<?= htmlspecialchars($s_log_limit) ?>">
            <div class="form-text"><?= te('Maximale Anzahl Logs auf der Log-Seite (Standard: 200, max. 2000).') ?></div>
          </div>
          <div class="col-md-2">
            <button type="submit" class="btn btn-primary w-100"><i class="bi bi-check2 me-1"></i> <?= te('Speichern') ?></button>
          </div>
        </div>
      </form>

      <div class="settings-section-title mt-2"><i class="bi bi-bell me-2"></i><?= te('Zahlungserinnerungen') ?></div>
      <form method="POST" class="mb-4">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save_reminders">
        <div class="row g-3 align-items-end">
          <div class="col-md-4">
            <label class="form-label fw-semibold"><?= te('Mahnstufen (Tage nach Fälligkeit)') ?></label>
            <input type="text" name="reminder_days" class="form-control" placeholder="<?= te('z. B. 7, 21') ?>" value="<?= htmlspecialchars($s_reminder_days) ?>">
            <div class="form-text"><?= te('Leer lassen, um keine Erinnerungen automatisch zu versenden. Der Knopf in der Rechnungsliste funktioniert unabhängig davon.') ?></div>
          </div>
          <div class="col-md-2">
            <button type="submit" class="btn btn-primary w-100"><i class="bi bi-check2 me-1"></i> <?= te('Speichern') ?></button>
          </div>
        </div>
      </form>
      <div class="alert alert-info py-2 small">
        <i class="bi bi-info-circle me-1"></i>
        <?= te('Automatische Erinnerungen werden nur verschickt, wenn cron.php regelmäßig läuft. Ohne eingerichteten Cron-Lauf passiert hier nichts.') ?>
      </div>

      <?php if ($s_user_id > 0): ?>
      <div class="settings-section-title mt-2"><i class="bi bi-shield-lock me-2"></i><?= te('Zwei-Faktor-Anmeldung') ?></div>

      <?php if ($s_totp_codes !== null): ?>
        <div class="alert alert-success py-3">
          <p class="fw-bold mb-2"><i class="bi bi-check-circle me-1"></i><?= te('Der zweite Faktor ist aktiv.') ?></p>
          <p class="small mb-2"><?= te('Bewahren Sie diese Ersatzcodes auf – ausgedruckt oder in einem Passwortspeicher. Jeder gilt einmal und hilft, wenn Ihr Telefon nicht zur Hand ist. Sie werden nur dieses eine Mal angezeigt.') ?></p>
          <div class="font-monospace" style="columns:2;">
            <?php foreach ($s_totp_codes as $_c): ?>
              <div><?= htmlspecialchars($_c) ?></div>
            <?php endforeach; ?>
          </div>
        </div>
      <?php endif; ?>

      <?php if ($s_totp_setup && $s_totp_gehei !== null): ?>
        <?php $_uri = totp_uri($s_totp_gehei, (string) ($_SESSION['admin_email'] ?? 'admin'), setting('company_short', COMPANY_SHORT)); ?>
        <?php if (($_GET['err'] ?? '') === 'code'): ?>
          <div class="alert alert-danger py-2 small"><i class="bi bi-exclamation-triangle me-1"></i><?= te('Der Code stimmt nicht. Bitte noch einmal.') ?></div>
        <?php endif; ?>
        <div class="row g-4 align-items-start mb-4">
          <div class="col-md-auto">
            <div id="totp_qr" class="p-2 bg-white d-inline-block rounded"></div>
          </div>
          <div class="col-md">
            <p class="small mb-2"><?= te('Scannen Sie den Code mit Ihrer Authenticator-App und geben Sie danach das angezeigte Einmalkennwort ein.') ?></p>
            <p class="small text-muted mb-3">
              <?= te('Lässt sich nicht scannen? Dieses Geheimnis von Hand eintragen:') ?><br>
              <code class="user-select-all"><?= htmlspecialchars($s_totp_gehei) ?></code>
            </p>
            <form method="POST" class="d-flex gap-2 align-items-start">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="totp_confirm">
              <input type="text" name="code" class="form-control font-monospace" style="max-width:160px;"
                     required autocomplete="one-time-code" inputmode="numeric" placeholder="000000">
              <button type="submit" class="btn btn-primary"><?= te('Bestätigen') ?></button>
            </form>
          </div>
        </div>
        <script src="<?= asset('assets/vendor/qrcode/qrcode.min.js') ?>"></script>
        <script>
          // Der QR-Code entsteht im Browser. Ein Bilddienst bekaeme sonst
          // das Geheimnis zu sehen - dieselbe Abwaegung wie beim
          // Girocode in den Kontakten.
          new QRCode(document.getElementById('totp_qr'), {
              text: <?= json_encode($_uri, JSON_HEX_TAG | JSON_HEX_APOS) ?>,
              width: 168, height: 168, correctLevel: QRCode.CorrectLevel.M
          });
        </script>

      <?php elseif ($s_totp_aktiv): ?>
        <p class="small mb-2">
          <span class="badge bg-success"><i class="bi bi-shield-check me-1"></i><?= te('Aktiv') ?></span>
          <span class="text-muted ms-2"><?= te('%d Ersatzcode(s) noch offen', $s_totp_offen) ?></span>
        </p>
        <?php if ($s_totp_offen === 0): ?>
          <div class="alert alert-warning py-2 small">
            <i class="bi bi-exclamation-triangle me-1"></i>
            <?= te('Alle Ersatzcodes sind verbraucht. Richten Sie den zweiten Faktor neu ein, um neue zu erhalten.') ?>
          </div>
        <?php endif; ?>
        <div class="d-flex gap-2 mb-4">
          <form method="POST" onsubmit="return confirm('<?= te('Neu einrichten? Der bisherige Eintrag in Ihrer App und alle Ersatzcodes gelten danach nicht mehr.') ?>')">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="totp_start">
            <button type="submit" class="btn btn-outline-secondary btn-sm"><?= te('Neu einrichten') ?></button>
          </form>
          <form method="POST" onsubmit="return confirm('<?= te('Den zweiten Faktor abschalten? Die Anmeldung braucht danach nur noch das Passwort.') ?>')">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="totp_off">
            <button type="submit" class="btn btn-outline-danger btn-sm"><?= te('Abschalten') ?></button>
          </form>
        </div>

      <?php else: ?>
        <p class="text-muted small">
          <?= te('Zusätzlich zum Passwort ein Einmalkennwort aus einer Authenticator-App. Wer Ihr Passwort kennt, kommt damit trotzdem nicht hinein.') ?>
        </p>
        <form method="POST" class="mb-4">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="totp_start">
          <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-shield-lock me-1"></i> <?= te('Einrichten') ?></button>
        </form>
      <?php endif; ?>
      <?php endif; ?>

      <div class="settings-section-title mt-2"><i class="bi bi-braces me-2"></i><?= te('Schnittstellen') ?></div>
      <p class="text-muted small">
        <?= te('Zwei Wege, auf denen etwas von außen ins Panel kommt. Je ein eigener Schlüssel: wer einen Dienst wechselt, muss den anderen nicht mitentziehen.') ?>
      </p>

      <?php
        // Zwei Bloecke, ein Aufbau. Als Schleife und nicht zweimal
        // abgeschrieben - sonst weicht der zweite frueher oder spaeter ab.
        $_apis = [
            'leads' => [
                'titel'   => te('Anfragen von der Website'),
                'pfad'    => '/api/leads',
                'hinweis' => te('Das Kontaktformular Ihrer Website schickt Anfragen hierher, ohne Zugang zur Datenbank.'),
            ],
            'tickets' => [
                'titel'   => te('Eingehende E-Mails'),
                'pfad'    => '/api/tickets',
                'hinweis' => te('Ein Maildienst (etwa Cloudflare Email Routing, Postmark oder Mailgun) reicht eingehende Nachrichten hierher weiter. Sie werden zu Support-Anfragen; eine Antwort mit [#Nummer] im Betreff landet am richtigen Vorgang.'),
            ],
        ];
      ?>

      <?php foreach ($_apis as $_zweck => $_api): ?>
        <?php $_key = $s_api_keys[$_zweck]; ?>
        <div class="mb-4">
          <div class="fw-bold mb-1"><?= $_api['titel'] ?></div>
          <p class="text-muted small mb-2"><?= $_api['hinweis'] ?></p>

          <?php if ($_key === ''): ?>
            <div class="alert alert-secondary py-2 small d-flex justify-content-between align-items-center gap-2 flex-wrap">
              <span><i class="bi bi-lock me-1"></i><?= te('Kein Schlüssel eingerichtet – geschlossen.') ?></span>
              <form method="POST" class="m-0">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="generate_api_key">
                <input type="hidden" name="zweck" value="<?= $_zweck ?>">
                <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-key me-1"></i> <?= te('Schlüssel erzeugen') ?></button>
              </form>
            </div>
          <?php else: ?>
            <?php if (($_GET['newkey'] ?? '') === $_zweck): ?>
              <div class="alert alert-success py-2 small">
                <i class="bi bi-check-circle me-1"></i>
                <?= te('Der Schlüssel wurde erzeugt. Tragen Sie ihn jetzt in Ihre Website ein.') ?>
              </div>
            <?php endif; ?>
            <div class="input-group input-group-sm mb-2">
              <span class="input-group-text font-monospace"><?= htmlspecialchars($_api['pfad']) ?></span>
              <input type="text" class="form-control font-monospace" id="api_key_<?= $_zweck ?>"
                     value="<?= htmlspecialchars($_key) ?>" readonly>
              <button class="btn btn-outline-secondary" type="button" onclick="apiSchluesselKopieren('<?= $_zweck ?>')">
                <i class="bi bi-clipboard"></i>
              </button>
            </div>
            <div class="d-flex gap-2">
              <form method="POST" onsubmit="return confirm('<?= te('Einen neuen Schlüssel erzeugen? Der bisherige gilt danach nicht mehr.') ?>')">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="generate_api_key">
                <input type="hidden" name="zweck" value="<?= $_zweck ?>">
                <button type="submit" class="btn btn-outline-secondary btn-sm"><?= te('Neu erzeugen') ?></button>
              </form>
              <form method="POST" onsubmit="return confirm('<?= te('Den Schlüssel entziehen? Die Schnittstelle ist danach geschlossen.') ?>')">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="revoke_api_key">
                <input type="hidden" name="zweck" value="<?= $_zweck ?>">
                <button type="submit" class="btn btn-outline-danger btn-sm"><?= te('Entziehen') ?></button>
              </form>
            </div>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>

      <?php if ($s_api_keys['leads'] !== '' || $s_api_keys['tickets'] !== ''): ?>
      <div class="alert alert-warning py-2 small mb-4">
        <i class="bi bi-exclamation-triangle me-1"></i>
        <?= te('Der Schlüssel berechtigt zum Schreiben. Er gehört auf den Server Ihrer Website, niemals in ein Formular oder in JavaScript – dort wäre er öffentlich.') ?>
      </div>
      <script>
        function apiSchluesselKopieren(zweck) {
            var feld = document.getElementById('api_key_' + zweck);
            feld.select();
            // Der moderne Weg braucht einen sicheren Kontext; das
            // ausgewaehlte Feld bleibt als Rueckfall.
            if (navigator.clipboard) { navigator.clipboard.writeText(feld.value); }
        }
      </script>
      <?php endif; ?>
      <div class="settings-section-title mt-2"><i class="bi bi-info-circle me-2"></i><?= te('Systeminfo') ?></div>
      <table class="table table-borderless" style="max-width:400px;">
        <tbody>
          <tr>
            <td class="text-muted fw-semibold" style="width:160px;"><?= te('PHP Version') ?></td>
            <td><code><?= htmlspecialchars($php_version) ?></code></td>
          </tr>
          <tr>
            <td class="text-muted fw-semibold"><?= te('MySQL Version') ?></td>
            <td><code><?= htmlspecialchars($db_version) ?></code></td>
          </tr>
          <tr>
            <td class="text-muted fw-semibold"><?= te('Server') ?></td>
            <td><code><?= htmlspecialchars($_SERVER['SERVER_SOFTWARE'] ?? 'n/a') ?></code></td>
          </tr>
          <tr>
            <td class="text-muted fw-semibold"><?= te('Zeitzone') ?></td>
            <td><code><?= htmlspecialchars(date_default_timezone_get()) ?></code></td>
          </tr>
          <tr>
            <td class="text-muted fw-semibold"><?= te('Schemastand') ?></td>
            <td>
              <?php
                // setting() hat die Tabelle ohnehin geladen - das kostet
                // keine weitere Abfrage.
                $_schema_ist  = (int) setting('schema_version', '0');
                $_schema_soll = SCHEMA_VERSION;
              ?>
              <code><?= $_schema_ist ?></code>
              <?php if ($_schema_ist === $_schema_soll): ?>
                <i class="bi bi-check-circle-fill text-success ms-1" aria-hidden="true"></i>
                <span class="text-muted small"><?= te('aktuell') ?></span>
              <?php else: ?>
                <span class="badge bg-danger ms-1"><?= te('erwartet: %d', $_schema_soll) ?></span>
                <div class="small text-danger mt-1">
                  <i class="bi bi-exclamation-triangle me-1"></i>
                  <?= te('Eine Migration ist nicht durchgelaufen. Der Grund steht im Fehlerprotokoll des Servers.') ?>
                </div>
              <?php endif; ?>
            </td>
          </tr>
          <tr>
            <td class="text-muted fw-semibold"><?= te('Tabellen') ?></td>
            <td>
              <?php
                // Die Zahl der Tabellen sagt dasselbe noch einmal von
                // der anderen Seite: eine Migration kann die Version
                // stempeln und trotzdem an einem Schritt gescheitert
                // sein.
                try {
                    $_tabellen = (int) $pdo->query('SELECT COUNT(*) FROM information_schema.tables
                                                     WHERE table_schema = DATABASE()')->fetchColumn();
                } catch (PDOException $e) {
                    $_tabellen = 0;
                }
              ?>
              <code><?= $_tabellen ?></code>
              <span class="text-muted small"><?= te('in der Datenbank') ?></span>
            </td>
          </tr>
        </tbody>
      </table>

    </div>
    <?php endif; ?>


  <script>
    // Dark mode toggle
    const dmToggle = document.getElementById('darkModeToggle');
    if (dmToggle) {
        dmToggle.checked = window.ansichtSpeicher.getItem('darkMode') === '1';
        dmToggle.addEventListener('change', function() {
            if (this.checked) {
                document.documentElement.setAttribute('data-theme', 'dark');
                window.ansichtSpeicher.setItem('darkMode', '1');
            } else {
                document.documentElement.removeAttribute('data-theme');
                window.ansichtSpeicher.setItem('darkMode', '0');
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
<script>
/* Platzhalter per Klick an der Cursorposition einsetzen - abtippen von
   {{meilenstein}} ist fehleranfaellig, und ein Tippfehler faellt erst
   auf, wenn die Mail beim Kunden liegt. */
document.querySelectorAll('.tpl-var').forEach(function (btn) {
    btn.addEventListener('click', function () {
        var feld = document.getElementById('tpl_body');
        var aktiv = document.activeElement;
        if (aktiv && aktiv.id === 'tpl_subject') feld = aktiv;
        if (!feld) return;
        var v = btn.dataset.var;
        var a = feld.selectionStart, b = feld.selectionEnd;
        feld.value = feld.value.slice(0, a) + v + feld.value.slice(b);
        feld.focus();
        feld.selectionStart = feld.selectionEnd = a + v.length;
    });
});
</script>
<?php
require 'includes/layout_end.php'; ?>
