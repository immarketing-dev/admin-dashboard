<?php
/**
 * Zahlen und Listen fuer die Startseite, im Minutentakt abgefragt.
 *
 * Frueher standen hier sechzehn einzelne Abfragen, jede in ihrem eigenen
 * try/catch. Das lief bei jedem offenen Browserfenster einmal pro Minute
 * - dauerhaft, auch wenn sich nichts geaendert hat.
 *
 * Zwei Dinge sind jetzt anders:
 *
 *  - Die acht Zaehler kommen aus EINER Abfrage. MySQL wertet die
 *    Unterabfragen ohnehin einzeln aus, aber es ist ein Hin und Her
 *    ueber die Verbindung statt acht.
 *
 *  - Die Antwort traegt ein ETag. Aendert sich nichts, schickt der
 *    Browser beim naechsten Mal If-None-Match, und wir antworten mit
 *    304 ohne Rumpf. Das ist der Normalfall - die meisten Minuten
 *    passiert nichts. Dafuer muss Cache-Control "no-cache" heissen und
 *    nicht "no-store": no-store verbietet dem Browser das Aufbewahren
 *    ganz, er koennte dann gar nicht erst rueckfragen.
 */
require_once 'config.php';
require_once 'includes/auth.php';

ob_start(); // PHP-Notices/Warnings abfangen, damit sie die JSON-Ausgabe nicht kaputtmachen

$out = [];

// ── Die Zaehler, in einer Abfrage ──────────────────────────────────
// Ein gemeinsames try/catch statt eines je Zaehler: seit es
// install/schema.sql und die Migrationen gibt, fehlt keine dieser
// Tabellen mehr einzeln. Faellt die Abfrage doch aus, stehen alle
// Zaehler auf 0 - die Startseite zeigt dann keine Abzeichen, statt
// einen Fehler auszuwerfen.
$zaehler = [
    'leads'       => "SELECT COUNT(*) FROM leads_inbox",
    'tickets'     => "SELECT COUNT(*) FROM support_tickets WHERE status != 'Erledigt'",
    'uploads'     => "SELECT COUNT(*) FROM client_assets
                       WHERE dashboard_seen = 0
                         AND (uploaded_by IS NULL OR uploaded_by = 'client')",
    'approvals'   => "SELECT COUNT(*) FROM task_milestones
                       WHERE approved_at IS NOT NULL AND approval_seen = 0",
    'feedbacks'   => "SELECT COUNT(*) FROM tasks
                       WHERE deleted_at IS NULL AND client_feedback IS NOT NULL
                         AND client_feedback != '' AND feedback_seen = 0",
    'open_tasks'  => "SELECT COUNT(*) FROM tasks
                       WHERE deleted_at IS NULL AND status NOT IN ('Erledigt','Storniert')",
    'contacts'    => "SELECT COUNT(*) FROM contacts WHERE deleted_at IS NULL",
    'ms_comments' => "SELECT COUNT(*) FROM milestone_comments
                       WHERE author = 'client' AND admin_seen = 0",
];

$teile = [];
foreach ($zaehler as $name => $sql) {
    $teile[] = '(' . $sql . ') AS ' . $name;
}

try {
    $zeile = $pdo->query('SELECT ' . implode(', ', $teile))->fetch(PDO::FETCH_ASSOC);
    foreach ($zaehler as $name => $_unused) {
        $out[$name] = (int) ($zeile[$name] ?? 0);
    }
} catch (Throwable $e) {
    error_log('ajax_poll: Zaehler fehlgeschlagen: ' . $e->getMessage());
    foreach ($zaehler as $name => $_unused) {
        $out[$name] = 0;
    }
}

// ── Listen ─────────────────────────────────────────────────────────
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
          AND f.deleted_at IS NULL
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

// ── Ausliefern, wenn sich etwas geaendert hat ──────────────────────
$json = (string) json_encode($out);
$etag = '"' . md5($json) . '"';

header('Content-Type: application/json');
header('ETag: ' . $etag);
header('Cache-Control: private, no-cache');

$mitgeschickt = trim($_SERVER['HTTP_IF_NONE_MATCH'] ?? '');
// Ein Zwischenspeicher darf dem ETag ein "W/" voranstellen; fuer den
// Vergleich zaehlt nur der Teil dahinter.
if ($mitgeschickt !== '' && preg_replace('#^W/#', '', $mitgeschickt) === $etag) {
    http_response_code(304);
    exit;
}

echo $json;
