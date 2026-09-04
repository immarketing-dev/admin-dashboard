<?php
require_once 'config.php';
require_once 'includes/auth.php';
header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache');

ob_start(); // PHP-Notices/Warnings abfangen, damit sie die JSON-Ausgabe nicht kaputtmachen

$out = [];

try { $out['leads']      = (int)$pdo->query("SELECT COUNT(*) FROM leads_inbox")->fetchColumn(); }                     catch (Throwable $e) { $out['leads'] = 0; }
try { $out['tickets']    = (int)$pdo->query("SELECT COUNT(*) FROM support_tickets WHERE status != 'Erledigt'")->fetchColumn(); } catch (Throwable $e) { $out['tickets'] = 0; }
try { $out['uploads']    = (int)$pdo->query("SELECT COUNT(*) FROM client_assets WHERE dashboard_seen=0 AND (uploaded_by IS NULL OR uploaded_by='client')")->fetchColumn(); } catch (Throwable $e) { $out['uploads'] = 0; }
try { $out['approvals']  = (int)$pdo->query("SELECT COUNT(*) FROM task_milestones WHERE approved_at IS NOT NULL AND approval_seen=0")->fetchColumn(); }                    catch (Throwable $e) { $out['approvals'] = 0; }
try { $out['feedbacks']  = (int)$pdo->query("SELECT COUNT(*) FROM tasks WHERE deleted_at IS NULL AND client_feedback IS NOT NULL AND client_feedback != '' AND feedback_seen=0")->fetchColumn(); } catch (Throwable $e) { $out['feedbacks'] = 0; }
try { $out['open_tasks'] = (int)$pdo->query("SELECT COUNT(*) FROM tasks WHERE deleted_at IS NULL AND status NOT IN ('Erledigt','Storniert')")->fetchColumn(); }                                    catch (Throwable $e) { $out['open_tasks'] = 0; }
try { $out['contacts']   = (int)$pdo->query("SELECT COUNT(*) FROM contacts WHERE deleted_at IS NULL")->fetchColumn(); }                                                                              catch (Throwable $e) { $out['contacts'] = 0; }
try { $out['ms_comments']= (int)$pdo->query("SELECT COUNT(*) FROM milestone_comments WHERE author='client' AND admin_seen=0")->fetchColumn(); }                             catch (Throwable $e) { $out['ms_comments'] = 0; }

// Ticket-Liste
try {
    $out['tickets_list'] = $pdo->query(
        "SELECT t.id, t.subject, t.status, t.priority, c.name AS contact_name
         FROM support_tickets t
         JOIN contacts c ON t.contact_id = c.id
         WHERE t.status != 'Erledigt'
         ORDER BY FIELD(t.priority,'Kritisch','Hoch','Mittel','Niedrig'), t.created_at ASC
         LIMIT 10"
    )->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) { $out['tickets_list'] = []; }

// Leads-Liste
try {
    $out['leads_list'] = $pdo->query(
        "SELECT * FROM leads_inbox ORDER BY created_at ASC LIMIT 10"
    )->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) { $out['leads_list'] = []; }

// Deadlines (nächste 14 Tage + überfällig)
try {
    $out['deadlines'] = $pdo->query("
        SELECT title, DATEDIFF(deadline, CURDATE()) AS days_left
        FROM tasks
        WHERE deleted_at IS NULL AND status != 'Erledigt' AND deadline IS NOT NULL AND deadline > '0000-00-00'
          AND deadline <= DATE_ADD(CURDATE(), INTERVAL 14 DAY)
        UNION ALL
        SELECT f.title, DATEDIFF(f.due_date, CURDATE())
        FROM finances f
        WHERE f.type = 'INCOME' AND f.status IN ('Offen','Überfällig')
          AND f.due_date IS NOT NULL AND f.due_date > '0000-00-00'
          AND f.due_date <= DATE_ADD(CURDATE(), INTERVAL 14 DAY)
        ORDER BY days_left ASC LIMIT 12
    ")->fetchAll(PDO::FETCH_ASSOC);
    $out['deadlines_count'] = count($out['deadlines']);
} catch (Throwable $e) { $out['deadlines'] = []; $out['deadlines_count'] = 0; }

// Termine (heute + diese Woche)
try {
    $t_today = $pdo->query("
        SELECT title, event_date, color, DATEDIFF(event_date, CURDATE()) AS days_left
        FROM calendar_events
        WHERE event_date = CURDATE() AND status != 'Abgesagt'
        ORDER BY start_time ASC, title ASC
    ")->fetchAll(PDO::FETCH_ASSOC);
    $t_week = $pdo->query("
        SELECT title, event_date, color, DATEDIFF(event_date, CURDATE()) AS days_left
        FROM calendar_events
        WHERE event_date > CURDATE() AND event_date <= DATE_ADD(CURDATE(), INTERVAL 6 DAY) AND status != 'Abgesagt'
        ORDER BY event_date ASC, start_time ASC LIMIT 10
    ")->fetchAll(PDO::FETCH_ASSOC);
    $out['termine']       = array_merge($t_today, $t_week);
    $out['termine_count'] = count($out['termine']);
} catch (Throwable $e) { $out['termine'] = []; $out['termine_count'] = 0; }

ob_end_clean(); // Stray-Output verwerfen
echo json_encode($out);
