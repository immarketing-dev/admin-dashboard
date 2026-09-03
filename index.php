<?php
require_once 'config.php';
require_once 'includes/auth.php';

// ==========================================
// AKTIONEN (Inbox, Portal & Monitoring)
// ==========================================
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    csrf_check();

    // 1. Posteingang (Leads)
    if (isset($_POST['inbox_action'])) {
        $action = $_POST['inbox_action'];
        $lead_id = (int)$_POST['lead_id'];

        if ($action === 'accept_lead') {
            $stmt = $pdo->prepare("SELECT * FROM leads_inbox WHERE id = ?");
            $stmt->execute([$lead_id]);
            $lead = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($lead) {
                $phone_note = !empty($lead['phone']) ? "Telefon: " . $lead['phone'] . "\n" : "";
                $notes = "Betreff: " . $lead['subject'] . "\n" . $phone_note . "\nNachricht:\n" . $lead['message'];
                
                $insert = $pdo->prepare("INSERT INTO contacts (name, email, phone, contact_type, source, notes) VALUES (?, ?, ?, 'Interessent', ?, ?)");
                $insert->execute([$lead['name'], $lead['email'], $lead['phone'] ?? null, $lead['source'], $notes]);
                
                $pdo->prepare("INSERT INTO logs (action_type, description) VALUES ('LEAD_ACCEPTED', ?)")->execute(["Neue Anfrage von " . $lead['name'] . " ins CRM übernommen."]);
                
                $pdo->prepare("DELETE FROM leads_inbox WHERE id = ?")->execute([$lead_id]);
            }
        } 
        elseif ($action === 'delete_lead') {
            $stmt = $pdo->prepare("SELECT name, subject FROM leads_inbox WHERE id = ?");
            $stmt->execute([$lead_id]);
            $del_lead = $stmt->fetch(PDO::FETCH_ASSOC);

            $pdo->prepare("DELETE FROM leads_inbox WHERE id = ?")->execute([$lead_id]);
            
            if ($del_lead) {
                $pdo->prepare("INSERT INTO logs (action_type, description) VALUES ('LEAD_REJECTED', ?)")
                    ->execute(["Anfrage von '" . $del_lead['name'] . "' (" . $del_lead['subject'] . ") wurde gelöscht."]);
            }
        }
    }

    // 2. Portal Aktivitäten ausblenden
    if (isset($_POST['dismiss_portal_activity'])) {
        $type = $_POST['activity_type'];
        $id   = (int)$_POST['activity_id'];
        if ($type === 'upload')       $pdo->prepare("UPDATE client_assets SET dashboard_seen = 1 WHERE id = ?")->execute([$id]);
        elseif ($type === 'approval') $pdo->prepare("UPDATE task_milestones SET approval_seen = 1 WHERE id = ?")->execute([$id]);
        elseif ($type === 'feedback') $pdo->prepare("UPDATE tasks SET feedback_seen = 1 WHERE id = ?")->execute([$id]);
        elseif ($type === 'ms_comment') { try { $pdo->prepare("UPDATE milestone_comments SET admin_seen = 1 WHERE id = ?")->execute([$id]); } catch (PDOException $e) {} }
    }

    // 3. Monitoring URLs
    if (isset($_POST['monitor_action'])) {
        if ($_POST['monitor_action'] === 'add_url') {
            $link = trim($_POST['url_link']);
            $name = trim($_POST['url_name']);
            if (!preg_match("~^(?:f|ht)tps?://~i", $link)) $link = "https://" . $link;
            
            $pdo->prepare("INSERT INTO monitored_urls (url_name, url_link) VALUES (?, ?)")->execute([$name, $link]);
            
            $pdo->prepare("INSERT INTO logs (action_type, description) VALUES ('MONITOR_ADDED', ?)")
                ->execute(["URL '$name' zum System-Monitor hinzugefügt."]);
        } 
        elseif ($_POST['monitor_action'] === 'delete_url') {
            $url_id = (int)$_POST['url_id'];
            
            $stmt = $pdo->prepare("SELECT url_name FROM monitored_urls WHERE id = ?");
            $stmt->execute([$url_id]);
            $url_name = $stmt->fetchColumn();

            $pdo->prepare("DELETE FROM monitored_urls WHERE id = ?")->execute([$url_id]);
            
            if ($url_name) {
                $pdo->prepare("INSERT INTO logs (action_type, description) VALUES ('MONITOR_DELETED', ?)")
                    ->execute(["URL '$url_name' aus dem System-Monitor entfernt."]);
            }
        }
    }
    
    header("Location: index"); exit();
}

// ==========================================
// PARALLELER UPTIME CHECK (curl_multi)
// ==========================================
function getParallelSiteStatuses(array $urls): array {
    if (empty($urls)) return [];

    $mh         = curl_multi_init();
    $handles    = [];
    $start_times = [];

    foreach ($urls as $key => $url_row) {
        $ch = curl_init($url_row['url_link']);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_NOBODY         => true,
            CURLOPT_TIMEOUT        => 6,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_USERAGENT      => 'Mozilla/5.0 AdminMonitor/1.0',
        ]);
        $start_times[$key] = microtime(true);
        $handles[$key]     = $ch;
        curl_multi_add_handle($mh, $ch);
    }

    $running = null;
    do {
        curl_multi_exec($mh, $running);
        curl_multi_select($mh, 0.1);
    } while ($running > 0);

    $results = [];
    foreach ($handles as $key => $ch) {
        $code      = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err       = curl_error($ch);
        $time_ms   = round((microtime(true) - $start_times[$key]) * 1000);
        $is_online = ($code >= 200 && $code < 400);

        $results[$key] = [
            'online' => $is_online,
            'code'   => $code,
            'time'   => $time_ms,
            'error'  => $err,
        ];
        curl_multi_remove_handle($mh, $ch);
        curl_close($ch);
    }
    curl_multi_close($mh);

    return $results;
}

// ── AJAX WIDGET PARTIALS ─────────────────────────────────────────────────────
if (isset($_GET['ajax_widget'])) {
    $aw = $_GET['ajax_widget'];
    header('Cache-Control: no-store, no-cache');

    if ($aw === 'portal_activity') {
        header('Content-Type: text/html; charset=utf-8');
        $portal_uploads   = $pdo->query("SELECT ca.*, t.title as task_title, c.name as client_name FROM client_assets ca JOIN tasks t ON ca.task_id = t.id JOIN contacts c ON t.contact_id = c.id WHERE ca.dashboard_seen = 0 AND (ca.uploaded_by IS NULL OR ca.uploaded_by = 'client') ORDER BY ca.uploaded_at DESC")->fetchAll(PDO::FETCH_ASSOC);
        $portal_approvals = $pdo->query("SELECT tm.*, t.title as task_title, c.name as client_name FROM task_milestones tm JOIN tasks t ON tm.task_id = t.id JOIN contacts c ON t.contact_id = c.id WHERE tm.approved_at IS NOT NULL AND tm.approval_seen = 0 ORDER BY tm.approved_at DESC")->fetchAll(PDO::FETCH_ASSOC);
        $portal_feedbacks = $pdo->query("SELECT t.id, t.title, t.client_feedback, c.name as client_name FROM tasks t JOIN contacts c ON t.contact_id = c.id WHERE t.client_feedback IS NOT NULL AND t.client_feedback != '' AND t.feedback_seen = 0")->fetchAll(PDO::FETCH_ASSOC);
        $portal_ms_comments = [];
        try { $portal_ms_comments = $pdo->query("SELECT mc.id, mc.message, mc.author_name, tm.title AS ms_title, t.title AS task_title, c.name AS client_name FROM milestone_comments mc JOIN task_milestones tm ON mc.milestone_id = tm.id JOIN tasks t ON tm.task_id = t.id JOIN contacts c ON t.contact_id = c.id WHERE mc.author = 'client' AND mc.admin_seen = 0 ORDER BY mc.created_at DESC LIMIT 20")->fetchAll(PDO::FETCH_ASSOC); } catch (PDOException $e) {}
        ?>
        <div class="col-md-3">
            <h6 class="small fw-bold text-muted mb-2" style="font-size:11px;border-bottom:1px solid #eee;padding-bottom:5px;">UPLOADS</h6>
            <?php if(count($portal_uploads) > 0): foreach($portal_uploads as $u): ?>
            <div class="position-relative bg-white border rounded-3 p-3 mb-2 shadow-sm portal-item-hover" style="border-left:4px solid var(--color-primary) !important;">
                <form method="POST" class="position-absolute" style="top:5px;right:5px;"><?= csrf_field() ?><input type="hidden" name="activity_type" value="upload"><input type="hidden" name="activity_id" value="<?=$u['id']?>"><button type="submit" name="dismiss_portal_activity" class="btn-close" style="font-size:.65rem;" title="Ausblenden"></button></form>
                <a href="tasks?q=<?=urlencode($u['task_title'])?>" class="text-decoration-none d-block pe-3">
                    <span class="badge bg-primary bg-opacity-10 text-primary mb-2" style="font-size:9px;letter-spacing:.5px;">DATEI</span>
                    <div class="fw-bold text-dark text-truncate mb-1" style="font-size:13px;"><?=htmlspecialchars($u['file_name'])?></div>
                    <div class="text-muted small text-truncate"><i class="bi bi-person"></i> <?=htmlspecialchars($u['client_name'])?></div>
                </a>
            </div>
            <?php endforeach; else: ?>
            <div class="text-muted small p-2 bg-light rounded text-center border mt-2"><i class="bi bi-file-earmark-check d-block mb-1" style="font-size:1.2rem;color:#ced4da;"></i><span style="font-size:10px;">Keine Uploads</span></div>
            <?php endif; ?>
        </div>
        <div class="col-md-3">
            <h6 class="small fw-bold text-muted mb-2" style="font-size:11px;border-bottom:1px solid #eee;padding-bottom:5px;">ABSEGNUNGEN</h6>
            <?php if(count($portal_approvals) > 0): foreach($portal_approvals as $a): ?>
            <div class="position-relative bg-white border rounded-3 p-3 mb-2 shadow-sm portal-item-hover" style="border-left:4px solid #28a745 !important;">
                <form method="POST" class="position-absolute" style="top:5px;right:5px;"><?= csrf_field() ?><input type="hidden" name="activity_type" value="approval"><input type="hidden" name="activity_id" value="<?=$a['id']?>"><button type="submit" name="dismiss_portal_activity" class="btn-close" style="font-size:.65rem;" title="Ausblenden"></button></form>
                <a href="tasks?q=<?=urlencode($a['task_title'])?>" class="text-decoration-none d-block pe-3">
                    <span class="badge bg-success bg-opacity-10 text-success mb-2" style="font-size:9px;letter-spacing:.5px;">BESTÄTIGT</span>
                    <div class="fw-bold text-dark text-truncate mb-1" style="font-size:13px;"><?=htmlspecialchars($a['title'])?></div>
                    <div class="text-muted small text-truncate"><i class="bi bi-person"></i> <?=htmlspecialchars($a['client_name'])?></div>
                </a>
            </div>
            <?php endforeach; else: ?>
            <div class="text-muted small p-2 bg-light rounded text-center border mt-2"><i class="bi bi-check-circle d-block mb-1" style="font-size:1.2rem;color:#ced4da;"></i><span style="font-size:10px;">Keine Absegnungen</span></div>
            <?php endif; ?>
        </div>
        <div class="col-md-3">
            <h6 class="small fw-bold text-muted mb-2" style="font-size:11px;border-bottom:1px solid #eee;padding-bottom:5px;">FEEDBACK</h6>
            <?php if(count($portal_feedbacks) > 0): foreach($portal_feedbacks as $f): ?>
            <div class="position-relative bg-white border rounded-3 p-3 mb-2 shadow-sm portal-item-hover" style="border-left:4px solid #ffc107 !important;">
                <form method="POST" class="position-absolute" style="top:5px;right:5px;"><?= csrf_field() ?><input type="hidden" name="activity_type" value="feedback"><input type="hidden" name="activity_id" value="<?=$f['id']?>"><button type="submit" name="dismiss_portal_activity" class="btn-close" style="font-size:.65rem;" title="Ausblenden"></button></form>
                <a href="tasks?q=<?=urlencode($f['title'])?>" class="text-decoration-none d-block pe-3">
                    <span class="badge bg-warning bg-opacity-10 text-dark mb-2" style="font-size:9px;letter-spacing:.5px;">NEUES FEEDBACK</span>
                    <div class="fw-bold text-dark text-truncate mb-1" style="font-size:13px;"><?=htmlspecialchars($f['title'])?></div>
                    <div class="text-muted fst-italic text-truncate" style="font-size:11px;">"<?=htmlspecialchars($f['client_feedback'])?>"</div>
                </a>
            </div>
            <?php endforeach; else: ?>
            <div class="text-muted small p-2 bg-light rounded text-center border mt-2"><i class="bi bi-chat-dots d-block mb-1" style="font-size:1.2rem;color:#ced4da;"></i><span style="font-size:10px;">Kein neues Feedback</span></div>
            <?php endif; ?>
        </div>
        <div class="col-md-3">
            <h6 class="small fw-bold text-muted mb-2" style="font-size:11px;border-bottom:1px solid #eee;padding-bottom:5px;">KOMMENTARE</h6>
            <?php if(count($portal_ms_comments) > 0): foreach($portal_ms_comments as $mc): ?>
            <div class="position-relative bg-white border rounded-3 p-3 mb-2 shadow-sm portal-item-hover" style="border-left:4px solid #6f42c1 !important;">
                <form method="POST" class="position-absolute" style="top:5px;right:5px;"><?= csrf_field() ?><input type="hidden" name="activity_type" value="ms_comment"><input type="hidden" name="activity_id" value="<?=$mc['id']?>"><button type="submit" name="dismiss_portal_activity" class="btn-close" style="font-size:.65rem;" title="Ausblenden"></button></form>
                <a href="tasks?q=<?=urlencode($mc['task_title'])?>" class="text-decoration-none d-block pe-3">
                    <span class="badge mb-2" style="background:#6f42c122;color:#6f42c1;font-size:9px;letter-spacing:.5px;">KOMMENTAR</span>
                    <div class="fw-bold text-dark text-truncate mb-1" style="font-size:13px;"><?=htmlspecialchars($mc['ms_title'])?></div>
                    <div class="text-muted fst-italic text-truncate" style="font-size:11px;">"<?=htmlspecialchars(mb_strimwidth($mc['message'],0,60,'…'))?>"</div>
                    <div class="text-muted small mt-1 text-truncate"><i class="bi bi-person"></i> <?=htmlspecialchars($mc['client_name'])?></div>
                </a>
            </div>
            <?php endforeach; else: ?>
            <div class="text-muted small p-2 bg-light rounded text-center border mt-2"><i class="bi bi-chat-dots d-block mb-1" style="font-size:1.2rem;color:#ced4da;"></i><span style="font-size:10px;">Keine Kommentare</span></div>
            <?php endif; ?>
        </div>
        <?php
        exit();
    }

    if ($aw === 'monitor') {
        header('Content-Type: text/html; charset=utf-8');
        $monitored_urls  = $pdo->query("SELECT * FROM monitored_urls ORDER BY url_name ASC")->fetchAll(PDO::FETCH_ASSOC);
        $uptime_statuses = getParallelSiteStatuses($monitored_urls);
        if (count($monitored_urls) > 0):
            foreach ($monitored_urls as $key => $url):
                $status = $uptime_statuses[$key];
                $dot_class   = 'bg-offline';
                $status_text = "Offline ($status[code])";
                if ($status['online']) {
                    if ($status['time'] > 1500) { $dot_class = 'bg-slow'; $status_text = "Langsam"; }
                    else { $dot_class = 'bg-online'; $status_text = "Online"; }
                } elseif ($status['code'] == 0) { $status_text = "Timeout/DNS"; }
        ?>
            <div class="uptime-item shadow-sm">
                <div class="uptime-header">
                    <div class="text-truncate fw-bold text-dark d-flex align-items-center" style="font-size:13px;">
                        <span class="status-dot <?=$dot_class?>"></span><?=htmlspecialchars($url['url_name'])?>
                    </div>
                    <button type="button" class="trash-btn" onclick="triggerDeleteMonitor(<?=$url['id']?>)"><i class="bi bi-trash"></i></button>
                </div>
                <div class="uptime-stats">
                    <span class="<?=!$status['online'] ? 'text-danger fw-bold' : ''?>"><i class="bi bi-activity"></i> <?=$status_text?></span>
                    <?php if($status['code'] > 0): ?><span><i class="bi bi-stopwatch"></i> <?=$status['time']?> ms</span><?php endif; ?>
                </div>
            </div>
        <?php endforeach; else: ?>
            <div class="text-muted small p-2 bg-light rounded text-center border mt-2">
                <i class="bi bi-server d-block mb-1" style="font-size:1.5rem;color:#ced4da;"></i>
                Keine URLs im Monitor.
            </div>
        <?php endif;
        exit();
    }

    exit();
}

// ==========================================
// DATEN LADEN
// ==========================================
$leads = $pdo->query("SELECT * FROM leads_inbox ORDER BY created_at ASC")->fetchAll(PDO::FETCH_ASSOC);

$tickets = $pdo->query("SELECT t.*, c.name as contact_name FROM support_tickets t JOIN contacts c ON t.contact_id = c.id WHERE t.status != 'Erledigt' ORDER BY t.created_at ASC")->fetchAll(PDO::FETCH_ASSOC);

$portal_uploads = $pdo->query("SELECT ca.*, t.title as task_title, c.name as client_name FROM client_assets ca JOIN tasks t ON ca.task_id = t.id JOIN contacts c ON t.contact_id = c.id WHERE ca.dashboard_seen = 0 AND (ca.uploaded_by IS NULL OR ca.uploaded_by = 'client') ORDER BY ca.uploaded_at DESC")->fetchAll(PDO::FETCH_ASSOC);
$portal_approvals = $pdo->query("SELECT tm.*, t.title as task_title, c.name as client_name FROM task_milestones tm JOIN tasks t ON tm.task_id = t.id JOIN contacts c ON t.contact_id = c.id WHERE tm.approved_at IS NOT NULL AND tm.approval_seen = 0 ORDER BY tm.approved_at DESC")->fetchAll(PDO::FETCH_ASSOC);
$portal_feedbacks = $pdo->query("SELECT t.id, t.title, t.client_feedback, c.name as client_name FROM tasks t JOIN contacts c ON t.contact_id = c.id WHERE t.client_feedback IS NOT NULL AND t.client_feedback != '' AND t.feedback_seen = 0")->fetchAll(PDO::FETCH_ASSOC);

$portal_ms_comments = [];
try {
    $portal_ms_comments = $pdo->query("SELECT mc.id, mc.message, mc.author_name, mc.created_at, tm.title AS ms_title, t.title AS task_title, c.name AS client_name FROM milestone_comments mc JOIN task_milestones tm ON mc.milestone_id = tm.id JOIN tasks t ON tm.task_id = t.id JOIN contacts c ON t.contact_id = c.id WHERE mc.author = 'client' AND mc.admin_seen = 0 ORDER BY mc.created_at DESC LIMIT 20")->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) { $portal_ms_comments = []; }

// Anstehende Deadlines (Projekte + Rechnungen, nächste 14 Tage + überfällig)
$upcoming_deadlines = $pdo->query("
    SELECT 'task' AS item_type, t.id, t.title, t.deadline AS due_date, c.name AS client_name, DATEDIFF(t.deadline, CURDATE()) AS days_left
    FROM tasks t LEFT JOIN contacts c ON t.contact_id = c.id
    WHERE t.status != 'Erledigt' AND t.deadline IS NOT NULL AND t.deadline > '0000-00-00'
      AND t.deadline <= DATE_ADD(CURDATE(), INTERVAL 14 DAY)
    UNION ALL
    SELECT 'invoice', f.id, f.title, f.due_date, c.name, DATEDIFF(f.due_date, CURDATE())
    FROM finances f LEFT JOIN contacts c ON f.contact_id = c.id
    WHERE f.type = 'INCOME' AND f.status IN ('Offen','Überfällig')
      AND f.due_date IS NOT NULL AND f.due_date > '0000-00-00'
      AND f.due_date <= DATE_ADD(CURDATE(), INTERVAL 14 DAY)
    ORDER BY due_date ASC LIMIT 12
")->fetchAll(PDO::FETCH_ASSOC);

$monitored_urls   = $pdo->query("SELECT * FROM monitored_urls ORDER BY url_name ASC")->fetchAll(PDO::FETCH_ASSOC);
$uptime_statuses  = getParallelSiteStatuses($monitored_urls); // alle URLs parallel prüfen
$open_tasks = $pdo->query("SELECT COUNT(*) FROM tasks WHERE status != 'Erledigt'")->fetchColumn();

// Termine heute & diese Woche
$today_appointments = [];
$week_appointments  = [];
try {
    $today_appointments = $pdo->query("
        SELECT id, title, event_date, start_time, end_time, location, meeting_url, color, status, category
        FROM calendar_events
        WHERE event_date = CURDATE() AND status != 'Abgesagt'
        ORDER BY start_time ASC, title ASC
    ")->fetchAll(PDO::FETCH_ASSOC);
    $week_appointments = $pdo->query("
        SELECT id, title, event_date, start_time, end_time, location, meeting_url, color, status, category
        FROM calendar_events
        WHERE event_date > CURDATE() AND event_date <= DATE_ADD(CURDATE(), INTERVAL 6 DAY) AND status != 'Abgesagt'
        ORDER BY event_date ASC, start_time ASC
        LIMIT 10
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {}
$total_contacts = $pdo->query("SELECT COUNT(*) FROM contacts")->fetchColumn();

// Projekt-Abfrage
$active_projects = $pdo->query("
    SELECT t.id, t.title, t.status, t.deadline, c.name as client_name
    FROM tasks t
    LEFT JOIN contacts c ON t.contact_id = c.id
    WHERE t.status NOT IN ('Erledigt','Storniert')
    ORDER BY t.created_at DESC LIMIT 8
")->fetchAll(PDO::FETCH_ASSOC);

$now = new DateTime();
$now->setTime(0, 0, 0);

// Meilensteine in einer einzigen Abfrage holen (N+1 vermieden)
$milestone_map = [];
if (!empty($active_projects)) {
    $ids          = array_column($active_projects, 'id');
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $ms_stmt      = $pdo->prepare("SELECT task_id, SUM(is_completed) AS done, COUNT(*) AS total FROM task_milestones WHERE task_id IN ($placeholders) GROUP BY task_id");
    $ms_stmt->execute($ids);
    foreach ($ms_stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $milestone_map[(int)$row['task_id']] = $row;
    }
}

foreach ($active_projects as &$project) {
    $ms_data           = $milestone_map[$project['id']] ?? null;
    $project['progress'] = $ms_data && $ms_data['total'] > 0
        ? round(($ms_data['done'] / $ms_data['total']) * 100)
        : 0;

    if (!empty($project['deadline']) && strpos($project['deadline'], '0000') === false) {
        $deadline = new DateTime($project['deadline']);
        $deadline->setTime(0, 0, 0);
        $diff = $now->diff($deadline);
        $project['days_until_deadline'] = (int)$diff->format('%R%a');
    } else {
        $project['days_until_deadline'] = null;
    }
}
unset($project);

$main_root = realpath($_SERVER['DOCUMENT_ROOT'] . '/../') ?: $_SERVER['DOCUMENT_ROOT'];
$size = 0; if (is_dir($main_root)) { foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($main_root, FilesystemIterator::SKIP_DOTS)) as $f) { $size += $f->getSize(); } }
$used_gb = round($size / (1024**3), 2);
$percent_used = max(0.1, round(($size / (200 * 1024**3)) * 100, 2));

// Zähler für Toasts
$count_leads = count($leads);
$count_tickets = count($tickets);
$count_uploads = count($portal_uploads);
$count_approvals = count($portal_approvals);
$count_feedbacks = count($portal_feedbacks);
$count_ms_comments = count($portal_ms_comments);

$page_title   = 'Dashboard';
$page_heading = 'Übersicht';
$current_page = basename($_SERVER['PHP_SELF']);
$header_actions = '
      <div class="d-flex align-items-center gap-3">
        <span id="live_indicator" title="Daten werden automatisch aktualisiert"
              style="font-size:11px;color:#6c757d;display:flex;align-items:center;gap:5px;opacity:.7;">
          <span id="live_dot" style="width:7px;height:7px;border-radius:50%;background:#28a745;display:inline-block;"></span>
          <span id="live_label">Live</span>
        </span>
        <a href="tasks" class="btn btn-outline-primary btn-sm shadow-sm fw-bold"><i class="bi bi-card-list"></i> Zu den Projekten</a>
      </div>';
$extra_head = <<<'CSS'
  <style>
      /* Toast Container Positionierung */
      .toast-container-dash {
          position: fixed;
          top: 20px;
          right: 20px;
          z-index: 1060;
      }
      
      /* Neue Projektkarten-Optik & Hover Effekte */
      .dash-project-card {
          background: #fff;
          border-radius: 12px;
          padding: 20px;
          border: 1px solid #e9ecef;
          border-left: 4px solid var(--color-primary);
          transition: all 0.3s ease;
          height: 100%;
          display: flex;
          flex-direction: column;
      }
      .dash-project-card:hover {
          box-shadow: 0 8px 25px rgba(0,0,0,0.06) !important;
          transform: translateY(-3px);
      }
      
      /* Portal Item Hover */
      .portal-item-hover:hover {
          transform: translateY(-2px);
          box-shadow: 0 6px 15px rgba(0,0,0,0.05) !important;
      }

      /* KPI Mini-Cards (Projekte / CRM) */
      .kpi-mini-card { padding: 1rem 0.5rem; }
      .kpi-icon { width: 36px; height: 36px; }
      .kpi-number { font-size: 1.8rem; }
      .kpi-label { font-size: 10px; }

      @media (max-width: 767.98px) {
        .kpi-mini-card { padding: 0.6rem 0.25rem; }
        .kpi-icon { width: 28px; height: 28px; font-size: 0.9rem !important; }
        .kpi-number { font-size: 1.3rem; }
        .kpi-label { font-size: 9px; letter-spacing: 0 !important; }
      }
  </style>
CSS;

require 'includes/head.php';
require 'includes/layout_start.php';
?>

  <div class="toast-container-dash">
      <?php if($count_leads > 0): ?>
      <div class="toast align-items-center text-bg-warning border-0 mb-2 shadow-lg" role="alert" aria-live="assertive" aria-atomic="true" data-bs-delay="6000">
          <div class="d-flex">
              <div class="toast-body fw-bold text-dark">
                  <i class="bi bi-envelope-paper-fill me-2 fs-5 align-middle"></i> 
                  <span class="align-middle"><?= $count_leads ?> neue Website-Anfrage(n)!</span>
              </div>
              <button type="button" class="btn-close btn-close-dark me-2 m-auto" data-bs-dismiss="toast"></button>
          </div>
      </div>
      <?php endif; ?>

      <?php if($count_tickets > 0): ?>
      <div class="toast align-items-center text-bg-danger border-0 mb-2 shadow-lg" role="alert" aria-live="assertive" aria-atomic="true" data-bs-delay="7000">
          <div class="d-flex">
              <div class="toast-body fw-bold text-white">
                  <i class="bi bi-life-preserver me-2 fs-5 align-middle"></i> 
                  <span class="align-middle"><?= $count_tickets ?> offene(s) Support-Ticket(s)!</span>
              </div>
              <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
          </div>
      </div>
      <?php endif; ?>

      <?php if($count_uploads > 0): ?>
      <div class="toast align-items-center text-bg-info border-0 mb-2 shadow-lg" role="alert" aria-live="assertive" aria-atomic="true" data-bs-delay="8000">
          <div class="d-flex">
              <div class="toast-body fw-bold text-dark">
                  <i class="bi bi-cloud-arrow-up-fill me-2 fs-5 align-middle"></i> 
                  <span class="align-middle"><?= $count_uploads ?> neue Datei(en) im Portal!</span>
              </div>
              <button type="button" class="btn-close btn-close-dark me-2 m-auto" data-bs-dismiss="toast"></button>
          </div>
      </div>
      <?php endif; ?>

      <?php if($count_feedbacks > 0): ?>
      <div class="toast align-items-center text-bg-warning border-0 mb-2 shadow-lg" role="alert" aria-live="assertive" aria-atomic="true" data-bs-delay="9000">
          <div class="d-flex">
              <div class="toast-body fw-bold text-dark">
                  <i class="bi bi-chat-left-text-fill me-2 fs-5 align-middle"></i> 
                  <span class="align-middle"><?= $count_feedbacks ?> neues Kunden-Feedback!</span>
              </div>
              <button type="button" class="btn-close btn-close-dark me-2 m-auto" data-bs-dismiss="toast"></button>
          </div>
      </div>
      <?php endif; ?>

      <?php if($count_ms_comments > 0): ?>
      <div class="toast align-items-center border-0 mb-2 shadow-lg" style="background:#6f42c1;color:#fff;" role="alert" aria-live="assertive" aria-atomic="true" data-bs-delay="9500">
          <div class="d-flex">
              <div class="toast-body fw-bold">
                  <i class="bi bi-chat-dots-fill me-2 fs-5 align-middle"></i>
                  <span class="align-middle"><?= $count_ms_comments ?> neue(r) Meilenstein-Kommentar(e)!</span>
              </div>
              <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
          </div>
      </div>
      <?php endif; ?>

      <?php if($count_approvals > 0): ?>
      <div class="toast align-items-center text-bg-success border-0 mb-2 shadow-lg" role="alert" aria-live="assertive" aria-atomic="true" data-bs-delay="10000">
          <div class="d-flex">
              <div class="toast-body fw-bold text-white">
                  <i class="bi bi-check-circle-fill me-2 fs-5 align-middle"></i> 
                  <span class="align-middle"><?= $count_approvals ?> abgesegnete(r) Meilenstein(e)!</span>
              </div>
              <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
          </div>
      </div>
      <?php endif; ?>
  </div>

    <div class="row g-4 mb-4 align-items-stretch">

      <!-- Projekte KPI -->
      <div class="col-6 col-xl-2">
        <a href="tasks" class="text-decoration-none d-flex h-100">
          <div class="widget-box w-100 h-100 d-flex flex-column align-items-center justify-content-center text-center kpi-mini-card" style="border-left:4px solid var(--color-primary);">
            <div class="rounded-2 d-flex align-items-center justify-content-center kpi-icon mb-2" style="background:var(--color-primary);color:#fff;font-size:1.1rem;"><i class="bi bi-briefcase"></i></div>
            <div class="fw-bold lh-1 mb-1 kpi-number" id="kpi_open_tasks" style="color:var(--color-primary);"><?=$open_tasks?></div>
            <div class="text-muted kpi-label" style="text-transform:uppercase;letter-spacing:.5px;">Projekte</div>
            <div class="small text-muted d-none d-md-block">offene Aufgaben</div>
          </div>
        </a>
      </div>

      <!-- CRM KPI -->
      <div class="col-6 col-xl-2">
        <a href="contacts" class="text-decoration-none d-flex h-100">
          <div class="widget-box w-100 h-100 d-flex flex-column align-items-center justify-content-center text-center kpi-mini-card" style="border-left:4px solid #28a745;">
            <div class="rounded-2 d-flex align-items-center justify-content-center kpi-icon mb-2" style="background:#28a745;color:#fff;font-size:1.1rem;"><i class="bi bi-people"></i></div>
            <div class="fw-bold lh-1 mb-1 kpi-number" id="kpi_total_contacts" style="color:#28a745;"><?=$total_contacts?></div>
            <div class="text-muted kpi-label" style="text-transform:uppercase;letter-spacing:.5px;">CRM</div>
            <div class="small text-muted d-none d-md-block">Kontakte</div>
          </div>
        </a>
      </div>

      <!-- Website-Anfragen -->
      <div class="col-12 col-md-6 col-xl-4">
        <div class="widget-box h-100" style="border-left: 4px solid #ffc107;">
           <div class="widget-title"><span><i class="bi bi-envelope-paper-fill text-warning me-2"></i> Neue Website-Anfragen</span></div>
           
           <div id="leads_widget_body">
           <?php if(count($leads) > 0): ?>
               <div class="list-group scroll-container">
                 <?php foreach($leads as $lead): ?>
                   <div class="list-group-item d-flex justify-content-between align-items-center py-2 lead-item" onclick='openLeadModal(<?=json_encode($lead, JSON_HEX_TAG|JSON_HEX_APOS)?>)'>
                     <div class="flex-grow-1 pe-3">
                       <h6 class="mb-1 fw-bold fs-6 text-dark"><?php echo htmlspecialchars($lead['name']); ?></h6>
                       <p class="mb-0 text-muted small text-truncate" style="font-size:11px; max-width: 200px;"><?php echo htmlspecialchars($lead['subject']); ?></p>
                     </div>
                     <div class="d-flex gap-2">
                       <button type="button" class="btn btn-sm btn-outline-danger" onclick="event.stopPropagation(); triggerDeleteLead(<?php echo $lead['id']; ?>)"><i class="bi bi-trash" style="pointer-events:none;"></i></button>
                     </div>
                   </div>
                 <?php endforeach; ?>
               </div>
           <?php else: ?>
               <div class="text-muted small p-2 bg-light rounded text-center border">
                   <i class="bi bi-inbox d-block mb-1" style="font-size: 1.5rem; color: #ced4da;"></i>
                   Keine neuen Anfragen über die Website.
               </div>
           <?php endif; ?>
           </div>
        </div>
      </div>

      <div class="col-12 col-md-6 col-xl-4">
        <div class="widget-box h-100" style="border-left: 4px solid #dc3545;">
           <div class="widget-title">
               <span><i class="bi bi-life-preserver text-danger me-2"></i> Offene Support-Tickets</span>
               <a href="tickets" class="btn btn-sm btn-link p-0 text-muted"><i class="bi bi-arrow-right"></i></a>
           </div>
           
           <div id="tickets_widget_body">
           <?php if(count($tickets) > 0): ?>
               <div class="list-group scroll-container">
                 <?php foreach($tickets as $tick): ?>
                   <a href="tickets" class="list-group-item list-group-item-action d-flex justify-content-between align-items-center py-2 border-0 border-bottom">
                     <div class="flex-grow-1 pe-3">
                       <h6 class="mb-1 fw-bold fs-6 text-dark"><?php echo htmlspecialchars($tick['contact_name']); ?></h6>
                       <p class="mb-0 text-muted small text-truncate" style="font-size:11px; max-width: 200px;"><?php echo htmlspecialchars($tick['subject']); ?></p>
                     </div>
                     <div>
                         <span class="badge <?= $tick['status'] == 'Offen' ? 'bg-warning text-dark' : 'bg-primary' ?>" style="font-size:10px;"><?= $tick['status'] ?></span>
                     </div>
                   </a>
                 <?php endforeach; ?>
               </div>
           <?php else: ?>
               <div class="text-muted small p-2 bg-light rounded text-center border">
                   <i class="bi bi-check2-all d-block mb-1" style="font-size: 1.5rem; color: #ced4da;"></i>
                   Keine offenen Support-Anfragen.
               </div>
           <?php endif; ?>
           </div>
        </div>
      </div>
    </div>

    <div class="row g-4 mb-4">
        <div class="col-lg-8">
            <div class="widget-box" style="border-left: 4px solid var(--color-primary);">
                <div class="widget-title"><span><i class="bi bi-magic text-primary me-2"></i> Portal Aktivitäten</span></div>
                
                <div class="scroll-container">
                    <div class="row g-3 w-100" id="portal_activity_body">
                        
                        <div class="col-md-3">
                            <h6 class="small fw-bold text-muted mb-2 uppercase" style="font-size:11px; border-bottom: 1px solid #eee; padding-bottom: 5px;">UPLOADS</h6>
                            <?php if(count($portal_uploads) > 0): ?>
                                <?php foreach($portal_uploads as $u): ?>
                                    <div class="position-relative bg-white border rounded-3 p-3 mb-2 shadow-sm portal-item-hover" style="border-left: 4px solid var(--color-primary) !important;">
                                        <form method="POST" class="position-absolute" style="top: 5px; right: 5px;">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="activity_type" value="upload">
                                            <input type="hidden" name="activity_id" value="<?=$u['id']?>">
                                            <button type="submit" name="dismiss_portal_activity" class="btn-close" style="font-size: 0.65rem;" title="Ausblenden"></button>
                                        </form>
                                        <a href="tasks?q=<?=urlencode($u['task_title'])?>" class="text-decoration-none d-block pe-3">
                                            <span class="badge bg-primary bg-opacity-10 text-primary mb-2" style="font-size: 9px; letter-spacing: 0.5px;">DATEI</span>
                                            <div class="fw-bold text-dark text-truncate mb-1" style="font-size: 13px;"><?=htmlspecialchars($u['file_name'])?></div>
                                            <div class="text-muted small text-truncate"><i class="bi bi-person"></i> <?=htmlspecialchars($u['client_name'])?></div>
                                        </a>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div class="text-muted small p-2 bg-light rounded text-center border mt-2">
                                    <i class="bi bi-file-earmark-check d-block mb-1" style="font-size: 1.2rem; color: #ced4da;"></i>
                                    <span style="font-size:10px;">Keine Uploads</span>
                                </div>
                            <?php endif; ?>
                        </div>

                        <div class="col-md-3">
                            <h6 class="small fw-bold text-muted mb-2 uppercase" style="font-size:11px; border-bottom: 1px solid #eee; padding-bottom: 5px;">ABSEGNUNGEN</h6>
                            <?php if(count($portal_approvals) > 0): ?>
                                <?php foreach($portal_approvals as $a): ?>
                                    <div class="position-relative bg-white border rounded-3 p-3 mb-2 shadow-sm portal-item-hover" style="border-left: 4px solid #28a745 !important;">
                                        <form method="POST" class="position-absolute" style="top: 5px; right: 5px;">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="activity_type" value="approval">
                                            <input type="hidden" name="activity_id" value="<?=$a['id']?>">
                                            <button type="submit" name="dismiss_portal_activity" class="btn-close" style="font-size: 0.65rem;" title="Ausblenden"></button>
                                        </form>
                                        <a href="tasks?q=<?=urlencode($a['task_title'])?>" class="text-decoration-none d-block pe-3">
                                            <span class="badge bg-success bg-opacity-10 text-success mb-2" style="font-size: 9px; letter-spacing: 0.5px;">BESTÄTIGT</span>
                                            <div class="fw-bold text-dark text-truncate mb-1" style="font-size: 13px;"><?=htmlspecialchars($a['title'])?></div>
                                            <div class="text-muted small text-truncate"><i class="bi bi-person"></i> <?=htmlspecialchars($a['client_name'])?></div>
                                        </a>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div class="text-muted small p-2 bg-light rounded text-center border mt-2">
                                    <i class="bi bi-check-circle d-block mb-1" style="font-size: 1.2rem; color: #ced4da;"></i>
                                    <span style="font-size:10px;">Keine Absegnungen</span>
                                </div>
                            <?php endif; ?>
                        </div>

                        <div class="col-md-3">
                            <h6 class="small fw-bold text-muted mb-2 uppercase" style="font-size:11px; border-bottom: 1px solid #eee; padding-bottom: 5px;">FEEDBACK</h6>
                            <?php if(count($portal_feedbacks) > 0): ?>
                                <?php foreach($portal_feedbacks as $f): ?>
                                    <div class="position-relative bg-white border rounded-3 p-3 mb-2 shadow-sm portal-item-hover" style="border-left: 4px solid #ffc107 !important;">
                                        <form method="POST" class="position-absolute" style="top: 5px; right: 5px;">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="activity_type" value="feedback">
                                            <input type="hidden" name="activity_id" value="<?=$f['id']?>">
                                            <button type="submit" name="dismiss_portal_activity" class="btn-close" style="font-size: 0.65rem;" title="Ausblenden"></button>
                                        </form>
                                        <a href="tasks?q=<?=urlencode($f['title'])?>" class="text-decoration-none d-block pe-3">
                                            <span class="badge bg-warning bg-opacity-10 text-dark mb-2" style="font-size: 9px; letter-spacing: 0.5px;">NEUES FEEDBACK</span>
                                            <div class="fw-bold text-dark text-truncate mb-1" style="font-size: 13px;"><?=htmlspecialchars($f['title'])?></div>
                                            <div class="text-muted fst-italic text-truncate" style="font-size:11px;">"<?=htmlspecialchars($f['client_feedback'])?>"</div>
                                        </a>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div class="text-muted small p-2 bg-light rounded text-center border mt-2">
                                    <i class="bi bi-chat-dots d-block mb-1" style="font-size: 1.2rem; color: #ced4da;"></i>
                                    <span style="font-size:10px;">Kein neues Feedback</span>
                                </div>
                            <?php endif; ?>
                        </div>

                        <div class="col-md-3">
                            <h6 class="small fw-bold text-muted mb-2 uppercase" style="font-size:11px; border-bottom: 1px solid #eee; padding-bottom: 5px;">KOMMENTARE</h6>
                            <?php if(count($portal_ms_comments) > 0): ?>
                                <?php foreach($portal_ms_comments as $mc): ?>
                                    <div class="position-relative bg-white border rounded-3 p-3 mb-2 shadow-sm portal-item-hover" style="border-left: 4px solid #6f42c1 !important;">
                                        <form method="POST" class="position-absolute" style="top: 5px; right: 5px;">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="activity_type" value="ms_comment">
                                            <input type="hidden" name="activity_id" value="<?=$mc['id']?>">
                                            <button type="submit" name="dismiss_portal_activity" class="btn-close" style="font-size: 0.65rem;" title="Ausblenden"></button>
                                        </form>
                                        <a href="tasks?q=<?=urlencode($mc['task_title'])?>" class="text-decoration-none d-block pe-3">
                                            <span class="badge mb-2" style="background:#6f42c122;color:#6f42c1;font-size:9px;letter-spacing:0.5px;">KOMMENTAR</span>
                                            <div class="fw-bold text-dark text-truncate mb-1" style="font-size: 13px;"><?=htmlspecialchars($mc['ms_title'])?></div>
                                            <div class="text-muted fst-italic text-truncate" style="font-size:11px;">"<?=htmlspecialchars(mb_strimwidth($mc['message'],0,60,'…'))?>"</div>
                                            <div class="text-muted small mt-1 text-truncate"><i class="bi bi-person"></i> <?=htmlspecialchars($mc['client_name'])?></div>
                                        </a>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div class="text-muted small p-2 bg-light rounded text-center border mt-2">
                                    <i class="bi bi-chat-dots d-block mb-1" style="font-size: 1.2rem; color: #ced4da;"></i>
                                    <span style="font-size:10px;">Keine Kommentare</span>
                                </div>
                            <?php endif; ?>
                        </div>

                    </div>
                </div>
            </div>
        </div>

        <div class="col-lg-4">
            <div class="widget-box" style="border-top: 4px solid #28a745;">
                <div class="widget-title">
                    <span><i class="bi bi-hdd-network text-success me-2"></i> System-Monitor</span>
                    <button class="btn btn-sm btn-link p-0 text-success" data-bs-toggle="modal" data-bs-target="#addMonitorModal"><i class="bi bi-plus-circle"></i></button>
                </div>
                
                <div class="scroll-container" id="monitor_widget_body">
                    <?php
                    if(count($monitored_urls) > 0):
                        foreach($monitored_urls as $key => $url):
                            $status = $uptime_statuses[$key];

                            $dot_class   = 'bg-offline';
                            $status_text = "Offline ($status[code])";

                            if ($status['online']) {
                                if ($status['time'] > 1500) {
                                    $dot_class   = 'bg-slow';
                                    $status_text = "Langsam";
                                } else {
                                    $dot_class   = 'bg-online';
                                    $status_text = "Online";
                                }
                            } elseif ($status['code'] == 0) {
                                $status_text = "Timeout/DNS";
                            }
                    ?>
                            <div class="uptime-item shadow-sm">
                                <div class="uptime-header">
                                    <div class="text-truncate fw-bold text-dark d-flex align-items-center" style="font-size:13px;">
                                        <span class="status-dot <?=$dot_class?>"></span>
                                        <?=$url['url_name']?>
                                    </div>
                                    <button type="button" class="trash-btn" onclick="triggerDeleteMonitor(<?=$url['id']?>)">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                </div>
                                
                                <div class="uptime-stats">
                                    <span class="<?=!$status['online'] ? 'text-danger fw-bold' : ''?>">
                                        <i class="bi bi-activity"></i> <?=$status_text?>
                                    </span>
                                    <?php if($status['code'] > 0): ?>
                                        <span><i class="bi bi-stopwatch"></i> <?=$status['time']?> ms</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="text-muted small p-2 bg-light rounded text-center border mt-2">
                            <i class="bi bi-server d-block mb-1" style="font-size: 1.5rem; color: #ced4da;"></i>
                            Keine URLs im Monitor.
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-3 mb-4 row-cols-1 row-cols-md-3 align-items-stretch">

      <!-- Deadlines -->
      <div class="col">
        <div class="widget-box" style="border-top:4px solid #fd7e14;">
          <div class="d-flex justify-content-between align-items-center mb-2">
            <div class="d-flex align-items-center gap-2">
              <div class="rounded-2 d-flex align-items-center justify-content-center flex-shrink-0" style="width:32px;height:32px;background:#fd7e1420;color:#fd7e14;">
                <i class="bi bi-alarm"></i>
              </div>
              <div>
                <div class="label-xs">Deadlines</div>
                <div class="fw-bold fs-5 lh-1" id="kpi_deadlines" style="color:#fd7e14;"><?=count($upcoming_deadlines)?></div>
              </div>
            </div>
            <a href="calendar" class="text-muted"><i class="bi bi-arrow-right-short fs-5"></i></a>
          </div>
          <div id="deadlines_list_body">
          <?php foreach(array_slice($upcoming_deadlines, 0, 2) as $dl):
            $d2 = (int)$dl['days_left'];
            $c2 = $d2 < 0 ? '#dc3545' : ($d2 === 0 ? '#fd7e14' : ($d2 <= 3 ? '#ffc107' : '#6c757d'));
            $l2 = $d2 < 0 ? 'Überfällig' : ($d2 === 0 ? 'Heute' : "in $d2 T.");
          ?>
          <div class="d-flex align-items-center gap-2 pt-2 border-top">
            <span class="badge" style="background:<?=$c2?>22;color:<?=$c2?>;font-size:9px;white-space:nowrap;"><?=$l2?></span>
            <span class="small text-truncate text-dark" style="font-size:11px;"><?=htmlspecialchars(mb_strimwidth($dl['title'],0,20,'…'))?></span>
          </div>
          <?php endforeach; ?>
          <?php if (empty($upcoming_deadlines)): ?>
          <div class="small text-muted pt-2 border-top"><i class="bi bi-check2-circle me-1 text-success"></i>Keine Deadlines</div>
          <?php endif; ?>
          </div>
        </div>
      </div>

      <!-- Termine -->
      <div class="col">
        <?php $all_apts = array_merge($today_appointments, $week_appointments); ?>
        <div class="widget-box" style="border-top:4px solid #6f42c1;">
          <div class="d-flex justify-content-between align-items-center mb-2">
            <div class="d-flex align-items-center gap-2">
              <div class="rounded-2 d-flex align-items-center justify-content-center flex-shrink-0" style="width:32px;height:32px;background:#6f42c120;color:#6f42c1;">
                <i class="bi bi-calendar2-event"></i>
              </div>
              <div>
                <div class="label-xs">Termine</div>
                <div class="fw-bold fs-5 lh-1" id="kpi_termine" style="color:#6f42c1;"><?=count($all_apts)?></div>
              </div>
            </div>
            <a href="calendar" class="text-muted"><i class="bi bi-arrow-right-short fs-5"></i></a>
          </div>
          <div id="termine_list_body">
          <?php foreach(array_slice($all_apts, 0, 2) as $apt):
            $ac   = htmlspecialchars($apt['color'] ?: '#6f42c1');
            $adys = isset($apt['event_date']) ? (int)round((strtotime($apt['event_date']) - strtotime('today')) / 86400) : 0;
            $albl = $adys === 0 ? 'Heute' : ($adys === 1 ? 'Morgen' : "in $adys T.");
          ?>
          <div class="d-flex align-items-center gap-2 pt-2 border-top">
            <span class="badge" style="background:<?=$ac?>22;color:<?=$ac?>;font-size:9px;white-space:nowrap;"><?=$albl?></span>
            <span class="small text-truncate text-dark" style="font-size:11px;"><?=htmlspecialchars(mb_strimwidth($apt['title'],0,20,'…'))?></span>
          </div>
          <?php endforeach; ?>
          <?php if (empty($all_apts)): ?>
          <div class="small text-muted pt-2 border-top"><i class="bi bi-calendar-check me-1 text-success"></i>Keine Termine</div>
          <?php endif; ?>
          </div>
        </div>
      </div>

      <!-- Webspace -->
      <div class="col">
        <div class="widget-box d-flex align-items-start gap-3" style="border-top:4px solid #0dcaf0;">
          <div class="rounded-2 d-flex align-items-center justify-content-center flex-shrink-0" style="width:38px;height:38px;background:#0dcaf020;color:#0dcaf0;">
            <i class="bi bi-hdd-fill"></i>
          </div>
          <div class="w-100">
            <div class="label-xs">Webspace</div>
            <div class="d-flex justify-content-between fw-bold small my-1"><span><?=$used_gb?> GB</span><span><?=$percent_used?>%</span></div>
            <div class="progress mb-1" style="height:5px;"><div class="progress-bar" style="width:<?=$percent_used?>%;background:#0dcaf0;"></div></div>
            <div class="small text-muted" style="font-size:10px;">von 200 GB belegt</div>
          </div>
        </div>
      </div>

    </div>

    <div class="row g-4 flex-grow-1 pb-4">
        <div class="col-lg-8">
            <div class="widget-box mt-0 h-100 d-flex flex-column">
                <div class="widget-title flex-wrap gap-2">
                    <span><i class="bi bi-kanban me-2"></i> Laufende Projekte</span>
                    <div class="d-flex gap-1 align-items-center flex-wrap">
                        <button class="btn btn-sm py-0 px-2 btn-primary proj-filter" data-filter="all" style="font-size:11px;">Alle</button>
                        <button class="btn btn-sm py-0 px-2 btn-outline-secondary proj-filter" data-filter="Offen" style="font-size:11px;">Offen</button>
                        <button class="btn btn-sm py-0 px-2 btn-outline-secondary proj-filter" data-filter="In Bearbeitung" style="font-size:11px;">Läuft</button>
                        <select id="projSort" class="form-select form-select-sm py-0 ms-1" style="width:auto;font-size:11px;">
                            <option value="newest">Neueste</option>
                            <option value="deadline">Deadline</option>
                            <option value="progress_asc">Fortschritt ↑</option>
                            <option value="progress_desc">Fortschritt ↓</option>
                        </select>
                    </div>
                </div>

                <div class="flex-grow-1 overflow-auto pe-2" style="min-height: 250px;">
                    <?php if(count($active_projects) > 0): ?>
                        <div class="row g-3" id="projRow">
                            <?php foreach($active_projects as $p): ?>
                                <div class="col-xl-6 col-lg-12 proj-card"
                                     data-status="<?=htmlspecialchars($p['status'])?>"
                                     data-deadline="<?= $p['deadline'] ?: '9999-99-99' ?>"
                                     data-progress="<?=$p['progress']?>"
                                     data-created="<?=$p['id']?>">
                                    <a href="tasks?q=<?=urlencode($p['title'])?>" class="text-decoration-none">
                                        <div class="dash-project-card shadow-sm bg-white">
                                            <div class="d-flex justify-content-between align-items-start mb-3">
                                                <h6 class="fw-bold m-0 text-dark text-truncate pe-2" style="font-size: 15px;"><?=htmlspecialchars($p['title'])?></h6>
                                                <?= status_badge($p['status']) ?>
                                            </div>
                                            
                                            <div class="d-flex justify-content-between align-items-center mb-3 small text-muted">
                                                <span class="text-truncate" style="max-width: 60%;"><i class="bi bi-person-fill me-1"></i> <?= $p['client_name'] ? htmlspecialchars($p['client_name']) : 'Kein Kunde' ?></span>
                                                <?php if($p['deadline']): ?>
                                                    <span class="<?= $p['days_until_deadline'] < 0 ? 'text-danger fw-bold' : '' ?>">
                                                        <i class="bi bi-calendar-event me-1"></i> <?= date('d.m.', strtotime($p['deadline'])) ?>
                                                    </span>
                                                <?php else: ?>
                                                    <span><i class="bi bi-calendar-x"></i> -</span>
                                                <?php endif; ?>
                                            </div>

                                            <div class="mt-auto border-top pt-3">
                                                <div class="d-flex justify-content-between mb-1 small fw-bold" style="font-size:11px;">
                                                    <span class="text-muted text-uppercase">Fortschritt</span>
                                                    <span class="<?=$p['progress']==100?'text-success':'text-primary'?>"><?=$p['progress']?>%</span>
                                                </div>
                                                <div class="progress" style="height:6px; border-radius:3px;">
                                                    <div class="progress-bar <?=$p['progress']==100?'bg-success':''?>" style="width:<?=$p['progress']?>%; <?= $p['progress'] < 100 ? 'background-color: var(--color-primary);' : '' ?>"></div>
                                                </div>
                                            </div>
                                        </div>
                                    </a>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <div id="projEmpty" class="text-muted small p-2 bg-light rounded text-center border mt-2" style="display:none;">
                            <i class="bi bi-funnel d-block mb-1" style="font-size:1.5rem;color:#ced4da;"></i>
                            Kein Projekt entspricht diesem Filter.
                        </div>
                    <?php else: ?>
                        <div class="text-muted small p-2 bg-light rounded text-center border mt-2">
                            <i class="bi bi-clipboard-check d-block mb-1" style="font-size: 1.5rem; color: #ced4da;"></i>
                            Aktuell keine laufenden Projekte.
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <div class="col-lg-4">
            <div class="widget-box mt-0 h-100 d-flex flex-column">
                <div class="widget-title">
                    <span><i class="bi bi-pen me-2"></i> Notizen</span>
                    <button type="button" class="btn btn-xs btn-link text-danger p-0" onclick="triggerClearNotes()">
                        <i class="bi bi-trash" style="pointer-events:none;"></i>
                    </button>
                </div>
                <textarea id="quickNotes" class="form-control flex-grow-1 shadow-sm border bg-light p-3" style="font-size: 14px; resize: none; min-height: 250px;" placeholder="Wird automatisch gespeichert..."></textarea>
            </div>
        </div>
    </div>

  <div class="modal fade" id="viewLeadModal" tabindex="-1">
      <div class="modal-dialog modal-lg">
          <div class="modal-content border-0 shadow">
              <div class="modal-header bg-warning">
                  <h5 class="modal-title text-dark fw-bold"><i class="bi bi-envelope-open me-2"></i>Anfrage Details</h5>
                  <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
              </div>
              <div class="modal-body p-4">
                  <div class="row mb-3">
                      <div class="col-md-6">
                          <label class="small text-muted fw-bold mb-1">Name</label>
                          <div class="p-2 bg-light rounded border fw-bold text-dark" id="vl_name"></div>
                      </div>
                      <div class="col-md-6 mt-3 mt-md-0">
                          <label class="small text-muted fw-bold mb-1">E-Mail</label>
                          <div class="p-2 bg-light rounded border" id="vl_email"></div>
                      </div>
                  </div>
                  
                  <div class="row mb-3">
                      <div class="col-md-6">
                          <label class="small text-muted fw-bold mb-1">Telefon</label>
                          <div class="p-2 bg-light rounded border" id="vl_phone"></div>
                      </div>
                      <div class="col-md-6 mt-3 mt-md-0">
                          <label class="small text-muted fw-bold mb-1">Quelle</label>
                          <div class="p-2 bg-light rounded border" id="vl_source"></div>
                      </div>
                  </div>

                  <div class="mb-3">
                      <label class="small text-muted fw-bold mb-1">Betreff</label>
                      <div class="p-2 bg-light rounded border fw-bold text-primary" id="vl_subject"></div>
                  </div>

                  <div>
                      <label class="small text-muted fw-bold mb-1">Nachricht</label>
                      <div class="p-3 bg-light rounded border" id="vl_message" style="min-height: 100px; white-space: pre-wrap;"></div>
                  </div>
              </div>
              <div class="modal-footer d-flex justify-content-between bg-light">
                  <button type="button" class="btn btn-secondary fw-bold px-4" data-bs-dismiss="modal">Schließen</button>
                  <form action="index" method="POST" style="margin:0;">
                      <?= csrf_field() ?>
                      <input type="hidden" name="inbox_action" value="accept_lead">
                      <input type="hidden" name="lead_id" id="vl_id">
                      <button type="submit" class="btn btn-success fw-bold px-4"><i class="bi bi-person-plus-fill me-1"></i> Als Kontakt übernehmen</button>
                  </form>
              </div>
          </div>
      </div>
  </div>

  <div class="modal fade" id="addMonitorModal" tabindex="-1" data-bs-backdrop="static" data-bs-keyboard="false">
      <div class="modal-dialog modal-sm">
          <div class="modal-content border-0 shadow">
              <form method="POST">
                  <?= csrf_field() ?>
                  <input type="hidden" name="monitor_action" value="add_url">
                  <div class="modal-header bg-dark text-white">
                      <h6 class="modal-title m-0 fw-bold"><i class="bi bi-plus-circle me-2"></i>URL hinzufügen</h6>
                      <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                  </div>
                  <div class="modal-body p-4 bg-light">
                      <label class="form-label small fw-bold">Projekt / Name *</label>
                      <input type="text" name="url_name" class="form-control mb-3" placeholder="Kunde XYZ" required>
                      
                      <label class="form-label small fw-bold">URL / Domain *</label>
                      <input type="text" name="url_link" class="form-control" placeholder="https://..." required>
                  </div>
                  <div class="modal-footer bg-light p-2">
                      <button type="submit" class="btn btn-primary w-100 fw-bold">Speichern</button>
                  </div>
              </form>
          </div>
      </div>
  </div>

  <div class="modal fade" id="deleteMonitorModal" tabindex="-1">
      <div class="modal-dialog modal-sm modal-dialog-centered">
          <div class="modal-content border-0 shadow">
              <form action="index" method="POST">
                  <?= csrf_field() ?>
                  <input type="hidden" name="monitor_action" value="delete_url">
                  <input type="hidden" name="url_id" id="delete_monitor_id">
                  <div class="modal-header bg-danger text-white">
                      <h6 class="modal-title m-0"><i class="bi bi-exclamation-triangle-fill me-2"></i>URL entfernen?</h6>
                      <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                  </div>
                  <div class="modal-body text-center py-4">
                      <p class="mb-0 fw-bold">Soll diese Domain aus der Überwachung entfernt werden?</p>
                  </div>
                  <div class="modal-footer p-2 d-flex justify-content-between bg-light">
                      <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Abbrechen</button>
                      <button type="submit" class="btn btn-danger btn-sm px-3 fw-bold">Ja, entfernen</button>
                  </div>
              </form>
          </div>
      </div>
  </div>

  <div class="modal fade" id="deleteLeadModal" tabindex="-1">
      <div class="modal-dialog modal-sm modal-dialog-centered">
          <div class="modal-content border-0 shadow">
              <form action="index" method="POST">
                  <?= csrf_field() ?>
                  <input type="hidden" name="inbox_action" value="delete_lead">
                  <input type="hidden" name="lead_id" id="delete_lead_id">
                  <div class="modal-header bg-danger text-white">
                      <h6 class="modal-title m-0"><i class="bi bi-exclamation-triangle-fill me-2"></i>Anfrage löschen?</h6>
                      <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                  </div>
                  <div class="modal-body text-center py-4">
                      <p class="mb-0 fw-bold">Die Nachricht unwiderruflich löschen?</p>
                  </div>
                  <div class="modal-footer p-2 d-flex justify-content-between bg-light">
                      <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Abbrechen</button>
                      <button type="submit" class="btn btn-danger btn-sm px-3 fw-bold">Ja, löschen</button>
                  </div>
              </form>
          </div>
      </div>
  </div>

  <div class="modal fade" id="clearNotesModal" tabindex="-1">
      <div class="modal-dialog modal-sm modal-dialog-centered">
          <div class="modal-content border-0 shadow">
              <div class="modal-header bg-danger text-white">
                  <h6 class="modal-title m-0"><i class="bi bi-exclamation-triangle-fill me-2"></i>Notizen leeren?</h6>
                  <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
              </div>
              <div class="modal-body text-center py-4">
                  <p class="mb-0 fw-bold">Möchtest du den gesamten Text im Notizblock endgültig löschen?</p>
              </div>
              <div class="modal-footer p-2 d-flex justify-content-between bg-light">
                  <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Abbrechen</button>
                  <button type="button" class="btn btn-danger btn-sm px-3 fw-bold" onclick="executeClearNotes()">Ja, leeren</button>
              </div>
          </div>
      </div>
  </div>

  <script>
    // TOAST INITIALISIERUNG
    document.addEventListener('DOMContentLoaded', function () {
        var toastElList = [].slice.call(document.querySelectorAll('.toast'));
        var toastList = toastElList.map(function (toastEl) {
            return new bootstrap.Toast(toastEl);
        });
        toastList.forEach(toast => toast.show());
    });

    const leadModal = new bootstrap.Modal(document.getElementById('viewLeadModal'));
    
    function openLeadModal(lead) {
        document.getElementById('vl_id').value = lead.id;
        document.getElementById('vl_name').textContent = lead.name || '-';
        // E-Mail sicher als Link setzen (kein innerHTML mit User-Daten)
        const emailEl = document.getElementById('vl_email');
        if (lead.email) {
            const a = document.createElement('a');
            a.href = 'mailto:' + lead.email;
            a.textContent = lead.email;
            emailEl.innerHTML = '';
            emailEl.appendChild(a);
        } else {
            emailEl.textContent = '-';
        }
        document.getElementById('vl_phone').textContent = lead.phone || 'Keine Angabe';
        document.getElementById('vl_source').textContent = lead.source || 'Unbekannt';
        document.getElementById('vl_subject').textContent = lead.subject || '-';
        document.getElementById('vl_message').textContent = lead.message || 'Keine Nachricht hinterlassen.';

        leadModal.show();
    }

    const notesField = document.getElementById('quickNotes');
    if(localStorage.getItem('david_quick_notes')) notesField.value = localStorage.getItem('david_quick_notes');
    notesField.addEventListener('input', function() { localStorage.setItem('david_quick_notes', this.value); });
    
    const clrNotesModal = new bootstrap.Modal(document.getElementById('clearNotesModal'));
    
    function triggerClearNotes() {
        clrNotesModal.show();
    }

    function executeClearNotes() {
        notesField.value = ''; 
        localStorage.removeItem('david_quick_notes'); 
        clrNotesModal.hide();
    }

    const delMonitorModal = new bootstrap.Modal(document.getElementById('deleteMonitorModal'));
    const delLeadModal = new bootstrap.Modal(document.getElementById('deleteLeadModal'));

    function triggerDeleteMonitor(id) {
        document.getElementById('delete_monitor_id').value = id;
        delMonitorModal.show();
    }

    function triggerDeleteLead(id) {
        document.getElementById('delete_lead_id').value = id;
        delLeadModal.show();
    }

    // Laufende Projekte: Filter & Sort
    (function () {
        const row = document.getElementById('projRow');
        if (!row) return;

        let activeFilter = 'all';
        let activeSort   = 'newest';

        function applyFilterSort() {
            const cards = Array.from(row.querySelectorAll('.proj-card'));

            // Filter
            cards.forEach(c => {
                c.style.display = (activeFilter === 'all' || c.dataset.status === activeFilter) ? '' : 'none';
            });

            // Sort visible cards
            const visible = cards.filter(c => c.style.display !== 'none');
            visible.sort((a, b) => {
                if (activeSort === 'deadline') {
                    return (a.dataset.deadline).localeCompare(b.dataset.deadline);
                } else if (activeSort === 'progress_desc') {
                    return parseInt(b.dataset.progress) - parseInt(a.dataset.progress);
                } else if (activeSort === 'progress_asc') {
                    return parseInt(a.dataset.progress) - parseInt(b.dataset.progress);
                }
                // newest = descending id (original order)
                return parseInt(b.dataset.created) - parseInt(a.dataset.created);
            });
            visible.forEach(c => row.appendChild(c));

            // Empty state
            const empty = document.getElementById('projEmpty');
            if (empty) empty.style.display = visible.length === 0 ? '' : 'none';
        }

        // Filter-Buttons
        document.querySelectorAll('.proj-filter').forEach(btn => {
            btn.addEventListener('click', function () {
                document.querySelectorAll('.proj-filter').forEach(b => {
                    b.classList.remove('btn-primary');
                    b.classList.add('btn-outline-secondary');
                });
                this.classList.add('btn-primary');
                this.classList.remove('btn-outline-secondary');
                activeFilter = this.dataset.filter;
                applyFilterSort();
            });
        });

        // Sort-Select
        const sortSel = document.getElementById('projSort');
        if (sortSel) {
            sortSel.addEventListener('change', function () {
                activeSort = this.value;
                applyFilterSort();
            });
        }
    })();

    // ── LEAD CACHE (global, für openLeadModal aus JS-gerendertem Widget) ────
    var _leadsCache = [];
    function openLeadByIdx(i) { if (_leadsCache[i]) openLeadModal(_leadsCache[i]); }

    // ── LIVE-POLL ────────────────────────────────────────────────────────────
    (function () {
        const INTERVAL      = 60000;  // 60s: JSON-Daten
        const HTML_INTERVAL = 120000; // 2min: Portal-Aktivitäten HTML
        const MON_INTERVAL  = 300000; // 5min: System-Monitor HTML

        let prev = {
            leads:       <?= $count_leads ?>,
            tickets:     <?= $count_tickets ?>,
            uploads:     <?= $count_uploads ?>,
            approvals:   <?= $count_approvals ?>,
            feedbacks:   <?= $count_feedbacks ?>,
            ms_comments: <?= $count_ms_comments ?>,
        };

        const toastDefs = {
            leads:       { icon: 'bi-envelope-paper-fill', bg: 'bg-warning',  tc: 'text-dark',  btn: 'btn-close-dark',  msg: n => n + ' neue Website-Anfrage(n)!' },
            tickets:     { icon: 'bi-life-preserver',      bg: 'bg-danger',   tc: 'text-white', btn: 'btn-close-white', msg: n => n + ' offene(s) Support-Ticket(s)!' },
            uploads:     { icon: 'bi-cloud-arrow-up-fill', bg: 'bg-info',     tc: 'text-dark',  btn: 'btn-close-dark',  msg: n => n + ' neue Datei(en) im Portal!' },
            approvals:   { icon: 'bi-check-circle-fill',   bg: 'bg-success',  tc: 'text-white', btn: 'btn-close-white', msg: n => n + ' abgesegnete(r) Meilenstein(e)!' },
            feedbacks:   { icon: 'bi-chat-left-text-fill', bg: 'bg-warning',  tc: 'text-dark',  btn: 'btn-close-dark',  msg: n => n + ' neues Kunden-Feedback!' },
            ms_comments: { icon: 'bi-chat-dots-fill',      bg: '',            tc: 'text-white', btn: 'btn-close-white', msg: n => n + ' neue(r) Meilenstein-Kommentar(e)!', style: 'background:#6f42c1;' },
        };

        function injectToast(def, count) {
            const container = document.querySelector('.toast-container-dash');
            if (!container) return;
            const el = document.createElement('div');
            const styleAttr = def.style ? ' style="' + def.style + '"' : '';
            el.className = 'toast show align-items-center border-0 mb-2 shadow-lg ' + def.bg + ' ' + def.tc;
            if (def.style) el.style.cssText = def.style;
            el.setAttribute('role', 'alert');
            el.innerHTML = `<div class="d-flex">
                <div class="toast-body fw-bold">
                  <i class="bi ${def.icon} me-2 fs-5 align-middle"></i>
                  <span class="align-middle">${def.msg(count)}</span>
                </div>
                <button type="button" class="btn-close ${def.btn} me-2 m-auto" onclick="this.closest('.toast').remove()"></button>
            </div>`;
            container.appendChild(el);
        }

        function setLiveState(ok) {
            const dot   = document.getElementById('live_dot');
            const label = document.getElementById('live_label');
            if (!dot) return;
            dot.style.background = ok ? '#28a745' : '#dc3545';
            if (label) label.textContent = ok ? 'Live' : 'Fehler';
        }

        function pulse() {
            const dot = document.getElementById('live_dot');
            if (!dot) return;
            dot.style.transform = 'scale(1.6)';
            dot.style.transition = 'transform .2s';
            setTimeout(() => { dot.style.transform = 'scale(1)'; }, 300);
        }

        const prioColors = { Kritisch:'#dc3545', Hoch:'#fd7e14', Mittel:'#ffc107', Niedrig:'#adb5bd' };

        function renderTicketsWidget(list) {
            const el = document.getElementById('tickets_widget_body');
            if (!el) return;
            if (!list || list.length === 0) {
                el.innerHTML = '<div class="text-muted small p-2 bg-light rounded text-center border"><i class="bi bi-check2-all d-block mb-1" style="font-size:1.5rem;color:#ced4da;"></i>Keine offenen Support-Anfragen.</div>';
                return;
            }
            const rows = list.map(t => {
                const name    = (t.contact_name || '').replace(/</g,'&lt;');
                const subject = (t.subject      || '').replace(/</g,'&lt;');
                const badgeCls = t.status === 'Offen' ? 'bg-warning text-dark' : 'bg-primary';
                const pc = prioColors[t.priority] || '#adb5bd';
                return `<a href="tickets" class="list-group-item list-group-item-action d-flex justify-content-between align-items-center py-2 border-0 border-bottom">
                    <div class="flex-grow-1 pe-3 d-flex align-items-center gap-2">
                        <span style="width:8px;height:8px;border-radius:50%;background:${pc};display:inline-block;flex-shrink:0;" title="${t.priority || ''}"></span>
                        <div><h6 class="mb-1 fw-bold fs-6 text-dark">${name}</h6>
                        <p class="mb-0 text-muted small text-truncate" style="font-size:11px;max-width:200px;">${subject}</p></div>
                    </div>
                    <span class="badge ${badgeCls}" style="font-size:10px;">${t.status}</span>
                </a>`;
            }).join('');
            el.innerHTML = `<div class="list-group scroll-container">${rows}</div>`;
        }

        function renderLeadsWidget(list) {
            const el = document.getElementById('leads_widget_body');
            if (!el) return;
            _leadsCache = list || [];
            if (!list || list.length === 0) {
                el.innerHTML = '<div class="text-muted small p-2 bg-light rounded text-center border"><i class="bi bi-inbox d-block mb-1" style="font-size:1.5rem;color:#ced4da;"></i>Keine neuen Anfragen über die Website.</div>';
                return;
            }
            const rows = list.map((lead, i) => {
                const name    = (lead.name    || '').replace(/</g,'&lt;');
                const subject = (lead.subject || '').replace(/</g,'&lt;');
                return `<div class="list-group-item d-flex justify-content-between align-items-center py-2 lead-item" onclick="openLeadByIdx(${i})" style="cursor:pointer;">
                    <div class="flex-grow-1 pe-3">
                        <h6 class="mb-1 fw-bold fs-6 text-dark">${name}</h6>
                        <p class="mb-0 text-muted small text-truncate" style="font-size:11px;max-width:200px;">${subject}</p>
                    </div>
                    <div class="d-flex gap-2">
                        <button type="button" class="btn btn-sm btn-outline-danger" onclick="event.stopPropagation();triggerDeleteLead(${lead.id})"><i class="bi bi-trash" style="pointer-events:none;"></i></button>
                    </div>
                </div>`;
            }).join('');
            el.innerHTML = `<div class="list-group scroll-container">${rows}</div>`;
        }

        function renderDeadlinesWidget(count, list) {
            const kpiEl  = document.getElementById('kpi_deadlines');
            const listEl = document.getElementById('deadlines_list_body');
            if (kpiEl)  kpiEl.textContent = count;
            if (!listEl) return;
            if (!list || list.length === 0) {
                listEl.innerHTML = '<div class="small text-muted pt-2 border-top"><i class="bi bi-check2-circle me-1 text-success"></i>Keine Deadlines</div>';
                return;
            }
            listEl.innerHTML = list.slice(0, 2).map(dl => {
                const d = parseInt(dl.days_left);
                const c = d < 0 ? '#dc3545' : (d === 0 ? '#fd7e14' : (d <= 3 ? '#ffc107' : '#6c757d'));
                const l = d < 0 ? 'Überfällig' : (d === 0 ? 'Heute' : `in ${d} T.`);
                const t = (dl.title || '').replace(/</g,'&lt;');
                const s = t.length > 20 ? t.substring(0,20)+'…' : t;
                return `<div class="d-flex align-items-center gap-2 pt-2 border-top">
                    <span class="badge" style="background:${c}22;color:${c};font-size:9px;white-space:nowrap;">${l}</span>
                    <span class="small text-truncate text-dark" style="font-size:11px;">${s}</span>
                </div>`;
            }).join('');
        }

        function renderTermineWidget(count, list) {
            const kpiEl  = document.getElementById('kpi_termine');
            const listEl = document.getElementById('termine_list_body');
            if (kpiEl)  kpiEl.textContent = count;
            if (!listEl) return;
            if (!list || list.length === 0) {
                listEl.innerHTML = '<div class="small text-muted pt-2 border-top"><i class="bi bi-calendar-check me-1 text-success"></i>Keine Termine</div>';
                return;
            }
            listEl.innerHTML = list.slice(0, 2).map(apt => {
                const ac  = (apt.color || '#6f42c1').replace(/[<>"'&]/g,'');
                const d   = parseInt(apt.days_left);
                const lbl = d === 0 ? 'Heute' : (d === 1 ? 'Morgen' : `in ${d} T.`);
                const t   = (apt.title || '').replace(/</g,'&lt;');
                const s   = t.length > 20 ? t.substring(0,20)+'…' : t;
                return `<div class="d-flex align-items-center gap-2 pt-2 border-top">
                    <span class="badge" style="background:${ac}22;color:${ac};font-size:9px;white-space:nowrap;">${lbl}</span>
                    <span class="small text-truncate text-dark" style="font-size:11px;">${s}</span>
                </div>`;
            }).join('');
        }

        function pollHtml(widgetId, url) {
            fetch(url, { cache: 'no-store' })
                .then(r => r.text())
                .then(html => { const el = document.getElementById(widgetId); if (el) el.innerHTML = html; })
                .catch(() => {});
        }

        function poll() {
            fetch('ajax_poll', { cache: 'no-store' })
                .then(r => r.text())
                .then(text => {
                    let data;
                    try { data = JSON.parse(text); }
                    catch (e) {
                        console.error('ajax_poll.php: ungültige Antwort:', text.substring(0, 300));
                        setLiveState(false);
                        return;
                    }
                    setLiveState(true);
                    pulse();
                    // KPI-Zahlen
                    const ot = document.getElementById('kpi_open_tasks');
                    const tc = document.getElementById('kpi_total_contacts');
                    if (ot && data.open_tasks !== undefined) ot.textContent = data.open_tasks;
                    if (tc && data.contacts   !== undefined) tc.textContent = data.contacts;

                    // Widgets – jede Funktion einzeln abgesichert, damit ein Fehler den Toast-Loop nicht blockiert
                    try { if (data.tickets_list !== undefined) renderTicketsWidget(data.tickets_list); } catch(e) { console.warn('[poll] renderTickets:', e); }
                    try { if (data.leads_list   !== undefined) renderLeadsWidget(data.leads_list);   } catch(e) { console.warn('[poll] renderLeads:', e); }
                    try { if (data.deadlines    !== undefined) renderDeadlinesWidget(data.deadlines_count || 0, data.deadlines); } catch(e) { console.warn('[poll] renderDeadlines:', e); }
                    try { if (data.termine      !== undefined) renderTermineWidget(data.termine_count || 0, data.termine);       } catch(e) { console.warn('[poll] renderTermine:', e); }

                    // Toast-Loop – läuft immer, unabhängig von Widget-Fehlern
                    for (const key of ['leads','tickets','uploads','approvals','feedbacks','ms_comments']) {
                        const newVal = data[key] ?? 0;
                        if (newVal > (prev[key] ?? 0)) injectToast(toastDefs[key], newVal);
                        prev[key] = newVal;
                    }
                })
                .catch(() => setLiveState(false));
        }

        setInterval(poll, INTERVAL);
        setInterval(() => pollHtml('portal_activity_body', 'index?ajax_widget=portal_activity'), HTML_INTERVAL);
        setInterval(() => pollHtml('monitor_widget_body',  'index?ajax_widget=monitor'), MON_INTERVAL);
    })();

  </script>
<?php require 'includes/layout_end.php'; ?>