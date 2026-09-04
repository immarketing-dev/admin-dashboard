<?php
require_once 'config.php';
require_once __DIR__ . '/includes/logging.php';
require_once 'includes/upload_helper.php';
require_once 'includes/session.php';
require_once 'includes/csrf.php';

// PHPMailer: das Portal meldet dem Absender, wenn ein Angebot
// angenommen wird oder eine Rueckfrage kommt.
if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
}
app_session_start();

// ── Der Zugangslink darf nicht weiterwandern ───────────────────────
// Der Token steht in der Adresszeile und ist damit der Schluessel zum
// Portal. Zwei Wege, auf denen er das Haus verlaesst, werden hier
// zugehalten:
//
//  - Der Verweisende. Klickt ein Kunde im Portal auf einen Link nach
//    aussen, schickt der Browser die aktuelle Adresse mit - samt Token.
//    Die allgemeine Regel in .htaccess ("strict-origin-when-cross-origin")
//    kuerzt zwar auf die Herkunft, aber nur bei fremdem Ziel; hier gilt
//    grundsaetzlich nichts zu senden.
//
//  - Suchmaschinen. Wird ein Portallink irgendwo veroeffentlicht - in
//    einem Forum, in einer weitergeleiteten E-Mail -, hat er in keinem
//    Index etwas verloren.
//
// Die PIN bleibt der eigentliche Schutz; das hier verhindert, dass der
// Token ueberhaupt erst in fremde Haende geraet.
header('Referrer-Policy: no-referrer');
header('X-Robots-Tag: noindex, nofollow, noarchive');

// Token-Prüfung
if (!isset($_GET['token']) || strlen($_GET['token']) < 10) {
    die(t('Ungültiger Zugriff. Bitte nutzen Sie den Link aus Ihrer E-Mail.'));
}
$token = $_GET['token'];
$stmt = $pdo->prepare("SELECT * FROM contacts WHERE deleted_at IS NULL AND portal_token = ?");
$stmt->execute([$token]);
$client = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$client) { die(t('Zugang abgelaufen oder ungültig.')); }

$_sess_key  = 'portal_auth_' . $client['id'];
$_pin_error = '';

/* Sprachwahl im Portal.
   Bewusst ueber die Adresszeile und die Sitzung, nicht ueber die
   Datenbank: so funktioniert der Umschalter auch in der Demo, wo jeder
   Schreibzugriff abgewiesen wird. Und der Kunde entscheidet selbst,
   ohne dass jemand im Panel etwas fuer ihn einstellen muss. */
if (isset($_GET['lang']) && in_array($_GET['lang'], SPRACHEN, true)) {
    $_SESSION['portal_lang_' . $client['id']] = $_GET['lang'];
}
if (!empty($_SESSION['portal_lang_' . $client['id']])) {
    sprache_setzen($_SESSION['portal_lang_' . $client['id']]);
}

/**
 * Darf dieser Kontakt in diesem Projekt handeln?
 *
 * Seit Migration 5 entscheidet die Mitgliedschaft, nicht mehr
 * tasks.contact_id — sonst sähe ein hinzugefügter Beteiligter das Projekt
 * zwar, dürfte aber nichts darin tun.
 */
function portal_darf_projekt(PDO $pdo, int $task_id, int $contact_id): bool
{
    $st = $pdo->prepare("SELECT 1 FROM task_contacts tc
                         JOIN tasks t ON t.id = tc.task_id
                         WHERE tc.task_id = ? AND tc.contact_id = ? AND t.deleted_at IS NULL");
    $st->execute([$task_id, $contact_id]);
    return (bool) $st->fetchColumn();
}

/**
 * Meldet dem Absender, was im Portal mit einem Angebot geschehen ist.
 *
 * Schlägt der Versand fehl, bleibt der Vorgang trotzdem gültig - der
 * Log-Eintrag ist die verlässliche Spur, die Mail nur die Bequemlichkeit.
 */
function portal_notify_admin(PDO $pdo, array $client, string $was,
                             string $nummer, string $betrag, string $nachricht): void
{
    try {
        $mail = new PHPMailer\PHPMailer\PHPMailer(true);
        $mail->isSMTP();
        $mail->Host       = SMTP_HOST;
        $mail->SMTPAuth   = true;
        $mail->Username   = SMTP_USER;
        $mail->Password   = SMTP_PASS;
        $mail->SMTPSecure = (SMTP_PORT == 587) ? 'tls' : 'ssl';
        $mail->Port       = SMTP_PORT;
        $mail->CharSet    = 'UTF-8';
        $mail->setFrom(SMTP_USER, setting('company_short', COMPANY_SHORT));
        $mail->addAddress(setting('admin_email', ADMIN_EMAIL));
        $mail->isHTML(false);
        $mail->Subject = "Angebot $nummer: $was";
        $mail->Body    = "{$client['name']}"
            . ($client['company'] ? " ({$client['company']})" : '')
            . " hat im Portal reagiert.\n\n"
            . "Angebot: $nummer\n"
            . 'Betrag:  ' . number_format((float)$betrag, 2, ',', '.') . " €\n"
            . "Vorgang: $was\n"
            . ($nachricht !== '' ? "\nNachricht:\n$nachricht\n" : '');
        $mail->send();
    } catch (Throwable $e) {
        // Throwable, nicht Exception: fehlt PHPMailer, wirft PHP einen Error -
        // der wuerde das Portal abbrechen, statt nur die Meldung ausfallen zu
        // lassen. Der Log-Eintrag oben ist die verlaessliche Spur.
        log_event($pdo, 'MAIL_ERROR', 'Angebots-Benachrichtigung fehlgeschlagen: ' . $e->getMessage());
    }
}

// =============================================
// POST / AJAX AKTIONEN
// =============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // Jede zustandsaendernde Anfrage muss das Token mitbringen. Gilt auch
    // fuer die PIN-Formulare: die Session steht dort bereits, und ein
    // fremdes Formular soll auch keinen Zugangscode setzen koennen.
    csrf_check();

    // Demo-Modus: allein die PIN-Prüfung darf durch, sie berührt nur die
    // Sitzung. Alles andere - Uploads, Angebotsentscheidungen, Kommentare
    // und die Benachrichtigung an den Absender - endet hier.
    demo_guard();

    // ── PIN: Zugangscode erstmalig vergeben
    if (isset($_POST['action']) && $_POST['action'] === 'set_portal_pin') {
        $pin  = trim($_POST['pin'] ?? '');
        $pin2 = trim($_POST['pin_confirm'] ?? '');
        if (mb_strlen($pin) < 4) {
            $_pin_error = t('Der Zugangscode muss mindestens 4 Zeichen haben.');
        } elseif ($pin !== $pin2) {
            $_pin_error = t('Die Zugangscodes stimmen nicht überein.');
        } else {
            $pdo->prepare("UPDATE contacts SET portal_pin=?, portal_pin_attempts=0, portal_pin_locked_until=NULL WHERE id=?")
                ->execute([password_hash($pin, PASSWORD_DEFAULT), $client['id']]);
            $_SESSION[$_sess_key] = true;
            log_event($pdo, 'PORTAL_PIN_SET', "{$client['name']} hat einen Zugangscode vergeben.");
            header("Location: portal?token=$token"); exit();
        }
    }

    // ── PIN: Zugangscode prüfen
    if (isset($_POST['action']) && $_POST['action'] === 'verify_portal_pin') {
        $stmt_fresh = $pdo->prepare("SELECT portal_pin, portal_pin_attempts, portal_pin_locked_until FROM contacts WHERE deleted_at IS NULL AND id=?");
        $stmt_fresh->execute([$client['id']]);
        $fresh = $stmt_fresh->fetch(PDO::FETCH_ASSOC);

        if (!$fresh || empty($fresh['portal_pin'])) {
            // Kein PIN gesetzt — kein Verify möglich (Angreifer soll keinen Hinweis erhalten)
            $_pin_error = t('Falscher Zugangscode.');
        } else {
            $locked_until = $fresh['portal_pin_locked_until'];
            if ($locked_until && strtotime($locked_until) > time()) {
                $mins = (int)ceil((strtotime($locked_until) - time()) / 60);
                $_pin_error = t('Zu viele Fehlversuche. Bitte noch %d Minute(n) warten.', $mins);
            } elseif (password_verify(trim($_POST['pin'] ?? ''), $fresh['portal_pin'])) {
                // In der Demo bleibt der Zähler unberührt: der dortige
                // Datenbankbenutzer darf ausschließlich lesen.
                if (!demo_mode()) {
                    $pdo->prepare("UPDATE contacts SET portal_pin_attempts=0, portal_pin_locked_until=NULL WHERE id=?")->execute([$client['id']]);
                }
                $_SESSION[$_sess_key] = true;
                header("Location: portal?token=$token"); exit();
            } elseif (demo_mode()) {
                // Kein Fehlversuchszähler in der Demo - ein einzelner
                // Besucher könnte sonst das Portal für alle sperren.
                $_pin_error = t('Falscher Zugangscode.');
            } else {
                $attempts = (int)$fresh['portal_pin_attempts'] + 1;
                if ($attempts >= 5) {
                    $pdo->prepare("UPDATE contacts SET portal_pin_attempts=?, portal_pin_locked_until=DATE_ADD(NOW(), INTERVAL 30 MINUTE) WHERE id=?")
                        ->execute([$attempts, $client['id']]);
                    log_event($pdo, 'PORTAL_PIN_LOCKED', "Portalzugang von {$client['name']} nach 5 Fehlversuchen für 30 Minuten gesperrt.");
                    $_pin_error = t('Zu viele Fehlversuche. Zugang für 30 Minuten gesperrt.');
                } else {
                    log_event($pdo, 'PORTAL_PIN_FAILED', "Falscher Zugangscode für {$client['name']} (Versuch $attempts von 5).");
                    $pdo->prepare("UPDATE contacts SET portal_pin_attempts=? WHERE id=?")->execute([$attempts, $client['id']]);
                    $_pin_error = t('Falscher Zugangscode. Noch %d Versuch(e).', 5 - $attempts);
                }
            }
        }
    }

    // AJAX: Meilenstein-Kommentar hinzufügen
    if (isset($_POST['action']) && $_POST['action'] === 'add_ms_comment') {
        header('Content-Type: application/json');
        $ms_id   = (int)($_POST['milestone_id'] ?? 0);
        $message = trim($_POST['message'] ?? '');
        if (!$ms_id || $message === '') { echo json_encode(['success' => false]); exit(); }

        // Sicherstellen dass dieser Meilenstein zu einem Projekt dieses Kunden gehört
        $check = $pdo->prepare("SELECT m.id FROM task_milestones m
                                JOIN task_contacts tc ON tc.task_id = m.task_id
                                JOIN tasks t ON t.id = m.task_id
                                WHERE m.id = ? AND tc.contact_id = ? AND t.deleted_at IS NULL");
        $check->execute([$ms_id, $client['id']]);
        if (!$check->fetch()) { echo json_encode(['success' => false, 'error' => 'forbidden']); exit(); }

        $pdo->prepare("INSERT INTO milestone_comments (milestone_id, author, author_name, message) VALUES (?, 'client', ?, ?)")
            ->execute([$ms_id, $client['name'], $message]);
        $new_id = $pdo->lastInsertId();

        log_event($pdo, 'PORTAL_MS_COMMENT', "Kunde {$client['name']} kommentierte Meilenstein #$ms_id");

        echo json_encode([
            'success'     => true,
            'id'          => $new_id,
            'author_name' => $client['name'],
            'message'     => htmlspecialchars($message, ENT_QUOTES),
            'time'        => date('d.m.Y H:i'),
        ]);
        exit();
    }

    // ── Beitrag zur Projekt-Diskussion ──────────────────────────────
    // Anders als die Meilenstein-Kommentare haengt der Beitrag am Projekt,
    // nicht an einem Schritt - fuer alles, was sich keinem Schritt zuordnen
    // laesst.
    if (isset($_POST['add_project_comment'])) {
        $t_id = (int)($_POST['task_id'] ?? 0);
        $msg  = trim($_POST['message'] ?? '');
        if ($msg !== '' && portal_darf_projekt($pdo, $t_id, (int)$client['id'])) {
            $pdo->prepare("INSERT INTO project_comments (task_id, author_contact_id, author_name, message)
                           VALUES (?, ?, ?, ?)")
                ->execute([$t_id, $client['id'], $client['name'], $msg]);
            log_event($pdo, 'PROJECT_COMMENT', "{$client['name']} hat im Projekt $t_id geschrieben: "
                           . mb_strimwidth($msg, 0, 120, '…'));
        }
        header("Location: portal?token=$token&msg=comment#tab-projects"); exit();
    }

    // ── Angebot annehmen ────────────────────────────────────────────
    // Der Status ist derselbe, den quotes.php kennt - der Vorgang landet
    // also ohne Umweg in der bestehenden Angebotsverwaltung.
    if (isset($_POST['accept_quote'])) {
        $qid = (int)($_POST['quote_id'] ?? 0);
        $chk = $pdo->prepare("SELECT quote_number, total_amount FROM quotes
                              WHERE id = ? AND contact_id = ? AND status = 'Gesendet' AND deleted_at IS NULL");
        $chk->execute([$qid, $client['id']]);
        if ($q = $chk->fetch(PDO::FETCH_ASSOC)) {
            $pdo->prepare("UPDATE quotes SET status = 'Angenommen' WHERE id = ?")->execute([$qid]);
            log_event($pdo, 'QUOTE_ACCEPTED', "Angebot {$q['quote_number']} von {$client['name']} im Portal angenommen.");
            portal_notify_admin($pdo, $client, 'angenommen', $q['quote_number'], $q['total_amount'], '');
            header("Location: portal?token=$token&msg=quote_accepted#quotes"); exit();
        }
        header("Location: portal?token=$token#quotes"); exit();
    }

    // ── Rückfrage zu einem Angebot ──────────────────────────────────
    // Aendert den Status bewusst nicht: eine Rueckfrage ist keine
    // Ablehnung. Sie geht als Nachricht an den Absender.
    if (isset($_POST['query_quote'])) {
        $qid  = (int)($_POST['quote_id'] ?? 0);
        $frage = trim($_POST['quote_message'] ?? '');
        $chk = $pdo->prepare("SELECT quote_number, total_amount FROM quotes
                              WHERE id = ? AND contact_id = ? AND deleted_at IS NULL");
        $chk->execute([$qid, $client['id']]);
        if (($q = $chk->fetch(PDO::FETCH_ASSOC)) && $frage !== '') {
            log_event($pdo, 'QUOTE_QUESTION', "Rückfrage von {$client['name']} zu Angebot {$q['quote_number']}: "
                           . mb_strimwidth($frage, 0, 160, '…'));
            portal_notify_admin($pdo, $client, 'Rückfrage', $q['quote_number'], $q['total_amount'], $frage);
            header("Location: portal?token=$token&msg=quote_question#quotes"); exit();
        }
        header("Location: portal?token=$token#quotes"); exit();
    }

    // Profildaten aktualisieren
    if (isset($_POST['update_profile'])) {
        $pdo->prepare("UPDATE contacts SET name=?,company=?,email=?,phone=?,website=?,street=?,zip=?,city=?,country=? WHERE id=?")
            ->execute([trim($_POST['name']),trim($_POST['company']),trim($_POST['email']),trim($_POST['phone']),trim($_POST['website']),trim($_POST['street']),trim($_POST['zip']),trim($_POST['city']),trim($_POST['country']),$client['id']]);
        log_event($pdo, 'PORTAL_PROFILE', "Kunde {$client['name']} aktualisierte Kontaktdaten.");
        header("Location: portal?token=$token&msg=profile_updated"); exit();
    }

    // Support-Ticket erstellen
    if (isset($_POST['create_ticket'])) {
        $pdo->prepare("INSERT INTO support_tickets (contact_id,subject,message) VALUES (?,?,?)")
            ->execute([$client['id'],trim($_POST['subject']),trim($_POST['message'])]);
        log_event($pdo, 'TICKET_CREATED', "Ticket von {$client['name']}: ".mb_strimwidth($_POST['subject'],0,30,'...'));
        header("Location: portal?token=$token&msg=ticket_created"); exit();
    }

    // Ticket-Priorität ändern (Kunde, AJAX)
    if (isset($_POST['action']) && $_POST['action'] === 'update_ticket_priority') {
        header('Content-Type: application/json');
        $tid  = (int)($_POST['ticket_id'] ?? 0);
        $prio = in_array($_POST['priority'] ?? '', ['Niedrig','Mittel','Hoch','Kritisch']) ? $_POST['priority'] : 'Mittel';
        $chk  = $pdo->prepare("SELECT id FROM support_tickets WHERE id=? AND contact_id=?");
        $chk->execute([$tid, $client['id']]);
        if (!$chk->fetch()) { echo json_encode(['ok' => false]); exit(); }
        $pdo->prepare("UPDATE support_tickets SET priority=? WHERE id=?")->execute([$prio, $tid]);
        log_event($pdo, 'PORTAL_TICKET_UPDATE', "Kunde {$client['name']} änderte Priorität von Ticket #$tid auf '$prio'");
        echo json_encode(['ok' => true]);
        exit();
    }

    // Ticket als Erledigt markieren (Kunde)
    if (isset($_POST['action']) && $_POST['action'] === 'close_ticket') {
        $tid = (int)($_POST['ticket_id'] ?? 0);
        $chk = $pdo->prepare("SELECT id FROM support_tickets WHERE id=? AND contact_id=?");
        $chk->execute([$tid, $client['id']]);
        if ($chk->fetch()) {
            $pdo->prepare("UPDATE support_tickets SET status='Erledigt' WHERE id=?")->execute([$tid]);
            log_event($pdo, 'PORTAL_TICKET_CLOSE', "Kunde {$client['name']} markierte Ticket #$tid als Erledigt");
        }
        header("Location: portal?token=$token&msg=ticket_closed&open_ticket=$tid#support"); exit();
    }

    // Ticket löschen (Kunde)
    if (isset($_POST['action']) && $_POST['action'] === 'delete_portal_ticket') {
        $tid = (int)($_POST['ticket_id'] ?? 0);
        $chk = $pdo->prepare("SELECT id FROM support_tickets WHERE id=? AND contact_id=?");
        $chk->execute([$tid, $client['id']]);
        if ($chk->fetch()) {
            $pdo->prepare("DELETE FROM ticket_notes WHERE ticket_id=?")->execute([$tid]);
            $pdo->prepare("DELETE FROM support_tickets WHERE id=?")->execute([$tid]);
            log_event($pdo, 'PORTAL_TICKET_DELETE', "Kunde {$client['name']} löschte Ticket #$tid");
        }
        header("Location: portal?token=$token&msg=ticket_deleted#support"); exit();
    }

    // Kundenantwort auf bestehendes Ticket
    if (isset($_POST['action']) && $_POST['action'] === 'add_ticket_reply') {
        $tid   = (int)($_POST['ticket_id'] ?? 0);
        $reply = trim($_POST['reply'] ?? '');
        $chk   = $pdo->prepare("SELECT id FROM support_tickets WHERE id=? AND contact_id=?");
        $chk->execute([$tid, $client['id']]);
        if ($chk->fetch() && $reply !== '') {
            $pdo->prepare("INSERT INTO ticket_notes (ticket_id, note, author, is_public) VALUES (?, ?, 'client', 1)")
                ->execute([$tid, $reply]);
            // Ticket reaktivieren wenn es bereits Erledigt war
            $pdo->prepare("UPDATE support_tickets SET status='Offen' WHERE id=? AND status='Erledigt'")->execute([$tid]);
            log_event($pdo, 'PORTAL_TICKET_REPLY', "Kunde {$client['name']} antwortete auf Ticket #$tid");
        }
        header("Location: portal?token=$token&msg=reply_sent#support"); exit();
    }

    // Datei-Upload (AJAX)
    if (isset($_FILES['asset_files'])) {
        $task_id    = (int)$_POST['task_id'];

        // Die Projektnummer kam bisher ungeprueft aus dem Formular - damit
        // liess sich eine Datei in ein beliebiges fremdes Projekt legen.
        if (!portal_darf_projekt($pdo, $task_id, (int)$client['id'])) {
            http_response_code(403);
            echo 'ERR_FORBIDDEN';
            exit();
        }

        $upload_dir = 'uploads/client_assets/';
        if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);
        foreach ($_FILES['asset_files']['tmp_name'] as $k => $tmp) {
            $name = basename($_FILES['asset_files']['name'][$k]);
            $size = $_FILES['asset_files']['size'][$k];
            if (!$tmp) continue;
            $err = validate_upload($tmp, $name, $size);
            if ($err) { echo "ERR_TYPE:$name"; exit(); }
            $safe = safe_filename($name);
            if (move_uploaded_file($tmp, $upload_dir . $safe)) {
                $pdo->prepare("INSERT INTO client_assets (task_id,file_name,file_path,dashboard_seen,uploaded_by,uploaded_by_contact_id,uploaded_by_name) VALUES (?,?,?,0,'client',?,?)")->execute([$task_id,$name,$upload_dir.$safe,$client["id"],$client["name"]]);
                log_event($pdo, 'PORTAL_UPLOAD', "Kunde {$client['name']} lud hoch: $name");
            }
        }
        echo "OK"; exit();
    }

    // Meilenstein absegnen
    if (isset($_POST['approve_ms'])) {
        $ms_id = (int)$_POST['ms_id'];
        // Sicherstellen dass dieser Meilenstein zu diesem Kunden gehört
        $check = $pdo->prepare("SELECT m.id FROM task_milestones m
                                JOIN task_contacts tc ON tc.task_id = m.task_id
                                JOIN tasks t ON t.id = m.task_id
                                WHERE m.id=? AND tc.contact_id=? AND t.deleted_at IS NULL");
        $check->execute([$ms_id, $client['id']]);
        if ($check->fetch()) {
            $pdo->prepare("UPDATE task_milestones SET approved_at=NOW(),is_completed=1,approval_seen=0 WHERE id=?")->execute([$ms_id]);
            log_event($pdo, 'PORTAL_APPROVAL', "Kunde {$client['name']} segnete Meilenstein #$ms_id ab.");
        }
        header("Location: portal?token=$token&msg=approved"); exit();
    }

    // Allgemeines Projekt-Feedback
    if (isset($_POST['send_feedback'])) {
        $pdo->prepare("UPDATE tasks SET client_feedback=?,feedback_seen=0,feedback_by_contact_id=?,feedback_by_name=?,feedback_at=NOW() WHERE id=?")->execute([$_POST['feedback'], $client['id'], $client['name'], (int)$_POST['task_id']]);
        log_event($pdo, 'PORTAL_FEEDBACK', "Kunde {$client['name']} sendete Projekt-Feedback.");
        header("Location: portal?token=$token&msg=feedback"); exit();
    }

    // Asset löschen
    if (isset($_POST['delete_asset'])) {
        $aid  = (int)$_POST['asset_id'];
        $row  = $pdo->prepare("SELECT file_path FROM client_assets WHERE id=? AND task_id IN (SELECT tc.task_id FROM task_contacts tc JOIN tasks t ON t.id = tc.task_id WHERE t.deleted_at IS NULL AND tc.contact_id=?)");
        $row->execute([$aid, $client['id']]);
        $file = $row->fetch();
        if ($file) {
            @unlink($file['file_path']);
            $pdo->prepare("DELETE FROM client_assets WHERE id=?")->execute([$aid]);
            log_event($pdo, 'PORTAL_DELETE', "Kunde {$client['name']} löschte Datei.");
        }
        header("Location: portal?token=$token&msg=deleted"); exit();
    }
}

// ── AUTH CHECK: PIN-Schutz ──────────────────────────────────────
$_pin_is_set = !empty($client['portal_pin'] ?? null);
$_is_auth    = !empty($_SESSION[$_sess_key]);

if (!$_is_auth) {
    $_name_parts = explode(' ', $client['name']);
    $_avatar     = strtoupper(substr($_name_parts[0], 0, 1) . (isset($_name_parts[1]) ? substr($_name_parts[1], 0, 1) : ''));
    $_first_name = htmlspecialchars($_name_parts[0]);
    $_locked     = !demo_mode()
                   && !empty($client['portal_pin_locked_until'] ?? null)
                   && strtotime($client['portal_pin_locked_until']) > time();
    ?><!DOCTYPE html>
<html lang="<?= lang() ?>">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= te('Zugang') ?> · <?= htmlspecialchars(setting('company_short', COMPANY_SHORT)) ?></title>
  <link href="<?= asset('assets/vendor/bootstrap/bootstrap.min.css') ?>" rel="stylesheet">
  <link href="<?= asset('assets/vendor/bootstrap-icons/bootstrap-icons.css') ?>" rel="stylesheet">
  <link href="<?= asset('assets/vendor/fonts/fonts.css') ?>" rel="stylesheet">
  <link rel="stylesheet" href="<?= asset('assets/css/tokens.css') ?>">
  <?php if (demo_mode()): ?><link rel="stylesheet" href="<?= asset('assets/css/demo.css') ?>"><?php endif; ?>
  <?php $theme_follow_system = true; require 'includes/theme.php'; ?>
  <style>
    *{box-sizing:border-box}
    body{min-height:100vh;margin:0;display:flex;align-items:center;justify-content:center;font-family:'Open Sans',sans-serif;padding:20px;
      background:linear-gradient(135deg,var(--color-sidebar) 0%,color-mix(in srgb,var(--color-sidebar) 60%,var(--color-primary)) 100%);
      position:relative;overflow:hidden;}
    body::before{content:'';position:fixed;inset:0;pointer-events:none;
      background:url("data:image/svg+xml,%3Csvg width='60' height='60' viewBox='0 0 60 60' xmlns='http://www.w3.org/2000/svg'%3E%3Cg fill='none' fill-rule='evenodd'%3E%3Cg fill='%23ffffff' fill-opacity='0.04'%3E%3Cpath d='M36 34v-4h-2v4h-4v2h4v4h2v-4h4v-2h-4zm0-30V0h-2v4h-4v2h4v4h2V6h4V4h-4zM6 34v-4H4v4H0v2h4v4h2v-4h4v-2H6zM6 4V0H4v4H0v2h4v4h2V6h4V4H6z'/%3E%3C/g%3E%3C/g%3E%3C/svg%3E");}
    .pin-card{background:var(--surface-card);border-radius:24px;padding:44px 40px 36px;width:100%;max-width:430px;
      box-shadow:0 24px 72px rgba(0,0,0,.4);position:relative;z-index:1;
      animation:fadeUp .35s cubic-bezier(.22,.68,0,1.2);}
    @keyframes fadeUp{from{opacity:0;transform:translateY(24px)}to{opacity:1;transform:none}}
    .pin-avatar{width:76px;height:76px;border-radius:22px;background:var(--color-primary);
      display:flex;align-items:center;justify-content:center;font-size:28px;font-weight:800;color:var(--text-invert);
      font-family:'Poppins',sans-serif;margin:0 auto 20px;
      box-shadow:0 8px 28px color-mix(in srgb,var(--color-primary) 55%,transparent);}
    .pin-wrap{position:relative}
    .pin-input{border-radius:12px;font-size:17px;letter-spacing:3px;padding:13px 48px 13px 18px;
      border:2px solid var(--border-subtle);width:100%;transition:border-color .2s,box-shadow .2s;
      font-family:'Poppins',sans-serif;background:var(--surface-subtle);outline:none;}
    .pin-input:focus{background:var(--surface-card);border-color:var(--color-primary);
      box-shadow:0 0 0 4px color-mix(in srgb,var(--color-primary) 14%,transparent);}
    .pin-input.err{border-color:var(--accent-danger);}
    .pin-toggle{position:absolute;right:14px;top:50%;transform:translateY(-50%);
      background:none;border:none;color:var(--text-faint);cursor:pointer;font-size:18px;padding:4px;
      line-height:1;transition:color .15s;}
    .pin-toggle:hover{color:var(--color-primary)}
    .btn-pin{background:var(--color-primary);color:var(--text-invert);border:none;border-radius:12px;padding:14px;
      width:100%;font-size:15px;font-weight:700;cursor:pointer;font-family:'Poppins',sans-serif;
      letter-spacing:.3px;transition:opacity .2s,transform .1s;}
    .btn-pin:hover{opacity:.88}.btn-pin:active{transform:scale(.98)}
    .strength-bar{height:4px;border-radius:2px;background:var(--surface-sunken);margin-top:6px;overflow:hidden}
    .strength-fill{height:100%;border-radius:2px;width:0;transition:width .3s,background .3s}
    .match-msg{font-size:12px;margin-top:4px;min-height:16px}
    /* Demo-Hinweis auf der Zugangskarte. Eigene Regel, weil dieses
       Dokument nur tokens.css lädt und nicht app.css. */
    .demo-note{background:var(--accent-soft);border:1px solid var(--accent-soft-strong);border-radius:12px;
      padding:12px 14px;margin-bottom:22px;font-size:13px;line-height:1.55;color:var(--text-body);text-align:center;}
    .demo-note code{background:var(--surface-subtle);border:1px solid var(--border-subtle);border-radius:6px;
      padding:2px 9px;font-size:14px;font-weight:700;letter-spacing:2px;color:var(--text-strong);}
  </style>
</head>
<body>
<div class="pin-card">
  <div class="pin-avatar"><?= $_avatar ?></div>

  <?php if (demo_mode()): ?>
    <div class="demo-note">
      <strong><?= te('Demo-Version') ?></strong> <?= te('&ndash; Zugangscode') ?> <code><?= htmlspecialchars(demo_portal_pin()) ?></code><br>
      <?= te('Alle Daten sind erfunden, Änderungen werden nicht gespeichert.') ?>
    </div>
  <?php endif; ?>

  <?php if ($_pin_is_set): ?>
    <h4 class="fw-bold text-center mb-1" style="font-family:'Poppins',sans-serif;color:var(--text-strong);"><?= te('Willkommen zurück') ?></h4>
    <p class="text-muted text-center mb-4" style="font-size:13.5px;"><?= te('Hallo %s, geben Sie Ihren Zugangscode ein.', $_first_name) ?></p>
  <?php else: ?>
    <h4 class="fw-bold text-center mb-1" style="font-family:'Poppins',sans-serif;color:var(--text-strong);"><?= te('Portal einrichten') ?></h4>
    <p class="text-muted text-center mb-4" style="font-size:13.5px;"><?= te('Legen Sie einmalig einen persönlichen Zugangscode für Ihr Portal fest.') ?></p>
  <?php endif; ?>

  <?php if ($_pin_error): ?>
  <div class="alert alert-danger rounded-3 d-flex align-items-start gap-2 py-2 px-3 mb-4" style="font-size:13px;">
    <i class="bi bi-exclamation-triangle-fill mt-1 flex-shrink-0"></i>
    <span><?= htmlspecialchars($_pin_error) ?></span>
  </div>
  <?php endif; ?>

  <?php if ($_locked): ?>
    <div class="text-center py-3 text-muted">
      <i class="bi bi-shield-lock-fill mb-3 d-block" style="font-size:3rem;color:var(--accent-danger);opacity:.7;"></i>
      <p class="fw-bold mb-1"><?= te('Zugang vorübergehend gesperrt') ?></p>
      <p class="small mb-0"><?= te('Bitte kontaktieren Sie') ?> <strong><?= htmlspecialchars(setting('company_short', COMPANY_SHORT)) ?></strong> <?= te('zum Zurücksetzen.') ?></p>
    </div>
  <?php elseif ($_pin_is_set): ?>
    <form method="POST">
    <?= csrf_field() ?>
      <input type="hidden" name="action" value="verify_portal_pin">
      <div class="mb-4">
        <label class="form-label fw-semibold" style="font-size:13px;"><?= te('Zugangscode') ?></label>
        <div class="pin-wrap">
          <input type="password" name="pin" id="pi1" class="pin-input <?= $_pin_error ? 'err' : '' ?>"
                 placeholder="••••••" autofocus autocomplete="current-password" required>
          <button type="button" class="pin-toggle" onclick="tv('pi1',this)" tabindex="-1"><i class="bi bi-eye"></i></button>
        </div>
      </div>
      <button type="submit" class="btn-pin"><i class="bi bi-shield-lock-fill me-2"></i><?= te('Einloggen') ?></button>
    </form>
  <?php else: ?>
    <form method="POST" autocomplete="off" onsubmit="return chkMatch()">
    <?= csrf_field() ?>
      <input type="hidden" name="action" value="set_portal_pin">
      <div class="mb-3">
        <label class="form-label fw-semibold" style="font-size:13px;"><?= te('Zugangscode wählen') ?> <span class="text-muted fw-normal"><?= te('(mind. 4 Zeichen)') ?></span></label>
        <div class="pin-wrap">
          <input type="password" name="pin" id="pi1" class="pin-input" placeholder="<?= te('Neuer Zugangscode') ?>"
                 autofocus autocomplete="new-password" required minlength="4" oninput="updStr(this.value)">
          <button type="button" class="pin-toggle" onclick="tv('pi1',this)" tabindex="-1"><i class="bi bi-eye"></i></button>
        </div>
        <div class="strength-bar"><div class="strength-fill" id="sf"></div></div>
      </div>
      <div class="mb-4">
        <label class="form-label fw-semibold" style="font-size:13px;"><?= te('Zugangscode bestätigen') ?></label>
        <div class="pin-wrap">
          <input type="password" name="pin_confirm" id="pi2" class="pin-input" placeholder="<?= te('Wiederholen') ?>"
                 autocomplete="new-password" required minlength="4" oninput="liveMatch()">
          <button type="button" class="pin-toggle" onclick="tv('pi2',this)" tabindex="-1"><i class="bi bi-eye"></i></button>
        </div>
        <div class="match-msg" id="mm"></div>
      </div>
      <button type="submit" class="btn-pin"><i class="bi bi-check-circle-fill me-2"></i><?= te('Zugangscode festlegen') ?></button>
    </form>
  <?php endif; ?>

  <div class="text-center text-muted mt-4" style="font-size:11.5px;">
    <i class="bi bi-lock-fill" style="color:var(--color-primary);"></i>
    <?= te('Bereitgestellt von') ?> <strong><?= htmlspecialchars(setting('company_short', COMPANY_SHORT)) ?></strong>
  </div>
</div>
<script>
function tv(id,btn){const f=document.getElementById(id),s=f.type==='password';f.type=s?'text':'password';btn.innerHTML=s?'<i class="bi bi-eye-slash"></i>':'<i class="bi bi-eye"></i>';}
function updStr(v){const f=document.getElementById('sf');let w=0,c='var(--accent-danger)';if(v.length>=4){w=33;c='var(--accent-warning)';}if(v.length>=7){w=66;c='var(--accent-warning)';}if(v.length>=10||v.length>=6&&/[^a-zA-Z0-9]/.test(v)){w=100;c='var(--accent-success)';}f.style.width=w+'%';f.style.background=c;}
function liveMatch(){const p1=document.getElementById('pi1').value,p2=document.getElementById('pi2'),mm=document.getElementById('mm'),ok=p2.value===p1&&p2.value.length>0;mm.style.color=ok?'var(--accent-success)':'var(--accent-danger)';mm.textContent=p2.value?(ok?<?= tjs('✓ Übereinstimmend') ?>:<?= tjs('✗ Stimmt nicht überein') ?>):'';p2.classList.toggle('err',!ok&&p2.value.length>0);}
function chkMatch(){const ok=document.getElementById('pi1').value===document.getElementById('pi2').value;if(!ok){liveMatch();return false;}return true;}
</script>
</body>
</html>
<?php
    exit();
}

// =============================================
// DATEN LADEN (alles gebatcht vor dem HTML)
// =============================================
// Mitgliedschaft statt Besitz: seit Migration 5 kann ein Projekt mehrere
// Beteiligte haben. tasks.contact_id bleibt der Hauptansprechpartner und
// steht per Rueckfuellung ebenfalls in task_contacts.
$projects = $pdo->prepare("SELECT t.* FROM tasks t
                           JOIN task_contacts tc ON tc.task_id = t.id
                           WHERE t.deleted_at IS NULL AND tc.contact_id = ?
                           ORDER BY t.created_at DESC");
$projects->execute([$client['id']]);
$projects = $projects->fetchAll(PDO::FETCH_ASSOC);

// Diskussion und Beteiligte gebatcht - nicht je Projektkarte einzeln.
$project_comments = [];
$project_members  = [];
if (!empty($projects)) {
    $pids = array_column($projects, 'id');
    $in   = implode(',', array_fill(0, count($pids), '?'));

    $c = $pdo->prepare("SELECT * FROM project_comments WHERE task_id IN ($in) ORDER BY created_at ASC");
    $c->execute($pids);
    foreach ($c->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $project_comments[$row['task_id']][] = $row;
    }

    $m = $pdo->prepare("SELECT tc.task_id, tc.role, c.id AS contact_id, c.name, c.company, c.contact_type
                        FROM task_contacts tc
                        JOIN contacts c ON c.id = tc.contact_id
                        WHERE tc.task_id IN ($in) AND c.deleted_at IS NULL
                        ORDER BY tc.role = 'owner' DESC, c.name ASC");
    $m->execute($pids);
    foreach ($m->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $project_members[$row['task_id']][] = $row;
    }
}

// Batch: Meilensteine + Assets + Kommentare
$milestones_by_task = [];
$assets_by_task     = [];
$comments_by_ms     = [];

if (!empty($projects)) {
    $pids = array_column($projects, 'id');
    $ph   = implode(',', array_fill(0, count($pids), '?'));

    $ms_rows = $pdo->prepare("SELECT * FROM task_milestones WHERE task_id IN ($ph) ORDER BY created_at ASC");
    $ms_rows->execute($pids);
    $all_ms_ids = [];
    foreach ($ms_rows->fetchAll(PDO::FETCH_ASSOC) as $ms) {
        $milestones_by_task[$ms['task_id']][] = $ms;
        $all_ms_ids[] = $ms['id'];
    }

    $asset_rows = $pdo->prepare("SELECT * FROM client_assets WHERE task_id IN ($ph) ORDER BY uploaded_at DESC");
    $asset_rows->execute($pids);
    foreach ($asset_rows->fetchAll(PDO::FETCH_ASSOC) as $a) {
        $assets_by_task[$a['task_id']][] = $a;
    }

    if (!empty($all_ms_ids)) {
        $ph2  = implode(',', array_fill(0, count($all_ms_ids), '?'));
        $coms = $pdo->prepare("SELECT * FROM milestone_comments WHERE milestone_id IN ($ph2) ORDER BY created_at ASC");
        $coms->execute($all_ms_ids);
        foreach ($coms->fetchAll(PDO::FETCH_ASSOC) as $c) {
            $comments_by_ms[$c['milestone_id']][] = $c;
        }
    }
}

// Entwuerfe bleiben aussen vor - was noch nicht gesendet wurde, geht den
// Empfaenger nichts an.
$quotes = $pdo->prepare("SELECT * FROM quotes
                         WHERE deleted_at IS NULL AND contact_id = ?
                           AND status IN ('Gesendet','Angenommen','Abgelehnt')
                         ORDER BY created_at DESC");
$quotes->execute([$client['id']]);
$quotes = $quotes->fetchAll(PDO::FETCH_ASSOC);
$open_quote_count = count(array_filter($quotes, fn($q) => $q['status'] === 'Gesendet'));

// Zahlungsangaben. Fehlt die IBAN, entfaellt der ganze Bereich - lieber
// kein Hinweis als ein halber.
$bank = [
    'holder' => trim(setting('bank_holder', setting('company_name', COMPANY_NAME))),
    'iban'   => strtoupper(preg_replace('/\s+/', '', setting('bank_iban', ''))),
    'bic'    => strtoupper(trim(setting('bank_bic', ''))),
    'note'   => trim(setting('payment_note', '')),
];
$has_bank = $bank['iban'] !== '' && $bank['holder'] !== '';

$invoices = $pdo->prepare("SELECT * FROM finances WHERE deleted_at IS NULL AND contact_id=? AND type='INCOME' ORDER BY record_date DESC");
$invoices->execute([$client['id']]);
$invoices = $invoices->fetchAll(PDO::FETCH_ASSOC);

$tickets_stmt = $pdo->prepare("SELECT t.*,
    (SELECT COUNT(*) FROM ticket_notes tn WHERE tn.ticket_id=t.id AND tn.is_public=1) AS reply_count
    FROM support_tickets t WHERE t.contact_id=? ORDER BY t.created_at DESC");
$tickets_stmt->execute([$client['id']]);
$tickets = $tickets_stmt->fetchAll(PDO::FETCH_ASSOC);

$public_notes_by_ticket = [];
if (!empty($tickets)) {
    $tids = array_column($tickets, 'id');
    $tph  = implode(',', array_fill(0, count($tids), '?'));
    $tnq  = $pdo->prepare("SELECT * FROM ticket_notes WHERE ticket_id IN ($tph) AND is_public=1 ORDER BY created_at ASC");
    $tnq->execute($tids);
    foreach ($tnq->fetchAll(PDO::FETCH_ASSOC) as $n) {
        $public_notes_by_ticket[$n['ticket_id']][] = $n;
    }
}

// Wiki-Artikel + Anhänge
$wiki_articles = [];
$wiki_stmt = $pdo->prepare("SELECT wa.* FROM wiki_articles wa JOIN wiki_client_shares wcs ON wa.id=wcs.article_id WHERE wcs.contact_id=? ORDER BY wa.is_pinned DESC, wa.created_at DESC");
$wiki_stmt->execute([$client['id']]);
$wiki_articles = $wiki_stmt->fetchAll(PDO::FETCH_ASSOC);
if (!empty($wiki_articles)) {
    $wids    = array_column($wiki_articles, 'id');
    $wph     = implode(',', array_fill(0, count($wids), '?'));
    $att_q   = $pdo->prepare("SELECT * FROM wiki_attachments WHERE article_id IN ($wph) ORDER BY uploaded_at ASC");
    $att_q->execute($wids);
    $att_map = [];
    foreach ($att_q->fetchAll(PDO::FETCH_ASSOC) as $a) { $att_map[$a['article_id']][] = $a; }
    foreach ($wiki_articles as &$wa) { $wa['attachments'] = $att_map[$wa['id']] ?? []; }
    unset($wa);
}

// Quick-Stats für den Header
$open_inv_count   = count(array_filter($invoices, fn($i) => in_array($i['status'], ['Offen','Überfällig'])));
$open_ticket_count = count(array_filter($tickets, fn($t) => $t['status'] !== 'Erledigt'));
$active_proj_count = count(array_filter($projects, fn($p) => $p['status'] !== 'Erledigt'));

// Client-Avatar (erste Buchstaben des Namens)
$name_parts = explode(' ', $client['name']);
$avatar_letters = strtoupper(substr($name_parts[0], 0, 1) . (isset($name_parts[1]) ? substr($name_parts[1], 0, 1) : ''));

$is_partner = ($client['contact_type'] === 'Geschäftspartner');
?>
<!DOCTYPE html>
<html lang="<?= lang() ?>">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= $is_partner ? 'Partner-Portal' : te('Kundenportal') ?> | <?= setting('company_short', COMPANY_SHORT) ?></title>
  <link href="<?= asset('assets/vendor/bootstrap/bootstrap.min.css') ?>" rel="stylesheet">
  <link href="<?= asset('assets/vendor/bootstrap-icons/bootstrap-icons.css') ?>" rel="stylesheet">
  <link href="<?= asset('assets/vendor/fonts/fonts.css') ?>" rel="stylesheet">
  <link href="<?= asset('assets/vendor/prism/prism-tomorrow.min.css') ?>" rel="stylesheet">
  <link rel="stylesheet" href="<?= asset('assets/css/tokens.css') ?>">
  <?php if (demo_mode()): ?>
  <link rel="stylesheet" href="<?= asset('assets/css/demo.css') ?>">
  <script src="<?= asset('assets/js/demo.js') ?>" defer></script>
  <?php endif; ?>
  <?php $theme_follow_system = true; require 'includes/theme.php'; ?>
  <style>
    /* Die Textfarbe gehoert an den Rumpf. Ohne sie erben alle Elemente
       ohne eigene Farbe die Browservorgabe - im dunklen Thema also
       schwarze Schrift auf dunklem Grund. */
    body { background: var(--surface-page); color: var(--text-body); font-family: 'Open Sans', sans-serif; }

    /* ── Bootstrap im dunklen Thema ──────────────────────────────────
       portal.php laedt app.css nicht und hat deshalb auch dessen
       [data-theme]-Block nicht. Hier steht nur, was diese Seite
       wirklich benutzt. */
    [data-theme="dark"] .text-muted { color: var(--text-faint) !important; }
    [data-theme="dark"] .text-dark  { color: var(--text-body) !important; }
    [data-theme="dark"] .bg-white   { background: var(--surface-card) !important; }
    [data-theme="dark"] .border,
    [data-theme="dark"] .border-top,
    [data-theme="dark"] .border-bottom { border-color: var(--border-base) !important; }
    [data-theme="dark"] .form-control,
    [data-theme="dark"] .form-select {
      background: var(--surface-subtle); border-color: var(--border-strong); color: var(--text-body);
    }
    [data-theme="dark"] .form-control:focus,
    [data-theme="dark"] .form-select:focus {
      background: var(--surface-sunken); border-color: var(--color-primary); color: var(--text-strong);
    }
    [data-theme="dark"] .form-control::placeholder { color: var(--text-faint); }
    [data-theme="dark"] .form-label { color: var(--text-body); }
    [data-theme="dark"] .modal-content { background: var(--surface-card); border-color: var(--border-base); }
    [data-theme="dark"] .modal-header,
    [data-theme="dark"] .modal-footer { border-color: var(--border-base); }
    [data-theme="dark"] .btn-close { filter: invert(1); }
    [data-theme="dark"] hr { border-color: var(--border-base); }

    /* ── HEADER ── */
    .portal-header {
      background: linear-gradient(135deg, var(--color-sidebar) 0%, color-mix(in srgb, var(--color-sidebar) 60%, var(--color-primary)) 100%);
      padding: 52px 0 88px;
      position: relative;
      overflow: hidden;
    }
    .portal-header::before {
      content: '';
      position: absolute; inset: 0;
      background: url("data:image/svg+xml,%3Csvg width='60' height='60' viewBox='0 0 60 60' xmlns='http://www.w3.org/2000/svg'%3E%3Cg fill='none' fill-rule='evenodd'%3E%3Cg fill='%23ffffff' fill-opacity='0.04'%3E%3Cpath d='M36 34v-4h-2v4h-4v2h4v4h2v-4h4v-2h-4zm0-30V0h-2v4h-4v2h4v4h2V6h4V4h-4zM6 34v-4H4v4H0v2h4v4h2v-4h4v-2H6zM6 4V0H4v4H0v2h4v4h2V6h4V4H6z'/%3E%3C/g%3E%3C/g%3E%3C/svg%3E");
    }
    .portal-avatar {
      width: 84px; height: 84px; border-radius: 50%;
      background: var(--color-primary);
      border: 3px solid rgba(255,255,255,.35);
      box-shadow: 0 0 0 6px rgba(255,255,255,.1);
      display: flex; align-items: center; justify-content: center;
      font-size: 30px; font-weight: 800; color: var(--text-invert);
      font-family: 'Poppins', sans-serif;
      flex-shrink: 0;
    }
    .portal-header-stat {
      background: rgba(255,255,255,.12);
      border: 1px solid rgba(255,255,255,.18);
      border-radius: 14px;
      padding: 14px 24px;
      text-align: center;
      backdrop-filter: blur(6px);
      flex: 1 0 0%;
      min-width: 110px;
      max-width: 190px;
      transition: background .2s;
    }
    .portal-header-stat:hover { background: rgba(255,255,255,.18); }
    .portal-header-stat .stat-val { font-size: 26px; font-weight: 800; color: var(--text-invert); line-height: 1; font-family: 'Poppins', sans-serif; }
    .portal-header-stat .stat-lbl { font-size: 11px; color: rgba(255,255,255,.65); margin-top: 4px; letter-spacing: .3px; }

    /* ── NAV PILLS ── */
    .portal-nav-wrap {
      background: var(--surface-card);
      box-shadow: var(--elev-raised);
      position: sticky; top: 0; z-index: 100;
      margin-top: -36px;
    }
    .portal-nav-inner {
      display: flex; overflow-x: auto; gap: 4px;
      padding: 10px 16px; scrollbar-width: none;
    }
    .portal-nav-inner::-webkit-scrollbar { display: none; }

    /* Die Pillen scrollen waagerecht, der Schalter bleibt rechts stehen. */
    .portal-nav-row { display: flex; align-items: center; gap: 8px; }
    .portal-nav-row .portal-nav-inner { flex: 1 1 auto; min-width: 0; }
    .portal-theme-toggle {
      flex-shrink: 0; width: 36px; height: 36px; margin-right: 16px;
      border-radius: 8px; border: 1px solid var(--border-subtle);
      background: transparent; color: var(--text-muted);
      font-size: 16px; line-height: 1; cursor: pointer;
      display: flex; align-items: center; justify-content: center;
      transition: color .2s, background .2s, border-color .2s;
    }
    .portal-theme-toggle:hover {
      color: var(--color-primary); border-color: var(--color-primary);
      background: var(--accent-soft);
    }
    .portal-theme-toggle:focus-visible {
      outline: 2px solid var(--color-primary); outline-offset: 2px;
    }
    /* Der Sprachknopf teilt sich Form und Verhalten mit dem Theme-Knopf,
       traegt aber Buchstaben statt eines Symbols. */
    .portal-lang-toggle {
      margin-right: 0; font-size: 12px; font-weight: 700;
      letter-spacing: .5px; text-decoration: none;
    }
    .portal-pill {
      white-space: nowrap; border-radius: 8px;
      padding: 8px 18px; font-size: 13px; font-weight: 600;
      color: var(--text-muted); border: none; background: transparent;
      cursor: pointer; transition: all .2s; display: flex; align-items: center; gap: 6px;
    }
    .portal-pill:hover { background: var(--accent-soft); color: var(--color-primary); }
    .portal-pill.active { background: var(--color-primary); color: var(--text-invert); }
    .portal-pill .pill-badge {
      background: rgba(255,255,255,.3); color: var(--text-invert);
      font-size: 10px; border-radius: 10px; padding: 1px 6px; font-weight: 700;
    }
    .portal-pill:not(.active) .pill-badge { background: var(--surface-sunken); color: var(--text-body); }
    .portal-pill.active .pill-badge-danger { background: var(--accent-danger); }

    /* ── CONTENT ── */
    .portal-content { padding: 32px 0 60px; }
    .tab-pane { display: none; }
    .tab-pane.active { display: block; }

    /* ── PROJECT CARDS ── */
    .project-card {
      background: var(--surface-card); border-radius: 16px;
      box-shadow: var(--elev-rest);
      border: 1px solid var(--border-subtle);
      overflow: hidden; margin-bottom: 24px;
    }
    .project-card-header {
      padding: 20px 24px 16px;
      border-bottom: 1px solid var(--border-subtle);
    }
    .project-progress-bar {
      height: 6px; border-radius: 3px;
      background: var(--surface-sunken); overflow: hidden;
    }
    .project-progress-fill {
      height: 100%; border-radius: 3px;
      background: var(--color-primary);
      transition: width .5s ease;
    }
    .project-progress-fill.complete { background: var(--accent-success); }

    /* ── MILESTONE TIMELINE ── */
    .timeline { padding: 4px 0; }
    .tl-item {
      display: flex; gap: 12px;
      position: relative; padding-bottom: 4px;
    }
    .tl-item:not(:last-child) .tl-line { display: block; }
    .tl-left { display: flex; flex-direction: column; align-items: center; flex-shrink: 0; width: 28px; }
    .tl-dot {
      width: 28px; height: 28px; border-radius: 50%;
      border: 2px solid var(--border-base); background: var(--surface-card);
      display: flex; align-items: center; justify-content: center;
      font-size: 13px; flex-shrink: 0; transition: all .2s; z-index: 1;
    }
    .tl-dot.done    { background: var(--accent-success); border-color: var(--accent-success); color: var(--text-invert); }
    .tl-dot.pending { background: var(--state-warn-bg); border-color: var(--accent-warning); color: var(--state-warn-fg); }
    .tl-dot.approved { background: var(--color-primary); border-color: var(--color-primary); color: var(--text-invert); }
    .tl-line {
      display: none; flex: 1; width: 2px;
      background: var(--surface-sunken); margin: 4px 0;
      min-height: 16px;
    }
    .tl-body { flex: 1; padding-bottom: 16px; }
    .tl-title { font-weight: 600; font-size: 14px; color: var(--text-strong); line-height: 1.4; }
    .tl-title.done-text { text-decoration: line-through; color: var(--text-faint); font-weight: 400; }
    .tl-meta { font-size: 11px; color: var(--text-faint); margin-top: 2px; }

    /* ── MILESTONE COMMENTS ── */
    .ms-comments { margin-top: 10px; }
    .ms-comment-bubble {
      background: var(--accent-soft); border-radius: 0 10px 10px 10px;
      padding: 9px 13px; margin-bottom: 6px; font-size: 13px;
      border: 1px solid var(--state-info-fg); position: relative;
    }
    .ms-comment-bubble.self {
      background: var(--state-success-bg); border-color: var(--state-success-fg); border-radius: 10px 10px 10px 0;
    }
    .ms-comment-meta { font-size: 11px; color: var(--text-muted); margin-bottom: 4px; font-weight: 600; }
    .ms-comment-text { color: var(--text-body); line-height: 1.5; }
    .ms-comment-form { display: none; margin-top: 8px; }
    .ms-comment-form.open { display: block; }
    .ms-comment-toggle {
      font-size: 12px; color: var(--color-primary); background: none; border: none;
      padding: 2px 0; cursor: pointer; font-weight: 600;
      display: flex; align-items: center; gap: 4px;
      transition: opacity .15s;
    }
    .ms-comment-toggle:hover { opacity: .7; }

    /* ── PROJECT COLLAPSE ── */
    .project-card-header {
      cursor: pointer; user-select: none;
    }
    .project-card-header:hover { background: var(--surface-subtle); }
    .proj-chevron { transition: transform .3s ease; color: var(--text-faint); flex-shrink: 0; }
    .project-card-header[aria-expanded="false"] .proj-chevron { transform: rotate(-90deg); }

    /* ── APPROVE BUTTON ── */
    .btn-approve {
      background: var(--state-success-bg); color: var(--state-success-fg); border: 1px solid var(--state-success-fg);
      border-radius: 8px; padding: 5px 14px; font-size: 12px; font-weight: 700;
      cursor: pointer; transition: all .2s; white-space: nowrap;
    }
    .btn-approve:hover { background: var(--accent-success); color: var(--text-invert); border-color: var(--accent-success); }

    /* ── FILES ZONE ── */
    .upload-zone {
      border: 2px dashed var(--border-strong); border-radius: 12px;
      padding: 24px 16px; text-align: center; cursor: pointer;
      transition: all .2s; background: var(--surface-subtle);
    }
    .upload-zone:hover, .upload-zone.dragover {
      border-color: var(--color-primary); background: var(--accent-soft);
    }
    .file-row {
      display: flex; align-items: center; gap: 8px;
      padding: 8px 10px; border-radius: 8px;
      background: var(--surface-subtle); border: 1px solid var(--border-subtle);
      margin-bottom: 6px; font-size: 13px;
    }
    .file-row .file-name { flex: 1; min-width: 0; font-weight: 600; color: var(--text-body); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .file-badge-admin { background: var(--state-info-bg); color: var(--state-info-fg); font-size: 10px; border-radius: 4px; padding: 1px 6px; font-weight: 700; white-space: nowrap; }
    .file-badge-client { background: var(--state-neutral-bg); color: var(--state-neutral-fg); font-size: 10px; border-radius: 4px; padding: 1px 6px; font-weight: 700; white-space: nowrap; }

    /* ── INVOICE CARDS ── */
    .invoice-card {
      background: var(--surface-card); border-radius: 14px;
      box-shadow: var(--elev-rest);
      border: 1px solid var(--border-subtle); padding: 20px;
      transition: box-shadow .2s; height: 100%;
    }
    .invoice-card:hover { box-shadow: var(--elev-raised); }
    .invoice-amount { font-size: 26px; font-weight: 800; font-family: 'Poppins', sans-serif; color: var(--text-strong); }
    .invoice-overdue .invoice-amount { color: var(--accent-danger); }

    /* ── TICKET CARDS ── */
    .ticket-card {
      background: var(--surface-card); border-radius: 14px;
      box-shadow: var(--elev-rest);
      border: 1px solid var(--border-subtle);
      margin-bottom: 14px;
      border-left: 4px solid var(--border-base);
      overflow: hidden;
    }
    .ticket-card.open   { border-left-color: var(--accent-warning); }
    .ticket-card.active { border-left-color: var(--color-primary); }
    .ticket-card.done   { border-left-color: var(--accent-success); opacity: .8; }
    .ticket-header { padding: 16px 20px; cursor: pointer; user-select: none; transition: background .15s; }
    .ticket-header:hover { background: var(--surface-subtle); }
    .ticket-chevron { font-size: 13px; transition: transform .3s ease; color: var(--text-faint); flex-shrink: 0; }
    [data-bs-toggle="collapse"][aria-expanded="true"] .ticket-chevron { transform: rotate(180deg); }
    .ticket-body { padding: 0 20px 16px; background: var(--surface-subtle); border-top: 1px solid var(--border-subtle); }

    /* ── ZUSAMMENARBEIT ── */
    .member-chip {
      display: inline-flex; align-items: center; gap: 6px;
      padding: 3px 10px 3px 3px; border-radius: 999px;
      background: var(--surface-subtle); border: 1px solid var(--border-subtle);
      font-size: 12px; color: var(--text-body);
    }
    .member-chip-self { border-color: var(--color-primary); }
    .member-dot {
      width: 22px; height: 22px; border-radius: 50%;
      background: var(--accent-soft); color: var(--color-primary);
      display: inline-flex; align-items: center; justify-content: center;
      font-size: 11px; font-weight: 700; flex-shrink: 0;
    }
    .proj-talk { max-height: 280px; overflow-y: auto; padding-right: 4px; }
    .wait-chip {
      display: inline-block; margin-left: 8px; padding: 1px 8px;
      border-radius: 999px; font-size: 10px; font-weight: 700;
      vertical-align: middle; white-space: nowrap;
    }
    /* Die Farbe sagt, wo der Ball liegt - bei uns beruhigend, bei Ihnen
       auffordernd. */
    .wait-us   { background: var(--accent-soft);  color: var(--color-primary); }
    .wait-them { background: var(--state-warn-bg); color: var(--state-warn-fg); }

    /* ── ZAHLUNG ── */
    .pay-box { margin-top: 14px; padding: 14px; border-radius: 12px;
      background: var(--surface-subtle); border: 1px solid var(--border-subtle); }
    .pay-qr { flex-shrink: 0; line-height: 0; background: #fff; padding: 6px; border-radius: 8px; }
    .pay-qr img, .pay-qr canvas { display: block; }
    .pay-box-noqr .pay-qr { display: none; }
    .pay-details { display: grid; gap: 3px; font-size: 13px; min-width: 0; }
    .pay-details > div { display: flex; gap: 8px; flex-wrap: wrap; }
    .pay-key { color: var(--text-muted); min-width: 128px; flex-shrink: 0; }
    .pay-val { color: var(--text-strong); word-break: break-all; }
    .pay-mono { font-family: var(--font-mono); }
    .pay-hint { margin-top: 10px; font-size: 11px; color: var(--text-muted); line-height: 1.5; }
    @media(max-width:576px) { .pay-key { min-width: 100%; } .pay-details > div { gap: 0; } }

    /* ── TOAST ── */
    .toast-container { position: fixed; top: 20px; right: 20px; z-index: 1090; }

    /* ── MISC ── */
    .section-label { font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .7px; color: var(--text-faint); margin-bottom: 10px; }
    .feedback-card { background: var(--state-warn-bg); border-radius: 14px; border: 1px solid var(--accent-warning); padding: 20px; }
    .empty-state { text-align: center; padding: 48px 20px; color: var(--text-faint); }
    .empty-state i { font-size: 48px; display: block; margin-bottom: 12px; }
    .empty-state p { font-weight: 600; margin: 0; }

    @media(max-width:768px) {
      .portal-header { padding: 28px 0 56px; }
      .portal-header-stat { min-width: 90px; max-width: none; padding: 10px 14px; flex: 1; }
      .portal-header-stat .stat-val { font-size: 20px; }
      .portal-nav-inner { padding: 8px 10px; gap: 2px; }
      .portal-pill { padding: 7px 12px; font-size: 12px; }
      .invoice-amount { font-size: 20px; }
      .portal-content { padding: 20px 0 50px; }
      .project-card-header { padding: 16px 18px 12px; }
    }
  </style>
</head>
<body>

<?php if (demo_mode()): ?>
  <div class="demo-portal-hinweis" role="status">
    <i class="bi bi-eye" aria-hidden="true"></i>
    <span><strong><?= te('Demo-Version') ?></strong> &ndash; <?= te('dies ist ein Beispielportal. Alle Namen, Projekte und Beträge sind erfunden, Änderungen werden nicht gespeichert.') ?></span>
  </div>
<?php endif; ?>

<!-- TOAST -->
<div class="toast-container">
  <?php if(isset($_GET['msg'])): ?>
  <div class="toast show align-items-center text-bg-success border-0 shadow-lg" role="alert" aria-atomic="true">
    <div class="d-flex">
      <div class="toast-body fw-bold">
        <?php
          $msgs = [
            'approved'        => t('Meilenstein erfolgreich abgesegnet!'),
            'feedback'        => t('Ihr Feedback wurde gespeichert!'),
            'deleted'         => t('Datei erfolgreich entfernt!'),
            'uploaded'        => t('Datei(en) erfolgreich hochgeladen!'),
            'ticket_created'  => t('Ihre Anfrage wurde gesendet!'),
            'reply_sent'      => t('Ihre Antwort wurde gesendet!'),
            'ticket_closed'   => t('Ticket als erledigt markiert.'),
            'ticket_deleted'  => t('Ticket wurde gelöscht.'),
            'profile_updated' => t('Ihre Daten wurden aktualisiert!'),
            'quote_accepted'  => t('Vielen Dank! Wir haben Ihre Zusage erhalten.'),
            'quote_question'  => t('Ihre Rückfrage ist bei uns eingegangen.'),
            'comment'         => t('Ihr Beitrag ist gespeichert.'),
          ];
          echo '<i class="bi bi-check-circle-fill me-2"></i>' . ($msgs[$_GET['msg']] ?? '');
        ?>
      </div>
      <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
    </div>
  </div>
  <?php endif; ?>
</div>

<!-- HEADER -->
<header class="portal-header">
  <div class="container">
    <div class="text-center mb-4">
      <div class="portal-avatar mx-auto mb-3"><?= $avatar_letters ?></div>
      <p class="text-white-50 small mb-2 fw-semibold d-flex align-items-center justify-content-center gap-2">
        <?= htmlspecialchars(setting('company_short', COMPANY_SHORT)) ?>
        <?php if($is_partner): ?>
          <span style="background:rgba(255,193,7,0.25);color:var(--accent-warning);border:1px solid rgba(255,193,7,0.4);border-radius:20px;padding:1px 10px;font-size:10px;font-weight:700;letter-spacing:0.5px;"><?= te('PARTNER') ?></span>
        <?php endif; ?>
      </p>
      <h1 class="text-white fw-bold mb-1" style="font-family:'Poppins',sans-serif;font-size:clamp(22px,5vw,36px);">
        <?= $is_partner ? te('Guten Tag') : 'Willkommen' ?><?= $client['company'] ? ', '.htmlspecialchars($client['company']) : ', '.htmlspecialchars(explode(' ',$client['name'])[0]) ?>!
      </h1>
      <p class="text-white-50 mb-0 small"><?= $is_partner ? te('Ihr persönliches Partner-Portal') : te('Ihr persönliches Projektportal') ?></p>
    </div>
    <div class="d-flex gap-3 flex-wrap justify-content-center">
      <div class="portal-header-stat">
        <div class="stat-val"><?= $active_proj_count ?></div>
        <div class="stat-lbl"><?= te('Aktive Projekte') ?></div>
      </div>
      <?php if($is_partner): ?>
      <div class="portal-header-stat">
        <div class="stat-val"><?= count($projects) ?></div>
        <div class="stat-lbl"><?= te('Projekte gesamt') ?></div>
      </div>
      <?php else: ?>
      <div class="portal-header-stat">
        <div class="stat-val" style="<?= $open_inv_count > 0 ? 'color:var(--accent-warning);' : '' ?>"><?= $open_inv_count ?></div>
        <div class="stat-lbl"><?= te('Offene Rechnungen') ?></div>
      </div>
      <?php endif; ?>
      <div class="portal-header-stat">
        <div class="stat-val" style="<?= $open_ticket_count > 0 ? 'color:var(--accent-danger);' : '' ?>"><?= $open_ticket_count ?></div>
        <div class="stat-lbl"><?= $is_partner ? te('Offene Anfragen') : 'Support-Tickets' ?></div>
      </div>
    </div><!-- /stats -->
  </div><!-- /container -->
</header>

<!-- NAV PILLS -->
<div class="portal-nav-wrap">
  <div class="container">
    <div class="portal-nav-row">
      <div class="portal-nav-inner" id="portalNav">
      <button class="portal-pill active" data-tab="projects">
        <i class="bi bi-<?= $is_partner ? 'diagram-3-fill' : 'kanban-fill' ?>"></i> <?= $is_partner ? te('Zusammenarbeit') : te('Projekte') ?>
        <?php if($active_proj_count > 0): ?><span class="pill-badge"><?= $active_proj_count ?></span><?php endif; ?>
      </button>
      <button class="portal-pill" data-tab="quotes">
        <i class="bi bi-file-earmark-text"></i> <?= te('Angebote') ?>
        <?php if($open_quote_count > 0): ?>
          <span class="pill-badge pill-badge-danger"><?= $open_quote_count ?></span>
        <?php elseif(count($quotes) > 0): ?>
          <span class="pill-badge"><?= count($quotes) ?></span>
        <?php endif; ?>
      </button>
      <button class="portal-pill" data-tab="invoices">
        <i class="bi bi-receipt"></i> <?= $is_partner ? te('Abrechnungen') : te('Rechnungen') ?>
        <?php if($open_inv_count > 0): ?><span class="pill-badge pill-badge-danger"><?= $open_inv_count ?></span><?php endif; ?>
      </button>
      <button class="portal-pill" data-tab="support">
        <i class="bi bi-<?= $is_partner ? 'envelope-fill' : 'life-preserver' ?>"></i> <?= $is_partner ? te('Anfragen') : te('Support') ?>
        <?php if($open_ticket_count > 0): ?><span class="pill-badge"><?= $open_ticket_count ?></span><?php endif; ?>
      </button>
      <?php if(!empty($wiki_articles)): ?>
      <button class="portal-pill" data-tab="wiki">
        <i class="bi bi-<?= $is_partner ? 'folder2-open' : 'book-fill' ?>"></i> <?= $is_partner ? te('Ressourcen') : te('Wissen') ?>
        <span class="pill-badge"><?= count($wiki_articles) ?></span>
      </button>
      <?php endif; ?>
      <button class="portal-pill" data-tab="profile">
        <i class="bi bi-person-fill"></i> <?= te('Mein Profil') ?>
      </button>
      </div>
      <a class="portal-theme-toggle portal-lang-toggle"
         href="portal?token=<?= urlencode($token) ?>&amp;lang=<?= lang() === 'de' ? 'en' : 'de' ?>"
         title="<?= te('Sprache wechseln') ?>">
        <span aria-hidden="true"><?= lang() === 'de' ? 'EN' : 'DE' ?></span>
        <span class="visually-hidden"><?= te('Sprache wechseln') ?></span>
      </a>
      <button type="button" class="portal-theme-toggle" id="portalThemeToggle" aria-pressed="false">
        <i class="bi bi-moon-stars" aria-hidden="true"></i>
        <span class="visually-hidden"><?= te('Dunkles Design umschalten') ?></span>
      </button>
    </div>
  </div>
</div>

<!-- MAIN CONTENT -->
<div class="portal-content">
  <div class="container">

    <!-- ═══════════════════════════════════════ PROJEKTE ═══ -->
    <div class="tab-pane active" id="tab-projects">

      <?php if(empty($projects)): ?>
        <div class="empty-state bg-white rounded-4 shadow-sm border">
          <i class="bi bi-folder-x"></i>
          <p><?= $is_partner ? te('Aktuell sind keine gemeinsamen Projekte hinterlegt.') : te('Aktuell sind keine Projekte hinterlegt.') ?></p>
        </div>

      <?php else: ?>
        <?php
        // Suchfeld
        if(count($projects) > 2): ?>
        <div class="d-flex justify-content-end mb-3">
          <div class="input-group" style="max-width:280px;">
            <span class="input-group-text bg-white border-end-0"><i class="bi bi-search text-muted small"></i></span>
            <input type="text" id="projectSearch" class="form-control border-start-0 ps-0 bg-white" placeholder="<?= te('Projekt suchen...') ?>">
          </div>
        </div>
        <?php endif; ?>

        <?php foreach($projects as $p_idx => $p):
          $milestones = $milestones_by_task[$p['id']] ?? [];
          $assets     = $assets_by_task[$p['id']] ?? [];
          $total = count($milestones);
          $done  = count(array_filter($milestones, fn($m) => $m['is_completed']));
          $prog  = $total > 0 ? round($done / $total * 100) : 0;
          $status_colors = ['In Bearbeitung'=>'var(--color-primary)','Erledigt'=>'var(--accent-success)','Pausiert'=>'var(--accent-warning)','Planung'=>'var(--text-muted)'];
          $s_color = $status_colors[$p['status']] ?? 'var(--text-muted)';
        ?>
        <div class="project-card project-card-item" data-title="<?= htmlspecialchars(strtolower($p['title'])) ?>">

          <!-- Project Header -->
          <div class="project-card-header" data-bs-toggle="collapse" data-bs-target="#proj-body-<?= $p['id'] ?>"
               aria-expanded="<?= $p_idx === 0 ? 'true' : 'false' ?>" aria-controls="proj-body-<?= $p['id'] ?>">
            <div class="d-flex align-items-start justify-content-between gap-3 mb-3 flex-wrap">
              <div style="min-width:0;">
                <h4 class="fw-bold mb-1 project-title-text" style="font-family:'Poppins',sans-serif;color:var(--text-strong);"><?= htmlspecialchars($p['title']) ?></h4>
                <?php if($p['category']): ?>
                  <span class="badge rounded-pill small fw-normal" style="background:var(--accent-soft);color:var(--color-primary);"><?= htmlspecialchars($p['category']) ?></span>
                <?php endif; ?>
              </div>
              <div class="d-flex align-items-center gap-2 flex-shrink-0">
                <span class="badge fw-semibold py-2 px-3 rounded-pill" style="background:color-mix(in srgb, <?= $s_color ?> 12%, transparent);color:<?= $s_color ?>;border:1px solid <?= $s_color ?>44;white-space:nowrap;"><?= htmlspecialchars($p['status']) ?></span>
                <i class="bi bi-chevron-down proj-chevron" style="<?= $p_idx !== 0 ? 'transform:rotate(-90deg);' : '' ?>"></i>
              </div>
            </div>
            <!-- Progress -->
            <div class="d-flex justify-content-between align-items-center mb-1 small fw-semibold">
              <span style="color:var(--color-primary);"><?= te('Fortschritt') ?></span>
              <span style="color:<?= $prog==100?'var(--accent-success)':'var(--color-primary)' ?>;"><?= $prog ?>%</span>
            </div>
            <div class="project-progress-bar">
              <div class="project-progress-fill <?= $prog==100?'complete':'' ?>" style="width:<?= $prog ?>%;"></div>
            </div>
            <?php if($total > 0): ?>
              <div class="text-muted mt-1" style="font-size:11px;"><?= $done ?> von <?= $total ?> <?= te('Schritten abgeschlossen') ?></div>
            <?php endif; ?>
          </div>

          <!-- Project Body -->
          <div class="collapse <?= $p_idx === 0 ? 'show' : '' ?>" id="proj-body-<?= $p['id'] ?>">
          <div class="p-4">
            <div class="row g-4">

              <!-- LEFT: Beschreibung + Meilensteine -->
              <div class="col-lg-7">
                <?php if(!empty(trim($p['description']))): ?>
                  <p class="text-muted mb-4" style="font-size:14px;line-height:1.75;"><?= nl2br(htmlspecialchars($p['description'])) ?></p>
                <?php endif; ?>

                <!-- Milestone Timeline -->
                <?php if(!empty($milestones)): ?>
                <div class="section-label"><i class="bi bi-list-check me-1"></i><?= $is_partner ? 'Projektschritte' : te('Roadmap & Meilensteine') ?></div>
                <?php if(!$is_partner): ?>
                <p class="text-muted small mb-3"><?= te('Schließen Sie erledigte Schritte mit "Absegnen" ab, damit wir mit dem nächsten beginnen können.') ?></p>
                <?php endif; ?>
                <div class="timeline">
                  <?php foreach($milestones as $idx => $ms):
                    $is_last   = $idx === count($milestones) - 1;
                    $ms_coms   = $comments_by_ms[$ms['id']] ?? [];
                    $dot_class = $ms['approved_at'] ? 'approved' : ($ms['is_completed'] ? 'pending' : '');
                    $dot_icon  = $ms['approved_at'] ? 'bi-check-lg' : ($ms['is_completed'] ? 'bi-hourglass-split' : '');
                  ?>
                  <div class="tl-item">
                    <div class="tl-left">
                      <div class="tl-dot <?= $dot_class ?>">
                        <?php if($dot_icon): ?><i class="bi <?= $dot_icon ?>" style="font-size:12px;"></i><?php endif; ?>
                      </div>
                      <?php if(!$is_last): ?><div class="tl-line"></div><?php endif; ?>
                    </div>
                    <div class="tl-body">
                      <div class="d-flex align-items-start justify-content-between gap-2 flex-wrap">
                        <div style="min-width:0;">
                          <div class="tl-title <?= $ms['approved_at'] ? 'done-text' : '' ?>"><?= htmlspecialchars($ms['title']) ?></div>
                            <?php if(!empty($ms['waiting_on']) && !$ms['is_completed']):
                              $wir = $ms['waiting_on'] === 'us';
                            ?>
                              <span class="wait-chip <?= $wir ? 'wait-us' : 'wait-them' ?>">
                                <i class="bi bi-hourglass-split me-1"></i>
                                <?= $wir ? te('Wir sind dran') : te('Sie sind dran') ?>
                              </span>
                            <?php endif; ?>
                          <?php if($ms['approved_at']): ?>
                            <div class="tl-meta"><i class="bi bi-patch-check-fill text-success me-1"></i><?= $is_partner ? te('Abgeschlossen am') : te('Freigegeben am') ?> <?= date('d.m.Y', strtotime($ms['approved_at'])) ?></div>
                          <?php elseif($ms['is_completed']): ?>
                            <?php if(!$is_partner): ?>
                            <div class="tl-meta" style="color:var(--state-warn-fg);"><i class="bi bi-hourglass me-1"></i><?= te('Warten auf Ihre Freigabe') ?></div>
                            <?php else: ?>
                            <div class="tl-meta text-success"><i class="bi bi-check2 me-1"></i><?= te('Abgeschlossen') ?></div>
                            <?php endif; ?>
                          <?php endif; ?>
                        </div>
                        <?php if(!$is_partner && $ms['is_completed'] && !$ms['approved_at']): ?>
                          <form method="POST" class="m-0 flex-shrink-0">
    <?= csrf_field() ?>
                            <input type="hidden" name="ms_id" value="<?= $ms['id'] ?>">
                            <button type="submit" name="approve_ms" class="btn-approve"><i class="bi bi-check-circle me-1"></i><?= te('Absegnen') ?></button>
                          </form>
                        <?php endif; ?>
                      </div>

                      <!-- Kommentar-Thread -->
                      <?php if(!empty($ms_coms)): ?>
                      <div class="ms-comments mt-2">
                        <?php foreach($ms_coms as $c): ?>
                        <div class="ms-comment-bubble<?= $c['author']==='client' ? ' self' : '' ?>">
                          <div class="ms-comment-meta">
                            <?php
                              // Vorher stand hier pauschal "Sie" - bei mehreren
                              // Beteiligten war das schlicht falsch, sobald jemand
                              // anderes geschrieben hatte.
                              $c_ist_ich = $c['author'] === 'client'
                                  && trim((string)$c['author_name']) === trim((string)$client['name']);
                            ?>
                            <?= $c['author']==='client'
                                 ? '<i class="bi bi-person-fill me-1"></i>' . htmlspecialchars($c['author_name'] ?: te('Unbekannt')) . ($c_ist_ich ? ' (Sie)' : '')
                                 : '<i class="bi bi-headset me-1"></i>'.htmlspecialchars(setting('company_short', COMPANY_SHORT)) ?>
                            <span class="fw-normal ms-2 text-muted"><?= date('d.m.Y H:i', strtotime($c['created_at'])) ?></span>
                          </div>
                          <div class="ms-comment-text"><?= nl2br(htmlspecialchars($c['message'])) ?></div>
                        </div>
                        <?php endforeach; ?>
                      </div>
                      <?php endif; ?>

                      <!-- Kommentar hinzufügen -->
                      <div class="mt-2">
                        <button type="button" class="ms-comment-toggle" onclick="toggleCommentForm(this, <?= $ms['id'] ?>)">
                          <i class="bi bi-chat-dots"></i>
                          <?= empty($ms_coms) ? te('Kommentar hinterlassen') : 'Antworten' ?>
                        </button>
                        <div class="ms-comment-form" id="cf_<?= $ms['id'] ?>">
                          <div class="d-flex gap-2 mt-2">
                            <textarea class="form-control form-control-sm" id="cft_<?= $ms['id'] ?>" rows="2"
                                      placeholder="<?= te('Ihre Anmerkung zu diesem Schritt...') ?>" style="font-size:13px;resize:none;border-radius:10px;"></textarea>
                            <div class="d-flex flex-column gap-1">
                              <button type="button" class="btn btn-sm btn-primary px-3 fw-bold" onclick="submitComment(<?= $ms['id'] ?>)" style="border-radius:8px;">
                                <i class="bi bi-send-fill"></i>
                              </button>
                              <button type="button" class="btn btn-sm btn-outline-secondary px-3" onclick="toggleCommentForm(null, <?= $ms['id'] ?>)" style="border-radius:8px;">
                                <i class="bi bi-x"></i>
                              </button>
                            </div>
                          </div>
                        </div>
                      </div>

                    </div>
                  </div>
                  <?php endforeach; ?>
                </div>
                <?php else: ?>
                  <div class="text-muted small fst-italic py-2"><?= te('Noch keine Meilensteine für dieses Projekt.') ?></div>
                <?php endif; ?>
              </div>

              <!-- RIGHT: Dateien + Feedback -->
              <div class="col-lg-5">

                <!-- Dateien -->
                <div class="mb-4">
                  <div class="section-label"><i class="bi bi-paperclip me-1"></i><?= $is_partner ? te('Dokumente & Dateien') : te('Dateien & Assets') ?></div>

                  <?php if(!empty($assets)): ?>
                  <div class="mb-3" style="max-height:220px;overflow-y:auto;">
                    <?php foreach($assets as $asset):
                      $ext = strtolower(pathinfo($asset['file_name'], PATHINFO_EXTENSION));
                      $viewable = in_array($ext, ['pdf','jpg','jpeg','png','gif','webp','svg']);
                      $is_admin = isset($asset['uploaded_by']) && $asset['uploaded_by'] === 'admin';
                    ?>
                    <div class="file-row" id="asset_row_<?= $asset['id'] ?>">
                      <i class="bi bi-file-earmark text-muted flex-shrink-0"></i>
                      <span class="file-name" title="<?= htmlspecialchars($asset['file_name']) ?>"><?= htmlspecialchars($asset['file_name']) ?></span>
                      <?php
                        // Vorher stand hier pauschal "Von Ihnen" - bei mehreren
                        // Beteiligten sagt das nichts. Der Name kommt aus
                        // Migration 7; fuer aeltere Uploads ohne Namen bleibt
                        // die alte Beschriftung.
                        $lader = trim((string)($asset['uploaded_by_name'] ?? ''));
                        $selbst = isset($asset['uploaded_by_contact_id'])
                               && (int)$asset['uploaded_by_contact_id'] === (int)$client['id'];
                      ?>
                      <span class="<?= $is_admin ? 'file-badge-admin' : 'file-badge-client' ?>"
                            title="<?= $is_admin ? te('Von uns hochgeladen') : htmlspecialchars($lader !== '' ? "Hochgeladen von $lader" : te('Von Ihrer Seite hochgeladen')) ?>">
                        <?= $is_admin ? te('Von uns')
                             : ($lader !== '' ? htmlspecialchars($selbst ? 'Sie' : $lader) : te('Von Ihnen')) ?>
                      </span>
                      <?php if($viewable): ?>
                        <a href="file?type=asset&amp;id=<?= (int)$asset['id'] ?>&amp;token=<?= urlencode($token) ?>" target="_blank" class="text-muted" title="<?= te('Ansehen') ?>"><i class="bi bi-eye small"></i></a>
                      <?php endif; ?>
                      <a href="file?type=asset&amp;id=<?= (int)$asset['id'] ?>&amp;token=<?= urlencode($token) ?>&amp;dl=1" download class="text-primary" title="<?= te('Herunterladen') ?>"><i class="bi bi-download small"></i></a>
                      <?php if(!$is_admin): ?>
                      <form method="POST" class="m-0 d-inline" id="del_asset_form_<?= $asset['id'] ?>">
                        <?= csrf_field() ?>
                        <input type="hidden" name="delete_asset" value="1">
                        <input type="hidden" name="asset_id" value="<?= $asset['id'] ?>">
                        <button type="button" class="btn-icon text-danger p-0" style="background:none;border:none;cursor:pointer;font-size:13px;"
                                data-confirmed="0" onclick="confirmDeleteAsset(this,<?= $asset['id'] ?>)" title="<?= te('Löschen') ?>"><i class="bi bi-trash3"></i></button>
                      </form>
                      <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                  </div>
                  <?php endif; ?>

                  <div class="upload-zone" data-task-id="<?= $p['id'] ?>" onclick="document.getElementById('file_<?= $p['id'] ?>').click()">
                    <i class="bi bi-cloud-upload-fill fs-2 mb-2 d-block" style="color:var(--color-primary);opacity:.6;"></i>
                    <div class="fw-semibold small text-dark"><?= te('Dateien hochladen') ?></div>
                    <div class="text-muted mt-1" style="font-size:11px;"><?= te('Klicken oder Dateien hierher ziehen · max. 100 MB') ?></div>
                    <input type="file" id="file_<?= $p['id'] ?>" multiple style="display:none;" onchange="autoUpload(this,<?= $p['id'] ?>)">
                  </div>
                  <div class="progress mt-2" id="box_<?= $p['id'] ?>" style="display:none;height:6px;border-radius:3px;">
                    <div id="bar_<?= $p['id'] ?>" class="progress-bar progress-bar-animated bg-success" style="width:0;"></div>
                  </div>
                </div>

                <?php
                  $beteiligte = $project_members[$p['id']] ?? [];
                  $beitraege  = $project_comments[$p['id']] ?? [];
                ?>

                <!-- Beteiligte -->
                <?php if(count($beteiligte) > 1): ?>
                <div class="section-label mt-4"><i class="bi bi-people me-1"></i><?= te('Beteiligte') ?></div>
                <div class="d-flex flex-wrap gap-2 mb-2">
                  <?php foreach($beteiligte as $b): ?>
                    <span class="member-chip<?= (int)$b['contact_id'] === (int)$client['id'] ? ' member-chip-self' : '' ?>"
                          title="<?= htmlspecialchars(trim($b['company'] . ' · ' . ($b['role'] === 'owner' ? te('Hauptansprechpartner') : te('Beteiligt')), ' ·')) ?>">
                      <span class="member-dot"><?= htmlspecialchars(mb_strtoupper(mb_substr($b['name'], 0, 1))) ?></span>
                      <?= htmlspecialchars($b['name']) ?><?= (int)$b['contact_id'] === (int)$client['id'] ? ' (Sie)' : '' ?>
                    </span>
                  <?php endforeach; ?>
                </div>
                <?php endif; ?>

                <!-- Projekt-Diskussion -->
                <div class="section-label mt-4">
                  <i class="bi bi-chat-square-text me-1"></i><?= te('Austausch zum Projekt') ?>
                  <?php if($beitraege): ?><span class="text-muted">(<?= count($beitraege) ?>)</span><?php endif; ?>
                </div>
                <p class="section-hint">
                  Für alles, was sich keinem einzelnen Schritt zuordnen lässt. Alle
                  Beteiligten sehen den Verlauf.
                </p>

                <?php if($beitraege): ?>
                  <div class="proj-talk">
                    <?php foreach($beitraege as $b):
                      $vonMir = $b['author_contact_id'] !== null && (int)$b['author_contact_id'] === (int)$client['id'];
                      $vonUns = $b['author_contact_id'] === null;
                    ?>
                    <div class="ms-comment-bubble<?= $vonMir ? ' self' : '' ?>">
                      <div class="ms-comment-meta">
                        <?= $vonUns ? htmlspecialchars(setting('company_short', COMPANY_SHORT))
                                    : htmlspecialchars($b['author_name']) ?><?= $vonMir ? ' (Sie)' : '' ?>
                        · <?= date('d.m.Y H:i', strtotime($b['created_at'])) ?>
                      </div>
                      <div class="ms-comment-text"><?= nl2br(htmlspecialchars($b['message'])) ?></div>
                    </div>
                    <?php endforeach; ?>
                  </div>
                <?php endif; ?>

                <form method="POST" class="mt-2">
                  <?= csrf_field() ?>
                  <input type="hidden" name="task_id" value="<?= (int)$p['id'] ?>">
                  <div class="d-flex gap-2 align-items-end flex-wrap">
                    <div style="flex:1 1 240px;min-width:0;">
                      <label class="visually-hidden" for="pc_<?= (int)$p['id'] ?>"><?= te('Beitrag') ?></label>
                      <textarea class="form-control" id="pc_<?= (int)$p['id'] ?>" name="message" rows="2"
                                placeholder="<?= te('Etwas mitteilen oder nachfragen …') ?>" required></textarea>
                    </div>
                    <button type="submit" name="add_project_comment" class="btn btn-sm btn-primary fw-bold">
                      <i class="bi bi-send me-1"></i><?= te('Senden') ?>
                    </button>
                  </div>
                </form>
                <?php if(!$is_partner): ?>
                <!-- Allgemeines Feedback -->
                <div class="feedback-card">
                  <?php if(trim((string)($p['client_feedback'] ?? '')) !== '' && !empty($p['feedback_by_name'])): ?>
                    <div class="section-hint mb-2">
                      <?= te('Zuletzt geschrieben von') ?>
                      <strong><?= htmlspecialchars($p['feedback_by_name']) ?></strong><?php
                        if (!empty($p['feedback_at'])) echo ' am ' . date('d.m.Y H:i', strtotime($p['feedback_at']));
                      ?>.
                    </div>
                  <?php endif; ?>
                  <div class="section-label" style="color:var(--state-warn-fg);"><i class="bi bi-chat-left-dots-fill me-1"></i><?= te('Allgemeines Feedback') ?></div>
                  <p class="text-muted small mb-3"><?= te('Haben Sie Fragen oder Korrekturwünsche zum aktuellen Stand?') ?></p>
                  <form method="POST">
    <?= csrf_field() ?>
                    <input type="hidden" name="task_id" value="<?= $p['id'] ?>">
                    <textarea name="feedback" rows="3" class="form-control mb-3" style="font-size:13px;border-radius:10px;resize:none;border-color:var(--accent-warning);background:var(--surface-card);" placeholder="<?= te('Ihre Anmerkungen...') ?>"><?= htmlspecialchars($p['client_feedback'] ?? '') ?></textarea>
                    <button type="submit" name="send_feedback" class="btn btn-sm fw-bold w-100 text-white" style="background:var(--color-sidebar);border-radius:10px;"><i class="bi bi-send me-1"></i><?= te('Feedback speichern') ?></button>
                  </form>
                </div>
                <?php endif; ?>

              </div>
            </div>
          </div>
          </div><!-- /collapse -->
        </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div><!-- /projects -->

    <!-- ═══════════════════════════════════════ RECHNUNGEN ═══ -->
      <!-- ══════════ ANGEBOTE ══════════ -->
      <div class="tab-pane" id="tab-quotes">
        <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
          <div>
            <h4 class="fw-bold mb-1" style="font-family:'Poppins',sans-serif;"><?= te('Angebote') ?></h4>
            <p class="text-muted mb-0 small">
              <?= $is_partner
                    ? te('Angebote zur gemeinsamen Zusammenarbeit.')
                    : te('Hier können Sie ein Angebot direkt annehmen oder eine Rückfrage stellen.') ?>
            </p>
          </div>
          <?php if($open_quote_count > 0): ?>
            <span class="badge rounded-pill px-3 py-2" style="background:var(--state-warn-bg);color:var(--state-warn-fg);">
              <i class="bi bi-hourglass-split me-1"></i><?= $open_quote_count ?> <?= te('wartet auf Ihre Antwort') ?>
            </span>
          <?php endif; ?>
        </div>

        <?php if(empty($quotes)): ?>
          <div class="empty-state bg-surface rounded-4 border shadow-sm">
            <i class="bi bi-file-earmark-text"></i>
            <p><?= te('Aktuell liegt kein Angebot vor.') ?></p>
          </div>
        <?php else: ?>
          <?php foreach($quotes as $q):
            $offen  = $q['status'] === 'Gesendet';
            $ja     = $q['status'] === 'Angenommen';
            $nein   = $q['status'] === 'Abgelehnt';
            $frist  = $q['valid_until'] ? strtotime($q['valid_until']) : null;
            $abgelaufen = $frist !== null && $frist < strtotime('today');
          ?>
          <div class="project-card">
            <div class="project-card-header" style="cursor:default;">
              <div class="d-flex align-items-start justify-content-between gap-3 mb-2 flex-wrap">
                <div style="min-width:0;">
                  <h4 class="fw-bold mb-1" style="font-family:'Poppins',sans-serif;font-size:18px;color:var(--text-strong);">
                    <?= htmlspecialchars($q['subject'] ?: te('Angebot')) ?>
                  </h4>
                  <div class="text-muted small"><?= htmlspecialchars($q['quote_number']) ?>
                    · <?= date('d.m.Y', strtotime($q['created_at'])) ?></div>
                </div>
                <div class="text-end flex-shrink-0">
                  <div class="invoice-amount"><?= number_format((float)$q['total_amount'], 2, ',', '.') ?> €</div>
                  <span class="badge rounded-pill px-3 py-1 mt-1"
                        style="<?= $ja   ? 'background:var(--state-success-bg);color:var(--state-success-fg);'
                                 : ($nein ? 'background:var(--state-danger-bg);color:var(--state-danger-fg);'
                                          : 'background:var(--state-warn-bg);color:var(--state-warn-fg);') ?>">
                    <?= htmlspecialchars($q['status']) ?>
                  </span>
                </div>
              </div>
            </div>

            <div style="padding:18px 24px 22px;">
              <?php if(trim((string)$q['intro_text']) !== ''): ?>
                <p class="small mb-3" style="white-space:pre-wrap;"><?= htmlspecialchars($q['intro_text']) ?></p>
              <?php endif; ?>

              <?php if($frist !== null): ?>
                <div class="section-label">
                  <i class="bi bi-calendar-event me-1"></i>
                  <?= $abgelaufen ? te('Frist abgelaufen am') : te('Gültig bis') ?>
                  <?= date('d.m.Y', $frist) ?>
                </div>
              <?php endif; ?>

              <div class="d-flex flex-wrap gap-2 mt-3">
                <?php if(!empty($q['quote_pdf_path']) && file_exists($q['quote_pdf_path'])): ?>
                  <a href="file?type=quote&amp;id=<?= (int)$q['id'] ?>&amp;token=<?= urlencode($token) ?>" target="_blank" rel="noopener"
                     class="btn btn-sm btn-outline-secondary fw-bold">
                    <i class="bi bi-file-earmark-pdf me-1"></i><?= te('Angebot als PDF') ?>
                  </a>
                <?php endif; ?>

                <?php if($offen && !$abgelaufen): ?>
                  <form method="POST" class="d-inline m-0"
                        onsubmit="return confirm('Angebot <?= htmlspecialchars($q['quote_number'], ENT_QUOTES) ?> verbindlich annehmen?')">
                    <?= csrf_field() ?>
                    <input type="hidden" name="quote_id" value="<?= (int)$q['id'] ?>">
                    <button type="submit" name="accept_quote" class="btn-approve">
                      <i class="bi bi-check-circle me-1"></i><?= te('Angebot annehmen') ?>
                    </button>
                  </form>
                  <button type="button" class="btn btn-sm btn-outline-primary fw-bold"
                          onclick="document.getElementById('qq_<?= (int)$q['id'] ?>').classList.toggle('d-none')">
                    <i class="bi bi-chat-left-text me-1"></i><?= te('Rückfrage stellen') ?>
                  </button>
                <?php elseif($offen && $abgelaufen): ?>
                  <span class="text-muted small align-self-center">
                    <i class="bi bi-info-circle me-1"></i><?= te('Die Frist ist abgelaufen — melden Sie sich gern, wir machen Ihnen ein neues Angebot.') ?>
                  </span>
                <?php elseif($ja): ?>
                  <span class="small align-self-center" style="color:var(--state-success-fg);">
                    <i class="bi bi-patch-check-fill me-1"></i><?= te('Angenommen — vielen Dank!') ?>
                  </span>
                <?php endif; ?>
              </div>

              <?php if($offen && !$abgelaufen): ?>
                <form method="POST" class="d-none mt-3" id="qq_<?= (int)$q['id'] ?>">
                  <?= csrf_field() ?>
                  <input type="hidden" name="quote_id" value="<?= (int)$q['id'] ?>">
                  <label class="section-label" for="qm_<?= (int)$q['id'] ?>"><?= te('Ihre Rückfrage') ?></label>
                  <textarea class="form-control mb-2" id="qm_<?= (int)$q['id'] ?>" name="quote_message" rows="3"
                            placeholder="<?= te('Was möchten Sie wissen oder anders haben?') ?>" required></textarea>
                  <button type="submit" name="query_quote" class="btn btn-sm btn-primary fw-bold">
                    <i class="bi bi-send me-1"></i><?= te('Rückfrage senden') ?>
                  </button>
                  <div class="section-hint mt-2">
                    <?= te('Eine Rückfrage ändert am Angebot nichts — sie erreicht uns als Nachricht.') ?>
                  </div>
                </form>
              <?php endif; ?>
            </div>
          </div>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>

    <div class="tab-pane" id="tab-invoices">
      <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
        <div>
          <h4 class="fw-bold mb-1" style="font-family:'Poppins',sans-serif;"><?= $is_partner ? te('Abrechnungen') : te('Rechnungsarchiv') ?></h4>
          <p class="text-muted small m-0"><?= $is_partner ? te('Gemeinsame Abrechnungen auf einen Blick — als PDF herunterladbar.') : te('Alle Rechnungen auf einen Blick — als PDF herunterladbar.') ?></p>
        </div>
        <?php if($open_inv_count > 0): ?>
          <span class="badge bg-danger bg-opacity-10 text-danger border border-danger border-opacity-25 px-3 py-2 fs-6">
            <i class="bi bi-exclamation-circle me-1"></i><?= $open_inv_count ?> <?= te('offen / überfällig') ?>
          </span>
        <?php endif; ?>
      </div>

      <?php if(empty($invoices)): ?>
        <div class="empty-state bg-white rounded-4 border shadow-sm">
          <i class="bi bi-receipt"></i>
          <p><?= te('Noch keine Rechnungen vorhanden.') ?></p>
        </div>
      <?php else: ?>
        <div class="row g-3">
          <?php foreach($invoices as $inv):
            $is_paid     = $inv['status'] === 'Bezahlt';
            $is_overdue  = $inv['status'] === 'Überfällig';
            $badge_cls   = $is_paid ? 'bg-success' : ($is_overdue ? 'bg-danger' : 'bg-warning text-dark');
          ?>
          <div class="col-md-6 col-lg-4">
            <div class="invoice-card <?= $is_overdue ? 'invoice-overdue' : '' ?>">
              <div class="d-flex justify-content-between align-items-start mb-3">
                <div class="invoice-amount"><?= number_format($inv['amount'],2,',','.') ?> €</div>
                <span class="badge <?= $badge_cls ?> rounded-pill px-3 py-2"><?= $inv['status'] ?></span>
              </div>
              <div class="fw-semibold text-dark mb-1"><?= htmlspecialchars($inv['title']) ?></div>
              <div class="text-muted small mb-3">
                <?= date('d.m.Y', strtotime($inv['record_date'])) ?>
                <?php if($inv['due_date']): ?>
                  · <span class="<?= $is_overdue ? 'text-danger fw-bold' : '' ?>"><?= te('fällig') ?> <?= date('d.m.Y', strtotime($inv['due_date'])) ?></span>
                <?php endif; ?>
              </div>
              <?php if($has_bank && !$is_paid):
                // Verwendungszweck: die Rechnungsnummer, sonst der Titel.
                $vz = trim((string)($inv['invoice_number'] ?? '')) !== ''
                    ? $inv['invoice_number'] : $inv['title'];
              ?>
              <div class="pay-box" data-pay
                   data-holder="<?= htmlspecialchars($bank['holder'], ENT_QUOTES) ?>"
                   data-iban="<?= htmlspecialchars($bank['iban'], ENT_QUOTES) ?>"
                   data-bic="<?= htmlspecialchars($bank['bic'], ENT_QUOTES) ?>"
                   data-amount="<?= htmlspecialchars(number_format((float)$inv['amount'], 2, '.', ''), ENT_QUOTES) ?>"
                   data-ref="<?= htmlspecialchars($vz, ENT_QUOTES) ?>">
                <div class="section-label mb-2"><i class="bi bi-bank me-1"></i><?= te('Zahlung') ?></div>
                <div class="d-flex gap-3 flex-wrap align-items-start">
                  <div class="pay-qr" aria-hidden="true"></div>
                  <div class="pay-details">
                    <div><span class="pay-key"><?= te('Empfänger') ?></span><span class="pay-val"><?= htmlspecialchars($bank['holder']) ?></span></div>
                    <div><span class="pay-key">IBAN</span><span class="pay-val pay-mono"><?= htmlspecialchars(chunk_split($bank['iban'], 4, ' ')) ?></span></div>
                    <?php if($bank['bic'] !== ''): ?>
                      <div><span class="pay-key">BIC</span><span class="pay-val pay-mono"><?= htmlspecialchars($bank['bic']) ?></span></div>
                    <?php endif; ?>
                    <div><span class="pay-key"><?= te('Betrag') ?></span><span class="pay-val fw-bold"><?= number_format((float)$inv['amount'], 2, ',', '.') ?> €</span></div>
                    <div><span class="pay-key"><?= te('Verwendungszweck') ?></span><span class="pay-val pay-mono"><?= htmlspecialchars($vz) ?></span></div>
                  </div>
                </div>
                <div class="pay-hint">
                  Mit der Banking-App scannen — Empfänger, Betrag und Verwendungszweck
                  sind dann bereits ausgefüllt.
                  <?php if($bank['note'] !== ''): ?><br><?= htmlspecialchars($bank['note']) ?><?php endif; ?>
                </div>
              </div>
              <?php endif; ?>
              <?php if(!empty($inv['invoice_pdf_path'])): ?>
                <div class="d-flex gap-2">
                  <a href="file?type=invoice&amp;id=<?= (int)$inv['id'] ?>&amp;token=<?= urlencode($token) ?>" target="_blank" class="btn btn-sm btn-outline-primary fw-bold flex-grow-1" style="border-radius:8px;">
                    <i class="bi bi-eye me-1"></i><?= te('Ansehen') ?>
                  </a>
                  <a href="file?type=invoice&amp;id=<?= (int)$inv['id'] ?>&amp;token=<?= urlencode($token) ?>&amp;dl=1" download class="btn btn-sm btn-outline-secondary px-3" style="border-radius:8px;" title="<?= te('Herunterladen') ?>">
                    <i class="bi bi-download"></i>
                  </a>
                </div>
              <?php else: ?>
                <div class="text-muted small text-center fst-italic py-1"><?= te('PDF auf Anfrage') ?></div>
              <?php endif; ?>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div><!-- /invoices -->

    <!-- ═══════════════════════════════════════ SUPPORT ═══ -->
    <div class="tab-pane" id="tab-support">
      <div class="row g-4">
        <div class="col-lg-5">
          <div class="project-card p-4">
            <h5 class="fw-bold mb-1" style="font-family:'Poppins',sans-serif;"><?= $is_partner ? te('Neue Mitteilung') : te('Neue Anfrage') ?></h5>
            <p class="text-muted small mb-4"><?= $is_partner ? te('Fragen zur Zusammenarbeit oder sonstige Anliegen? Ich melde mich schnellstmöglich.') : te('Probleme mit der Website oder Änderungswünsche? Ich melde mich schnellstmöglich.') ?></p>
            <form method="POST">
    <?= csrf_field() ?>
              <div class="mb-3">
                <label class="form-label small fw-bold"><?= te('Betreff') ?></label>
                <input type="text" name="subject" class="form-control" required placeholder="<?= te('Worum geht es?') ?>" style="border-radius:10px;">
              </div>
              <div class="mb-4">
                <label class="form-label small fw-bold"><?= te('Beschreibung') ?></label>
                <textarea name="message" class="form-control" rows="5" required placeholder="<?= te('Beschreiben Sie Ihr Anliegen...') ?>" style="border-radius:10px;resize:none;"></textarea>
              </div>
              <button type="submit" name="create_ticket" class="btn btn-danger w-100 fw-bold py-2" style="border-radius:10px;">
                <i class="bi bi-send me-2"></i><?= $is_partner ? 'Absenden' : te('Ticket absenden') ?>
              </button>
            </form>
          </div>
        </div>

        <div class="col-lg-7">
          <h5 class="fw-bold mb-3" style="font-family:'Poppins',sans-serif;"><?= $is_partner ? te('Bisherige Mitteilungen') : te('Meine Anfragen') ?></h5>
          <?php if(empty($tickets)): ?>
            <div class="empty-state bg-white rounded-4 border shadow-sm">
              <i class="bi bi-inbox" style="font-size:36px;"></i>
              <p><?= te('Noch keine Anfragen gestellt.') ?></p>
            </div>
          <?php else: ?>
            <?php
            $_prio_map = ['Kritisch'=>'var(--accent-danger)','Hoch'=>'var(--accent-warning)','Mittel'=>'var(--color-primary)','Niedrig'=>'var(--text-faint)'];
            foreach($tickets as $t):
              $is_open   = $t['status'] === 'Offen';
              $is_active = $t['status'] === 'In Bearbeitung';
              $is_done   = $t['status'] === 'Erledigt';
              $t_badge   = $is_done ? 'bg-success' : ($is_active ? 'bg-primary' : 'bg-warning text-dark');
              $card_cls  = $is_done ? 'done' : ($is_active ? 'active' : 'open');
              $prio      = $t['priority'] ?? 'Mittel';
              $pc        = $_prio_map[$prio] ?? 'var(--text-faint)';
              $pub_notes = $public_notes_by_ticket[$t['id']] ?? [];
              $reply_cnt = (int)($t['reply_count'] ?? 0);
            ?>
            <div class="ticket-card <?= $card_cls ?>">

              <!-- Ticket-Kopfzeile (klickbar) -->
              <div class="ticket-header d-flex justify-content-between align-items-start gap-2 flex-wrap"
                   data-bs-toggle="collapse" data-bs-target="#tkb_<?= $t['id'] ?>" aria-expanded="false">
                <div style="min-width:0;">
                  <div class="fw-bold text-dark mb-1"><?= htmlspecialchars($t['subject']) ?></div>
                  <div class="d-flex align-items-center flex-wrap gap-2">
                    <span style="background:<?=$pc?>22;color:<?=$pc?>;font-size:10px;font-weight:700;padding:1px 7px;border-radius:4px;text-transform:uppercase;white-space:nowrap;"><?= htmlspecialchars($prio) ?></span>
                    <small class="text-muted"><i class="bi bi-clock me-1"></i><?= date('d.m.Y', strtotime($t['created_at'])) ?></small>
                    <?php if ($reply_cnt > 0): ?>
                      <small class="text-primary fw-semibold"><i class="bi bi-chat-dots-fill me-1"></i><?= $reply_cnt ?> Antwort<?= $reply_cnt > 1 ? 'en' : '' ?></small>
                    <?php endif; ?>
                  </div>
                </div>
                <div class="d-flex align-items-center gap-2 flex-shrink-0">
                  <span class="badge <?= $t_badge ?> rounded-pill px-3 py-1 small"><?= $t['status'] ?></span>
                  <i class="bi bi-chevron-down ticket-chevron"></i>
                </div>
              </div>

              <!-- Ticket-Inhalt (einklappbar) -->
              <div class="collapse" id="tkb_<?= $t['id'] ?>">
                <div class="ticket-body pt-3">

                  <!-- Originalnachricht -->
                  <div class="ms-comment-bubble self">
                    <div class="ms-comment-meta">
                      <i class="bi bi-person-fill me-1"></i><?= htmlspecialchars($client['name']) ?>
                      <span class="fw-normal ms-2 text-muted"><?= date('d.m.Y H:i', strtotime($t['created_at'])) ?></span>
                    </div>
                    <div class="ms-comment-text"><?= nl2br(htmlspecialchars($t['message'])) ?></div>
                  </div>

                  <!-- Öffentliche Antworten (Admin + Kunde) -->
                  <?php foreach($pub_notes as $n): $is_cn = $n['author'] === 'client'; ?>
                  <div class="ms-comment-bubble <?= $is_cn ? 'self' : '' ?> mt-2">
                    <div class="ms-comment-meta">
                      <?php if ($is_cn): ?>
                        <i class="bi bi-person-fill me-1"></i><?= htmlspecialchars($client['name']) ?>
                      <?php else: ?>
                        <i class="bi bi-headset me-1"></i><?= htmlspecialchars(setting('company_short', COMPANY_SHORT)) ?>
                      <?php endif; ?>
                      <span class="fw-normal ms-2 text-muted"><?= date('d.m.Y H:i', strtotime($n['created_at'])) ?></span>
                    </div>
                    <div class="ms-comment-text"><?= nl2br(htmlspecialchars($n['note'])) ?></div>
                  </div>
                  <?php endforeach; ?>

                  <!-- Antwortformular (nur wenn nicht Erledigt) -->
                  <?php if (!$is_done): ?>
                  <form method="POST" class="mt-3">
    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="add_ticket_reply">
                    <input type="hidden" name="ticket_id" value="<?= $t['id'] ?>">
                    <div class="d-flex gap-2">
                      <textarea name="reply" class="form-control form-control-sm" rows="2"
                                placeholder="<?= te('Antwort oder Rückfrage hinzufügen…') ?>"
                                style="resize:none;border-radius:10px;" required></textarea>
                      <button type="submit" class="btn btn-primary btn-sm px-3 fw-bold align-self-end" style="border-radius:10px;" title="<?= te('Absenden') ?>">
                        <i class="bi bi-send-fill"></i>
                      </button>
                    </div>
                  </form>
                  <?php endif; ?>

                  <!-- Aktions-Leiste -->
                  <div class="d-flex justify-content-between align-items-center mt-3 pt-3 border-top flex-wrap gap-2">

                    <!-- Priorität -->
                    <div class="d-flex align-items-center gap-2">
                      <span class="small text-muted fw-bold"><?= te('Priorität:') ?></span>
                      <select class="form-select form-select-sm" style="width:auto;border-radius:8px;"
                              id="prio_sel_<?= $t['id'] ?>"
                              onchange="updatePortalPrio(<?= $t['id'] ?>, this)">
                        <?php foreach (['Niedrig'=>'⚪','Mittel'=>'🟡','Hoch'=>'🟠','Kritisch'=>'🔴'] as $pv => $pe): ?>
                          <option value="<?= $pv ?>" <?= $prio === $pv ? 'selected' : '' ?>><?= $pe ?> <?= $pv ?></option>
                        <?php endforeach; ?>
                      </select>
                      <small id="prio_msg_<?= $t['id'] ?>" class="text-success" style="display:none;">✓</small>
                    </div>

                    <!-- Status + Löschen -->
                    <div class="d-flex gap-2">
                      <?php if (!$is_done): ?>
                      <form method="POST">
    <?= csrf_field() ?>
                        <input type="hidden" name="action" value="close_ticket">
                        <input type="hidden" name="ticket_id" value="<?= $t['id'] ?>">
                        <button type="submit" class="btn btn-sm btn-outline-success fw-bold" style="border-radius:8px;">
                          <i class="bi bi-check-circle me-1"></i><?= te('Erledigt') ?>
                        </button>
                      </form>
                      <?php endif; ?>
                      <form method="POST" onsubmit="return confirm('Ticket und alle Nachrichten endgültig löschen?')">
    <?= csrf_field() ?>
                        <input type="hidden" name="action" value="delete_portal_ticket">
                        <input type="hidden" name="ticket_id" value="<?= $t['id'] ?>">
                        <button type="submit" class="btn btn-sm btn-outline-danger fw-bold" style="border-radius:8px;">
                          <i class="bi bi-trash3 me-1"></i><?= te('Löschen') ?>
                        </button>
                      </form>
                    </div>

                  </div>

                </div>
              </div>

            </div>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>
      </div>
    </div><!-- /support -->

    <!-- ═══════════════════════════════════════ WIKI ═══ -->
    <?php if(!empty($wiki_articles)): ?>
    <div class="tab-pane" id="tab-wiki">
      <h4 class="fw-bold mb-1" style="font-family:'Poppins',sans-serif;"><?= te('Wissensdatenbank') ?></h4>
      <p class="text-muted small mb-4"><?= te('Artikel, die') ?> <?= setting('company_short', COMPANY_SHORT) ?> <?= te('für Sie freigegeben hat.') ?></p>

      <?php
      $wiki_grouped = [];
      foreach($wiki_articles as $wa) { $wiki_grouped[$wa['category'] ?: 'Allgemein'][] = $wa; }
      foreach($wiki_grouped as $cat => $items):
      ?>
        <div class="section-label mt-3"><?= htmlspecialchars($cat) ?></div>
        <div class="row g-3 mb-2">
          <?php foreach($items as $wa):
            $safe_art = htmlspecialchars(json_encode($wa, JSON_HEX_TAG|JSON_HEX_APOS), ENT_QUOTES, 'UTF-8');
            $att_count = count($wa['attachments'] ?? []);
          ?>
          <div class="col-md-6 col-lg-4">
            <button type="button" class="project-card w-100 text-start p-0 border-0 h-100" onclick='openPortalWikiModal(<?= $safe_art ?>)' style="cursor:pointer;">
              <div class="p-4">
                <div class="d-flex align-items-start gap-3">
                  <div style="width:40px;height:40px;border-radius:10px;background:var(--state-success-bg);display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                    <i class="bi bi-file-earmark-text text-success fs-5"></i>
                  </div>
                  <div style="min-width:0;flex:1;">
                    <div class="fw-semibold text-dark mb-1">
                      <?php if($wa['is_pinned']): ?><i class="bi bi-pin-angle-fill text-warning me-1"></i><?php endif; ?>
                      <?= htmlspecialchars($wa['title']) ?>
                    </div>
                    <div class="text-muted small"><?= htmlspecialchars(mb_strimwidth(strip_tags($wa['content']),0,80,'…')) ?></div>
                    <?php if($att_count > 0): ?>
                      <div class="mt-2 small text-muted"><i class="bi bi-paperclip me-1"></i><?= $att_count ?> Anhang<?= $att_count > 1 ? 'e' : '' ?></div>
                    <?php endif; ?>
                  </div>
                  <i class="bi bi-arrow-right-circle text-muted flex-shrink-0"></i>
                </div>
              </div>
            </button>
          </div>
          <?php endforeach; ?>
        </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?><!-- /wiki -->

    <!-- ═══════════════════════════════════════ PROFIL ═══ -->
    <div class="tab-pane" id="tab-profile">
      <div class="project-card p-4" style="max-width:760px;">
        <h4 class="fw-bold mb-1" style="font-family:'Poppins',sans-serif;"><?= te('Mein Profil') ?></h4>
        <p class="text-muted small mb-4"><?= te('Ihre bei uns hinterlegten Stammdaten — werden u.a. für die Rechnungsstellung verwendet.') ?></p>
        <form method="POST">
    <?= csrf_field() ?>
          <input type="hidden" name="update_profile" value="1">
          <h6 class="fw-bold text-dark mb-3 pb-2 border-bottom small text-uppercase" style="letter-spacing:.5px;"><?= te('Kontaktdaten') ?></h6>
          <div class="row g-3 mb-4">
            <div class="col-md-6"><label class="form-label small fw-bold"><?= te('Vor- & Nachname *') ?></label><input type="text" name="name" class="form-control" value="<?= htmlspecialchars($client['name']) ?>" required style="border-radius:10px;"></div>
            <div class="col-md-6"><label class="form-label small fw-bold"><?= te('Firmenname') ?></label><input type="text" name="company" class="form-control" value="<?= htmlspecialchars($client['company'] ?? '') ?>" style="border-radius:10px;"></div>
            <div class="col-md-4"><label class="form-label small fw-bold"><?= te('E-Mail *') ?></label><input type="email" name="email" class="form-control" value="<?= htmlspecialchars($client['email'] ?? '') ?>" required style="border-radius:10px;"></div>
            <div class="col-md-4"><label class="form-label small fw-bold"><?= te('Telefon') ?></label><input type="text" name="phone" class="form-control" value="<?= htmlspecialchars($client['phone'] ?? '') ?>" style="border-radius:10px;"></div>
            <div class="col-md-4"><label class="form-label small fw-bold"><?= te('Website') ?></label><input type="text" name="website" class="form-control" value="<?= htmlspecialchars($client['website'] ?? '') ?>" style="border-radius:10px;"></div>
          </div>
          <h6 class="fw-bold text-dark mb-3 pb-2 border-bottom small text-uppercase" style="letter-spacing:.5px;"><?= te('Rechnungsadresse') ?></h6>
          <div class="row g-3 mb-4">
            <div class="col-12"><label class="form-label small fw-bold"><?= te('Straße & Hausnummer') ?></label><input type="text" name="street" class="form-control" value="<?= htmlspecialchars($client['street'] ?? '') ?>" style="border-radius:10px;"></div>
            <div class="col-md-3"><label class="form-label small fw-bold"><?= te('PLZ') ?></label><input type="text" name="zip" class="form-control" value="<?= htmlspecialchars($client['zip'] ?? '') ?>" style="border-radius:10px;"></div>
            <div class="col-md-5"><label class="form-label small fw-bold"><?= te('Ort') ?></label><input type="text" name="city" class="form-control" value="<?= htmlspecialchars($client['city'] ?? '') ?>" style="border-radius:10px;"></div>
            <div class="col-md-4"><label class="form-label small fw-bold"><?= te('Land') ?></label><input type="text" name="country" class="form-control" value="<?= htmlspecialchars($client['country'] ?? te('Deutschland')) ?>" style="border-radius:10px;"></div>
          </div>
          <button type="submit" class="btn btn-lg fw-bold w-100 text-white" style="background:var(--color-primary);border-radius:12px;">
            <i class="bi bi-check-circle me-2"></i><?= te('Daten speichern') ?>
          </button>
        </form>
      </div>
    </div><!-- /profile -->

  </div><!-- /container -->
</div><!-- /portal-content -->

<!-- WIKI LESE-MODAL -->
<div class="modal fade" id="portalWikiModal" tabindex="-1">
  <div class="modal-dialog modal-xl modal-dialog-scrollable">
    <div class="modal-content border-0 shadow-lg">
      <div class="modal-header border-bottom pb-3 mt-2 mx-2 align-items-start">
        <div>
          <span class="badge bg-success text-uppercase" id="pw_category" style="letter-spacing:.5px;"></span>
          <span id="pw_tags" class="ms-2"></span>
          <h2 id="pw_title" class="fw-bold mt-2 mb-0" style="color:var(--text-strong);font-family:'Poppins',sans-serif;font-size:clamp(18px,3vw,26px);"></h2>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body pt-3 px-4 pb-5">
        <div id="pw_content" style="font-size:15px;color:var(--text-body);line-height:1.8;"></div>
        <div id="pw_attachments" class="mt-5 pt-4 border-top" style="display:none;">
          <h6 class="fw-bold mb-3 text-dark"><i class="bi bi-paperclip me-2"></i><?= te('Angehängte Dateien') ?></h6>
          <div id="pw_attachments_list" class="d-flex flex-wrap gap-2"></div>
        </div>
      </div>
    </div>
  </div>
</div>

<footer class="text-center py-5 text-muted small">
  <p class="mb-0">&copy; <?= date('Y') ?> <?= setting('company_name', COMPANY_NAME) ?> &bull; <a href="<?= setting('main_website', MAIN_WEBSITE) ?>" class="text-decoration-none text-muted fw-bold"><?= str_replace(['http://','https://','www.'],'',setting('main_website', MAIN_WEBSITE)) ?></a></p>
</footer>

<script src="<?= asset('assets/vendor/bootstrap/bootstrap.bundle.min.js') ?>"></script>
<script src="<?= asset('assets/vendor/qrcode/qrcode.min.js') ?>"
        integrity="sha384-3zSEDfvllQohrq0PHL1fOXJuC/jSOO34H46t6UQfobFOmxE5BpjjaIJY5F2/bMnU"
        crossorigin="anonymous" referrerpolicy="no-referrer"></script>
<script src="<?= asset('assets/js/payment-qr.js') ?>" defer></script>
<script src="<?= asset('assets/vendor/prism/prism.min.js') ?>"></script>
<!-- markup-templating MUSS vor prism-php stehen: die PHP-Definition baut
     darauf auf und wirft sonst "buildPlaceholders of undefined". Die
     Abhaengigkeit fehlte von Anfang an; der Fehler faellt nicht auf, weil
     er erst beim Anzeigen eines PHP-Blocks auftritt und dort nur still
     nicht einfaerbt. -->
<script src="<?= asset('assets/vendor/prism/components/prism-markup-templating.min.js') ?>"></script>
<script src="<?= asset('assets/vendor/prism/components/prism-php.min.js') ?>"></script>
<script src="<?= asset('assets/vendor/prism/components/prism-javascript.min.js') ?>"></script>
<script src="<?= asset('assets/vendor/prism/components/prism-css.min.js') ?>"></script>
<script>
/* Umschalter für das dunkle Design.
   Ohne gespeicherte Wahl folgt das Portal der Systemeinstellung des
   Geräts (siehe includes/theme.php). Ein Klick hier legt die Wahl fest
   und hält sie im Browser des Kunden - der Wert liegt unter demselben
   Schlüssel wie im Admin-Panel, gilt aber je Gerät. */
(function () {
    var btn = document.getElementById("portalThemeToggle");
    if (!btn) return;
    var icon = btn.querySelector("i");

    function paint() {
        var dark = document.documentElement.getAttribute("data-theme") === "dark";
        icon.className = dark ? "bi bi-sun" : "bi bi-moon-stars";
        btn.setAttribute("aria-pressed", dark ? "true" : "false");
        btn.title = dark ? <?= tjs('Zum hellen Design wechseln') ?> : <?= tjs('Zum dunklen Design wechseln') ?>;
    }

    paint();
    btn.addEventListener("click", function () {
        var dark = document.documentElement.getAttribute("data-theme") !== "dark";
        document.documentElement.setAttribute("data-theme", dark ? "dark" : "light");
        try { window.ansichtSpeicher.setItem("darkMode", dark ? "1" : "0"); } catch (e) {}
        paint();
    });
})();

// Wird an jede AJAX-Anfrage angehaengt - die Formulare tragen es als
// verstecktes Feld, hier muss es von Hand mit.
const PORTAL_CSRF  = '<?= htmlspecialchars(csrf_token(), ENT_QUOTES) ?>';
const PORTAL_TOKEN = '<?= htmlspecialchars($token, ENT_QUOTES) ?>';

// ── TAB-NAVIGATION ──
document.querySelectorAll('.portal-pill').forEach(btn => {
    btn.addEventListener('click', function() {
        document.querySelectorAll('.portal-pill').forEach(b => b.classList.remove('active'));
        document.querySelectorAll('.tab-pane').forEach(p => p.classList.remove('active'));
        this.classList.add('active');
        const target = document.getElementById('tab-' + this.dataset.tab);
        if (target) target.classList.add('active');
        // Hash für Bookmark-Fähigkeit
        history.replaceState(null, '', '#' + this.dataset.tab);
    });
});
// Beim Laden ggf. Hash auswerten
(function() {
    const hash = location.hash.replace('#','');
    if (hash) {
        const btn = document.querySelector('.portal-pill[data-tab="'+hash+'"]');
        if (btn) btn.click();
    }
})();

// ── TOAST ──
document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('.toast').forEach(el => new bootstrap.Toast(el, {delay:4000}).show());
    if (window.history.replaceState) {
        const url = new URL(window.location);
        if (url.searchParams.has('msg')) { url.searchParams.delete('msg'); window.history.replaceState({}, '', url); }
    }
    // Drag & Drop für Upload-Zones
    document.querySelectorAll('.upload-zone').forEach(zone => {
        zone.addEventListener('dragover',  e => { e.preventDefault(); zone.classList.add('dragover'); });
        zone.addEventListener('dragleave', e => { e.preventDefault(); zone.classList.remove('dragover'); });
        zone.addEventListener('drop', e => {
            e.preventDefault(); zone.classList.remove('dragover');
            const tid = zone.dataset.taskId;
            const fi  = document.getElementById('file_'+tid);
            if (e.dataTransfer.files.length) { fi.files = e.dataTransfer.files; autoUpload(fi, tid); }
        });
    });
    // Projekt-Suchfeld
    const si = document.getElementById('projectSearch');
    if (si) si.addEventListener('keyup', function() {
        const f = this.value.toLowerCase();
        document.querySelectorAll('.project-card-item').forEach(c => {
            c.style.display = c.dataset.title.includes(f) ? '' : 'none';
        });
    });
});

// ── KOMMENTAR-TOGGLE ──
function toggleCommentForm(btn, msId) {
    const form = document.getElementById('cf_' + msId);
    form.classList.toggle('open');
    if (form.classList.contains('open')) document.getElementById('cft_' + msId).focus();
}

// ── KOMMENTAR SENDEN (AJAX) ──
function submitComment(msId) {
    const ta  = document.getElementById('cft_' + msId);
    const msg = ta.value.trim();
    if (!msg) return;

    const fd = new FormData();
    fd.append('csrf_token', PORTAL_CSRF);
    fd.append('action', 'add_ms_comment');
    fd.append('milestone_id', msId);
    fd.append('message', msg);

    const btn = ta.nextElementSibling?.querySelector('button');
    if (btn) { btn.disabled = true; btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span>'; }

    fetch('portal?token=' + PORTAL_TOKEN, { method: 'POST', body: fd,
          headers: { 'X-Requested-With': 'XMLHttpRequest' } })
        .then(r => r.json())
        .then(data => {
            // Die Demo antwortet mit 403 und demo:true - ohne diesen
            // Zweig bliebe der Knopf stumm im Ladezustand stehen.
            if (data && data.demo) {
                if (window.demoHinweis) demoHinweis(data.error);
                if (btn) { btn.disabled = false; btn.innerHTML = '<i class="bi bi-send"></i>'; }
                return;
            }
            if (!data.success) return;

            const bubble = document.createElement('div');
            bubble.className = 'ms-comment-bubble self';
            bubble.innerHTML = `<div class="ms-comment-meta"><i class="bi bi-person-fill me-1"></i>Sie <span class="fw-normal ms-2 text-muted">${data.time}</span></div><div class="ms-comment-text">${data.message.replace(/\n/g,'<br>')}</div>`;

            let thread = document.getElementById('cf_' + msId).closest('.tl-body').querySelector('.ms-comments');
            if (!thread) {
                thread = document.createElement('div');
                thread.className = 'ms-comments mt-2';
                document.getElementById('cf_' + msId).before(thread);
            }
            thread.appendChild(bubble);

            ta.value = '';
            document.getElementById('cf_' + msId).classList.remove('open');
            if (btn) { btn.disabled = false; btn.innerHTML = '<i class="bi bi-send-fill"></i>'; }
        })
        .catch(() => { if (btn) { btn.disabled = false; btn.innerHTML = '<i class="bi bi-send-fill"></i>'; } });
}

// ── DATEI LÖSCHEN ──
function confirmDeleteAsset(btn, id) {
    if (btn.dataset.confirmed === '1') {
        btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span>';
        document.getElementById('del_asset_form_' + id).submit();
    } else {
        btn.dataset.confirmed = '1';
        btn.style.color = 'var(--accent-danger)';
        btn.innerHTML = '<i class="bi bi-trash3-fill"></i>';
        setTimeout(() => {
            if (document.getElementById('asset_row_' + id)) {
                btn.dataset.confirmed = '0';
                btn.innerHTML = '<i class="bi bi-trash3"></i>';
            }
        }, 3000);
    }
}

// ── DATEI-UPLOAD ──
function autoUpload(input, taskId) {
    const files = input.files;
    if (!files.length) return;
    const MAX = 100 * 1024 * 1024;
    const forbidden = ['php','phtml','exe','sh','js','html','htm'];
    const fd = new FormData();
    fd.append('csrf_token', PORTAL_CSRF);
    fd.append('task_id', taskId);
    for (let f of files) {
        const ext = f.name.split('.').pop().toLowerCase();
        if (forbidden.includes(ext)) { alert(<?= tjs('Dateityp .') ?> + ext + ' ist nicht erlaubt.'); input.value=''; return; }
        if (f.size > MAX) { alert(f.name + <?= tjs(' ist zu groß (max. 100 MB).') ?>); input.value=''; return; }
        fd.append('asset_files[]', f);
    }
    document.getElementById('box_' + taskId).style.display = 'block';
    const xhr = new XMLHttpRequest();
    xhr.open('POST', 'portal?token=' + PORTAL_TOKEN, true);
    xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
    xhr.upload.onprogress = e => { if (e.lengthComputable) document.getElementById('bar_'+taskId).style.width = Math.round(e.loaded/e.total*100)+'%'; };
    xhr.onload = () => {
        if (xhr.status === 403) {
            // In der Demo wird nichts gespeichert; die Fortschritts-
            // anzeige bliebe sonst dauerhaft stehen.
            if (window.demoHinweis) demoHinweis();
            document.getElementById('box_' + taskId).style.display = 'none';
            return;
        }
        const r = xhr.responseText.trim();
        if (r.startsWith('ERR_SIZE'))  { alert(<?= tjs('Datei zu groß (max. 100 MB).') ?>); document.getElementById('box_'+taskId).style.display='none'; }
        else if (r.startsWith('ERR_FORBIDDEN')) { alert(<?= tjs('Für dieses Projekt haben Sie keine Berechtigung.') ?>); document.getElementById('box_'+taskId).style.display='none'; }
        else if (r.startsWith('ERR_TYPE')) { alert(<?= tjs('Dieser Dateityp ist gesperrt.') ?>); document.getElementById('box_'+taskId).style.display='none'; }
        else if (xhr.status === 200)  { window.location.href = 'portal?token='+PORTAL_TOKEN+'&msg=uploaded'; }
    };
    xhr.send(fd);
}

// ── TICKET: PRIORITÄT PER AJAX ÄNDERN ──
function updatePortalPrio(ticketId, sel) {
    const prio = sel.value;
    const msg  = document.getElementById('prio_msg_' + ticketId);
    const fd   = new FormData();
    fd.append('csrf_token', PORTAL_CSRF);
    fd.append('action',    'update_ticket_priority');
    fd.append('ticket_id', ticketId);
    fd.append('priority',  prio);
    fetch('portal?token=' + PORTAL_TOKEN, { method: 'POST', body: fd,
          headers: { 'X-Requested-With': 'XMLHttpRequest' } })
        .then(r => r.json())
        .then(resp => {
            if (resp && resp.demo) {
                if (window.demoHinweis) demoHinweis(resp.error);
                return;
            }
            if (resp.ok && msg) {
                msg.style.display = 'inline';
                setTimeout(() => { msg.style.display = 'none'; }, 2000);
                // Prioritäts-Badge in der Kopfzeile aktualisieren
                const map  = { Kritisch: 'var(--accent-danger)', Hoch: 'var(--accent-warning)', Mittel: 'var(--color-primary)', Niedrig: 'var(--text-faint)' };
                const pc   = map[prio] || 'var(--text-faint)';
                const hdr  = sel.closest('.ticket-card')?.querySelector('.ticket-header [style*="border-radius:4px"]');
                if (hdr) {
                    hdr.style.background = pc + '22';
                    hdr.style.color      = pc;
                    hdr.textContent      = prio;
                }
            }
        })
        .catch(() => {});
}

// ── TICKET: AKKORDEON NACH REDIRECT WIEDER ÖFFNEN ──
(function () {
    const params   = new URLSearchParams(location.search);
    const openId   = params.get('open_ticket');
    if (!openId) return;
    const collapse = document.getElementById('tkb_' + openId);
    if (collapse) new bootstrap.Collapse(collapse, { toggle: false }).show();
    // URL bereinigen
    params.delete('open_ticket');
    const newUrl = location.pathname + '?' + params.toString() + location.hash;
    history.replaceState(null, '', newUrl);
})();

// ── WIKI MODAL ──
function openPortalWikiModal(art) {
    document.getElementById('pw_category').textContent = art.category || '';
    document.getElementById('pw_title').textContent    = art.title    || '';
    document.getElementById('pw_content').innerHTML    = art.content  || '';
    let tagsHtml = '';
    if (art.tags && art.tags.trim()) {
        art.tags.split(',').forEach(t => { tagsHtml += `<span class="badge bg-light text-muted border me-1 fw-normal">${t.trim()}</span>`; });
    }
    document.getElementById('pw_tags').innerHTML = tagsHtml;
    const attCont = document.getElementById('pw_attachments');
    const attList = document.getElementById('pw_attachments_list');
    if (art.attachments && art.attachments.length) {
        let html = '';
        art.attachments.forEach(a => {
            const ext = a.file_name.split('.').pop().toLowerCase();
            const viewable = ['pdf','jpg','jpeg','png','gif','webp','svg'].includes(ext);
            const icon = ext==='pdf' ? 'bi-file-earmark-pdf text-danger' : ['jpg','jpeg','png','gif','webp','svg'].includes(ext) ? 'bi-file-earmark-image text-success' : 'bi-file-earmark text-secondary';
            html += `<div class="btn-group shadow-sm me-2 mb-2">`;
            const wUrl = `file?type=wiki&id=${a.id}&token=${PORTAL_TOKEN}`;
            if (viewable) html += `<a href="${wUrl}" target="_blank" class="btn btn-sm btn-outline-secondary" title="Ansehen"><i class="bi bi-eye"></i></a>`;
            html += `<a href="${wUrl}&dl=1" download class="btn btn-sm btn-outline-primary fw-semibold"><i class="bi ${icon} me-1"></i>${a.file_name}</a></div>`;
        });
        attList.innerHTML = html;
        attCont.style.display = 'block';
    } else { attCont.style.display = 'none'; }
    new bootstrap.Modal(document.getElementById('portalWikiModal')).show();
    setTimeout(() => Prism.highlightAllUnder(document.getElementById('pw_content')), 150);
}
</script>
</body>
</html>
