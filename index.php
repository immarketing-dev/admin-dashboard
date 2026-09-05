<?php
require_once 'config.php';
require_once __DIR__ . '/includes/logging.php';
require_once 'includes/uptime.php';
require_once 'includes/auth.php';
require_once 'includes/dashboard_layout.php';

// ==========================================
// AKTIONEN (Inbox, Portal & Monitoring)
// ==========================================
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    csrf_check();

    // 0. Anordnung der Widgets auf der Startseite.
    //
    // Antwortet mit JSON und beendet die Anfrage sofort: der Aufrufer ist ein
    // fetch() aus dem Dashboard, keine Formularsendung. Im Demo-Modus laesst der
    // Riegel in auth.php die beiden Aktionen durch, weil dashboard_layout_save()
    // dort in die Sitzung schreibt statt in die Datenbank - siehe
    // DEMO_ERLAUBTE_AKTIONEN in includes/demo.php.
    $dash_action = $_POST['action'] ?? '';
    if ($dash_action === 'save_dashboard_layout' || $dash_action === 'reset_dashboard_layout') {
        header('Content-Type: application/json; charset=utf-8');
        try {
            if ($dash_action === 'reset_dashboard_layout') dashboard_layout_reset($pdo);
            else                                           dashboard_layout_save($pdo, $_POST['layout'] ?? '');
            echo json_encode(['ok' => true]);
        } catch (PDOException $e) {
            http_response_code(500);
            echo json_encode(['ok' => false, 'error' => 'Anordnung konnte nicht gespeichert werden.']);
        }
        exit();
    }

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
                
                log_event($pdo, 'LEAD_ACCEPTED', "Neue Anfrage von " . $lead['name'] . " ins CRM übernommen.");
                
                $pdo->prepare("DELETE FROM leads_inbox WHERE id = ?")->execute([$lead_id]);
            }
        } 
        elseif ($action === 'delete_lead') {
            $stmt = $pdo->prepare("SELECT name, subject FROM leads_inbox WHERE id = ?");
            $stmt->execute([$lead_id]);
            $del_lead = $stmt->fetch(PDO::FETCH_ASSOC);

            $pdo->prepare("DELETE FROM leads_inbox WHERE id = ?")->execute([$lead_id]);
            
            if ($del_lead) {
                log_event($pdo, 'LEAD_REJECTED', "Anfrage von '" . $del_lead['name'] . "' (" . $del_lead['subject'] . ") wurde gelöscht.");
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
            
            log_event($pdo, 'MONITOR_ADDED', "URL '$name' zum System-Monitor hinzugefügt.");
        } 
        elseif ($_POST['monitor_action'] === 'delete_url') {
            $url_id = (int)$_POST['url_id'];
            
            $stmt = $pdo->prepare("SELECT url_name FROM monitored_urls WHERE id = ?");
            $stmt->execute([$url_id]);
            $url_name = $stmt->fetchColumn();

            $pdo->prepare("DELETE FROM monitored_urls WHERE id = ?")->execute([$url_id]);
            
            if ($url_name) {
                log_event($pdo, 'MONITOR_DELETED', "URL '$url_name' aus dem System-Monitor entfernt.");
            }
        }
    }
    
    header("Location: index"); exit();
}

// ==========================================
// PARALLELER UPTIME CHECK (curl_multi)
// ==========================================
/**
 * Der Zustand der ueberwachten Adressen fuer die Anzeige.
 *
 * Vorher stand hier getParallelSiteStatuses(), die bei JEDEM
 * Dashboardaufruf alle Adressen abfragte - bis zu sechs Sekunden im
 * Seitenaufbau, und das Ergebnis wurde danach weggeworfen.
 *
 * Jetzt misst der Cron-Lauf (includes/uptime.php), und die Seite liest
 * nur noch. Sind die gespeicherten Werte zu alt - oder gibt es fuer eine
 * neu eingetragene Adresse noch keine -, misst sie selbst wie bisher.
 * Ohne diesen Rueckfall zeigte eine Installation ohne eingerichteten
 * Cron gar nichts mehr an, und das waere schlechter als die Wartezeit.
 *
 * @return array{status: array, verlauf: array, gemessen: bool}
 */
function dashboard_uptime(PDO $pdo, array $urls): array {
    $verlauf = [];
    try {
        $letzte  = uptime_letzte($pdo);
        $verlauf = uptime_verlauf($pdo);
    } catch (PDOException $e) {
        // url_checks gibt es erst ab Schemaversion 15. Eine noch nicht
        // migrierte Datenbank soll die Startseite nicht kippen.
        $letzte = [];
    }

    if (uptime_frisch($letzte, $urls, date('Y-m-d H:i:s'))) {
        $status = [];
        foreach ($urls as $key => $url) {
            $z = $letzte[(int) $url['id']];
            $status[$key] = [
                'online' => $z['status'] !== 'offline',
                'code'   => (int) $z['http_code'],
                'time'   => (int) $z['response_ms'],
                'error'  => (string) ($z['error'] ?? ''),
            ];
        }
        return ['status' => $status, 'verlauf' => $verlauf, 'gemessen' => false];
    }

    return ['status' => uptime_messen($urls), 'verlauf' => $verlauf, 'gemessen' => true];
}

// ── AJAX WIDGET PARTIALS ─────────────────────────────────────────────────────
if (isset($_GET['ajax_widget'])) {
    $aw = $_GET['ajax_widget'];
    header('Cache-Control: no-store, no-cache');

    if ($aw === 'portal_activity') {
        header('Content-Type: text/html; charset=utf-8');
        $portal_uploads   = $pdo->query("SELECT ca.*, t.title as task_title, c.name as client_name FROM client_assets ca JOIN tasks t ON ca.task_id = t.id JOIN contacts c ON t.contact_id = c.id WHERE ca.dashboard_seen = 0 AND (ca.uploaded_by IS NULL OR ca.uploaded_by = 'client') ORDER BY ca.uploaded_at DESC")->fetchAll(PDO::FETCH_ASSOC);
        $portal_approvals = $pdo->query("SELECT tm.*, t.title as task_title, c.name as client_name FROM task_milestones tm JOIN tasks t ON tm.task_id = t.id JOIN contacts c ON t.contact_id = c.id WHERE tm.approved_at IS NOT NULL AND tm.approval_seen = 0 ORDER BY tm.approved_at DESC")->fetchAll(PDO::FETCH_ASSOC);
        $portal_feedbacks = $pdo->query("SELECT t.id, t.title, t.client_feedback, c.name as client_name FROM tasks t JOIN contacts c ON t.contact_id = c.id WHERE t.deleted_at IS NULL AND t.client_feedback IS NOT NULL AND t.client_feedback != '' AND t.feedback_seen = 0")->fetchAll(PDO::FETCH_ASSOC);
        $portal_ms_comments = [];
        try { $portal_ms_comments = $pdo->query("SELECT mc.id, mc.message, mc.author_name, tm.title AS ms_title, t.title AS task_title, c.name AS client_name FROM milestone_comments mc JOIN task_milestones tm ON mc.milestone_id = tm.id JOIN tasks t ON tm.task_id = t.id JOIN contacts c ON t.contact_id = c.id WHERE mc.author = 'client' AND mc.admin_seen = 0 ORDER BY mc.created_at DESC LIMIT 20")->fetchAll(PDO::FETCH_ASSOC); } catch (PDOException $e) {}
        ?>
        <div class="col-md-3">
            <h6 class="section-label"><?= te('Uploads') ?></h6>
            <?php if(count($portal_uploads) > 0): foreach($portal_uploads as $u): ?>
            <div class="position-relative bg-surface border border-subtle-c rounded-3 p-3 mb-2 portal-item-hover">
                <form method="POST" class="position-absolute" style="top:5px;right:5px;"><?= csrf_field() ?><input type="hidden" name="activity_type" value="upload"><input type="hidden" name="activity_id" value="<?=$u['id']?>"><button type="submit" name="dismiss_portal_activity" class="btn-close" style="font-size:.65rem;" title="<?= te('Ausblenden') ?>"></button></form>
                <a href="tasks?q=<?=urlencode($u['task_title'])?>" class="text-decoration-none d-block pe-3">
                    <span class="badge bg-primary bg-opacity-10 text-primary mb-2" style="font-size:9px;letter-spacing:.5px;"><?= te('DATEI') ?></span>
                    <div class="fw-bold text-strong-c text-truncate mb-1" style="font-size:13px;"><?=htmlspecialchars($u['file_name'])?></div>
                    <div class="text-muted small text-truncate"><i class="bi bi-person"></i> <?=htmlspecialchars($u['client_name'])?></div>
                </a>
            </div>
            <?php endforeach; else: ?>
            <div class="text-muted small p-3 bg-subtle rounded-3 text-center border border-subtle-c mt-2"><i class="bi bi-file-earmark-check d-block mb-1" style="font-size:1.2rem;color:var(--text-faint);"></i><span style="font-size:10px;"><?= te('Keine Uploads') ?></span></div>
            <?php endif; ?>
        </div>
        <div class="col-md-3">
            <h6 class="section-label"><?= te('Absegnungen') ?></h6>
            <?php if(count($portal_approvals) > 0): foreach($portal_approvals as $a): ?>
            <div class="position-relative bg-surface border border-subtle-c rounded-3 p-3 mb-2 portal-item-hover">
                <form method="POST" class="position-absolute" style="top:5px;right:5px;"><?= csrf_field() ?><input type="hidden" name="activity_type" value="approval"><input type="hidden" name="activity_id" value="<?=$a['id']?>"><button type="submit" name="dismiss_portal_activity" class="btn-close" style="font-size:.65rem;" title="<?= te('Ausblenden') ?>"></button></form>
                <a href="tasks?q=<?=urlencode($a['task_title'])?>" class="text-decoration-none d-block pe-3">
                    <span class="badge bg-success bg-opacity-10 text-success mb-2" style="font-size:9px;letter-spacing:.5px;"><?= te('BESTÄTIGT') ?></span>
                    <div class="fw-bold text-strong-c text-truncate mb-1" style="font-size:13px;"><?=htmlspecialchars($a['title'])?></div>
                    <div class="text-muted small text-truncate"><i class="bi bi-person"></i> <?=htmlspecialchars($a['client_name'])?></div>
                </a>
            </div>
            <?php endforeach; else: ?>
            <div class="text-muted small p-3 bg-subtle rounded-3 text-center border border-subtle-c mt-2"><i class="bi bi-check-circle d-block mb-1" style="font-size:1.2rem;color:var(--text-faint);"></i><span style="font-size:10px;"><?= te('Keine Absegnungen') ?></span></div>
            <?php endif; ?>
        </div>
        <div class="col-md-3">
            <h6 class="section-label">Feedback</h6>
            <?php if(count($portal_feedbacks) > 0): foreach($portal_feedbacks as $f): ?>
            <div class="position-relative bg-surface border border-subtle-c rounded-3 p-3 mb-2 portal-item-hover">
                <form method="POST" class="position-absolute" style="top:5px;right:5px;"><?= csrf_field() ?><input type="hidden" name="activity_type" value="feedback"><input type="hidden" name="activity_id" value="<?=$f['id']?>"><button type="submit" name="dismiss_portal_activity" class="btn-close" style="font-size:.65rem;" title="<?= te('Ausblenden') ?>"></button></form>
                <a href="tasks?q=<?=urlencode($f['title'])?>" class="text-decoration-none d-block pe-3">
                    <span class="badge bg-warning bg-opacity-10 text-dark mb-2" style="font-size:9px;letter-spacing:.5px;"><?= te('NEUES FEEDBACK') ?></span>
                    <div class="fw-bold text-strong-c text-truncate mb-1" style="font-size:13px;"><?=htmlspecialchars($f['title'])?></div>
                    <div class="text-muted fst-italic text-truncate" style="font-size:11px;">"<?=htmlspecialchars($f['client_feedback'])?>"</div>
                </a>
            </div>
            <?php endforeach; else: ?>
            <div class="text-muted small p-3 bg-subtle rounded-3 text-center border border-subtle-c mt-2"><i class="bi bi-chat-dots d-block mb-1" style="font-size:1.2rem;color:var(--text-faint);"></i><span style="font-size:10px;"><?= te('Kein neues Feedback') ?></span></div>
            <?php endif; ?>
        </div>
        <div class="col-md-3">
            <h6 class="section-label"><?= te('Kommentare') ?></h6>
            <?php if(count($portal_ms_comments) > 0): foreach($portal_ms_comments as $mc): ?>
            <div class="position-relative bg-surface border border-subtle-c rounded-3 p-3 mb-2 portal-item-hover">
                <form method="POST" class="position-absolute" style="top:5px;right:5px;"><?= csrf_field() ?><input type="hidden" name="activity_type" value="ms_comment"><input type="hidden" name="activity_id" value="<?=$mc['id']?>"><button type="submit" name="dismiss_portal_activity" class="btn-close" style="font-size:.65rem;" title="<?= te('Ausblenden') ?>"></button></form>
                <a href="tasks?q=<?=urlencode($mc['task_title'])?>" class="text-decoration-none d-block pe-3">
                    <span class="badge mb-2" style="background:var(--neutral-soft);color:var(--text-muted);font-size:9px;letter-spacing:.5px;"><?= te('KOMMENTAR') ?></span>
                    <div class="fw-bold text-strong-c text-truncate mb-1" style="font-size:13px;"><?=htmlspecialchars($mc['ms_title'])?></div>
                    <div class="text-muted fst-italic text-truncate" style="font-size:11px;">"<?=htmlspecialchars(mb_strimwidth($mc['message'],0,60,'…'))?>"</div>
                    <div class="text-muted small mt-1 text-truncate"><i class="bi bi-person"></i> <?=htmlspecialchars($mc['client_name'])?></div>
                </a>
            </div>
            <?php endforeach; else: ?>
            <div class="text-muted small p-3 bg-subtle rounded-3 text-center border border-subtle-c mt-2"><i class="bi bi-chat-dots d-block mb-1" style="font-size:1.2rem;color:var(--text-faint);"></i><span style="font-size:10px;"><?= te('Keine Kommentare') ?></span></div>
            <?php endif; ?>
        </div>
        <?php
        exit();
    }

    if ($aw === 'monitor') {
        header('Content-Type: text/html; charset=utf-8');
        $monitored_urls  = $pdo->query("SELECT * FROM monitored_urls ORDER BY url_name ASC")->fetchAll(PDO::FETCH_ASSOC);
        $_up             = dashboard_uptime($pdo, $monitored_urls);
        $uptime_statuses = $_up['status'];
        $uptime_verlauf  = $_up['verlauf'];
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
            <div class="uptime-item">
                <div class="uptime-header">
                    <div class="text-truncate fw-bold text-strong-c d-flex align-items-center" style="font-size:13px;">
                        <span class="status-dot <?=$dot_class?>"></span><?=htmlspecialchars($url['url_name'])?>
                    </div>
                    <button type="button" class="trash-btn" onclick="triggerDeleteMonitor(<?=$url['id']?>)"><i class="bi bi-trash"></i></button>
                </div>
                <?php
                  // Die Quote der letzten 24 Stunden, sofern der Cron-Lauf
                  // schon gemessen hat. Ohne Verlauf bleibt die Stelle
                  // leer, statt "100 %" zu behaupten.
                  $_v = $uptime_verlauf[(int)$url['id']] ?? null;
                ?>
                <div class="uptime-stats">
                    <span class="<?=!$status['online'] ? 'text-danger fw-bold' : ''?>"><i class="bi bi-activity"></i> <?=$status_text?></span>
                    <?php if($status['code'] > 0): ?><span><i class="bi bi-stopwatch"></i> <?=$status['time']?> ms</span><?php endif; ?>
                    <?php if($_v !== null && $_v['quote'] !== null): ?>
                      <span title="<?= te('Verfügbarkeit der letzten 24 Stunden, %d Messungen', $_v['messungen']) ?>">
                        <i class="bi bi-graph-up"></i> <?= number_format($_v['quote'], 1, ',', '.') ?> %
                      </span>
                    <?php endif; ?>
                </div>
                <?php if($_v !== null && count($_v['punkte']) > 1): ?>
                  <?php /* Der Verlauf als Balkenreihe. aria-hidden: die Zahl
                           daneben sagt dasselbe, und einzelne Striche
                           vorzulesen hilft niemandem. */ ?>
                  <div class="uptime-spark" aria-hidden="true">
                    <?php foreach($_v['punkte'] as $_p): ?>
                      <span class="uptime-tick uptime-tick-<?= htmlspecialchars($_p) ?>"></span>
                    <?php endforeach; ?>
                  </div>
                <?php endif; ?>
            </div>
        <?php endforeach; else: ?>
            <div class="text-muted small p-3 bg-subtle rounded-3 text-center border border-subtle-c mt-2">
                <i class="bi bi-server d-block mb-1" style="font-size:1.5rem;color:var(--text-faint);"></i>
                <?= te('Keine URLs im Monitor.') ?>
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
$portal_feedbacks = $pdo->query("SELECT t.id, t.title, t.client_feedback, c.name as client_name FROM tasks t JOIN contacts c ON t.contact_id = c.id WHERE t.deleted_at IS NULL AND t.client_feedback IS NOT NULL AND t.client_feedback != '' AND t.feedback_seen = 0")->fetchAll(PDO::FETCH_ASSOC);

$portal_ms_comments = [];
try {
    $portal_ms_comments = $pdo->query("SELECT mc.id, mc.message, mc.author_name, mc.created_at, tm.title AS ms_title, t.title AS task_title, c.name AS client_name FROM milestone_comments mc JOIN task_milestones tm ON mc.milestone_id = tm.id JOIN tasks t ON tm.task_id = t.id JOIN contacts c ON t.contact_id = c.id WHERE mc.author = 'client' AND mc.admin_seen = 0 ORDER BY mc.created_at DESC LIMIT 20")->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) { $portal_ms_comments = []; }

// Anstehende Deadlines (Projekte + Rechnungen, nächste 14 Tage + überfällig)
$upcoming_deadlines = $pdo->query("
    SELECT 'task' AS item_type, t.id, t.title, t.deadline AS due_date, c.name AS client_name, DATEDIFF(t.deadline, CURDATE()) AS days_left
    FROM tasks t LEFT JOIN contacts c ON t.contact_id = c.id
    WHERE t.deleted_at IS NULL AND t.status != 'Erledigt' AND t.deadline IS NOT NULL AND t.deadline > '0000-00-00'
      AND t.deadline <= DATE_ADD(CURDATE(), INTERVAL 14 DAY)
    UNION ALL
    SELECT 'invoice', f.id, f.title, f.due_date, c.name, DATEDIFF(f.due_date, CURDATE())
    FROM finances f LEFT JOIN contacts c ON f.contact_id = c.id
    WHERE f.type = 'INCOME' AND f.status IN ('Offen','Überfällig')
      AND f.deleted_at IS NULL
      AND f.due_date IS NOT NULL AND f.due_date > '0000-00-00'
      AND f.due_date <= DATE_ADD(CURDATE(), INTERVAL 14 DAY)
    ORDER BY due_date ASC LIMIT 12
")->fetchAll(PDO::FETCH_ASSOC);

// Nur die Anzahl wird beim Seitenaufbau gebraucht. Die eigentliche
// Prüfung übernimmt der AJAX-Teil (ajax_widget=monitor), der direkt nach
// dem Laden angestoßen wird: ein erreichbarer, aber langsamer Server darf
// nicht den Aufbau der ganzen Seite aufhalten.
$monitored_count = (int)$pdo->query("SELECT COUNT(*) FROM monitored_urls")->fetchColumn();
$open_tasks = $pdo->query("SELECT COUNT(*) FROM tasks WHERE deleted_at IS NULL AND status != 'Erledigt'")->fetchColumn();

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
$total_contacts = $pdo->query("SELECT COUNT(*) FROM contacts WHERE deleted_at IS NULL")->fetchColumn();

// Projekt-Abfrage
$active_projects = $pdo->query("
    SELECT t.id, t.title, t.status, t.deadline, c.name as client_name
    FROM tasks t
    LEFT JOIN contacts c ON t.contact_id = c.id
    WHERE t.deleted_at IS NULL AND t.status NOT IN ('Erledigt','Storniert')
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
// Menue zum Ein- und Ausblenden der Widgets.
//
// Hier gebaut und nicht im JavaScript: die Haekchen stimmen damit schon im
// ersten Bild, ohne dass die Seite nach dem Laden noch einmal umspringt.
$dash_menu = '';
foreach (dash_layout()['items'] as $dash_id => $dash_i) {
    $dash_menu .= '<label class="dropdown-item dash-menu-item">'
        . '<input class="form-check-input" type="checkbox" data-dash-toggle="'
        . htmlspecialchars($dash_id, ENT_QUOTES) . '"' . ($dash_i['hidden'] ? '' : ' checked') . '>'
        . '<span>' . htmlspecialchars($dash_i['title']) . '</span></label>';
}
$header_actions = '
      <div class="d-flex align-items-center gap-3">
        <span id="live_indicator" title="Daten werden automatisch aktualisiert"
              style="font-size:var(--text-2xs);color:var(--text-muted);display:flex;align-items:center;gap:5px;">
          <span id="live_dot" style="width:7px;height:7px;border-radius:50%;background:var(--accent-success);display:inline-block;"></span>
          <span id="live_label">Live</span>
        </span>
        <div class="dropdown">
          <button class="btn btn-outline-secondary btn-sm" type="button" data-bs-toggle="dropdown"
                  data-bs-auto-close="outside" aria-expanded="false"
                  title="' . te('Widgets ein- und ausblenden') . '">
            <i class="bi bi-grid-1x2"></i> <span class="btn-label">' . te('Widgets') . '</span>
          </button>
          <div class="dropdown-menu dropdown-menu-end shadow dash-menu">
            <h6 class="dropdown-header">' . te('Auf der Startseite zeigen') . '</h6>
            ' . $dash_menu . '
            <hr class="dropdown-divider">
            <button type="button" class="dropdown-item dash-menu-reset" id="dashResetBtn">
              <i class="bi bi-arrow-counterclockwise"></i> ' . te('Standard wiederherstellen') . '
            </button>
          </div>
        </div>
        <a href="tasks" class="btn btn-outline-primary btn-sm fw-bold"><i class="bi bi-card-list"></i> <span class="btn-label">' . te('Zu den Projekten') . '</span></a>
      </div>';
// Verkettung statt Nowdoc: die Einbindungen brauchen den Zeitstempel aus
// asset(), und ein Nowdoc setzt nichts ein.
$extra_head = '<link rel="stylesheet" href="' . asset('assets/vendor/gridstack/gridstack.min.css') . '">' . "\n"
            . '<script src="' . asset('assets/vendor/gridstack/gridstack-all.js') . '"></script>';

require 'includes/head.php';
require 'includes/layout_start.php';
?>

  <div class="toast-container-dash">
      <?php if($count_leads > 0): ?>
      <div class="toast align-items-center text-bg-warning border-0 mb-2 shadow-lg" role="alert" aria-live="assertive" aria-atomic="true" data-bs-delay="6000">
          <div class="d-flex">
              <div class="toast-body fw-bold text-dark">
                  <i class="bi bi-envelope-paper-fill me-2 fs-5 align-middle"></i> 
                  <span class="align-middle"><?= $count_leads ?> <?= te('neue Website-Anfrage(n)!') ?></span>
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
                  <span class="align-middle"><?= $count_tickets ?> <?= te('offene(s) Support-Ticket(s)!') ?></span>
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
                  <span class="align-middle"><?= $count_uploads ?> <?= te('neue Datei(en) im Portal!') ?></span>
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
                  <span class="align-middle"><?= $count_feedbacks ?> <?= te('neues Kunden-Feedback!') ?></span>
              </div>
              <button type="button" class="btn-close btn-close-dark me-2 m-auto" data-bs-dismiss="toast"></button>
          </div>
      </div>
      <?php endif; ?>

      <?php if($count_ms_comments > 0): ?>
      <div class="toast align-items-center border-0 mb-2 shadow-lg" style="background:var(--color-primary);color:var(--text-invert);" role="alert" aria-live="assertive" aria-atomic="true" data-bs-delay="9500">
          <div class="d-flex">
              <div class="toast-body fw-bold">
                  <i class="bi bi-chat-dots-fill me-2 fs-5 align-middle"></i>
                  <span class="align-middle"><?= $count_ms_comments ?> <?= te('neue(r) Meilenstein-Kommentar(e)!') ?></span>
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
                  <span class="align-middle"><?= $count_approvals ?> <?= te('abgesegnete(r) Meilenstein(e)!') ?></span>
              </div>
              <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
          </div>
      </div>
      <?php endif; ?>
  </div>

    <div class="grid-stack" id="dashGrid">

      <!-- Projekte KPI -->
      <?php dash_widget_open('kpi_projects'); ?>
        <a href="tasks" class="text-decoration-none d-flex h-100">
          <div class="widget-box widget-accent-left w-100 h-100 d-flex flex-column align-items-center justify-content-center text-center kpi-mini-card">
            <div class="icon-tile icon-tile-primary kpi-icon mb-2"><i class="bi bi-briefcase"></i></div>
            <div class="kpi-number mb-1" id="kpi_open_tasks"><?=$open_tasks?></div>
            <div class="kpi-label"><?= te('Projekte') ?></div>
            <div class="small text-muted d-none d-md-block"><?= te('offene Aufgaben') ?></div>
          </div>
        </a>
      <?php dash_widget_close(); ?>

      <!-- CRM KPI -->
      <?php dash_widget_open('kpi_contacts'); ?>
        <a href="contacts" class="text-decoration-none d-flex h-100">
          <div class="widget-box widget-accent-left w-100 h-100 d-flex flex-column align-items-center justify-content-center text-center kpi-mini-card">
            <div class="icon-tile icon-tile-primary kpi-icon mb-2"><i class="bi bi-people"></i></div>
            <div class="kpi-number mb-1" id="kpi_total_contacts"><?=$total_contacts?></div>
            <div class="kpi-label"><?= te('Kontakte') ?></div>
            <div class="small text-muted d-none d-md-block"><?= te('Kontakte') ?></div>
          </div>
        </a>
      <?php dash_widget_close(); ?>

      <!-- Website-Anfragen -->
      <?php dash_widget_open('leads'); ?>
        <div class="widget-box widget-accent-left h-100">
           <div class="widget-title">
               <span><i class="bi bi-envelope-paper-fill"></i> <?= te('Neue Website-Anfragen') ?></span>
               <span class="widget-count<?= count($leads) > 0 ? ' widget-count-warning' : '' ?>" id="leads_count"><?= count($leads) ?></span>
           </div>
           
           <div id="leads_widget_body">
           <?php if(count($leads) > 0): ?>
               <div class="list-group scroll-container">
                 <?php foreach($leads as $lead): ?>
                   <div class="list-group-item d-flex justify-content-between align-items-center py-2 lead-item" onclick='openLeadModal(<?=json_encode($lead, JSON_HEX_TAG|JSON_HEX_APOS)?>)'>
                     <div class="flex-grow-1 pe-3">
                       <h6 class="mb-1 fw-bold fs-6 text-strong-c"><?php echo htmlspecialchars($lead['name']); ?></h6>
                       <p class="mb-0 text-muted small text-truncate" style="font-size:11px; max-width: 200px;"><?php echo htmlspecialchars($lead['subject']); ?></p>
                     </div>
                     <div class="d-flex gap-2">
                       <button type="button" class="btn btn-sm btn-outline-danger" onclick="event.stopPropagation(); triggerDeleteLead(<?php echo $lead['id']; ?>)"><i class="bi bi-trash" style="pointer-events:none;"></i></button>
                     </div>
                   </div>
                 <?php endforeach; ?>
               </div>
           <?php else: ?>
               <div class="text-muted small p-3 bg-subtle rounded-3 text-center border border-subtle-c">
                   <i class="bi bi-inbox d-block mb-1" style="font-size: 1.5rem; color:var(--text-faint);"></i>
                   <?= te('Keine neuen Anfragen über die Website.') ?>
               </div>
           <?php endif; ?>
           </div>
        </div>
      <?php dash_widget_close(); ?>

      <?php dash_widget_open('tickets'); ?>
        <div class="widget-box widget-accent-left h-100">
           <div class="widget-title">
               <span><i class="bi bi-life-preserver"></i> <?= te('Offene Support-Tickets') ?></span>
               <span class="d-flex align-items-center gap-2">
                   <span class="widget-count<?= count($tickets) > 0 ? ' widget-count-danger' : '' ?>" id="tickets_count"><?= count($tickets) ?></span>
                   <a href="tickets" class="btn btn-sm btn-link p-0 text-muted" aria-label="<?= te('Alle Tickets öffnen') ?>"><i class="bi bi-arrow-right"></i></a>
               </span>
           </div>
           
           <div id="tickets_widget_body">
           <?php if(count($tickets) > 0): ?>
               <div class="list-group scroll-container">
                 <?php foreach($tickets as $tick): ?>
                   <a href="tickets" class="list-group-item list-group-item-action d-flex justify-content-between align-items-center py-2 border-0 border-bottom">
                     <div class="flex-grow-1 pe-3">
                       <h6 class="mb-1 fw-bold fs-6 text-strong-c"><?php echo htmlspecialchars($tick['contact_name']); ?></h6>
                       <p class="mb-0 text-muted small text-truncate" style="font-size:11px; max-width: 200px;"><?php echo htmlspecialchars($tick['subject']); ?></p>
                     </div>
                     <div>
                         <span class="badge <?= $tick['status'] == 'Offen' ? 'bg-warning text-dark' : 'bg-primary' ?>" style="font-size:10px;"><?= htmlspecialchars(datenwert($tick['status'])) ?></span>
                     </div>
                   </a>
                 <?php endforeach; ?>
               </div>
           <?php else: ?>
               <div class="text-muted small p-3 bg-subtle rounded-3 text-center border border-subtle-c">
                   <i class="bi bi-check2-all d-block mb-1" style="font-size: 1.5rem; color:var(--text-faint);"></i>
                   <?= te('Keine offenen Support-Anfragen.') ?>
               </div>
           <?php endif; ?>
           </div>
        </div>
      <?php dash_widget_close(); ?>

      <?php dash_widget_open('portal_activity'); ?>
            <div class="widget-box widget-accent-left">
                <div class="widget-title"><span><i class="bi bi-magic"></i> <?= te('Portal Aktivitäten') ?></span></div>
                
                <div class="scroll-container">
                    <div class="row g-3 w-100" id="portal_activity_body">
                        
                        <div class="col-md-3">
                            <h6 class="section-label"><?= te('Uploads') ?></h6>
                            <?php if(count($portal_uploads) > 0): ?>
                                <?php foreach($portal_uploads as $u): ?>
                                    <div class="position-relative bg-surface border border-subtle-c rounded-3 p-3 mb-2 portal-item-hover">
                                        <form method="POST" class="position-absolute" style="top: 5px; right: 5px;">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="activity_type" value="upload">
                                            <input type="hidden" name="activity_id" value="<?=$u['id']?>">
                                            <button type="submit" name="dismiss_portal_activity" class="btn-close" style="font-size: 0.65rem;" title="<?= te('Ausblenden') ?>"></button>
                                        </form>
                                        <a href="tasks?q=<?=urlencode($u['task_title'])?>" class="text-decoration-none d-block pe-3">
                                            <span class="badge bg-primary bg-opacity-10 text-primary mb-2" style="font-size: 9px; letter-spacing: 0.5px;"><?= te('DATEI') ?></span>
                                            <div class="fw-bold text-strong-c text-truncate mb-1" style="font-size: 13px;"><?=htmlspecialchars($u['file_name'])?></div>
                                            <div class="text-muted small text-truncate"><i class="bi bi-person"></i> <?=htmlspecialchars($u['client_name'])?></div>
                                        </a>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div class="text-muted small p-3 bg-subtle rounded-3 text-center border border-subtle-c mt-2">
                                    <i class="bi bi-file-earmark-check d-block mb-1" style="font-size: 1.2rem; color:var(--text-faint);"></i>
                                    <span style="font-size:10px;"><?= te('Keine Uploads') ?></span>
                                </div>
                            <?php endif; ?>
                        </div>

                        <div class="col-md-3">
                            <h6 class="section-label"><?= te('Absegnungen') ?></h6>
                            <?php if(count($portal_approvals) > 0): ?>
                                <?php foreach($portal_approvals as $a): ?>
                                    <div class="position-relative bg-surface border border-subtle-c rounded-3 p-3 mb-2 portal-item-hover">
                                        <form method="POST" class="position-absolute" style="top: 5px; right: 5px;">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="activity_type" value="approval">
                                            <input type="hidden" name="activity_id" value="<?=$a['id']?>">
                                            <button type="submit" name="dismiss_portal_activity" class="btn-close" style="font-size: 0.65rem;" title="<?= te('Ausblenden') ?>"></button>
                                        </form>
                                        <a href="tasks?q=<?=urlencode($a['task_title'])?>" class="text-decoration-none d-block pe-3">
                                            <span class="badge bg-success bg-opacity-10 text-success mb-2" style="font-size: 9px; letter-spacing: 0.5px;"><?= te('BESTÄTIGT') ?></span>
                                            <div class="fw-bold text-strong-c text-truncate mb-1" style="font-size: 13px;"><?=htmlspecialchars($a['title'])?></div>
                                            <div class="text-muted small text-truncate"><i class="bi bi-person"></i> <?=htmlspecialchars($a['client_name'])?></div>
                                        </a>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div class="text-muted small p-3 bg-subtle rounded-3 text-center border border-subtle-c mt-2">
                                    <i class="bi bi-check-circle d-block mb-1" style="font-size: 1.2rem; color:var(--text-faint);"></i>
                                    <span style="font-size:10px;"><?= te('Keine Absegnungen') ?></span>
                                </div>
                            <?php endif; ?>
                        </div>

                        <div class="col-md-3">
                            <h6 class="section-label">Feedback</h6>
                            <?php if(count($portal_feedbacks) > 0): ?>
                                <?php foreach($portal_feedbacks as $f): ?>
                                    <div class="position-relative bg-surface border border-subtle-c rounded-3 p-3 mb-2 portal-item-hover">
                                        <form method="POST" class="position-absolute" style="top: 5px; right: 5px;">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="activity_type" value="feedback">
                                            <input type="hidden" name="activity_id" value="<?=$f['id']?>">
                                            <button type="submit" name="dismiss_portal_activity" class="btn-close" style="font-size: 0.65rem;" title="<?= te('Ausblenden') ?>"></button>
                                        </form>
                                        <a href="tasks?q=<?=urlencode($f['title'])?>" class="text-decoration-none d-block pe-3">
                                            <span class="badge bg-warning bg-opacity-10 text-dark mb-2" style="font-size: 9px; letter-spacing: 0.5px;"><?= te('NEUES FEEDBACK') ?></span>
                                            <div class="fw-bold text-strong-c text-truncate mb-1" style="font-size: 13px;"><?=htmlspecialchars($f['title'])?></div>
                                            <div class="text-muted fst-italic text-truncate" style="font-size:11px;">"<?=htmlspecialchars($f['client_feedback'])?>"</div>
                                        </a>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div class="text-muted small p-3 bg-subtle rounded-3 text-center border border-subtle-c mt-2">
                                    <i class="bi bi-chat-dots d-block mb-1" style="font-size: 1.2rem; color:var(--text-faint);"></i>
                                    <span style="font-size:10px;"><?= te('Kein neues Feedback') ?></span>
                                </div>
                            <?php endif; ?>
                        </div>

                        <div class="col-md-3">
                            <h6 class="section-label"><?= te('Kommentare') ?></h6>
                            <?php if(count($portal_ms_comments) > 0): ?>
                                <?php foreach($portal_ms_comments as $mc): ?>
                                    <div class="position-relative bg-surface border border-subtle-c rounded-3 p-3 mb-2 portal-item-hover">
                                        <form method="POST" class="position-absolute" style="top: 5px; right: 5px;">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="activity_type" value="ms_comment">
                                            <input type="hidden" name="activity_id" value="<?=$mc['id']?>">
                                            <button type="submit" name="dismiss_portal_activity" class="btn-close" style="font-size: 0.65rem;" title="<?= te('Ausblenden') ?>"></button>
                                        </form>
                                        <a href="tasks?q=<?=urlencode($mc['task_title'])?>" class="text-decoration-none d-block pe-3">
                                            <span class="badge mb-2" style="background:var(--neutral-soft);color:var(--text-muted);font-size:9px;letter-spacing:.5px;"><?= te('KOMMENTAR') ?></span>
                                            <div class="fw-bold text-strong-c text-truncate mb-1" style="font-size: 13px;"><?=htmlspecialchars($mc['ms_title'])?></div>
                                            <div class="text-muted fst-italic text-truncate" style="font-size:11px;">"<?=htmlspecialchars(mb_strimwidth($mc['message'],0,60,'…'))?>"</div>
                                            <div class="text-muted small mt-1 text-truncate"><i class="bi bi-person"></i> <?=htmlspecialchars($mc['client_name'])?></div>
                                        </a>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div class="text-muted small p-3 bg-subtle rounded-3 text-center border border-subtle-c mt-2">
                                    <i class="bi bi-chat-dots d-block mb-1" style="font-size: 1.2rem; color:var(--text-faint);"></i>
                                    <span style="font-size:10px;"><?= te('Keine Kommentare') ?></span>
                                </div>
                            <?php endif; ?>
                        </div>

                    </div>
                </div>
            </div>
      <?php dash_widget_close(); ?>

      <?php dash_widget_open('monitor'); ?>
            <div class="widget-box widget-accent-left">
                <div class="widget-title">
                    <span><i class="bi bi-hdd-network"></i> <?= te('System-Monitor') ?></span>
                    <button class="btn btn-sm btn-link p-0 text-muted" data-bs-toggle="modal" data-bs-target="#addMonitorModal" aria-label="<?= te('URL zum Monitor hinzufügen') ?>"><i class="bi bi-plus-circle"></i></button>
                </div>
                
                <div class="scroll-container" id="monitor_widget_body">
                    <?php if ($monitored_count > 0): ?>
                        <?php for ($i = 0; $i < min($monitored_count, 3); $i++): ?>
                        <div class="uptime-item" aria-hidden="true">
                            <div class="uptime-header">
                                <div class="d-flex align-items-center gap-2">
                                    <span class="status-dot" style="background:var(--border-strong);"></span>
                                    <span class="skeleton-line" style="width:120px;"></span>
                                </div>
                            </div>
                            <div class="uptime-stats"><span class="skeleton-line" style="width:60px;"></span></div>
                        </div>
                        <?php endfor; ?>
                        <div class="text-muted text-center" style="font-size:var(--text-2xs);">
                            <span class="spinner-border spinner-border-sm me-1" role="status"></span><?= te('Status wird geprüft …') ?>
                        </div>
                    <?php else: ?>
                        <div class="text-muted small p-3 bg-subtle rounded-3 text-center border border-subtle-c mt-2">
                            <i class="bi bi-server d-block mb-1" style="font-size:1.5rem;color:var(--text-faint);"></i>
                            <?= te('Keine URLs im Monitor.') ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
      <?php dash_widget_close(); ?>


      <!-- Deadlines -->
      <?php dash_widget_open('deadlines'); ?>
        <div class="widget-box widget-accent-left">
          <div class="d-flex justify-content-between align-items-center mb-2">
            <div class="d-flex align-items-center gap-2">
              <div class="icon-tile icon-tile-primary"><i class="bi bi-alarm"></i></div>
              <div>
                <div class="label-xs"><?= te('Deadlines') ?></div>
                <div class="fw-bold fs-5 lh-1 text-strong-c" id="kpi_deadlines"><?=count($upcoming_deadlines)?></div>
              </div>
            </div>
            <a href="calendar" class="text-muted"><i class="bi bi-arrow-right-short fs-5"></i></a>
          </div>
          <div id="deadlines_list_body">
          <?php foreach(array_slice($upcoming_deadlines, 0, 2) as $dl):
            $d2 = (int)$dl['days_left'];
            // Farbe = Dringlichkeit. Ohne Dringlichkeit bleibt der Chip neutral.
            $k2 = $d2 < 0 ? 'due-overdue' : ($d2 === 0 ? 'due-today' : ($d2 <= 3 ? 'due-soon' : ''));
            $l2 = $d2 < 0 ? t('Überfällig') : ($d2 === 0 ? t('Heute') : t('in %d T.', $d2));
          ?>
          <div class="d-flex align-items-center gap-2 pt-2 border-top border-subtle-c">
            <span class="due-chip <?=$k2?>"><?=$l2?></span>
            <span class="small text-truncate text-strong-c" style="font-size:var(--text-2xs);"><?=htmlspecialchars(mb_strimwidth($dl['title'],0,20,'…'))?></span>
          </div>
          <?php endforeach; ?>
          <?php if (empty($upcoming_deadlines)): ?>
          <div class="small text-muted pt-2 border-top border-subtle-c"><i class="bi bi-check2-circle me-1 text-success"></i><?= te('Keine Deadlines') ?></div>
          <?php endif; ?>
          </div>
        </div>
      <?php dash_widget_close(); ?>

      <!-- Termine -->
      <?php dash_widget_open('appointments'); ?>
        <?php $all_apts = array_merge($today_appointments, $week_appointments); ?>
        <div class="widget-box widget-accent-left">
          <div class="d-flex justify-content-between align-items-center mb-2">
            <div class="d-flex align-items-center gap-2">
              <div class="icon-tile icon-tile-primary"><i class="bi bi-calendar2-event"></i></div>
              <div>
                <div class="label-xs"><?= te('Termine') ?></div>
                <div class="fw-bold fs-5 lh-1 text-strong-c" id="kpi_termine"><?=count($all_apts)?></div>
              </div>
            </div>
            <a href="calendar" class="text-muted"><i class="bi bi-arrow-right-short fs-5"></i></a>
          </div>
          <div id="termine_list_body">
          <?php foreach(array_slice($all_apts, 0, 2) as $apt):
            $ac   = htmlspecialchars($apt['color'] ?: '#6c757d');
            $adys = isset($apt['event_date']) ? (int)round((strtotime($apt['event_date']) - strtotime('today')) / 86400) : 0;
            $albl = $adys === 0 ? t('Heute') : ($adys === 1 ? t('Morgen') : t('in %d T.', $adys));
          ?>
          <div class="d-flex align-items-center gap-2 pt-2 border-top border-subtle-c">
            <span class="due-chip"><span class="status-dot m-0" style="background:<?=$ac?>;width:8px;height:8px;"></span><?=$albl?></span>
            <span class="small text-truncate text-strong-c" style="font-size:var(--text-2xs);"><?=htmlspecialchars(mb_strimwidth($apt['title'],0,20,'…'))?></span>
          </div>
          <?php endforeach; ?>
          <?php if (empty($all_apts)): ?>
          <div class="small text-muted pt-2 border-top border-subtle-c"><i class="bi bi-calendar-check me-1 text-success"></i><?= te('Keine Termine') ?></div>
          <?php endif; ?>
          </div>
        </div>
      <?php dash_widget_close(); ?>

      <!-- Webspace -->
      <?php dash_widget_open('webspace'); ?>
        <?php
          // Der Balken faerbt sich erst, wenn der Platz wirklich knapp wird.
          $ws_pct  = (float)$percent_used;
          $ws_tile = $ws_pct >= 90 ? 'icon-tile-danger' : ($ws_pct >= 75 ? 'icon-tile-warning' : 'icon-tile-primary');
          $ws_bar  = $ws_pct >= 90 ? 'var(--accent-danger)' : ($ws_pct >= 75 ? 'var(--accent-warning)' : 'var(--color-primary)');
        ?>
        <div class="widget-box widget-accent-left d-flex align-items-start gap-3">
          <div class="icon-tile <?=$ws_tile?>"><i class="bi bi-hdd-fill"></i></div>
          <div class="w-100">
            <div class="label-xs"><?= te('Webspace') ?></div>
            <div class="d-flex justify-content-between fw-bold small my-1 text-strong-c"><span><?=$used_gb?> GB</span><span><?=$percent_used?>%</span></div>
            <div class="progress mb-1 bg-sunken" style="height:5px;" role="progressbar" aria-valuenow="<?=(int)$percent_used?>" aria-valuemin="0" aria-valuemax="100" aria-label="<?= te('Belegter Webspace') ?>"><div class="progress-bar" style="width:<?=$percent_used?>%;background:<?=$ws_bar?>;"></div></div>
            <div class="small text-muted" style="font-size:var(--text-xs);"><?= te('von 200 GB belegt') ?></div>
          </div>
        </div>
      <?php dash_widget_close(); ?>


      <?php dash_widget_open('projects'); ?>
            <div class="widget-box mt-0 h-100 d-flex flex-column">
                <div class="widget-title flex-wrap gap-2">
                    <span><i class="bi bi-kanban me-2"></i> <?= te('Laufende Projekte') ?></span>
                    <div class="d-flex gap-1 align-items-center flex-wrap">
                        <button class="btn btn-sm py-0 px-2 btn-primary proj-filter" data-filter="all" style="font-size:11px;"><?= te('Alle') ?></button>
                        <button class="btn btn-sm py-0 px-2 btn-outline-secondary proj-filter" data-filter="Offen" style="font-size:11px;"><?= te('Offen') ?></button>
                        <button class="btn btn-sm py-0 px-2 btn-outline-secondary proj-filter" data-filter="In Bearbeitung" style="font-size:11px;"><?= te('Läuft') ?></button>
                        <select id="projSort" class="form-select form-select-sm py-0 ms-1" style="width:auto;font-size:11px;">
                            <option value="newest"><?= te('Neueste') ?></option>
                            <option value="deadline"><?= te('Deadline') ?></option>
                            <option value="progress_asc"><?= te('Fortschritt ↑') ?></option>
                            <option value="progress_desc"><?= te('Fortschritt ↓') ?></option>
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
                                        <div class="dash-project-card">
                                            <div class="d-flex justify-content-between align-items-start mb-3">
                                                <h6 class="fw-bold m-0 text-strong-c text-truncate pe-2" style="font-size: 15px;"><?=htmlspecialchars($p['title'])?></h6>
                                                <?= status_badge($p['status']) ?>
                                            </div>
                                            
                                            <div class="d-flex justify-content-between align-items-center mb-3 small text-muted">
                                                <span class="text-truncate" style="max-width: 60%;"><i class="bi bi-person-fill me-1"></i> <?= $p['client_name'] ? htmlspecialchars($p['client_name']) : te('Kein Kunde') ?></span>
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
                                                    <span class="text-muted text-uppercase"><?= te('Fortschritt') ?></span>
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
                        <div id="projEmpty" class="text-muted small p-3 bg-subtle rounded-3 text-center border border-subtle-c mt-2" style="display:none;">
                            <i class="bi bi-funnel d-block mb-1" style="font-size:1.5rem;color:var(--text-faint);"></i>
                            <?= te('Kein Projekt entspricht diesem Filter.') ?>
                        </div>
                    <?php else: ?>
                        <div class="text-muted small p-3 bg-subtle rounded-3 text-center border border-subtle-c mt-2">
                            <i class="bi bi-clipboard-check d-block mb-1" style="font-size: 1.5rem; color:var(--text-faint);"></i>
                            <?= te('Aktuell keine laufenden Projekte.') ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
      <?php dash_widget_close(); ?>
      <?php dash_widget_open('notes'); ?>
            <div class="widget-box mt-0 h-100 d-flex flex-column">
                <div class="widget-title">
                    <span><i class="bi bi-pen me-2"></i> <?= te('Notizen') ?></span>
                    <button type="button" class="btn btn-xs btn-link text-danger p-0" onclick="triggerClearNotes()">
                        <i class="bi bi-trash" style="pointer-events:none;"></i>
                    </button>
                </div>
                <textarea id="quickNotes" class="form-control flex-grow-1 shadow-sm border bg-subtle p-3" style="font-size: 14px; resize: none; min-height: 250px;" placeholder="<?= te('Wird automatisch gespeichert...') ?>"></textarea>
            </div>
      <?php dash_widget_close(); ?>
    </div>

  <div class="modal fade" id="viewLeadModal" tabindex="-1">
      <div class="modal-dialog modal-lg">
          <div class="modal-content border-0 shadow">
              <div class="modal-header bg-warning">
                  <h5 class="modal-title text-strong-c fw-bold"><i class="bi bi-envelope-open me-2"></i><?= te('Anfrage Details') ?></h5>
                  <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
              </div>
              <div class="modal-body p-4">
                  <div class="row mb-3">
                      <div class="col-md-6">
                          <label class="small text-muted fw-bold mb-1"><?= te('Name') ?></label>
                          <div class="p-2 bg-subtle rounded border border-subtle-c fw-bold text-strong-c" id="vl_name"></div>
                      </div>
                      <div class="col-md-6 mt-3 mt-md-0">
                          <label class="small text-muted fw-bold mb-1">E-Mail</label>
                          <div class="p-2 bg-subtle rounded border" id="vl_email"></div>
                      </div>
                  </div>
                  
                  <div class="row mb-3">
                      <div class="col-md-6">
                          <label class="small text-muted fw-bold mb-1"><?= te('Telefon') ?></label>
                          <div class="p-2 bg-subtle rounded border" id="vl_phone"></div>
                      </div>
                      <div class="col-md-6 mt-3 mt-md-0">
                          <label class="small text-muted fw-bold mb-1"><?= te('Quelle') ?></label>
                          <div class="p-2 bg-subtle rounded border" id="vl_source"></div>
                      </div>
                  </div>

                  <div class="mb-3">
                      <label class="small text-muted fw-bold mb-1"><?= te('Betreff') ?></label>
                      <div class="p-2 bg-subtle rounded border fw-bold text-primary" id="vl_subject"></div>
                  </div>

                  <div>
                      <label class="small text-muted fw-bold mb-1"><?= te('Nachricht') ?></label>
                      <div class="p-3 bg-subtle rounded border" id="vl_message" style="min-height: 100px; white-space: pre-wrap;"></div>
                  </div>
              </div>
              <div class="modal-footer d-flex justify-content-between bg-subtle">
                  <button type="button" class="btn btn-secondary fw-bold px-4" data-bs-dismiss="modal"><?= te('Schließen') ?></button>
                  <form action="index" method="POST" style="margin:0;">
                      <?= csrf_field() ?>
                      <input type="hidden" name="inbox_action" value="accept_lead">
                      <input type="hidden" name="lead_id" id="vl_id">
                      <button type="submit" class="btn btn-success fw-bold px-4"><i class="bi bi-person-plus-fill me-1"></i> <?= te('Als Kontakt übernehmen') ?></button>
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
                      <h6 class="modal-title m-0 fw-bold"><i class="bi bi-plus-circle me-2"></i><?= te('URL hinzufügen') ?></h6>
                      <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                  </div>
                  <div class="modal-body p-4 bg-subtle">
                      <label class="form-label small fw-bold"><?= te('Projekt / Name *') ?></label>
                      <input type="text" name="url_name" class="form-control mb-3" placeholder="<?= te('Kunde XYZ') ?>" required>
                      
                      <label class="form-label small fw-bold"><?= te('URL / Domain *') ?></label>
                      <input type="text" name="url_link" class="form-control" placeholder="https://..." required>
                  </div>
                  <div class="modal-footer bg-subtle p-2">
                      <button type="submit" class="btn btn-primary w-100 fw-bold"><?= te('Speichern') ?></button>
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
                      <h6 class="modal-title m-0"><i class="bi bi-exclamation-triangle-fill me-2"></i><?= te('URL entfernen?') ?></h6>
                      <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                  </div>
                  <div class="modal-body text-center py-4">
                      <p class="mb-0 fw-bold"><?= te('Soll diese Domain aus der Überwachung entfernt werden?') ?></p>
                  </div>
                  <div class="modal-footer p-2 d-flex justify-content-between bg-subtle">
                      <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal"><?= te('Abbrechen') ?></button>
                      <button type="submit" class="btn btn-danger btn-sm px-3 fw-bold"><?= te('Ja, entfernen') ?></button>
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
                      <h6 class="modal-title m-0"><i class="bi bi-exclamation-triangle-fill me-2"></i><?= te('Anfrage löschen?') ?></h6>
                      <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                  </div>
                  <div class="modal-body text-center py-4">
                      <p class="mb-0 fw-bold"><?= te('Die Nachricht unwiderruflich löschen?') ?></p>
                  </div>
                  <div class="modal-footer p-2 d-flex justify-content-between bg-subtle">
                      <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal"><?= te('Abbrechen') ?></button>
                      <button type="submit" class="btn btn-danger btn-sm px-3 fw-bold"><?= te('Ja, löschen') ?></button>
                  </div>
              </form>
          </div>
      </div>
  </div>

  <div class="modal fade" id="clearNotesModal" tabindex="-1">
      <div class="modal-dialog modal-sm modal-dialog-centered">
          <div class="modal-content border-0 shadow">
              <div class="modal-header bg-danger text-white">
                  <h6 class="modal-title m-0"><i class="bi bi-exclamation-triangle-fill me-2"></i><?= te('Notizen leeren?') ?></h6>
                  <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
              </div>
              <div class="modal-body text-center py-4">
                  <p class="mb-0 fw-bold"><?= te('Möchtest du den gesamten Text im Notizblock endgültig löschen?') ?></p>
              </div>
              <div class="modal-footer p-2 d-flex justify-content-between bg-subtle">
                  <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal"><?= te('Abbrechen') ?></button>
                  <button type="button" class="btn btn-danger btn-sm px-3 fw-bold" onclick="executeClearNotes()"><?= te('Ja, leeren') ?></button>
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
        document.getElementById('vl_phone').textContent = lead.phone || <?= tjs('Keine Angabe') ?>;
        document.getElementById('vl_source').textContent = lead.source || 'Unbekannt';
        document.getElementById('vl_subject').textContent = lead.subject || '-';
        document.getElementById('vl_message').textContent = lead.message || <?= tjs('Keine Nachricht hinterlassen.') ?>;

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
            leads:       { icon: 'bi-envelope-paper-fill', bg: 'bg-warning',  tc: 'text-dark',  btn: 'btn-close-dark',  msg: n => n + ' <?= te('neue Website-Anfrage(n)!') ?>' },
            tickets:     { icon: 'bi-life-preserver',      bg: 'bg-danger',   tc: 'text-white', btn: 'btn-close-white', msg: n => n + ' <?= te('offene(s) Support-Ticket(s)!') ?>' },
            uploads:     { icon: 'bi-cloud-arrow-up-fill', bg: 'bg-info',     tc: 'text-dark',  btn: 'btn-close-dark',  msg: n => n + ' <?= te('neue Datei(en) im Portal!') ?>' },
            approvals:   { icon: 'bi-check-circle-fill',   bg: 'bg-success',  tc: 'text-white', btn: 'btn-close-white', msg: n => n + ' <?= te('abgesegnete(r) Meilenstein(e)!') ?>' },
            feedbacks:   { icon: 'bi-chat-left-text-fill', bg: 'bg-warning',  tc: 'text-dark',  btn: 'btn-close-dark',  msg: n => n + ' <?= te('neues Kunden-Feedback!') ?>' },
            ms_comments: { icon: 'bi-chat-dots-fill',      bg: '',            tc: 'text-white', btn: 'btn-close-white', msg: n => n + ' <?= te('neue(r) Meilenstein-Kommentar(e)!') ?>', style: 'background:var(--color-primary);color:var(--text-invert);' },
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
            dot.style.background = ok ? 'var(--accent-success)' : 'var(--accent-danger)';
            if (label) label.textContent = ok ? 'Live' : 'Fehler';
        }

        function pulse() {
            const dot = document.getElementById('live_dot');
            if (!dot) return;
            dot.style.transform = 'scale(1.6)';
            dot.style.transition = 'transform .2s';
            setTimeout(() => { dot.style.transform = 'scale(1)'; }, 300);
        }

        // Prioritaet als vierstufige Skala aus Tokens statt vier roher Hex-Werte.
        const prioColors = {
            Kritisch: 'var(--accent-danger)',
            Hoch:     'var(--accent-warning)',
            Mittel:   'var(--color-primary)',
            Niedrig:  'var(--text-faint)',
        };

        /** Setzt den Zaehler im Widget-Kopf. Farbe nur, wenn etwas offen ist. */
        function setWidgetCount(id, n, variant) {
            const el = document.getElementById(id);
            if (!el) return;
            el.textContent = n;
            el.className = 'widget-count' + (n > 0 ? ' ' + variant : '');
        }

        function renderTicketsWidget(list) {
            setWidgetCount('tickets_count', (list || []).length, 'widget-count-danger');
            const el = document.getElementById('tickets_widget_body');
            if (!el) return;
            if (!list || list.length === 0) {
                el.innerHTML = '<div class="text-muted small p-3 bg-subtle rounded-3 text-center border border-subtle-c"><i class="bi bi-check2-all d-block mb-1" style="font-size:1.5rem;color:var(--text-faint);"></i><?= te('Keine offenen Support-Anfragen.') ?></div>';
                return;
            }
            // Die Zustaende stehen deutsch in der Datenbank; datenwert()
            // uebersetzt sie nur fuer die Anzeige. Serverseitig passiert
            // das im PHP-Teil oben, hier braucht der Browser dieselbe
            // Zuordnung.
            const ticketStatusLabels = <?= json_encode([
                'Offen'          => datenwert('Offen'),
                'In Bearbeitung' => datenwert('In Bearbeitung'),
                'Erledigt'       => datenwert('Erledigt'),
            ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>;
            const rows = list.map(t => {
                const name    = (t.contact_name || '').replace(/</g,'&lt;');
                const subject = (t.subject      || '').replace(/</g,'&lt;');
                const badgeCls = t.status === 'Offen' ? 'bg-warning text-dark' : 'bg-primary';
                const statusLbl = ticketStatusLabels[t.status] || t.status;
                const pc = prioColors[t.priority] || 'var(--text-faint)';
                return `<a href="tickets" class="list-group-item list-group-item-action d-flex justify-content-between align-items-center py-2 border-0 border-bottom">
                    <div class="flex-grow-1 pe-3 d-flex align-items-center gap-2">
                        <span style="width:8px;height:8px;border-radius:50%;background:${pc};display:inline-block;flex-shrink:0;" title="${t.priority || ''}"></span>
                        <div><h6 class="mb-1 fw-bold fs-6 text-strong-c">${name}</h6>
                        <p class="mb-0 text-muted small text-truncate" style="font-size:11px;max-width:200px;">${subject}</p></div>
                    </div>
                    <span class="badge ${badgeCls}" style="font-size:10px;">${statusLbl}</span>
                </a>`;
            }).join('');
            el.innerHTML = `<div class="list-group scroll-container">${rows}</div>`;
        }

        function renderLeadsWidget(list) {
            setWidgetCount('leads_count', (list || []).length, 'widget-count-warning');
            const el = document.getElementById('leads_widget_body');
            if (!el) return;
            _leadsCache = list || [];
            if (!list || list.length === 0) {
                el.innerHTML = '<div class="text-muted small p-3 bg-subtle rounded-3 text-center border border-subtle-c"><i class="bi bi-inbox d-block mb-1" style="font-size:1.5rem;color:var(--text-faint);"></i><?= te('Keine neuen Anfragen über die Website.') ?></div>';
                return;
            }
            const rows = list.map((lead, i) => {
                const name    = (lead.name    || '').replace(/</g,'&lt;');
                const subject = (lead.subject || '').replace(/</g,'&lt;');
                return `<div class="list-group-item d-flex justify-content-between align-items-center py-2 lead-item" onclick="openLeadByIdx(${i})" style="cursor:pointer;">
                    <div class="flex-grow-1 pe-3">
                        <h6 class="mb-1 fw-bold fs-6 text-strong-c">${name}</h6>
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
                listEl.innerHTML = '<div class="small text-muted pt-2 border-top border-subtle-c"><i class="bi bi-check2-circle me-1 text-success"></i><?= te('Keine Deadlines') ?></div>';
                return;
            }
            listEl.innerHTML = list.slice(0, 2).map(dl => {
                const d = parseInt(dl.days_left);
                const k = d < 0 ? 'due-overdue' : (d === 0 ? 'due-today' : (d <= 3 ? 'due-soon' : ''));
                const l = d < 0 ? <?= tjs('Überfällig') ?>
                          : (d === 0 ? <?= tjs('Heute') ?>
                                     : <?= tjs('in %d T.') ?>.replace('%d', d));
                const t = (dl.title || '').replace(/</g,'&lt;');
                const s = t.length > 20 ? t.substring(0,20)+'…' : t;
                return `<div class="d-flex align-items-center gap-2 pt-2 border-top border-subtle-c">
                    <span class="due-chip ${k}">${l}</span>
                    <span class="small text-truncate text-strong-c" style="font-size:var(--text-2xs);">${s}</span>
                </div>`;
            }).join('');
        }

        function renderTermineWidget(count, list) {
            const kpiEl  = document.getElementById('kpi_termine');
            const listEl = document.getElementById('termine_list_body');
            if (kpiEl)  kpiEl.textContent = count;
            if (!listEl) return;
            if (!list || list.length === 0) {
                listEl.innerHTML = '<div class="small text-muted pt-2 border-top border-subtle-c"><i class="bi bi-calendar-check me-1 text-success"></i><?= te('Keine Termine') ?></div>';
                return;
            }
            listEl.innerHTML = list.slice(0, 2).map(apt => {
                const ac  = (apt.color || '#6c757d').replace(/[<>"'&]/g,'');
                const d   = parseInt(apt.days_left);
                const lbl = d === 0 ? <?= tjs('Heute') ?>
                          : (d === 1 ? <?= tjs('Morgen') ?>
                                     : <?= tjs('in %d T.') ?>.replace('%d', d));
                const t   = (apt.title || '').replace(/</g,'&lt;');
                const s   = t.length > 20 ? t.substring(0,20)+'…' : t;
                return `<div class="d-flex align-items-center gap-2 pt-2 border-top border-subtle-c">
                    <span class="due-chip"><span class="status-dot m-0" style="background:${ac};width:8px;height:8px;"></span>${lbl}</span>
                    <span class="small text-truncate text-strong-c" style="font-size:var(--text-2xs);">${s}</span>
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
            // no-cache und nicht no-store: no-store umgeht den Zwischenspeicher
            // vollstaendig, der Browser schickt dann kein If-None-Match, und
            // das ETag in ajax_poll.php koennte nie greifen. no-cache heisst
            // "aufbewahren, aber jedes Mal rueckfragen" - bei einer 304 legt
            // der Browser den gespeicherten Rumpf vor, r.text() liefert also
            // unveraendert die Daten.
            fetch('ajax_poll', { cache: 'no-cache' })
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
        // Erstabruf sofort: der Seitenaufbau prüft die URLs nicht mehr selbst.
        pollHtml('monitor_widget_body', 'index?ajax_widget=monitor');
    })();

  </script>
<?php require 'includes/layout_end.php'; ?>
  <script>
  /* =====================================================================
     Verschiebbare Widgets
     ---------------------------------------------------------------------
     Die Plaetze stehen bereits als gs-Attribute im Markup (siehe
     includes/dashboard_layout.php) - Gridstack liest sie hier nur noch
     ein. Deshalb steht die Seite sofort richtig da, statt kurz im
     Standardlayout aufzublitzen und dann zu springen.
     ===================================================================== */
  (function () {
      const gridEl = document.getElementById('dashGrid');
      if (!gridEl || typeof GridStack === 'undefined') return;

      const SCHMAL = window.matchMedia('(max-width: 767.98px)');
      const ZIEL   = window.location.pathname;
      const TOKEN  = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';

      const grid = GridStack.init({
          column: 12,
          cellHeight: 76,
          margin: 12,
          animate: true,
          // Angefasst wird die Titelzeile - oder, wo es keine gibt, der
          // schmale Streifen im oberen Innenabstand der Kachel.
          handle: '.widget-title, .dash-drag-bar',
          // Ohne diese Ausnahmen gaelte jeder Klick auf eine Schaltflaeche
          // in der Titelzeile als Beginn einer Ziehbewegung.
          draggable: { cancel: 'input,textarea,button,select,option,a,.btn-close' },
          resizable: { handles: 'se' },
          // Unter 768 px steht alles untereinander.
          columnOpts: { breakpointForWindow: true, breakpoints: [{ w: 768, c: 1 }] }
      }, gridEl);

      // Der Name des Widgets muss den Ausflug in den Vorrat ueberleben -
      // removeWidget() raeumt die gs-Attribute ab.
      gridEl.querySelectorAll('.grid-stack-item').forEach(function (el) {
          el.dataset.dashId = el.getAttribute('gs-id') || '';
      });

      // Vorrat fuer ausgeblendete Widgets. Sie bleiben im Dokument, damit
      // das Haekchen im Menue sie ohne Neuladen zurueckholen kann.
      const vorrat = document.createElement('div');
      vorrat.id = 'dashStash';
      vorrat.hidden = true;
      gridEl.parentNode.insertBefore(vorrat, gridEl.nextSibling);

      grid.batchUpdate();
      gridEl.querySelectorAll('[data-dash-hidden="1"]').forEach(function (el) {
          el.style.display = '';
          el.removeAttribute('data-dash-hidden');
          grid.removeWidget(el, false);
          vorrat.appendChild(el);
      });
      // Gegenstueck zu batchUpdate() - das fruehere commit() gibt es seit
      // Gridstack 11 nicht mehr.
      grid.batchUpdate(false);

      // ---------------------------------------------------------------
      // Speichern
      // ---------------------------------------------------------------

      function aktuellerStand() {
          const items = {};
          gridEl.querySelectorAll('.grid-stack-item').forEach(function (el) {
              const n = el.gridstackNode;
              if (!n || !el.dataset.dashId) return;
              items[el.dataset.dashId] = { x: n.x, y: n.y, w: n.w, h: n.h };
          });
          const hidden = Array.prototype.map.call(vorrat.children, function (el) {
              return el.dataset.dashId;
          }).filter(Boolean);
          return { v: 1, items: items, hidden: hidden };
      }

      let wartend = null;
      function speichern() {
          // In der Einspaltenansicht gibt es keine Plaetze, die sich zu
          // merken lohnten - und ein Zug dort wuerde die Anordnung fuer
          // den grossen Bildschirm ueberschreiben.
          if (grid.getColumn() !== 12) return;
          clearTimeout(wartend);
          wartend = setTimeout(function () {
              senden('save_dashboard_layout', { layout: JSON.stringify(aktuellerStand()) })
                  .catch(function () { meldung('Anordnung konnte nicht gespeichert werden.'); });
          }, 600);
      }

      function senden(aktion, felder) {
          const daten = { action: aktion, csrf_token: TOKEN };
          for (const k in (felder || {})) daten[k] = felder[k];
          return fetch(ZIEL, {
              method: 'POST',
              headers: { 'X-Requested-With': 'XMLHttpRequest' },
              body: new URLSearchParams(daten)
          }).then(function (r) { return r.json(); }).then(function (d) {
              if (!d || d.ok !== true) throw new Error((d && d.error) || 'Fehler');
              return d;
          });
      }

      // Ein Zug loest mehrere Ereignisse aus; das Entprellen oben fasst
      // sie zu einer Sendung zusammen.
      grid.on('change added removed', speichern);

      // ---------------------------------------------------------------
      // Aus- und Einblenden
      // ---------------------------------------------------------------

      function zeigen(id, sichtbar) {
          const wo = sichtbar ? vorrat : gridEl;
          const el = Array.prototype.find.call(
              wo.querySelectorAll('.grid-stack-item'),
              function (e) { return e.dataset.dashId === id; }
          );
          if (!el) return;

          if (sichtbar) {
              gridEl.appendChild(el);
              grid.makeWidget(el);
          } else {
              grid.removeWidget(el, false);
              vorrat.appendChild(el);
          }

          const kasten = document.querySelector('[data-dash-toggle="' + id + '"]');
          if (kasten && kasten.checked !== sichtbar) kasten.checked = sichtbar;
          speichern();
      }

      gridEl.addEventListener('click', function (e) {
          const knopf = e.target.closest('[data-dash-hide]');
          if (!knopf) return;
          e.preventDefault();
          zeigen(knopf.getAttribute('data-dash-hide'), false);
      });

      document.querySelectorAll('[data-dash-toggle]').forEach(function (kasten) {
          kasten.addEventListener('change', function () {
              zeigen(this.getAttribute('data-dash-toggle'), this.checked);
          });
      });

      const zuruecksetzen = document.getElementById('dashResetBtn');
      if (zuruecksetzen) {
          zuruecksetzen.addEventListener('click', function () {
              // Neu laden statt im Browser zurueckbauen: der Standard steht
              // in PHP, und die Seite holt ihn beim naechsten Aufbau.
              senden('reset_dashboard_layout')
                  .then(function () { window.location.reload(); })
                  .catch(function () { meldung('Zuruecksetzen fehlgeschlagen.'); });
          });
      }

      // ---------------------------------------------------------------
      // Schmale Bildschirme
      // ---------------------------------------------------------------

      function anpassen() {
          const aus = SCHMAL.matches;
          grid.enableMove(!aus);
          grid.enableResize(!aus);
      }
      anpassen();
      SCHMAL.addEventListener('change', anpassen);

      // ---------------------------------------------------------------

      function meldung(text) {
          const behaelter = document.querySelector('.toast-container-dash');
          if (!behaelter) { console.warn(text); return; }
          const el = document.createElement('div');
          el.className = 'toast align-items-center text-bg-danger border-0 mb-2 shadow-lg';
          el.setAttribute('role', 'alert');
          const zeile = document.createElement('div');
          zeile.className = 'd-flex';
          const rumpf = document.createElement('div');
          rumpf.className = 'toast-body fw-bold';
          rumpf.textContent = text;
          const zu = document.createElement('button');
          zu.type = 'button';
          zu.className = 'btn-close btn-close-white me-2 m-auto';
          zu.setAttribute('data-bs-dismiss', 'toast');
          zeile.appendChild(rumpf);
          zeile.appendChild(zu);
          el.appendChild(zeile);
          behaelter.appendChild(el);
          new bootstrap.Toast(el, { delay: 5000 }).show();
      }
  })();
  </script>
