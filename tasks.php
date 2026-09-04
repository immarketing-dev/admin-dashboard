<?php
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require_once __DIR__ . '/vendor/autoload.php';

require_once 'config.php';
require_once 'includes/mail_templates.php';
require_once 'includes/auth.php';
require_once 'includes/upload_helper.php';

// HILFSFUNKTION: UPTIME CHECK
function checkUptime($url) {
    if (!$url) return false;
    $url = (preg_match("~^(?:f|ht)tps?://~i", $url)) ? $url : "https://" . $url;
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_NOBODY, true);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AdminMonitor/1.0');
    curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ($code >= 200 && $code < 400);
}

// ==========================================
// AKTIONEN (TIMER, CRUD & AJAX UPLOADS)
// ==========================================
if (isset($_POST['ajax_action'])) {
    csrf_check();
    $task_id = $_POST['task_id'];
    
    if ($_POST['ajax_action'] === 'start_timer') {
        $pdo->prepare("UPDATE tasks SET is_timer_running = 1, timer_start = NOW() WHERE id = ?")->execute([$task_id]);
        echo "STARTED"; exit();
    }
    
    if ($_POST['ajax_action'] === 'stop_timer') {
        $stmt = $pdo->prepare("SELECT timer_start FROM tasks WHERE deleted_at IS NULL AND id = ?"); $stmt->execute([$task_id]);
        $start = $stmt->fetchColumn();
        if ($start) {
            $minutes = round(abs(time() - strtotime($start)) / 60);
            if ($minutes > 0) {
                $pdo->prepare("INSERT INTO time_entries (task_id, duration_minutes, note) VALUES (?, ?, 'Timer')")->execute([$task_id, $minutes]);
            }
            $pdo->prepare("UPDATE tasks SET is_timer_running = 0, timer_start = NULL WHERE id = ?")->execute([$task_id]);
        }
        echo "STOPPED"; exit();
    }
    
    // AJAX Upload direkt aus dem Modal (Gibt HTML für die Liste zurück!)
    if ($_POST['ajax_action'] === 'admin_upload_asset') {
        $html_response = "";
        if (!empty($_FILES['admin_assets']['name'][0])) {
            $upload_dir = 'uploads/client_assets/';
            if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);

            foreach ($_FILES['admin_assets']['tmp_name'] as $key => $tmp_name) {
                $file_name = $_FILES['admin_assets']['name'][$key];
                $file_size = $_FILES['admin_assets']['size'][$key];
                if (!$tmp_name) continue;

                $err = validate_upload($tmp_name, $file_name, $file_size);
                if ($err) {
                    $html_response .= '<div class="text-danger small mb-1"><i class="bi bi-x-circle"></i> ' . $err . '</div>';
                    continue;
                }

                $safe_name = safe_filename($file_name);
                $path      = $upload_dir . $safe_name;

                if (move_uploaded_file($tmp_name, $path)) {
                    $pdo->prepare("INSERT INTO client_assets (task_id, file_name, file_path, dashboard_seen, uploaded_by) VALUES (?, ?, ?, 1, 'admin')")
                        ->execute([$task_id, $file_name, $path]);
                    $asset_id = $pdo->lastInsertId();

                    $badge          = '<span class="badge bg-primary me-2" style="font-size:9px; padding:3px 5px;">Admin</span>';
                    $html_response .= '<div class="d-flex justify-content-between align-items-center mb-1 bg-surface p-2 rounded border small shadow-sm"><span class="text-truncate d-flex align-items-center" style="max-width: 70%;">' . $badge . ' ' . htmlspecialchars($file_name) . '</span><div class="d-flex gap-2"><a href="' . $path . '" download><i class="bi bi-download"></i></a><button type="button" class="btn btn-link text-danger p-0 shadow-none" onclick="openDeleteAssetModal(' . $asset_id . ')"><i class="bi bi-trash"></i></button></div></div>';
                }
            }
            $pdo->prepare("INSERT INTO logs (action_type, description) VALUES ('ASSET_ADDED', ?)")->execute(["Admin hat neue Dateien zu Projekt #$task_id hochgeladen."]);
        }
        echo $html_response; exit();
    }

    if ($_POST['ajax_action'] === 'add_admin_ms_comment') {
        header('Content-Type: application/json');
        $ms_id  = (int)($_POST['milestone_id'] ?? 0);
        $msg    = trim($_POST['message'] ?? '');
        if (!$ms_id || $msg === '') { echo json_encode(['ok'=>false]); exit(); }
        $_cs = setting('company_short', COMPANY_SHORT);
        $pdo->prepare("INSERT INTO milestone_comments (milestone_id, author, author_name, message, admin_seen) VALUES (?, 'admin', ?, ?, 1)")
            ->execute([$ms_id, $_cs, $msg]);
        $new_id = (int)$pdo->lastInsertId();
        echo json_encode(['ok'=>true, 'id'=>$new_id, 'author_name'=>$_cs, 'message'=>$msg, 'created_at'=>date('d.m.Y H:i')]);
        exit();
    }
}

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['action'])) {
    csrf_check();
    $action = $_POST['action'];
    
    // Auf wen wartet dieser Schritt? Leer heisst: nicht festgelegt.
    if ($action === 'set_waiting_on') {
        $ms_id = (int)($_POST['milestone_id'] ?? 0);
        $wert  = $_POST['waiting_on'] ?? '';
        if (!in_array($wert, ['', 'us', 'them'], true)) $wert = '';
        $pdo->prepare("UPDATE task_milestones SET waiting_on = ? WHERE id = ?")->execute([$wert, $ms_id]);
        header("Location: tasks?q=" . urlencode($_POST['back_q'] ?? '')); exit();
    }

    // Antwort in der Projekt-Diskussion. author_contact_id bleibt NULL -
    // daran erkennt das Portal, dass der Beitrag von uns kommt.
    if ($action === 'add_project_reply') {
        $t_id = (int)($_POST['task_id'] ?? 0);
        $msg  = trim($_POST['message'] ?? '');
        if ($t_id > 0 && $msg !== '') {
            $pdo->prepare("INSERT INTO project_comments (task_id, author_contact_id, author_name, message, admin_seen)
                           VALUES (?, NULL, ?, ?, 1)")
                ->execute([$t_id, setting('company_short', COMPANY_SHORT), $msg]);
        }
        header("Location: tasks?q=" . urlencode($_POST['back_q'] ?? '')); exit();
    }

    // Beim Aufruf der Seite gelten die Beitraege als gesehen.
    // ── Beteiligte am Projekt ───────────────────────────────────────
    // Seit Migration 5 kann ein Projekt mehrere Kontakte haben. Jeder
    // Beteiligte sieht es in seinem eigenen Portal - mit eigenem Link
    // und eigener PIN.
    if ($action === 'add_task_contact') {
        $t_id = (int)($_POST['task_id'] ?? 0);
        $c_id = (int)($_POST['contact_id'] ?? 0);
        if ($t_id > 0 && $c_id > 0) {
            $pdo->prepare("INSERT IGNORE INTO task_contacts (task_id, contact_id, role) VALUES (?, ?, 'member')")
                ->execute([$t_id, $c_id]);
            $n = $pdo->prepare("SELECT name FROM contacts WHERE deleted_at IS NULL AND id = ?");
            $n->execute([$c_id]);
            $pdo->prepare("INSERT INTO logs (action_type, description) VALUES ('TASK_CONTACT_ADDED', ?)")
                ->execute([($n->fetchColumn() ?: "Kontakt $c_id") . " zu Projekt $t_id hinzugefügt."]);
        }
        header("Location: tasks?q=" . urlencode($_POST['back_q'] ?? '')); exit();
    }

    if ($action === 'remove_task_contact') {
        $t_id = (int)($_POST['task_id'] ?? 0);
        $c_id = (int)($_POST['contact_id'] ?? 0);
        // Der Hauptansprechpartner bleibt: an ihm haengen Rechnungen und
        // Auswertungen. Wer ihn wechseln will, aendert das Projekt selbst.
        $ist_haupt = $pdo->prepare("SELECT 1 FROM tasks WHERE deleted_at IS NULL AND id = ? AND contact_id = ?");
        $ist_haupt->execute([$t_id, $c_id]);
        if (!$ist_haupt->fetchColumn()) {
            $pdo->prepare("DELETE FROM task_contacts WHERE task_id = ? AND contact_id = ?")
                ->execute([$t_id, $c_id]);
            $pdo->prepare("INSERT INTO logs (action_type, description) VALUES ('TASK_CONTACT_REMOVED', ?)")
                ->execute(["Kontakt $c_id von Projekt $t_id entfernt."]);
        }
        header("Location: tasks?q=" . urlencode($_POST['back_q'] ?? '')); exit();
    }

    if ($action === 'add_manual_time') {
        $mins = (int)$_POST['minutes'];
        $t_id = $_POST['task_id'];
        if($mins > 0 && !empty($t_id)) {
            $pdo->prepare("INSERT INTO time_entries (task_id, duration_minutes, note) VALUES (?, ?, 'Manuell nachgetragen')")->execute([$t_id, $mins]);
        }
    }
    elseif ($action === 'edit_task') {
        $title = trim($_POST['title']);
        $task_id = $_POST['task_id'];
        
        $pdo->prepare("UPDATE tasks SET title=?, category=?, description=?, contact_id=?, start_date=?, deadline=? WHERE id=?")
            ->execute([$title, trim($_POST['category']), trim($_POST['description']), $_POST['contact_id'] ?: null, $_POST['start_date'] ?: null, $_POST['deadline'] ?: null, $task_id]);
            
        // Fallback Upload (Falls jemand ohne JS hochlädt)
        if (!empty($_FILES['admin_assets']['name'][0])) {
            $upload_dir = 'uploads/client_assets/';
            if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);

            foreach ($_FILES['admin_assets']['tmp_name'] as $key => $tmp_name) {
                $file_name = basename($_FILES['admin_assets']['name'][$key]);
                $file_size = $_FILES['admin_assets']['size'][$key];
                if ($tmp_name) {
                    // Auch dieser Fallback-Pfad (ohne JS) muss durch validate_upload()
                    // laufen - sonst waere er ein ungeprueftes Upload-Schlupfloch
                    // (z.B. fuer SVG mit eingebettetem Script) am MIME-/Endungs-Check
                    // vorbei, den der AJAX-Upload weiter oben in dieser Datei nutzt.
                    $err = validate_upload($tmp_name, $file_name, $file_size);
                    if ($err) continue;

                    $safe_name = safe_filename($file_name);
                    $path = $upload_dir . $safe_name;

                    if (move_uploaded_file($tmp_name, $path)) {
                        $pdo->prepare("INSERT INTO client_assets (task_id, file_name, file_path, dashboard_seen, uploaded_by) VALUES (?, ?, ?, 1, 'admin')")
                            ->execute([$task_id, $file_name, $path]);
                    }
                }
            }
        }
        
        $pdo->prepare("INSERT INTO logs (action_type, description) VALUES ('TASK_EDITED', ?)")->execute(["Projekt '". $title ."' wurde aktualisiert."]);
    }
    elseif ($action === 'add_task') {
        $title = trim($_POST['title']);
        $pdo->prepare("INSERT INTO tasks (title, category, description, status, contact_id, start_date, deadline) VALUES (?, ?, ?, 'In Bearbeitung', ?, ?, ?)")
            ->execute([$title, trim($_POST['category']), trim($_POST['description']), $_POST['contact_id'] ?: null, $_POST['start_date'] ?: null, $_POST['deadline'] ?: null]);
        $pdo->prepare("INSERT INTO logs (action_type, description) VALUES ('TASK_ADDED', ?)")->execute(["Neues Projekt '". $title ."' wurde angelegt."]);
    }
    elseif ($action === 'delete_task') { 
        $stmt = $pdo->prepare("SELECT title FROM tasks WHERE deleted_at IS NULL AND id = ?");
        $stmt->execute([$_POST['task_id']]);
        $del_title = $stmt->fetchColumn();

        // Papierkorb statt Sofortloeschung: der Datensatz verschwindet aus
        // allen Ansichten, bleibt aber 30 Tage wiederherstellbar.
        $pdo->prepare("UPDATE tasks SET deleted_at = NOW() WHERE id = ? AND deleted_at IS NULL")->execute([(int)$_POST['task_id']]); 
        if($del_title) {
            $pdo->prepare("INSERT INTO logs (action_type, description) VALUES ('TASK_DELETED', ?)")->execute(["Projekt '". $del_title ."' wurde gelöscht."]);
        }
    }
    elseif ($action === 'delete_asset') {
        $stmt = $pdo->prepare("SELECT file_name, file_path FROM client_assets WHERE id = ?"); $stmt->execute([$_POST['asset_id']]);
        $file = $stmt->fetch(); 
        if($file) { 
            @unlink($file['file_path']); 
            $pdo->prepare("DELETE FROM client_assets WHERE id = ?")->execute([$_POST['asset_id']]); 
            $pdo->prepare("INSERT INTO logs (action_type, description) VALUES ('ASSET_DELETED', ?)")->execute(["Datei " . $file['file_name'] . " wurde gelöscht."]);
        }
    }
    elseif ($action === 'add_milestone') {
        $pdo->prepare("INSERT INTO task_milestones (task_id, title) VALUES (?, ?)")->execute([$_POST['task_id'], trim($_POST['milestone_title'])]);
        $pdo->prepare("INSERT INTO logs (action_type, description) VALUES ('MILESTONE_ADDED',?)")->execute(["Meilenstein '".trim($_POST['milestone_title'])."' zu Projekt #".(int)$_POST['task_id']." hinzugefügt."]);
    }
    elseif ($action === 'toggle_milestone') {
        $ms_id = (int)$_POST['milestone_id'];

        // Aktuellen Zustand lesen, bevor wir toggeln
        $ms_row = $pdo->prepare("SELECT m.is_completed, m.title, m.task_id, t.title AS task_title, c.email AS c_email, c.name AS c_name, c.portal_token
            FROM task_milestones m
            JOIN tasks t ON m.task_id = t.id
            LEFT JOIN contacts c ON t.contact_id = c.id
            WHERE m.id = ?");
        $ms_row->execute([$ms_id]);
        $ms = $ms_row->fetch(PDO::FETCH_ASSOC);

        $pdo->prepare("UPDATE task_milestones SET is_completed = NOT is_completed WHERE id = ?")->execute([$ms_id]);
        if ($ms) {
            $action_type = (int)$ms['is_completed'] === 0 ? 'MILESTONE_COMPLETED' : 'MILESTONE_REOPENED';
            $pdo->prepare("INSERT INTO logs (action_type, description) VALUES (?,?)")->execute([$action_type, "Meilenstein '{$ms['title']}' in Projekt '{$ms['task_title']}' ".((int)$ms['is_completed']===0?'abgeschlossen':'wieder geöffnet')."."]);
        }

        // Nur bei Abschluss (0→1), wenn Nutzer Ja gewählt hat und Kontakt eine E-Mail hat
        if ($ms && (int)$ms['is_completed'] === 0 && ($_POST['notify_client'] ?? '0') === '1' && !empty($ms['c_email']) && class_exists('PHPMailer\PHPMailer\PHPMailer')) {
            try {
                $mail = new PHPMailer(true);
                $mail->isSMTP();
                $mail->Host       = SMTP_HOST;
                $mail->SMTPAuth   = true;
                $mail->Username   = SMTP_USER;
                $mail->Password   = SMTP_PASS;
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
                $mail->Port       = SMTP_PORT;
                $mail->CharSet    = 'UTF-8';
                $mail->setFrom(setting('admin_email', ADMIN_EMAIL), setting('company_name', COMPANY_NAME));
                $mail->addAddress($ms['c_email'], $ms['c_name']);
                $mail->addReplyTo(setting('support_email', SUPPORT_EMAIL), setting('company_short', COMPANY_SHORT));
                $mail->isHTML(true);
                // Wortlaut aus der Vorlage (Einstellungen > E-Mail-Vorlagen).
                $_portal_url = $ms['portal_token']
                    ? rtrim(setting('main_website', MAIN_WEBSITE), '/') . '/portal?token=' . urlencode($ms['portal_token'])
                    : '';
                $_m = mail_render('milestone', [
                    'kunde'       => $ms['c_name'],
                    'projekt'     => $ms['task_title'],
                    'meilenstein' => $ms['title'],
                    'firma'       => setting('company_short', COMPANY_SHORT),
                ], $_portal_url);
                $mail->Subject = $_m['subject'];
                $mail->Body    = $_m['html'];
                $mail->AltBody = $_m['text'];
                $mail->send();
                $pdo->prepare("INSERT INTO logs (action_type, description) VALUES ('MILESTONE_MAIL',?)")
                    ->execute(["Meilenstein-E-Mail an {$ms['c_name']} gesendet: {$ms['title']}"]);
            } catch (Exception $e) {
                $pdo->prepare("INSERT INTO logs (action_type, description) VALUES ('MAIL_ERROR',?)")
                    ->execute(["SMTP-Fehler bei Meilenstein-Mail an {$ms['c_name']}: " . $mail->ErrorInfo]);
            }
        }
    }
    elseif ($action === 'delete_milestone') {
        $ms_del = $pdo->prepare("SELECT title FROM task_milestones WHERE id=?");
        $ms_del->execute([(int)$_POST['milestone_id']]);
        $ms_del_title = $ms_del->fetchColumn();
        $pdo->prepare("DELETE FROM task_milestones WHERE id = ?")->execute([$_POST['milestone_id']]);
        $pdo->prepare("INSERT INTO logs (action_type, description) VALUES ('MILESTONE_DELETED',?)")->execute(["Meilenstein '".($ms_del_title?:'#'.$_POST['milestone_id'])."' gelöscht."]);
    }
    elseif ($action === 'update_task_status') { 
        $pdo->prepare("UPDATE tasks SET status = ? WHERE id = ?")->execute([$_POST['status'], $_POST['task_id']]); 
        $pdo->prepare("INSERT INTO logs (action_type, description) VALUES ('TASK_STATUS', ?)")->execute(["Task ID #".$_POST['task_id']." Status geändert auf: ".$_POST['status']]);
    }
    
    header("Location: tasks"); exit();
}

// ==========================================
// FILTER & DATEN LADEN
// ==========================================
$search_query = isset($_GET['q']) ? trim($_GET['q']) : '';
$filter_status = isset($_GET['status']) ? $_GET['status'] : 'all';
$filter_category = isset($_GET['category']) ? $_GET['category'] : 'all';
$filter_contact = isset($_GET['contact']) ? $_GET['contact'] : 'all';
$sort_by = isset($_GET['sort']) ? $_GET['sort'] : 'deadline_asc';
$filter_month = isset($_GET['start_month']) ? $_GET['start_month'] : 'all'; 
$filter_created = isset($_GET['created']) ? $_GET['created'] : 'all'; 
$filter_deadline = isset($_GET['deadline_filter']) ? $_GET['deadline_filter'] : 'all';

$available_months = $pdo->query("SELECT DISTINCT DATE_FORMAT(start_date, '%Y-%m') as ym FROM tasks WHERE deleted_at IS NULL AND start_date IS NOT NULL AND start_date != '0000-00-00' ORDER BY ym DESC")->fetchAll(PDO::FETCH_COLUMN);

$sql = "SELECT t.*, c.name AS contact_name, c.email AS contact_email, c.website AS contact_website, c.street, c.zip, c.city, c.company
        FROM tasks t LEFT JOIN contacts c ON t.contact_id = c.id WHERE t.deleted_at IS NULL AND 1=1";
$params = [];

if ($search_query !== '') { $sql .= " AND (t.title LIKE ? OR t.description LIKE ?)"; $params[] = "%$search_query%"; $params[] = "%$search_query%"; }
if ($filter_status !== 'all') { $sql .= " AND t.status = ?"; $params[] = $filter_status; }
if ($filter_category !== 'all') { $sql .= " AND t.category = ?"; $params[] = $filter_category; }
if ($filter_contact !== 'all') { $sql .= " AND t.contact_id = ?"; $params[] = $filter_contact; }

if ($filter_month !== 'all') {
    $sql .= " AND DATE_FORMAT(t.start_date, '%Y-%m') = ?";
    $params[] = $filter_month;
}

if ($filter_created === '7') { $sql .= " AND t.start_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY) AND t.start_date <= CURDATE()"; }
if ($filter_created === '30') { $sql .= " AND t.start_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY) AND t.start_date <= CURDATE()"; }

if ($filter_deadline === 'overdue') { $sql .= " AND t.deadline IS NOT NULL AND t.deadline < CURDATE() AND t.status != 'Erledigt'"; }
if ($filter_deadline === '7') { $sql .= " AND t.deadline IS NOT NULL AND t.deadline BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)"; }
if ($filter_deadline === '30') { $sql .= " AND t.deadline IS NOT NULL AND t.deadline BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)"; }

$sql .= ($sort_by === 'newest') ? " ORDER BY t.created_at DESC" : " ORDER BY CASE WHEN t.status IN ('Erledigt','Storniert') THEN 1 ELSE 0 END, t.deadline ASC";

$stmt = $pdo->prepare($sql); $stmt->execute($params); $tasks = $stmt->fetchAll(PDO::FETCH_ASSOC);
$all_categories = $pdo->query("SELECT DISTINCT category FROM tasks WHERE deleted_at IS NULL AND category IS NOT NULL AND category != ''")->fetchAll(PDO::FETCH_COLUMN);
$all_contacts = $pdo->query("SELECT * FROM contacts WHERE deleted_at IS NULL ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);

// Projekt-Diskussion je Projekt - in einer Abfrage.
$project_talk = [];
if (!empty($tasks)) {
    $tids = array_column($tasks, 'id');
    $in2  = implode(',', array_fill(0, count($tids), '?'));
    $tst  = $pdo->prepare("SELECT * FROM project_comments WHERE task_id IN ($in2) ORDER BY created_at ASC");
    $tst->execute($tids);
    foreach ($tst->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $project_talk[$row['task_id']][] = $row;
    }
    // Als gesehen markieren, sobald die Seite sie zeigt.
    $pdo->prepare("UPDATE project_comments SET admin_seen = 1
                   WHERE admin_seen = 0 AND author_contact_id IS NOT NULL AND task_id IN ($in2)")
        ->execute($tids);
}

// Beteiligte je Projekt - in einer Abfrage, nicht je Karte einzeln.
$task_members = [];
if (!empty($tasks)) {
    $ids = array_column($tasks, 'id');
    $in  = implode(',', array_fill(0, count($ids), '?'));
    $mst = $pdo->prepare("SELECT tc.task_id, tc.contact_id, tc.role, c.name, c.company, c.contact_type,
                                 c.portal_token
                          FROM task_contacts tc
                          JOIN contacts c ON c.id = tc.contact_id
                          WHERE tc.task_id IN ($in) AND c.deleted_at IS NULL
                          ORDER BY tc.role = 'owner' DESC, c.name ASC");
    $mst->execute($ids);
    foreach ($mst->fetchAll(PDO::FETCH_ASSOC) as $m) {
        $task_members[$m['task_id']][] = $m;
    }
}

// Batch-Abfragen statt N+1 Queries pro Task
$task_ids = array_column($tasks, 'id');
$milestones_map = [];
$assets_map = [];
$time_map = [];

if (!empty($task_ids)) {
    $ph = implode(',', array_fill(0, count($task_ids), '?'));

    $ms_stmt = $pdo->prepare("SELECT * FROM task_milestones WHERE task_id IN ($ph) ORDER BY created_at ASC");
    $ms_stmt->execute($task_ids);
    foreach ($ms_stmt->fetchAll() as $ms) {
        $milestones_map[$ms['task_id']][] = $ms;
    }

    $as_stmt = $pdo->prepare("SELECT * FROM client_assets WHERE task_id IN ($ph) ORDER BY uploaded_at DESC");
    $as_stmt->execute($task_ids);
    foreach ($as_stmt->fetchAll() as $a) {
        $assets_map[$a['task_id']][] = $a;
    }

    $te_stmt = $pdo->prepare("SELECT task_id, COALESCE(SUM(duration_minutes), 0) as total FROM time_entries WHERE task_id IN ($ph) GROUP BY task_id");
    $te_stmt->execute($task_ids);
    foreach ($te_stmt->fetchAll() as $t) {
        $time_map[$t['task_id']] = (int)$t['total'];
    }

    // Batch-load milestone IDs for comment loading
    $all_ms_ids = [];
    foreach ($milestones_map as $ms_list) {
        foreach ($ms_list as $ms_item) { $all_ms_ids[] = (int)$ms_item['id']; }
    }
    $ms_comments_map = [];
    if (!empty($all_ms_ids)) {
        $ms_ph = implode(',', array_fill(0, count($all_ms_ids), '?'));
        try {
            $com_stmt = $pdo->prepare("SELECT * FROM milestone_comments WHERE milestone_id IN ($ms_ph) ORDER BY created_at ASC");
            $com_stmt->execute($all_ms_ids);
            foreach ($com_stmt->fetchAll(PDO::FETCH_ASSOC) as $com) {
                $ms_comments_map[$com['milestone_id']][] = $com;
            }
            // Mark all client comments as seen
            $pdo->prepare("UPDATE milestone_comments SET admin_seen=1 WHERE milestone_id IN ($ms_ph) AND author='client' AND admin_seen=0")
                ->execute($all_ms_ids);
        } catch (PDOException $e) {}
    }
}

$now = new DateTime();
$now->setTime(0, 0, 0);

foreach ($tasks as &$task) {
    $raw_milestones = $milestones_map[$task['id']] ?? [];
    foreach ($raw_milestones as &$ms_item) {
        $ms_item['comments'] = $ms_comments_map[$ms_item['id']] ?? [];
    }
    unset($ms_item);
    $task['milestones'] = $raw_milestones;
    $task['assets']     = $assets_map[$task['id']] ?? [];
    $task['tracked_minutes'] = $time_map[$task['id']] ?? 0;
    $total = count($task['milestones']); $done = 0; foreach($task['milestones'] as $m) if($m['is_completed']) $done++;
    $task['progress'] = $total > 0 ? round(($done / $total) * 100) : 0;
    $task['is_online'] = ($task['contact_website']) ? checkUptime($task['contact_website']) : null;

    if (!empty($task['start_date']) && strpos($task['start_date'], '0000') === false) {
        $start_d = new DateTime($task['start_date']);
        $start_d->setTime(0, 0, 0);
        $diff_start = $now->diff($start_d);
        $diff_days_start = (int)$diff_start->format('%R%a'); 
        
        if ($diff_days_start == 0) {
            $task['start_text'] = "Start: Heute";
        } elseif ($diff_days_start == -1) {
            $task['start_text'] = "Start: Gestern";
        } elseif ($diff_days_start == 1) {
            $task['start_text'] = "Start: Morgen";
        } elseif ($diff_days_start < -1) {
            $task['start_text'] = "Start: Vor " . abs($diff_days_start) . " T.";
        } else {
            $task['start_text'] = "Start: In " . $diff_days_start . " T.";
        }
    } else {
        $task['start_text'] = "Start: Unbekannt";
    }
    
    if ($task['deadline']) {
        $deadline = new DateTime($task['deadline']);
        $deadline->setTime(0, 0, 0);
        $diff = $now->diff($deadline);
        $task['days_until_deadline'] = (int)$diff->format('%R%a'); 
    } else {
        $task['days_until_deadline'] = null;
    }
}
unset($task);

$german_months = ['01'=>'Januar','02'=>'Februar','03'=>'März','04'=>'April','05'=>'Mai','06'=>'Juni','07'=>'Juli','08'=>'August','09'=>'September','10'=>'Oktober','11'=>'November','12'=>'Dezember'];

$page_title   = 'Aufgaben & Projekte';
$page_heading = 'Projekte & Aufgaben';
$current_page = basename($_SERVER['PHP_SELF']);
$header_actions = '
      <div class="d-flex gap-2">
          <a href="board" class="btn btn-outline-primary btn-sm fw-bold"><i class="bi bi-kanban"></i> <span class="btn-label">Boardansicht</span></a>
          <button class="btn btn-primary btn-sm fw-bold" data-bs-toggle="modal" data-bs-target="#addTaskModal"><i class="bi bi-plus-lg"></i> Neues Projekt</button>
      </div>';
$extra_head = <<<'CSS'
  <style>
      .ms-comment-thread { background:var(--surface-subtle); border-radius:6px; padding:8px 10px; margin-top:6px; font-size:12px; }
      .ms-com-bubble { border-radius:6px; padding:5px 9px; margin-bottom:4px; max-width:100%; word-break:break-word; }
      .ms-com-bubble.client { background:var(--state-info-bg); border-left:3px solid var(--state-info-fg); }
      .ms-com-bubble.admin  { background:var(--state-success-bg); border-left:3px solid var(--state-success-fg); }
      .ms-com-meta { font-size:10px; color:var(--text-muted); margin-bottom:2px; }
      .ms-com-toggle { font-size:11px; color:var(--text-muted); cursor:pointer; text-decoration:none; }
      .ms-com-toggle:hover { color:var(--text-strong); text-decoration:underline; }
      .ms-reply-row { display:flex; gap:4px; margin-top:6px; }
      .waiting-select { width:auto; font-size:11px; padding:1px 18px 1px 6px; height:auto; }
      .proj-talk-admin .ms-comment-thread { max-height:240px; overflow-y:auto; }
      .ms-reply-row input { font-size:12px; }
      .task-card-cancelled { background-color: var(--surface-subtle) !important; border-top-color: var(--text-faint) !important; opacity: 0.75; }
      .task-card-cancelled:hover { opacity: 1; }
  </style>
CSS;

require 'includes/head.php';
require 'includes/layout_start.php';
?>

    <?php
    $active_filters = array_filter([
        $filter_status   !== 'all',
        $filter_contact  !== 'all',
        $filter_category !== 'all',
        $filter_month    !== 'all',
        $filter_created  !== 'all',
        $filter_deadline !== 'all',
    ]);
    $active_filter_count = count($active_filters);
    $any_filter_active   = $active_filter_count > 0 || $search_query !== '';
    ?>
    <div class="row mb-4">
        <div class="col-12">
            <form method="GET" action="tasks" class="filter-bar d-block">

                <!-- Search bar + mobile collapse toggle -->
                <div class="d-flex gap-2 align-items-center">
                    <div class="input-group" style="flex-grow:1; min-width:150px;">
                        <span class="input-group-text bg-surface"><i class="bi bi-search text-muted"></i></span>
                        <input type="text" name="q" class="form-control border-start-0 ps-0" placeholder="Suche..." value="<?=htmlspecialchars($search_query)?>">
                    </div>
                    <button type="button"
                            class="btn btn-outline-secondary d-md-none position-relative"
                            data-bs-toggle="collapse"
                            data-bs-target="#taskFiltersCollapse"
                            aria-expanded="<?= $active_filter_count > 0 ? 'true' : 'false' ?>">
                        <i class="bi bi-sliders"></i> Filter
                        <?php if($active_filter_count > 0): ?>
                            <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-primary" style="font-size:10px;"><?=$active_filter_count?></span>
                        <?php endif; ?>
                    </button>
                </div>

                <!-- Filter selects: collapse on mobile, always visible on md+ -->
                <div class="collapse d-md-block <?= $active_filter_count > 0 ? 'show' : '' ?>" id="taskFiltersCollapse">
                    <div class="d-flex flex-wrap gap-2 align-items-center mt-2">

                        <div style="min-width:140px; flex:1 1 140px;">
                            <select name="contact" class="form-select">
                                <option value="all">Alle Kunden</option>
                                <?php foreach($all_contacts as $c): ?>
                                    <option value="<?=$c['id']?>" <?= $filter_contact == $c['id'] ? 'selected' : '' ?>><?=htmlspecialchars($c['name'])?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div style="min-width:130px; flex:1 1 130px;">
                            <select name="status" class="form-select">
                                <option value="all">Alle Status</option>
                                <option value="Offen" <?= $filter_status === 'Offen' ? 'selected' : '' ?>>Offen</option>
                                <option value="In Bearbeitung" <?= $filter_status === 'In Bearbeitung' ? 'selected' : '' ?>>In Bearbeitung</option>
                                <option value="Erledigt" <?= $filter_status === 'Erledigt' ? 'selected' : '' ?>>Erledigt</option>
                                <option value="Storniert" <?= $filter_status === 'Storniert' ? 'selected' : '' ?>>Storniert</option>
                            </select>
                        </div>

                        <div style="min-width:140px; flex:1 1 140px;">
                            <select name="start_month" class="form-select">
                                <option value="all">Startmonat egal</option>
                                <?php
                                foreach($available_months as $ym):
                                    if(!$ym) continue;
                                    list($y, $m) = explode('-', $ym);
                                    $display = $german_months[$m] . ' ' . $y;
                                ?>
                                    <option value="<?=htmlspecialchars($ym)?>" <?= $filter_month === $ym ? 'selected' : '' ?>><?= $display ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div style="min-width:130px; flex:1 1 130px;">
                            <select name="created" class="form-select">
                                <option value="all">Start egal</option>
                                <option value="7" <?= $filter_created === '7' ? 'selected' : '' ?>>Letzte 7 Tage</option>
                                <option value="30" <?= $filter_created === '30' ? 'selected' : '' ?>>Letzte 30 Tage</option>
                            </select>
                        </div>

                        <div style="min-width:130px; flex:1 1 130px;">
                            <select name="deadline_filter" class="form-select">
                                <option value="all">Deadline egal</option>
                                <option value="overdue" <?= $filter_deadline === 'overdue' ? 'selected' : '' ?>>Überfällig</option>
                                <option value="7" <?= $filter_deadline === '7' ? 'selected' : '' ?>>Nächste 7 Tage</option>
                                <option value="30" <?= $filter_deadline === '30' ? 'selected' : '' ?>>Nächste 30 Tage</option>
                            </select>
                        </div>

                        <div class="d-flex gap-2">
                            <button type="submit" class="btn btn-dark px-3">Filtern</button>
                            <?php if($any_filter_active): ?>
                                <a href="tasks" class="btn btn-outline-secondary" title="Filter zurücksetzen"><i class="bi bi-x-circle"></i></a>
                            <?php endif; ?>
                        </div>

                    </div>
                </div>

            </form>
        </div>
    </div>

    <div class="row align-items-stretch">
      <?php if(empty($tasks)): ?>
          <div class="col-12 text-center py-5 text-muted">
              <i class="bi bi-folder-x fs-1"></i>
              <p class="mt-2">Keine Projekte gefunden, die diesen Kriterien entsprechen.</p>
          </div>
      <?php endif; ?>
      <?php foreach($tasks as $task):
          $h = floor($task['tracked_minutes'] / 60); $m = $task['tracked_minutes'] % 60;
          $completed_class = $task['status'] === 'Erledigt' ? 'task-card-completed' : '';
          $cancelled_style = $task['status'] === 'Storniert' ? ' style="background-color:var(--surface-subtle);border-top-color:var(--text-faint);opacity:0.75;"' : '';
      ?>
          <div class="col-lg-6">
            <div class="task-card <?= $completed_class ?>"<?= $cancelled_style ?>>
              
              <div class="d-flex justify-content-between align-items-start mb-2 flex-wrap task-header-row">
                <div style="flex: 1; min-width: 0;"> 
                    <span class="badge bg-subtle text-primary mb-1"><?=htmlspecialchars($task['category'])?></span>
                    
                    <h4 class="fw-bold mb-0">
                        <?php if($task['is_online'] !== null): ?>
                            <span class="status-dot <?= $task['is_online'] ? 'dot-online' : 'dot-offline' ?>"></span>
                        <?php endif; ?>
                        <span class="<?= in_array($task['status'], ['Erledigt','Storniert']) ? 'text-decoration-line-through text-muted' : '' ?>">
                            <?=htmlspecialchars($task['title'])?>
                        </span>
                    </h4>
                    
                    <div class="small mt-1 text-muted d-flex flex-wrap gap-3">
                        <?php
                          $mitglieder = $task_members[$task['id']] ?? [];
                          $weitere    = max(0, count($mitglieder) - 1);
                        ?>
                        <?php if($task['contact_name']): ?>
                            <span role="button" data-bs-toggle="modal" data-bs-target="#membersModal"
                                  onclick='openMembers(<?= (int)$task["id"] ?>, <?= json_encode($task["title"], JSON_HEX_TAG|JSON_HEX_APOS) ?>)'
                                  title="Beteiligte verwalten">
                              <i class="bi bi-person"></i> <?=$task['contact_name']?><?php
                                if ($weitere > 0) echo ' <span class="badge rounded-pill bg-subtle text-strong-c" style="font-size:var(--text-2xs);">+' . $weitere . '</span>';
                              ?>
                            </span>
                        <?php else: ?>
                            <span role="button" class="text-muted" data-bs-toggle="modal" data-bs-target="#membersModal"
                                  onclick='openMembers(<?= (int)$task["id"] ?>, <?= json_encode($task["title"], JSON_HEX_TAG|JSON_HEX_APOS) ?>)'>
                              <i class="bi bi-person-plus"></i> Beteiligte
                            </span>
                        <?php endif; ?>
                        
                        <span><i class="bi bi-calendar-event"></i> <?=$task['start_text']?></span>
                        
                        <?php if($task['deadline']): ?>
                            <?php $is_active = !in_array($task['status'], ['Erledigt','Storniert']); ?>
                            <span class="<?= $task['days_until_deadline'] < 0 && $is_active ? 'text-danger fw-bold' : '' ?>">
                                <i class="bi bi-alarm"></i>
                                <?php
                                    if($task['days_until_deadline'] < 0 && $is_active) {
                                        echo 'Überfällig (' . abs($task['days_until_deadline']) . ' T.)';
                                    } else {
                                        echo 'In ' . $task['days_until_deadline'] . ' T.';
                                    }
                                ?>
                            </span>
                        <?php endif; ?>
                    </div>
                </div>
                
                <div class="d-flex align-items-center gap-2 mt-2 mt-sm-0">
                  <div class="dropdown">
                    <?php $_sc = ['Offen'=>'status-offen','In Bearbeitung'=>'status-in-bearbeitung','Erledigt'=>'status-erledigt','Storniert'=>'status-storniert'][$task['status']] ?? 'status-offen'; ?>
                    <button class="status-badge <?=$_sc?> dropdown-toggle" type="button" data-bs-toggle="dropdown"><?= htmlspecialchars($task['status']); ?></button>
                    <ul class="dropdown-menu dropdown-menu-end shadow-sm">
                      <li><form action="tasks" method="POST" class="px-3 py-1 m-0"><?= csrf_field() ?><input type="hidden" name="action" value="update_task_status"><input type="hidden" name="task_id" value="<?=$task['id']?>"><input type="hidden" name="status" value="Offen"><button type="submit" class="btn btn-sm btn-link text-strong-c text-start p-0 text-decoration-none w-100">Offen</button></form></li>
                      <li><form action="tasks" method="POST" class="px-3 py-1 m-0"><?= csrf_field() ?><input type="hidden" name="action" value="update_task_status"><input type="hidden" name="task_id" value="<?=$task['id']?>"><input type="hidden" name="status" value="In Bearbeitung"><button type="submit" class="btn-sm btn btn-link text-strong-c text-start p-0 text-decoration-none w-100">In Bearbeitung</button></form></li>
                      <li><form action="tasks" method="POST" class="px-3 py-1 m-0"><?= csrf_field() ?><input type="hidden" name="action" value="update_task_status"><input type="hidden" name="task_id" value="<?=$task['id']?>"><input type="hidden" name="status" value="Erledigt"><button type="submit" class="btn-sm btn btn-link text-success text-start p-0 text-decoration-none w-100">Erledigt</button></form></li>
                      <li><form action="tasks" method="POST" class="px-3 py-1 m-0"><?= csrf_field() ?><input type="hidden" name="action" value="update_task_status"><input type="hidden" name="task_id" value="<?=$task['id']?>"><input type="hidden" name="status" value="Storniert"><button type="submit" class="btn-sm btn btn-link text-secondary text-start p-0 text-decoration-none w-100">Storniert</button></form></li>
                    </ul>
                  </div>
                  
                  <button type="button" class="btn-icon p-1" onclick='openEditModal(<?= json_encode($task, JSON_HEX_APOS); ?>)' title="Bearbeiten">
                      <i class="bi bi-pencil-square fs-5"></i>
                  </button>
                  
                  <button type="button" class="btn-icon text-danger p-1" title="Löschen" onclick="openDeleteModal(<?= $task['id']; ?>)">
                      <i class="bi bi-trash3-fill fs-5"></i>
                  </button>
                </div>
              </div>

              <div class="task-content-scroll mt-2 border-top pt-2">
                  <div class="milestone-box">
                    <?php foreach($task['milestones'] as $ms): ?>
                        <?php $ms_com_count = count($ms['comments']); $ms_has_client = false; foreach($ms['comments'] as $c) if($c['author']==='client') { $ms_has_client=true; break; } ?>
                        <div class="border-bottom pb-1 pt-1">
                            <div class="d-flex justify-content-between align-items-center small">
                                <form method="POST" style="margin:0; display:flex; align-items:center;"
                                      class="ms-toggle-form"
                                      data-completing="<?= $ms['is_completed'] ? '0' : '1' ?>"
                                      data-has-email="<?= !empty($task['contact_email']) ? '1' : '0' ?>">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="toggle_milestone">
                                    <input type="hidden" name="milestone_id" value="<?=$ms['id']?>">
                                    <input type="hidden" name="notify_client" value="1" class="notify-input">
                                    <button type="submit" class="btn btn-link p-0 me-2 shadow-none text-decoration-none">
                                        <i class="bi <?=$ms['is_completed']?'bi-check-circle-fill text-success':'bi-circle text-muted'?>" style="font-size: 1.1rem;"></i>
                                    </button>
                                    <span class="<?= ($ms['is_completed'] || $task['status']==='Storniert') ? 'text-muted text-decoration-line-through' : '' ?>">
                                        <?=htmlspecialchars($ms['title'])?>
                                    </span>
                                </form>
                                <div class="d-flex align-items-center gap-1">
                                    <?php if(!$ms['is_completed']): ?>
                                    <form method="POST" class="m-0">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="set_waiting_on">
                                        <input type="hidden" name="milestone_id" value="<?=$ms['id']?>">
                                        <input type="hidden" name="back_q" value="<?= htmlspecialchars($search_query, ENT_QUOTES) ?>">
                                        <select name="waiting_on" class="form-select form-select-sm waiting-select"
                                                onchange="this.form.submit()" title="Auf wen wartet dieser Schritt?">
                                            <option value=""     <?= ($ms['waiting_on'] ?? '') === ''     ? 'selected' : '' ?>>—</option>
                                            <option value="us"   <?= ($ms['waiting_on'] ?? '') === 'us'   ? 'selected' : '' ?>>wir</option>
                                            <option value="them" <?= ($ms['waiting_on'] ?? '') === 'them' ? 'selected' : '' ?>>Kunde</option>
                                        </select>
                                    </form>
                                    <?php endif; ?>
                                    <?php if($ms_com_count > 0): ?>
                                        <a class="ms-com-toggle" onclick="toggleMsComments(<?=$ms['id']?>)" title="Kommentare anzeigen">
                                            <i class="bi bi-chat-dots<?=$ms_has_client?' text-primary':''?>"></i> <?=$ms_com_count?>
                                        </a>
                                    <?php endif; ?>
                                    <button type="button" class="btn btn-link text-danger p-0 shadow-none" onclick="openDeleteMilestoneModal(<?=$ms['id']?>)"><i class="bi bi-x fs-5"></i></button>
                                </div>
                            </div>
                            <div class="ms-comment-thread d-none" id="ms-thread-<?=$ms['id']?>">
                                <div id="ms-bubbles-<?=$ms['id']?>">
                                <?php foreach($ms['comments'] as $com): ?>
                                    <div class="ms-com-bubble <?=$com['author']==='client'?'client':'admin'?>">
                                        <div class="ms-com-meta"><?=htmlspecialchars($com['author_name'] ?? $com['author'])?> &bull; <?=date('d.m.Y H:i', strtotime($com['created_at']))?></div>
                                        <div><?=htmlspecialchars($com['message'])?></div>
                                    </div>
                                <?php endforeach; ?>
                                </div>
                                <div class="ms-reply-row">
                                    <input type="text" class="form-control form-control-sm" id="ms-reply-<?=$ms['id']?>" placeholder="Antworten…">
                                    <button class="btn btn-sm btn-success" onclick="sendAdminComment(<?=$ms['id']?>)"><i class="bi bi-send"></i></button>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                    <form method="POST" class="mt-2"><?= csrf_field() ?><input type="hidden" name="action" value="add_milestone"><input type="hidden" name="task_id" value="<?=$task['id']?>"><div class="input-group input-group-sm"><input type="text" name="milestone_title" class="form-control border-light" placeholder="Neuer Meilenstein..."><button class="btn btn-light border">+</button></div></form>
                  </div>
                  <?php $talk = $project_talk[$task['id']] ?? []; ?>
                  <div class="proj-talk-admin mt-2">
                    <a class="ms-com-toggle" onclick="toggleTalk(<?=$task['id']?>)">
                      <i class="bi bi-chat-square-text"></i>
                      Austausch<?= $talk ? ' (' . count($talk) . ')' : '' ?>
                    </a>
                    <div class="ms-comment-thread d-none" id="talk-<?=$task['id']?>">
                      <?php foreach($talk as $t): $vonUns = $t['author_contact_id'] === null; ?>
                        <div class="ms-com-bubble <?= $vonUns ? 'admin' : 'client' ?>">
                          <div class="ms-com-meta">
                            <?= htmlspecialchars($t['author_name']) ?>
                            · <?= date('d.m.Y H:i', strtotime($t['created_at'])) ?>
                          </div>
                          <?= nl2br(htmlspecialchars($t['message'])) ?>
                        </div>
                      <?php endforeach; ?>
                      <?php if(!$talk): ?>
                        <div class="text-muted" style="font-size:11px;">Noch kein Austausch.</div>
                      <?php endif; ?>
                      <form method="POST" class="ms-reply-row">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="add_project_reply">
                        <input type="hidden" name="task_id" value="<?=$task['id']?>">
                        <input type="hidden" name="back_q" value="<?= htmlspecialchars($search_query, ENT_QUOTES) ?>">
                        <input type="text" name="message" class="form-control form-control-sm"
                               placeholder="Antworten …" required>
                        <button class="btn btn-sm btn-primary"><i class="bi bi-send"></i></button>
                      </form>
                    </div>
                  </div>
              </div> 

              <div class="mt-auto border-top pt-3 w-100">
                  <div class="d-flex justify-content-between align-items-end mb-1">
                      <small class="text-muted fw-bold" style="font-size: 10px; text-transform: uppercase;">Fortschritt</small>
                      <span class="small fw-bold <?=$task['progress']==100?'text-success':''?>"><?=$task['progress']?>%</span>
                  </div>
                  <div class="progress mb-3" style="height:6px;">
                      <div class="progress-bar <?=$task['progress']==100?'bg-success':''?>" style="width:<?=$task['progress']?>%; <?=$task['progress']!=100?'background-color: var(--color-primary);':''?>"></div>
                  </div>

                  <div class="d-flex gap-2 mb-2">
                      <button class="btn btn-sm w-50 d-flex justify-content-center align-items-center gap-2 <?=$task['is_timer_running']?'btn-danger':'btn-light border'?>" data-bs-toggle="collapse" data-bs-target="#t_<?=$task['id']?>">
                        <span class="text-truncate"><?=$task['is_timer_running']?'<span class="pulse-dot"></span> Timer':'Zeiterfassung'?></span>
                        <span class="badge <?=$task['is_timer_running']?'bg-surface text-danger':'bg-secondary'?>"><?=sprintf('%02dh %02dm', $h, $m)?></span>
                      </button>

                      <button class="btn btn-sm btn-dark invoice-btn w-50 d-flex justify-content-center align-items-center gap-2" 
                              data-id="<?= $task['id'] ?>" 
                              data-contact-id="<?= $task['contact_id'] ?>" 
                              data-task-title="<?= htmlspecialchars($task['title']) ?>" 
                              data-hours="<?= round($task['tracked_minutes']/60,2) ?>" 
                              data-bs-toggle="modal" data-bs-target="#invoiceModal">
                          <i class="bi bi-receipt"></i> Rechnung
                      </button>
                  </div>

                  <div class="collapse <?=$task['is_timer_running']?'show':''?> mb-2" id="t_<?=$task['id']?>">
                      <div class="d-flex gap-2">
                        <?php if($task['is_timer_running']): ?>
                            <button class="btn btn-sm btn-danger w-100 fw-bold" onclick="toggleTimer(<?=$task['id']?>, 'stop_timer')">Stop</button>
                        <?php else: ?>
                            <button class="btn btn-sm btn-success w-50 fw-bold" onclick="toggleTimer(<?=$task['id']?>, 'start_timer')">Start</button>
                            <button class="btn btn-sm btn-outline-secondary w-50" data-bs-toggle="modal" data-bs-target="#manualTimeModal_<?= $task['id'] ?>">Manuell</button>
                        <?php endif; ?>
                      </div>
                  </div>

                  <?php if($task['client_feedback'] || count($task['assets']) > 0): ?>
                      <div class="d-flex gap-2 mt-2">
                          <?php if($task['client_feedback']): ?>
                              <button class="btn btn-sm btn-outline-warning text-strong-c w-50 d-flex justify-content-center align-items-center gap-2" data-bs-toggle="collapse" data-bs-target="#fb_<?=$task['id']?>">
                                  <i class="bi bi-chat-left-text"></i> Feedback
                              </button>
                          <?php endif; ?>
                          
                          <?php if(count($task['assets']) > 0): ?>
                              <button class="btn btn-sm btn-outline-info text-strong-c w-50 d-flex justify-content-center align-items-center gap-2" data-bs-toggle="collapse" data-bs-target="#up_<?=$task['id']?>">
                                  <i class="bi bi-paperclip"></i> Uploads (<?=count($task['assets'])?>)
                              </button>
                          <?php endif; ?>
                      </div>
                  <?php endif; ?>

                  <?php if($task['client_feedback']): ?>
                      <div class="collapse mt-2" id="fb_<?=$task['id']?>">
                          <div class="feedback-box scroll-box-sm mb-0">
                              <span class="text-muted"><?=nl2br(htmlspecialchars($task['client_feedback']))?></span>
                          </div>
                      </div>
                  <?php endif; ?>

                  <?php if(count($task['assets']) > 0): ?>
                      <div class="collapse mt-2" id="up_<?=$task['id']?>">
                          <div class="p-2 bg-subtle rounded border scroll-box-sm mb-0">
                              <?php foreach($task['assets'] as $a): 
                                  $uploaderBadge = (isset($a['uploaded_by']) && $a['uploaded_by'] === 'admin') 
                                      ? '<span class="badge bg-primary me-2" style="font-size:8px; padding: 3px 5px;">Admin</span>' 
                                      : '<span class="badge bg-secondary me-2" style="font-size:8px; padding: 3px 5px;">Kunde</span>';
                              ?>
                                  <div class="d-flex justify-content-between align-items-center mb-1 bg-surface p-1 px-2 rounded small shadow-sm">
                                      <span class="text-truncate d-flex align-items-center" style="max-width:65%">
                                          <?= $uploaderBadge ?>
                                          <?=htmlspecialchars($a['file_name'])?>
                                      </span>
                                      <div class="d-flex gap-2">
                                          <a href="<?=$a['file_path']?>" download class="text-primary"><i class="bi bi-download"></i></a>
                                          <button type="button" class="btn btn-link text-danger p-0 shadow-none" onclick="openDeleteAssetModal(<?=$a['id']?>)"><i class="bi bi-trash"></i></button>
                                      </div>
                                  </div>
                              <?php endforeach; ?>
                          </div>
                      </div>
                  <?php endif; ?>

              </div>
            </div>
          </div>
          
          <div class="modal fade" id="manualTimeModal_<?= $task['id'] ?>" tabindex="-1" data-bs-backdrop="static" data-bs-keyboard="false">
            <div class="modal-dialog modal-sm">
                <div class="modal-content">
                    <form method="POST" action="tasks">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="add_manual_time">
                        <input type="hidden" name="task_id" value="<?= $task['id'] ?>">
                        <div class="modal-header">
                            <h6>Zeit nachtragen</h6>
                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="modal-body">
                            <label class="form-label small">Minuten eingeben:</label>
                            <input type="number" name="minutes" class="form-control" placeholder="z.B. 45" required min="1">
                        </div>
                        <div class="modal-footer">
                            <button type="submit" class="btn btn-primary btn-sm w-100">Speichern</button>
                        </div>
                    </form>
                </div>
            </div>
          </div>

      <?php endforeach; ?>
    </div>

  <div class="modal fade" id="addTaskModal" tabindex="-1" data-bs-backdrop="static" data-bs-keyboard="false"><div class="modal-dialog modal-lg"><div class="modal-content"><form method="POST"><?= csrf_field() ?><input type="hidden" name="action" value="add_task"><div class="modal-header bg-dark text-white"><h5>Neues Projekt</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div><div class="modal-body"><div class="row g-3"><div class="col-md-8"><label class="form-label">Titel *</label><input type="text" name="title" class="form-control" required></div><div class="col-md-4"><label class="form-label">Kategorie</label><input type="text" name="category" class="form-control"></div><div class="col-12"><label class="form-label">Kunde</label><select name="contact_id" class="form-select"><option value="">-- Ohne Kunde --</option><?php foreach($all_contacts as $c): ?><option value="<?=$c['id']?>"><?=$c['name']?></option><?php endforeach; ?></select></div><div class="col-md-6"><label class="form-label">Start</label><input type="date" name="start_date" class="form-control"></div><div class="col-md-6"><label class="form-label">Deadline</label><input type="date" name="deadline" class="form-control"></div><div class="col-12"><label class="form-label">Beschreibung</label><textarea name="description" class="form-control" rows="4"></textarea></div></div></div><div class="modal-footer"><button type="submit" class="btn btn-primary px-4 fw-bold">Projekt anlegen</button></div></form></div></div></div>

  <div class="modal fade" id="editTaskModal" tabindex="-1" data-bs-backdrop="static" data-bs-keyboard="false">
    <div class="modal-dialog modal-lg">
      <div class="modal-content">
        <div class="modal-header bg-dark text-white"><h5>Projekt bearbeiten</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
            <form method="POST" id="editTaskForm" enctype="multipart/form-data">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="edit_task">
                <input type="hidden" name="task_id" id="e_id">
                <div class="row g-3">
                    <div class="col-md-8"><label class="form-label">Titel</label><input type="text" name="title" id="e_title" class="form-control" required></div>
                    <div class="col-md-4"><label class="form-label">Kategorie</label><input type="text" name="category" id="e_cat" class="form-control"></div>
                    <div class="col-md-12"><label class="form-label text-warning fw-bold">Feedback vom Portal</label><div id="e_feedback" class="p-3 bg-subtle rounded border scroll-box-sm" style="min-height:80px;"></div></div>
                    <div class="col-md-12"><label class="form-label fw-bold">Projekt-Dateien</label><div id="e_assets" class="p-2 bg-subtle rounded border scroll-box-sm"></div></div>
                    
                    <div class="col-md-12 border-top pt-3 mt-2">
                        <label class="form-label fw-bold">Eigene Dateien hinzufügen</label>
                        <input type="file" id="adminAssetUpload" class="form-control form-control-sm border-primary" multiple>
                        <small class="text-muted" style="font-size:11px;">Der Upload startet sofort nach der Auswahl. Die Dateien sind für den Kunden im Portal sofort sichtbar.</small>
                        
                        <div class="progress mt-2 shadow-sm" id="adminUploadProgressContainer" style="display:none; height:15px; border-radius: 4px;">
                            <div id="adminUploadProgressBar" class="progress-bar progress-bar-striped progress-bar-animated bg-primary" style="width: 0%; font-size:10px; font-weight:bold;">0%</div>
                        </div>
                    </div>

                    <div class="col-12"><label class="form-label">Kunde</label><select name="contact_id" id="e_contact" class="form-select"><option value="">-- Ohne Kunde --</option><?php foreach($all_contacts as $c): ?><option value="<?=$c['id']?>"><?=$c['name']?></option><?php endforeach; ?></select></div>
                    <div class="col-md-6"><label class="form-label">Start</label><input type="date" name="start_date" id="e_start" class="form-control"></div>
                    <div class="col-md-6"><label class="form-label">Deadline</label><input type="date" name="deadline" id="e_deadline" class="form-control"></div>
                    <div class="col-12"><label class="form-label">Beschreibung</label><textarea name="description" id="e_desc" class="form-control" rows="4"></textarea></div>
                </div>
            </form>
        </div>
        <div class="modal-footer d-flex justify-content-between bg-subtle">
            <button type="button" class="btn btn-outline-danger fw-bold" onclick="triggerDeleteFromEdit()"><i class="bi bi-trash3-fill"></i> Löschen</button>
            <button type="submit" form="editTaskForm" class="btn btn-primary px-4 fw-bold">Speichern</button>
        </div>
      </div>
    </div>
  </div>

  <div class="modal fade" id="deleteAssetModal" tabindex="-1">
      <div class="modal-dialog modal-sm modal-dialog-centered">
          <div class="modal-content border-0 shadow">
              <form action="tasks" method="POST">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="delete_asset">
                  <input type="hidden" name="asset_id" id="delete_asset_id">
                  <div class="modal-header bg-danger text-white">
                      <h6 class="modal-title m-0"><i class="bi bi-exclamation-triangle-fill me-2"></i>Datei löschen</h6>
                      <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                  </div>
                  <div class="modal-body text-center py-4">
                      <p class="mb-0 fw-bold">Möchtest du diese Datei wirklich endgültig löschen?</p>
                  </div>
                  <div class="modal-footer p-2 d-flex justify-content-between bg-subtle">
                      <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Abbrechen</button>
                      <button type="submit" class="btn btn-danger btn-sm px-3 fw-bold">Ja, löschen</button>
                  </div>
              </form>
          </div>
      </div>
  </div>

  <div class="modal fade" id="deleteMilestoneModal" tabindex="-1">
      <div class="modal-dialog modal-sm modal-dialog-centered">
          <div class="modal-content border-0 shadow">
              <form action="tasks" method="POST">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="delete_milestone">
                  <input type="hidden" name="milestone_id" id="delete_milestone_id">
                  <div class="modal-header bg-danger text-white">
                      <h6 class="modal-title m-0"><i class="bi bi-exclamation-triangle-fill me-2"></i>Meilenstein löschen</h6>
                      <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                  </div>
                  <div class="modal-body text-center py-4">
                      <p class="mb-0 fw-bold">Möchtest du diesen Meilenstein wirklich löschen?</p>
                  </div>
                  <div class="modal-footer p-2 d-flex justify-content-between bg-subtle">
                      <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Abbrechen</button>
                      <button type="submit" class="btn btn-danger btn-sm px-3 fw-bold">Ja, löschen</button>
                  </div>
              </form>
          </div>
      </div>
  </div>

  <!-- MEILENSTEIN BENACHRICHTIGUNGS-MODAL -->
  <div class="modal fade" id="msNotifyModal" tabindex="-1" data-bs-backdrop="static">
      <div class="modal-dialog modal-sm modal-dialog-centered">
          <div class="modal-content border-0 shadow">
              <div class="modal-header border-0 pb-0">
                  <h6 class="modal-title fw-bold"><i class="bi bi-envelope-fill text-primary me-2"></i>Kunde benachrichtigen?</h6>
              </div>
              <div class="modal-body text-center py-3">
                  <p class="mb-0 text-muted small">Soll der Kunde per E-Mail über den abgeschlossenen Meilenstein informiert werden?</p>
              </div>
              <div class="modal-footer p-2 d-flex gap-2 justify-content-center border-0 bg-subtle">
                  <button type="button" class="btn btn-primary btn-sm px-4 fw-bold" id="msNotifyYes"><i class="bi bi-send me-1"></i>Ja, senden</button>
                  <button type="button" class="btn btn-outline-secondary btn-sm px-4" id="msNotifyNo">Nein</button>
              </div>
          </div>
      </div>
  </div>

  <div class="modal fade" id="deleteConfirmModal" tabindex="-1">
      <div class="modal-dialog modal-sm modal-dialog-centered">
          <div class="modal-content border-0 shadow">
              <form action="tasks" method="POST">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="delete_task">
                  <input type="hidden" name="task_id" id="delete_confirm_id">
                  <div class="modal-header bg-danger text-white">
                      <h6 class="modal-title m-0"><i class="bi bi-exclamation-triangle-fill me-2"></i>Projekt löschen</h6>
                      <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                  </div>
                  <div class="modal-body text-center py-4">
                      <p class="mb-0 fw-bold">Möchtest du dieses Projekt wirklich endgültig löschen?</p>
                  </div>
                  <div class="modal-footer p-2 d-flex justify-content-between bg-subtle">
                      <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Abbrechen</button>
                      <button type="submit" class="btn btn-danger btn-sm px-3 fw-bold">Ja, löschen</button>
                  </div>
              </form>
          </div>
      </div>
  </div>
  
  <div class="modal fade" id="invoiceModal" tabindex="-1" data-bs-backdrop="static" data-bs-keyboard="false">
    <div class="modal-dialog modal-xl">
      <div class="modal-content border-0 shadow">
        <form action="invoice" method="POST" target="_blank">
          <input type="hidden" name="contact_id" id="inv_contact_id">
          
          <div class="modal-header bg-dark text-white"><h5><i class="bi bi-file-earmark-pdf me-2"></i> Rechnung konfigurieren</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div>
          <div class="modal-body p-4 bg-subtle">
            <div class="row mb-4">
              <div class="col-md-6">
                <div class="card border-0 shadow-sm p-3 h-100">
                  <h6 class="fw-bold text-primary mb-2">Absender (Meine Daten)</h6>
                  <input type="text" name="sender_name" class="form-control form-control-sm mb-1" value="<?= COMPANY_NAME ?>">
                  <input type="text" name="sender_street" class="form-control form-control-sm mb-1" placeholder="Straße & Hausnr. (optional)">
                  <input type="text" name="sender_city" class="form-control form-control-sm mb-1" placeholder="PLZ & Ort (optional)">
                  <input type="text" name="sender_email" class="form-control form-control-sm mb-1" value="<?= ADMIN_EMAIL ?>">
                  <input type="text" name="sender_website" class="form-control form-control-sm mb-1" value="<?= str_replace(['http://', 'https://', 'www.'], '', MAIN_WEBSITE) ?>">
                  <input type="text" name="sender_line1" class="form-control form-control-sm mb-1 border-info-subtle" placeholder="Zusatzzeile 1 (z.B. Steuernummer)">
                  <input type="text" name="sender_line2" class="form-control form-control-sm mb-1 border-info-subtle" placeholder="Zusatzzeile 2 (Optional)">
                </div>
              </div>
              <div class="col-md-6">
                <div class="card border-0 shadow-sm p-3 h-100">
                  <h6 class="fw-bold text-primary mb-2">Empfänger (Kunde)</h6>
                  
                  <select class="form-select form-select-sm mb-2" onchange="autoFillInv(this)">
                      <option value="">-- Kunde aus CRM laden --</option>
                      <?php foreach($all_contacts as $c): ?>
                          <option value="<?=$c['id']?>" data-name="<?=$c['company']?:$c['name']?>" data-street="<?=$c['street']?>" data-city="<?=$c['zip'].' '.$c['city']?>"><?=$c['name']?></option>
                      <?php endforeach; ?>
                  </select>

                  <input type="text" name="client_name" id="inv_client_name" class="form-control form-control-sm mb-1" placeholder="Name/Firma" required>
                  <input type="text" name="client_street" id="inv_client_street" class="form-control form-control-sm mb-1" placeholder="Straße">
                  <input type="text" name="client_city" id="inv_client_city" class="form-control form-control-sm mb-1" placeholder="PLZ & Ort">
                  <input type="text" name="client_country" value="Deutschland" class="form-control form-control-sm mb-1">
                  <input type="text" name="client_line1" class="form-control form-control-sm mb-1 border-info-subtle" placeholder="Zusatzzeile 1 (z.B. Abteilung)">
                  <input type="text" name="client_line2" class="form-control form-control-sm mb-1 border-info-subtle" placeholder="Zusatzzeile 2 (Optional)">
                </div>
              </div>
            </div>
            <div class="row mb-4">
              <div class="col-md-3"><label class="fw-bold small">Rechnungsnummer</label><input type="text" name="invoice_number" id="inv_number" class="form-control fw-bold text-primary" readonly></div>
              <div class="col-md-3"><label class="fw-bold small">Datum</label><input type="date" name="invoice_date" class="form-control" value="<?=date('Y-m-d')?>"></div>
              <div class="col-md-3"><label class="fw-bold small">MwSt-Regel</label><select name="tax_type" id="inv_tax" class="form-select" onchange="calcInv()"><option value="kleinunternehmer" selected>Kleinunternehmer (0%)</option><option value="regel">Regelbesteuerung (19%)</option></select></div>
              <div class="col-md-3"><label class="fw-bold small">Modalität</label><select name="installments" class="form-select"><option value="1">Einmalzahlung</option><option value="2">2 Raten</option><option value="3">3 Raten</option><option value="abo">Monatliches Abo</option></select></div>
            </div>
            
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-body">
                    <h6>Positionen</h6>
                    <div id="invoice-items-container"></div>
                    <button type="button" class="btn btn-sm btn-outline-primary mt-2" onclick="addInvoiceRow('', 1, 60)">+ Hinzufügen</button>
                    
                    <div class="mt-4 pt-3 border-top">
                        <div class="row justify-content-end text-end g-2">
                            <div class="col-md-4 col-6 fw-bold">Netto:</div><div class="col-md-3 col-6" id="inv_netto">0,00 €</div>
                        </div>
                        <div class="row justify-content-end text-end g-2" id="inv_tax_row" style="display:none;">
                            <div class="col-md-4 col-6 fw-bold">MwSt (19%):</div><div class="col-md-3 col-6" id="inv_tax_val">0,00 €</div>
                        </div>
                        <div class="row justify-content-end text-end g-2 mt-1">
                            <div class="col-md-4 col-6 fw-bold text-primary fs-5">Brutto:</div><div class="col-md-3 col-6 fw-bold text-primary fs-5"><span id="inv_total">0,00</span> €</div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="row">
                <div class="col-md-6 mb-3"><label class="fw-bold small">Bankverbindung (IBAN)</label><input type="text" name="iban" class="form-control form-control-sm" placeholder="DE12 3456..."></div>
                <div class="col-md-6 mb-3"><label class="fw-bold small">PayPal / Notiz</label><input type="text" name="paypal" class="form-control form-control-sm mb-2" placeholder="PayPal Adresse (Optional)"><textarea name="notes" class="form-control form-control-sm" placeholder="Z.B. Vielen Dank für das Vertrauen!" rows="2"></textarea></div>
            </div>
          </div>
          <div class="modal-footer"><button type="submit" class="btn btn-primary px-4 fw-bold" onclick="setTimeout(()=>window.location.reload(), 1500)">PDF erstellen & verbuchen</button></div>
        </form>
      </div>
    </div>
  </div>

  <script>
    // OPTISCHES FEEDBACK FÜR AUFKLAPP-MENÜS
    document.addEventListener('DOMContentLoaded', () => {
        document.querySelectorAll('.collapse').forEach(collapseEl => {
            collapseEl.addEventListener('show.bs.collapse', function () {
                let btn = document.querySelector('[data-bs-target="#' + this.id + '"]');
                if(btn) {
                    if(this.id.startsWith('fb_')) {
                        btn.classList.remove('btn-outline-warning');
                        btn.classList.add('btn-warning', 'text-dark', 'fw-bold');
                    }
                    if(this.id.startsWith('up_')) {
                        btn.classList.remove('btn-outline-info');
                        btn.classList.add('btn-info', 'text-dark', 'fw-bold');
                    }
                }
            });
            collapseEl.addEventListener('hide.bs.collapse', function () {
                let btn = document.querySelector('[data-bs-target="#' + this.id + '"]');
                if(btn) {
                    if(this.id.startsWith('fb_')) {
                        btn.classList.add('btn-outline-warning');
                        btn.classList.remove('btn-warning', 'fw-bold');
                    }
                    if(this.id.startsWith('up_')) {
                        btn.classList.add('btn-outline-info');
                        btn.classList.remove('btn-info', 'fw-bold');
                    }
                }
            });
        });
    });

    function toggleTimer(id, act) {
        const xhr = new XMLHttpRequest(); xhr.open("POST", "tasks", true); xhr.setRequestHeader("Content-Type", "application/x-www-form-urlencoded");
        xhr.onload = () => window.location.reload(); xhr.send("ajax_action="+act+"&task_id="+id);
    }
    
    const contactData = <?= json_encode(array_column($all_contacts, null, 'id')) ?>;
    
    const editModal = new bootstrap.Modal(document.getElementById('editTaskModal'));
    const deleteModal = new bootstrap.Modal(document.getElementById('deleteConfirmModal'));
    
    // NEU: Modals für Asset & Meilenstein initialisieren
    const deleteAssetModal = new bootstrap.Modal(document.getElementById('deleteAssetModal'));
    const deleteMilestoneModal = new bootstrap.Modal(document.getElementById('deleteMilestoneModal'));

    // NEU: Funktionen zum Aufrufen der neuen Lösch-Modals
    function openDeleteAssetModal(id) {
        document.getElementById('delete_asset_id').value = id;
        deleteAssetModal.show();
    }

    function openDeleteMilestoneModal(id) {
        document.getElementById('delete_milestone_id').value = id;
        deleteMilestoneModal.show();
    }
    
    function openEditModal(task) {
        document.getElementById('e_id').value = task.id;
        document.getElementById('e_title').value = task.title;
        document.getElementById('e_cat').value = task.category;
        document.getElementById('e_desc').value = task.description;
        document.getElementById('e_contact').value = task.contact_id;
        
        let sd = task.start_date ? task.start_date.split(' ')[0] : '';
        let dd = task.deadline ? task.deadline.split(' ')[0] : '';
        document.getElementById('e_start').value = sd;
        document.getElementById('e_deadline').value = dd;
        
        const feedbackEl = document.getElementById('e_feedback');
        if (task.client_feedback) {
            feedbackEl.textContent = task.client_feedback;
            feedbackEl.innerHTML = feedbackEl.innerHTML.replace(/\n/g, '<br>');
        } else {
            feedbackEl.textContent = 'Kein Feedback.';
        }
        
        let assetHtml = '';
        if(task.assets.length > 0) {
            task.assets.forEach(a => {
                let badge = (a.uploaded_by === 'admin') 
                    ? '<span class="badge bg-primary me-2" style="font-size:9px; padding:3px 5px;">Admin</span>' 
                    : '<span class="badge bg-secondary me-2" style="font-size:9px; padding:3px 5px;">Kunde</span>';
                    
                assetHtml += `<div class="d-flex justify-content-between align-items-center mb-1 bg-surface p-2 rounded border small shadow-sm"><span class="text-truncate d-flex align-items-center" style="max-width: 70%;">${badge} ${a.file_name}</span><div class="d-flex gap-2"><a href="${a.file_path}" download><i class="bi bi-download"></i></a><button type="button" class="btn btn-link text-danger p-0 shadow-none" onclick="openDeleteAssetModal(${a.id})"><i class="bi bi-trash"></i></button></div></div>`;
            });
        } else { assetHtml = '<span class="small text-muted">Keine Dokumente.</span>'; }
        document.getElementById('e_assets').innerHTML = assetHtml;
        
        document.getElementById('adminAssetUpload').value = '';
        document.getElementById('adminUploadProgressContainer').style.display = 'none';
        
        editModal.show();
    }

    // AJAX Upload Logik
    document.getElementById('adminAssetUpload').addEventListener('change', function() {
        const files = this.files; 
        if(files.length === 0) return;

        const taskId = document.getElementById('e_id').value;
        if(!taskId) {
            alert("Bitte speichern Sie das Projekt zuerst, bevor Sie Dateien hochladen!");
            this.value = '';
            return;
        }

        const fd = new FormData(); 
        fd.append('ajax_action', 'admin_upload_asset');
        fd.append('task_id', taskId);

        const MAX_SIZE = 100 * 1024 * 1024; // 100MB

        for(let f of files) {
            let ext = f.name.split('.').pop().toLowerCase();
            let forbidden = ['php', 'phtml', 'exe', 'sh', 'js', 'html', 'htm'];
            if(forbidden.includes(ext)) {
                alert(`Dateityp .${ext} ist aus Sicherheitsgründen nicht erlaubt!`);
                this.value = '';
                return;
            }
            if (f.size > MAX_SIZE) {
                alert(`Die Datei "${f.name}" ist zu groß (max. 100MB).`);
                this.value = ''; 
                return;
            }
            fd.append('admin_assets[]', f);
        }

        document.getElementById('adminUploadProgressContainer').style.display = 'flex';
        let pBar = document.getElementById('adminUploadProgressBar');
        pBar.classList.replace('bg-success', 'bg-primary');
        
        const xhr = new XMLHttpRequest();
        xhr.open('POST', 'tasks', true);
        
        xhr.upload.onprogress = (e) => { 
            if(e.lengthComputable) {
                let percent = Math.round((e.loaded / e.total) * 100);
                pBar.style.width = percent + '%';
                pBar.innerText = percent + '%';
            } 
        };

        xhr.onload = () => { 
            if(xhr.status === 200) {
                pBar.classList.replace('bg-primary', 'bg-success');
                pBar.innerText = 'Upload erfolgreich!';
                
                let html = xhr.responseText.trim();
                let container = document.getElementById('e_assets');
                if(container.innerHTML.includes('Keine Dokumente')) {
                    container.innerHTML = '';
                }
                container.innerHTML += html;
                
                setTimeout(() => {
                    document.getElementById('adminUploadProgressContainer').style.display = 'none';
                    pBar.style.width = '0%';
                    pBar.innerText = '';
                    document.getElementById('adminAssetUpload').value = '';
                }, 2000);

            } else {
                alert('Fehler beim Upload!');
                document.getElementById('adminUploadProgressContainer').style.display = 'none';
            }
        };
        xhr.send(fd);
    });

    function openDeleteModal(id) {
        document.getElementById('delete_confirm_id').value = id;
        deleteModal.show();
    }

    function triggerDeleteFromEdit() {
        let id = document.getElementById('e_id').value;
        editModal.hide();
        openDeleteModal(id);
    }

    function addInvoiceRow(desc = '', qty = 1, price = 60) {
      const container = document.getElementById('invoice-items-container');
      const row = document.createElement('div'); row.className = 'row g-2 mb-2 pb-2 border-bottom inv-item-row';
      row.innerHTML = `<div class="col-7"><input type="text" name="item_desc[]" class="form-control form-control-sm" value="${desc}"></div><div class="col-2"><input type="number" step="0.01" name="item_qty[]" class="form-control form-control-sm inv-qty" value="${qty}" oninput="calcInv()"></div><div class="col-2"><input type="number" step="0.01" name="item_price[]" class="form-control form-control-sm inv-price" value="${price}" oninput="calcInv()"></div><div class="col-1"><button type="button" class="btn btn-sm btn-danger" onclick="this.parentElement.parentElement.remove(); calcInv();">X</button></div>`;
      container.appendChild(row);
      calcInv();
    }

    function calcInv() {
        let netto = 0; 
        document.querySelectorAll('.inv-item-row').forEach(r => {
            netto += (parseFloat(r.querySelector('.inv-qty').value)||0) * (parseFloat(r.querySelector('.inv-price').value)||0);
        });
        let tax = (document.getElementById('inv_tax').value === 'regel') ? netto * 0.19 : 0;
        document.getElementById('inv_tax_row').style.display = tax > 0 ? 'flex' : 'none';
        document.getElementById('inv_netto').innerText = netto.toLocaleString('de-DE', {minimumFractionDigits:2}) + ' €';
        document.getElementById('inv_tax_val').innerText = tax.toLocaleString('de-DE', {minimumFractionDigits:2}) + ' €';
        document.getElementById('inv_total').innerText = (netto + tax).toLocaleString('de-DE', {minimumFractionDigits:2});
    }

    document.querySelectorAll('.invoice-btn').forEach(btn => {
      btn.addEventListener('click', function() {
          const cId = this.getAttribute('data-contact-id');
          const d = new Date(); 
          
          const timestamp = d.getFullYear().toString() + 
                            (d.getMonth()+1).toString().padStart(2, '0') + 
                            d.getDate().toString().padStart(2, '0') + "-" + 
                            d.getHours().toString().padStart(2, '0') + 
                            d.getMinutes().toString().padStart(2, '0') + 
                            d.getSeconds().toString().padStart(2, '0');
                            
          document.getElementById('inv_number').value = "RE-" + timestamp;
          document.getElementById('inv_contact_id').value = cId || '';
          
          document.getElementById('inv_client_name').value = '';
          document.getElementById('inv_client_street').value = '';
          document.getElementById('inv_client_city').value = '';

          if(cId && contactData[cId]) {
              const c = contactData[cId];
              document.getElementById('inv_client_name').value = c.company || c.name;
              document.getElementById('inv_client_street').value = c.street || '';
              document.getElementById('inv_client_city').value = (c.zip || '') + " " + (c.city || '');
          }
          document.getElementById('invoice-items-container').innerHTML = '';
          addInvoiceRow("Service: " + this.getAttribute('data-task-title'), this.getAttribute('data-hours'), 60);
      });
    });

    // MEILENSTEIN-BENACHRICHTIGUNGS-LOGIK
    const msNotifyModal = new bootstrap.Modal(document.getElementById('msNotifyModal'));
    let _pendingMsForm = null;
    const msNotifyEnabled = <?= setting('notify_milestone_email', '1') === '1' ? 'true' : 'false' ?>;

    document.querySelectorAll('.ms-toggle-form').forEach(form => {
        form.addEventListener('submit', function(e) {
            const completing  = this.dataset.completing === '1';
            const hasEmail    = this.dataset.hasEmail === '1';

            if (completing && hasEmail && msNotifyEnabled) {
                e.preventDefault();
                _pendingMsForm = this;
                msNotifyModal.show();
            }
            // Ohne E-Mail oder beim Rückgängigmachen: direkt submitten (notify_client bleibt 0)
            else if (!completing) {
                this.querySelector('.notify-input').value = '0';
            }
        });
    });

    document.getElementById('msNotifyYes').addEventListener('click', () => {
        if (_pendingMsForm) {
            _pendingMsForm.querySelector('.notify-input').value = '1';
            msNotifyModal.hide();
            _pendingMsForm.submit();
        }
    });

    document.getElementById('msNotifyNo').addEventListener('click', () => {
        if (_pendingMsForm) {
            _pendingMsForm.querySelector('.notify-input').value = '0';
            msNotifyModal.hide();
            _pendingMsForm.submit();
        }
    });

    function toggleMsComments(msId) {
        const thread = document.getElementById('ms-thread-' + msId);
        if (thread) thread.classList.toggle('d-none');
    }

    function sendAdminComment(msId) {
        const input = document.getElementById('ms-reply-' + msId);
        const msg   = input ? input.value.trim() : '';
        if (!msg) return;
        const fd = new FormData();
        fd.append('ajax_action', 'add_admin_ms_comment');
        fd.append('milestone_id', msId);
        fd.append('message', msg);
        fetch('tasks', { method: 'POST', body: fd })
            .then(r => r.json())
            .then(data => {
                if (!data.ok) return;
                const bubbles = document.getElementById('ms-bubbles-' + msId);
                const div = document.createElement('div');
                div.className = 'ms-com-bubble admin';
                div.innerHTML = '<div class="ms-com-meta">' + data.author_name + ' &bull; ' + data.created_at + '</div><div>' + data.message.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;') + '</div>';
                bubbles.appendChild(div);
                input.value = '';
                // Update toggle count badge
                const thread = document.getElementById('ms-thread-' + msId);
                const toggleLink = thread.closest('.border-bottom').querySelector('.ms-com-toggle');
                if (toggleLink) {
                    const count = bubbles.querySelectorAll('.ms-com-bubble').length;
                    toggleLink.innerHTML = '<i class="bi bi-chat-dots"></i> ' + count;
                } else {
                    // No toggle link existed (no comments before) — add one
                    const actionsDiv = thread.closest('.border-bottom').querySelector('.d-flex.align-items-center.gap-1');
                    const a = document.createElement('a');
                    a.className = 'ms-com-toggle';
                    a.setAttribute('onclick', 'toggleMsComments(' + msId + ')');
                    a.innerHTML = '<i class="bi bi-chat-dots"></i> 1';
                    actionsDiv.insertBefore(a, actionsDiv.firstChild);
                }
            });
    }
  </script>

  <!-- ══════════ BETEILIGTE ══════════ -->
  <div class="modal fade" id="membersModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
      <div class="modal-content">
        <div class="modal-header">
          <h6 class="modal-title m-0 fw-bold"><i class="bi bi-people me-2"></i>Beteiligte am Projekt</h6>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="fw-bold text-strong-c mb-1" id="mm_title"></div>
          <p class="text-muted small">
            Jeder Beteiligte sieht das Projekt in seinem eigenen Portal — mit eigenem
            Zugangslink und eigener PIN. So lässt sich einzeln entziehen, und jede
            Handlung im Portal trägt einen Namen.
          </p>

          <div id="mm_list" class="mb-3"></div>

          <form method="POST" class="d-flex gap-2 flex-wrap align-items-end">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="add_task_contact">
            <input type="hidden" name="task_id" id="mm_task_add">
            <input type="hidden" name="back_q" value="<?= htmlspecialchars($search_query, ENT_QUOTES) ?>">
            <div style="flex:1 1 220px;min-width:0;">
              <label class="fw-bold small mb-1" for="mm_contact">Person hinzufügen</label>
              <select name="contact_id" id="mm_contact" class="form-select form-select-sm" required>
                <option value="">Kontakt wählen …</option>
                <?php foreach($all_contacts as $c): ?>
                  <option value="<?= (int)$c['id'] ?>">
                    <?= htmlspecialchars($c['name']) ?><?= $c['company'] ? ' · ' . htmlspecialchars($c['company']) : '' ?>
                    <?= $c['contact_type'] === 'Geschäftspartner' ? ' (Partner)' : '' ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
            <button class="btn btn-primary btn-sm fw-bold"><i class="bi bi-plus-lg me-1"></i>Hinzufügen</button>
          </form>
          <div class="form-text mt-2">
            Ohne Portal-Zugang sieht die Person nichts — den Zugang vergeben Sie
            unter <a href="contacts">Kontakte</a>.
          </div>
        </div>
      </div>
    </div>
  </div>

  <script>
  /* Die Beteiligten stehen bereits im Seitenquelltext - das Fenster baut
     seine Liste daraus, ohne weitere Anfrage. */
  const TASK_MEMBERS = <?= json_encode($task_members, JSON_HEX_TAG|JSON_HEX_APOS) ?>;
  const MEMBERS_CSRF = <?= json_encode(csrf_token(), JSON_HEX_TAG|JSON_HEX_APOS) ?>;
  const MEMBERS_BACK = <?= json_encode($search_query, JSON_HEX_TAG|JSON_HEX_APOS) ?>;

  function openMembers(taskId, titel) {
      document.getElementById('mm_title').textContent = titel;
      document.getElementById('mm_task_add').value = taskId;
      const liste = document.getElementById('mm_list');
      liste.textContent = '';

      const leute = TASK_MEMBERS[taskId] || [];
      if (!leute.length) {
          const p = document.createElement('p');
          p.className = 'text-muted small mb-0';
          p.textContent = 'Noch niemand zugeordnet.';
          liste.appendChild(p);
          return;
      }

      leute.forEach(function (m) {
          const zeile = document.createElement('div');
          zeile.className = 'd-flex align-items-center justify-content-between gap-2 py-2 border-bottom border-subtle-c';

          const links = document.createElement('div');
          links.className = 'min-w-0';
          const name = document.createElement('div');
          name.className = 'fw-semibold text-strong-c';
          name.textContent = m.name;
          const meta = document.createElement('div');
          meta.className = 'text-muted';
          meta.style.fontSize = 'var(--text-2xs)';
          meta.textContent = (m.role === 'owner' ? 'Hauptansprechpartner' : 'Beteiligt')
                           + (m.company ? ' · ' + m.company : '')
                           + (m.portal_token ? '' : ' · kein Portal-Zugang');
          links.appendChild(name); links.appendChild(meta);

          zeile.appendChild(links);

          if (m.role !== 'owner') {
              const f = document.createElement('form');
              f.method = 'POST';
              f.className = 'm-0';
              f.onsubmit = function () { return confirm(m.name + ' aus diesem Projekt entfernen?'); };
              [['csrf_token', MEMBERS_CSRF], ['action', 'remove_task_contact'],
               ['task_id', taskId], ['contact_id', m.contact_id], ['back_q', MEMBERS_BACK]
              ].forEach(function (kv) {
                  const i = document.createElement('input');
                  i.type = 'hidden'; i.name = kv[0]; i.value = kv[1];
                  f.appendChild(i);
              });
              const b = document.createElement('button');
              b.className = 'btn btn-sm btn-icon text-danger';
              b.title = 'Entfernen';
              b.innerHTML = '<i class="bi bi-x-lg"></i>';
              f.appendChild(b);
              zeile.appendChild(f);
          }
          liste.appendChild(zeile);
      });
  }
  </script>

<script>
function toggleTalk(id) {
    var el = document.getElementById('talk-' + id);
    if (el) el.classList.toggle('d-none');
}
</script>
<?php require 'includes/layout_end.php'; ?>