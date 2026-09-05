<?php
ob_start();
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require_once __DIR__ . '/vendor/autoload.php';
ob_end_clean();

require_once 'config.php';
require_once __DIR__ . '/includes/logging.php';
require_once __DIR__ . '/includes/mail_log.php';
require_once 'includes/mail_templates.php';
require_once 'includes/auth.php';

$year  = isset($_GET['year'])  ? (int)$_GET['year']  : (int)date('Y');
$month = isset($_GET['month']) ? (int)$_GET['month'] : (int)date('m');

if ($month < 1)  { $month = 12; $year--; }
if ($month > 12) { $month = 1;  $year++; }

// Navigation
$prev_month = $month - 1; $prev_year = $year;
if ($prev_month < 1)  { $prev_month = 12; $prev_year--; }
$next_month = $month + 1; $next_year = $year;
if ($next_month > 12) { $next_month = 1;  $next_year++; }

$days_in_month = (int)date('t', mktime(0, 0, 0, $month, 1, $year));
$first_weekday = (int)date('N', mktime(0, 0, 0, $month, 1, $year));

$saved_ok    = isset($_GET['saved']);
$invited_ok  = isset($_GET['invited']);
$ask_invite  = isset($_GET['ask_invite'])   ? (int)$_GET['ask_invite']   : 0;
$invite_count = isset($_GET['invite_count']) ? (int)$_GET['invite_count'] : 0;

// ==========================================
// POST HANDLER
// ==========================================
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    csrf_check();
    $action = $_POST['action'] ?? '';

    if ($action === 'save_event') {
        $event_id    = !empty($_POST['event_id']) ? (int)$_POST['event_id'] : null;
        $title       = trim($_POST['title'] ?? '');
        $ev_date     = $_POST['event_date'] ?? '';
        $start_time  = !empty($_POST['start_time']) ? $_POST['start_time'] : null;
        $end_time    = !empty($_POST['end_time'])   ? $_POST['end_time']   : null;
        $location    = trim($_POST['location']    ?? '');
        $meeting_url = trim($_POST['meeting_url'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $category    = in_array($_POST['category'] ?? '', ['Termin','Meeting','Anruf','Deadline','Sonstiges']) ? $_POST['category'] : 'Termin';
        $color       = preg_match('/^#[0-9a-fA-F]{6}$/', $_POST['color'] ?? '') ? $_POST['color'] : '#4a90d9';
        $status      = in_array($_POST['status'] ?? '', ['Geplant','Bestätigt','Abgesagt','Abgeschlossen']) ? $_POST['status'] : 'Geplant';
        $contact_ids = array_unique(array_filter(array_map('intval', $_POST['contact_ids'] ?? [])));

        if (!empty($title) && !empty($ev_date)) {
            if ($event_id) {
                $pdo->prepare("UPDATE calendar_events SET title=?, description=?, location=?, meeting_url=?, event_date=?, start_time=?, end_time=?, category=?, color=?, status=? WHERE id=?")
                    ->execute([$title, $description, $location, $meeting_url, $ev_date, $start_time, $end_time, $category, $color, $status, $event_id]);
                $pdo->prepare("DELETE FROM event_contacts WHERE event_id=?")->execute([$event_id]);
                log_event($pdo, 'EVENT_UPDATED', "Termin '$title' aktualisiert.");
            } else {
                $ics_uid = uniqid('evt_', true) . '@' . parse_url(BASE_URL, PHP_URL_HOST);
                $pdo->prepare("INSERT INTO calendar_events (title, description, location, meeting_url, event_date, start_time, end_time, category, color, status, ics_uid) VALUES (?,?,?,?,?,?,?,?,?,?,?)")
                    ->execute([$title, $description, $location, $meeting_url, $ev_date, $start_time, $end_time, $category, $color, $status, $ics_uid]);
                $event_id = (int)$pdo->lastInsertId();
                log_event($pdo, 'EVENT_CREATED', "Termin '$title' am $ev_date erstellt.");
            }
            foreach ($contact_ids as $cid) {
                $tok = bin2hex(random_bytes(32));
                try { $pdo->prepare("INSERT IGNORE INTO event_contacts (event_id, contact_id, invite_token) VALUES (?,?,?)")->execute([$event_id, $cid, $tok]); }
                catch (PDOException $e) {
                    // Der Termin steht, diese eine Einladung nicht. Den
                    // ganzen Speichervorgang deswegen abzubrechen waere
                    // falsch - spurlos verschwinden darf sie aber auch
                    // nicht.
                    error_log('Einladung fuer Kontakt ' . (int) $cid
                        . ' zu Termin ' . (int) $event_id . ' fehlgeschlagen: '
                        . $e->getMessage());
                }
            }
        }
        $goto_y = !empty($ev_date) ? (int)date('Y', strtotime($ev_date)) : $year;
        $goto_m = !empty($ev_date) ? (int)date('n', strtotime($ev_date)) : $month;
        $ask_param = '';
        if (empty($_POST['event_id']) && !empty($event_id) && !empty($contact_ids)) {
            $ask_param = "&ask_invite={$event_id}&invite_count=" . count($contact_ids);
        }
        header("Location: calendar?year=$goto_y&month=$goto_m&saved=1{$ask_param}");
        exit();
    }

    if ($action === 'delete_event') {
        $event_id = (int)$_POST['event_id'];
        $stmt = $pdo->prepare("SELECT title, event_date FROM calendar_events WHERE id=?");
        $stmt->execute([$event_id]);
        $ev = $stmt->fetch(PDO::FETCH_ASSOC);
        $pdo->prepare("DELETE FROM event_contacts WHERE event_id=?")->execute([$event_id]);
        $pdo->prepare("DELETE FROM calendar_events WHERE id=?")->execute([$event_id]);
        if ($ev) {
            log_event($pdo, 'EVENT_DELETED', "Termin '{$ev['title']}' gelöscht.");
        }
        header("Location: calendar?year=$year&month=$month");
        exit();
    }

    if ($action === 'send_invite' || $action === 'send_all_invites') {
        $event_id = (int)$_POST['event_id'];
        $ev_stmt  = $pdo->prepare("SELECT * FROM calendar_events WHERE id=?");
        $ev_stmt->execute([$event_id]);
        $ev = $ev_stmt->fetch(PDO::FETCH_ASSOC);

        if ($ev && class_exists('PHPMailer\PHPMailer\PHPMailer')) {
            if ($action === 'send_invite') {
                $cids = [(int)$_POST['contact_id']];
            } else {
                $cids = $pdo->prepare("SELECT contact_id FROM event_contacts WHERE event_id=?");
                $cids->execute([$event_id]);
                $cids = $cids->fetchAll(PDO::FETCH_COLUMN);
            }

            $date_fmt = date('d.m.Y', strtotime($ev['event_date']));
            $time_str = '';
            if (!empty($ev['start_time'])) {
                $time_str = substr($ev['start_time'], 0, 5);
                if (!empty($ev['end_time'])) $time_str .= ' – ' . substr($ev['end_time'], 0, 5);
                $time_str .= ' Uhr';
            }
            $primary = htmlspecialchars(setting('color_primary', COLOR_PRIMARY));

            foreach ($cids as $cid) {
                $ec_s = $pdo->prepare("SELECT ec.invite_token, c.name, c.email FROM event_contacts ec JOIN contacts c ON ec.contact_id=c.id WHERE ec.event_id=? AND ec.contact_id=?");
                $ec_s->execute([$event_id, $cid]);
                $ec = $ec_s->fetch(PDO::FETCH_ASSOC);
                if (!$ec || empty($ec['email'])) continue;

                $ics_link    = BASE_URL . '/event_ics?token=' . $ec['invite_token'];
                // Wortlaut aus der Vorlage (Einstellungen > E-Mail-Vorlagen).
                // Vorher stand hier ein fest verdrahtetes HTML-Dokument mit
                // eigenen Farben und eigener Fusszeile.
                $_ort = trim(($ev['location'] ?? '') . (
                    !empty($ev['meeting_url']) ? ' · ' . $ev['meeting_url'] : ''
                ));
                $_m = mail_render('event_invite', [
                    'kunde'        => $ec['name'],
                    'titel'        => $ev['title'],
                    'datum'        => $date_fmt . ($time_str ? ', ' . $time_str : ''),
                    'ort'          => $_ort,
                    'beschreibung' => $ev['description'] ?? '',
                    'firma'        => setting('company_short', COMPANY_SHORT),
                ], $ics_link);
                $html_body = $_m['html'];
                $alt_body = "Termineinladung: {$ev['title']}\nDatum: {$date_fmt}" . ($time_str ? " | $time_str" : '') . (!empty($ev['location']) ? "\nOrt: {$ev['location']}" : '') . (!empty($ev['meeting_url']) ? "\nMeeting: {$ev['meeting_url']}" : '') . "\n\nTermin herunterladen: $ics_link";

                $mail = new PHPMailer(true);
                try {
                    $mail->isSMTP();
                    $mail->Host       = SMTP_HOST;
                    $mail->SMTPAuth   = true;
                    $mail->Username   = SMTP_USER;
                    $mail->Password   = SMTP_PASS;
                    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
                    $mail->Port       = SMTP_PORT;
                    $mail->CharSet    = 'UTF-8';
                    $mail->setFrom(setting('admin_email', ADMIN_EMAIL), setting('company_name', COMPANY_NAME));
                    $mail->addAddress($ec['email'], $ec['name']);
                    $mail->Subject    = $_m['subject'];
                    $mail->isHTML(true);
                    $mail->Body       = $html_body;
                    $mail->AltBody    = $alt_body;
                    $mail->send();
                    mail_protokollieren($pdo, 'event_invite', $ec['email'], $_m['subject'], true,
                        null, 'Termin: ' . ($ev['title'] ?? ''));
                } catch (Exception $e) {
                    // Der catch-Block war leer: ein fehlgeschlagener Versand
                    // hinterliess ueberhaupt keine Spur.
                    mail_protokollieren($pdo, 'event_invite', $ec['email'], $_m['subject'], false,
                        $e->getMessage(), 'Termin: ' . ($ev['title'] ?? ''));
                }
            }
        }
        header("Location: calendar?year=$year&month=$month&invited=1");
        exit();
    }
}

// ==========================================
// DATA LOADING
// ==========================================

// Task deadlines
$task_stmt = $pdo->prepare("
    SELECT t.id, t.title, t.status, t.deadline, c.name AS contact_name
    FROM tasks t LEFT JOIN contacts c ON t.contact_id = c.id
    WHERE t.deleted_at IS NULL AND t.deadline IS NOT NULL AND YEAR(t.deadline) = ? AND MONTH(t.deadline) = ?
    ORDER BY t.deadline ASC
");
$task_stmt->execute([$year, $month]);
$tasks_by_day = [];
foreach ($task_stmt->fetchAll(PDO::FETCH_ASSOC) as $t) {
    $tasks_by_day[(int)date('j', strtotime($t['deadline']))][] = $t;
}

// Invoice due dates
$inv_stmt = $pdo->prepare("
    SELECT f.id, f.title, f.amount, f.status, f.due_date, c.name AS contact_name
    FROM finances f LEFT JOIN contacts c ON f.contact_id = c.id
    WHERE f.deleted_at IS NULL AND f.type = 'INCOME' AND f.due_date IS NOT NULL AND YEAR(f.due_date) = ? AND MONTH(f.due_date) = ?
    ORDER BY f.due_date ASC
");
$inv_stmt->execute([$year, $month]);
$invoices_by_day = [];
foreach ($inv_stmt->fetchAll(PDO::FETCH_ASSOC) as $inv) {
    $invoices_by_day[(int)date('j', strtotime($inv['due_date']))][] = $inv;
}

// Calendar events
$ev_stmt = $pdo->prepare("
    SELECT id, title, description, location, meeting_url, event_date, start_time, end_time, category, color, status, ics_uid
    FROM calendar_events
    WHERE YEAR(event_date) = ? AND MONTH(event_date) = ?
    ORDER BY start_time ASC, title ASC
");
$ev_stmt->execute([$year, $month]);
$events_by_day = [];
foreach ($ev_stmt->fetchAll(PDO::FETCH_ASSOC) as $ev) {
    $events_by_day[(int)date('j', strtotime($ev['event_date']))][] = $ev;
}

// Attach contacts + invite tokens to events
$all_ev_ids = [];
foreach ($events_by_day as $evs) { foreach ($evs as $ev) { $all_ev_ids[] = $ev['id']; } }
$contacts_by_event = [];
if (!empty($all_ev_ids)) {
    $ph = implode(',', array_fill(0, count($all_ev_ids), '?'));
    $ec_stmt = $pdo->prepare("SELECT ec.event_id, ec.invite_token, c.id AS contact_id, c.name, c.email FROM event_contacts ec JOIN contacts c ON ec.contact_id=c.id WHERE ec.event_id IN ($ph)");
    $ec_stmt->execute($all_ev_ids);
    foreach ($ec_stmt->fetchAll(PDO::FETCH_ASSOC) as $ec) {
        $contacts_by_event[$ec['event_id']][] = [
            'id'       => (int)$ec['contact_id'],
            'name'     => $ec['name'],
            'email'    => $ec['email'],
            'ics_link' => BASE_URL . '/event_ics?token=' . $ec['invite_token'],
        ];
    }
    foreach ($events_by_day as $d => &$evs) {
        foreach ($evs as &$ev) { $ev['contacts'] = $contacts_by_event[$ev['id']] ?? []; }
        unset($ev);
    }
    unset($evs);
}

// Contacts for modal
$all_contacts = $pdo->query("SELECT id, name, email FROM contacts WHERE deleted_at IS NULL ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);

$today_d  = (int)date('j');
$today_m  = (int)date('m');
$today_y  = (int)date('Y');
$is_current_month = ($today_m === $month && $today_y === $year);

$month_names = ['','Januar','Februar','März','April','Mai','Juni','Juli','August','September','Oktober','November','Dezember'];

// Monthly stats
$total_tasks_month    = 0; $overdue_tasks_month  = 0;
$total_invoices_month = 0; $open_invoices_month  = 0;
$total_events_month   = 0;
$now_ts = time();
foreach ($tasks_by_day    as $dt) { foreach ($dt  as $t)   { $total_tasks_month++;    if ($t['status'] !== 'Erledigt' && strtotime($t['deadline']) < $now_ts) $overdue_tasks_month++; } }
foreach ($invoices_by_day as $di) { foreach ($di  as $inv) { $total_invoices_month++; if ($inv['status'] !== 'Bezahlt') $open_invoices_month++; } }
foreach ($events_by_day   as $des){ foreach ($des as $ev)  { if ($ev['status'] !== 'Abgesagt') $total_events_month++; } }

// Upcoming 14 days
$upcoming   = [];
$limit_ts   = strtotime('+14 days');
foreach ($tasks_by_day as $d => $dt) {
    foreach ($dt as $t) {
        $ts = strtotime($t['deadline']);
        if ($ts >= strtotime('today') && $ts <= $limit_ts) $upcoming[] = ['type'=>'task','ts'=>$ts,'data'=>$t];
    }
}
foreach ($invoices_by_day as $d => $di) {
    foreach ($di as $inv) {
        $ts = strtotime($inv['due_date']);
        if ($ts >= strtotime('today') && $ts <= $limit_ts) $upcoming[] = ['type'=>'invoice','ts'=>$ts,'data'=>$inv];
    }
}
foreach ($events_by_day as $d => $des) {
    foreach ($des as $ev) {
        if ($ev['status'] === 'Abgesagt') continue;
        $ts = strtotime($ev['event_date'] . (!empty($ev['start_time']) ? ' '.$ev['start_time'] : ''));
        if ($ts >= strtotime('today') && $ts <= $limit_ts) $upcoming[] = ['type'=>'event','ts'=>$ts,'data'=>$ev];
    }
}
usort($upcoming, fn($a, $b) => $a['ts'] - $b['ts']);

// Color presets
$preset_colors = [
    '#4a90d9' => 'Blau',   '#28a745' => 'Grün',   '#fd7e14' => 'Orange',
    '#dc3545' => 'Rot',    '#6f42c1' => 'Lila',   '#20c997' => 'Türkis',
    '#e83e8c' => 'Pink',   '#343a40' => 'Dunkel',
];
$page_title   = 'Kalender';
$page_heading = 'Kalender';
$current_page = basename($_SERVER['SCRIPT_NAME']);
$header_actions = '
    <div class="d-flex align-items-center gap-2">
        <button class="btn btn-sm btn-primary fw-bold" onclick="openNewModal(\'\')">
            <i class="bi bi-calendar-plus me-1"></i> Neuer Termin
        </button>
        <a href="calendar?year=' . $today_y . '&month=' . $today_m . '" class="btn btn-sm btn-outline-secondary fw-bold">Heute</a>
        <a href="calendar?year=' . $prev_year . '&month=' . $prev_month . '" class="btn btn-sm btn-outline-primary px-2"><i class="bi bi-chevron-left"></i></a>
        <a href="calendar?year=' . $next_year . '&month=' . $next_month . '" class="btn btn-sm btn-outline-primary px-2"><i class="bi bi-chevron-right"></i></a>
    </div>';

require 'includes/head.php';
require 'includes/layout_start.php';
?>

  <?php if ($saved_ok): ?>
  <div class="alert alert-success alert-dismissible fade show mb-3" role="alert">
    <i class="bi bi-check-circle-fill me-2"></i> <?= te('Termin gespeichert.') ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
  </div>
  <?php endif; ?>
  <?php if ($invited_ok): ?>
  <div class="alert alert-info alert-dismissible fade show mb-3" role="alert">
    <i class="bi bi-envelope-check me-2"></i> <?= te('Einladung(en) wurden versendet.') ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
  </div>
  <?php endif; ?>

  <div class="row g-4">
    <div class="col-lg-9">
      <div class="bg-surface rounded-3 shadow-sm border p-4">
        <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
          <h4 class="fw-bold m-0 text-strong-c"><?= $month_names[$month] ?> <?= $year ?></h4>
          <div class="d-flex gap-3 small flex-wrap">
            <span class="legend-chip"><span class="legend-dot legend-dot-task"></span><?= te('Deadline') ?></span>
            <span class="legend-chip"><span class="legend-dot legend-dot-inv"></span><?= te('Rechnung') ?></span>
            <span class="legend-chip"><span class="legend-dot legend-dot-event"></span><?= te('Termin') ?></span>
            <span class="legend-chip"><span class="legend-dot legend-dot-overdue"></span><?= te('Überfällig') ?></span>
          </div>
        </div>

        <div class="cal-grid">
          <?php foreach(['Mo','Di','Mi','Do','Fr','Sa','So'] as $idx => $d): ?>
            <div class="cal-header-cell <?= $idx >= 5 ? 'cal-wk-end' : '' ?>"><?= $d ?></div>
          <?php endforeach; ?>

          <?php for($i = 1; $i < $first_weekday; $i++): ?>
            <div class="cal-empty"></div>
          <?php endfor; ?>

          <?php for($day = 1; $day <= $days_in_month; $day++):
            $is_today     = $is_current_month && $day === $today_d;
            $is_weekend   = (($first_weekday - 1 + $day - 1) % 7) >= 5;
            $day_tasks    = $tasks_by_day[$day]    ?? [];
            $day_invoices = $invoices_by_day[$day] ?? [];
            $day_events   = $events_by_day[$day]   ?? [];
            $total_items  = count($day_tasks) + count($day_invoices) + count($day_events);
            $show_limit   = 3;
            $shown        = 0;
            $date_str     = sprintf('%04d-%02d-%02d', $year, $month, $day);
          ?>
            <div class="cal-day <?= $is_today ? 'cal-today' : ($is_weekend ? 'cal-weekend' : '') ?>"
                 onclick="dayClick(<?=(int)$day?>, <?=$year?>, <?=$month?>, '<?=$date_str?>')">

              <span class="cal-day-num"><?= $day ?></span>
              <span class="cal-add-hint">+</span>

              <?php foreach($day_events as $ev):
                if ($shown >= $show_limit) break; $shown++;
                $ev_color = ($ev['status'] === 'Abgesagt') ? '#6c757d' : $ev['color'];
                $ev_bg    = $ev_color . '22';
                $ev_time  = !empty($ev['start_time']) ? substr($ev['start_time'],0,5).' ' : '';
              ?>
                <span class="cal-event" style="background:<?=$ev_bg?>;color:<?=$ev_color?>;border-left:2px solid <?=$ev_color?>;"
                      title="<?= htmlspecialchars($ev['title']) ?>">
                  <i class="bi bi-calendar2-event"></i><span class="cal-event-text"><?= $ev_time . htmlspecialchars(mb_strimwidth($ev['title'],0,18,'…')) ?></span>
                </span>
              <?php endforeach; ?>

              <?php foreach($day_tasks as $t):
                if ($shown >= $show_limit) break; $shown++;
                $dl_ts = strtotime($t['deadline']);
                if ($t['status'] === 'Erledigt')   $cls = 'cal-event-task-done';
                elseif ($dl_ts < $now_ts)           $cls = 'cal-event-task-overdue';
                else                                $cls = 'cal-event-task';
              ?>
                <span class="cal-event <?= $cls ?>" title="<?= htmlspecialchars($t['title']) ?>">
                  <i class="bi bi-briefcase"></i><span class="cal-event-text"><?= htmlspecialchars(mb_strimwidth($t['title'],0,18,'…')) ?></span>
                </span>
              <?php endforeach; ?>

              <?php foreach($day_invoices as $inv):
                if ($shown >= $show_limit) break; $shown++;
                if ($inv['status'] === 'Bezahlt')       $cls = 'cal-event-inv-paid';
                elseif ($inv['status'] === 'Überfällig') $cls = 'cal-event-inv-overdue';
                else                                     $cls = 'cal-event-inv';
              ?>
                <span class="cal-event <?= $cls ?>" title="<?= htmlspecialchars($inv['title']) ?>">
                  <i class="bi bi-receipt"></i><span class="cal-event-text"><?= htmlspecialchars(mb_strimwidth($inv['title'],0,18,'…')) ?></span>
                </span>
              <?php endforeach; ?>

              <?php if ($total_items > $show_limit): ?>
                <span class="cal-more">+<?= $total_items - $show_limit ?> <?= te('weitere') ?></span>
              <?php endif; ?>

            </div>
          <?php endfor; ?>

          <?php
          $total_cells = ($first_weekday - 1) + $days_in_month;
          $trailing = (7 - ($total_cells % 7)) % 7;
          for($i = 0; $i < $trailing; $i++): ?>
            <div class="cal-empty"></div>
          <?php endfor; ?>
        </div>
      </div>
    </div>

    <!-- Right sidebar -->
    <div class="col-lg-3">
      <div id="event-detail-panel">

        <!-- Monthly overview -->
        <div class="bg-surface rounded-3 shadow-sm border p-3 mb-3">
          <h6 class="fw-bold text-strong-c mb-3"><i class="bi bi-bar-chart-fill me-2" style="color:var(--color-primary);"></i><?= te('Monatsüberblick') ?></h6>
          <?php if ($total_events_month > 0): ?>
          <div class="d-flex justify-content-between align-items-center py-2 border-bottom">
            <span class="small text-muted"><i class="bi bi-calendar2-event me-1"></i><?= te('Termine') ?></span>
            <span class="fw-bold"><?= $total_events_month ?></span>
          </div>
          <?php endif; ?>
          <div class="d-flex justify-content-between align-items-center py-2 border-bottom">
            <span class="small text-muted"><i class="bi bi-briefcase me-1"></i><?= te('Deadlines') ?></span>
            <span class="fw-bold"><?= $total_tasks_month ?></span>
          </div>
          <?php if ($overdue_tasks_month > 0): ?>
          <div class="d-flex justify-content-between align-items-center py-2 border-bottom">
            <span class="small text-danger"><i class="bi bi-exclamation-triangle me-1"></i><?= te('Überfällig') ?></span>
            <span class="fw-bold text-danger"><?= $overdue_tasks_month ?></span>
          </div>
          <?php endif; ?>
          <div class="d-flex justify-content-between align-items-center py-2 <?= $open_invoices_month > 0 ? 'border-bottom' : '' ?>">
            <span class="small text-muted"><i class="bi bi-receipt me-1"></i><?= te('Rechnungsfällig') ?></span>
            <span class="fw-bold"><?= $total_invoices_month ?></span>
          </div>
          <?php if ($open_invoices_month > 0): ?>
          <div class="d-flex justify-content-between align-items-center py-2">
            <span class="small text-warning"><i class="bi bi-hourglass-split me-1"></i><?= te('Offen') ?></span>
            <span class="fw-bold text-warning"><?= $open_invoices_month ?></span>
          </div>
          <?php endif; ?>
        </div>

        <!-- Day detail panel (filled via JS) -->
        <div id="day-detail-card" class="bg-surface rounded-3 shadow-sm border p-3 mb-3" style="display:none;">
          <div class="d-flex justify-content-between align-items-center mb-3">
            <h6 class="fw-bold text-strong-c m-0" id="day-detail-title"></h6>
            <button class="btn btn-xs btn-outline-primary" onclick="openNewModalForDay(currentDetailDay)">
              <i class="bi bi-plus"></i>
            </button>
          </div>
          <div id="day-detail-body"></div>
        </div>

        <!-- Upcoming 14 days -->
        <?php if (!empty($upcoming) && $is_current_month): ?>
        <div class="bg-surface rounded-3 shadow-sm border p-3 mt-3">
          <h6 class="fw-bold text-strong-c mb-3"><i class="bi bi-calendar-event me-2 text-warning"></i><?= te('Nächste 14 Tage') ?></h6>
          <?php foreach($upcoming as $up): ?>
            <?php if ($up['type'] === 'event'): $evt = $up['data']; ?>
              <div class="d-flex align-items-start gap-2 mb-2 p-2 rounded" style="background:<?=$evt['color']?>1a;border-left:3px solid <?=$evt['color']?>;">
                <i class="bi bi-calendar2-event mt-1 small flex-shrink-0" style="color:<?=$evt['color']?>;"></i>
                <div>
                  <div class="fw-semibold small text-strong-c"><?= htmlspecialchars(mb_strimwidth($evt['title'],0,32,'…')) ?></div>
                  <div class="text-muted" style="font-size:11px;">
                    <?= date('d.m.Y', strtotime($evt['event_date'])) ?>
                    <?= !empty($evt['start_time']) ? ' · '.substr($evt['start_time'],0,5).' Uhr' : '' ?>
                  </div>
                </div>
              </div>
            <?php elseif ($up['type'] === 'task'): $t = $up['data']; ?>
              <div class="d-flex align-items-start gap-2 mb-2 p-2 rounded tint-info">
                <i class="bi bi-briefcase mt-1 small" style="color:var(--state-info-fg);flex-shrink:0;"></i>
                <div>
                  <div class="fw-semibold small text-strong-c"><?= htmlspecialchars(mb_strimwidth($t['title'],0,32,'…')) ?></div>
                  <div class="text-muted" style="font-size:11px;"><?= date('d.m.Y', $up['ts']) ?> &middot; <?= htmlspecialchars($t['status']) ?></div>
                </div>
              </div>
            <?php else: $inv = $up['data']; ?>
              <div class="d-flex align-items-start gap-2 mb-2 p-2 rounded tint-warn">
                <i class="bi bi-receipt mt-1 small" style="color:var(--state-warn-fg);flex-shrink:0;"></i>
                <div>
                  <div class="fw-semibold small text-strong-c"><?= htmlspecialchars(mb_strimwidth($inv['title'],0,32,'…')) ?></div>
                  <div class="text-muted" style="font-size:11px;"><?= date('d.m.Y', $up['ts']) ?> &middot; <?= number_format($inv['amount'],2,',','.') ?> €</div>
                </div>
              </div>
            <?php endif; ?>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>

      </div>
    </div>
  </div>

<!-- ============================================================ -->
<!-- CREATE / EDIT EVENT MODAL                                     -->
<!-- ============================================================ -->
<div class="modal fade" id="eventCreateModal" tabindex="-1" data-bs-backdrop="static" data-bs-keyboard="false">
  <div class="modal-dialog modal-lg">
    <div class="modal-content border-0 shadow">
      <form method="POST" id="eventForm">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save_event">
        <input type="hidden" name="event_id" id="ev_id">

        <div class="modal-header" style="background:var(--color-primary);">
          <h5 class="modal-title text-white fw-bold" id="eventModalLabel">
            <i class="bi bi-calendar-plus me-2"></i><?= te('Neuer Termin') ?>
          </h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>

        <div class="modal-body p-4">
          <div class="row g-3">
            <div class="col-12">
              <label class="form-label fw-bold small"><?= te('Titel *') ?></label>
              <input type="text" name="title" id="ev_title" class="form-control" placeholder="<?= te('z.B. Kundengespräch mit Firma XY') ?>" required>
            </div>

            <div class="col-md-4">
              <label class="form-label fw-bold small"><?= te('Datum *') ?></label>
              <input type="date" name="event_date" id="ev_date" class="form-control" required>
            </div>
            <div class="col-md-4">
              <label class="form-label fw-bold small"><?= te('Startzeit') ?></label>
              <input type="time" name="start_time" id="ev_start_time" class="form-control">
            </div>
            <div class="col-md-4">
              <label class="form-label fw-bold small"><?= te('Endzeit') ?></label>
              <input type="time" name="end_time" id="ev_end_time" class="form-control">
            </div>

            <div class="col-md-6">
              <label class="form-label fw-bold small"><i class="bi bi-geo-alt me-1"></i><?= te('Ort / Adresse') ?></label>
              <input type="text" name="location" id="ev_location" class="form-control" placeholder="<?= te('Büro, Online, Adresse...') ?>">
            </div>
            <div class="col-md-6">
              <label class="form-label fw-bold small"><i class="bi bi-camera-video text-success me-1"></i><?= te('Meeting-Link') ?> <small class="text-muted fw-normal"><?= te('(Zoom, Teams, Meet…)') ?></small></label>
              <input type="url" name="meeting_url" id="ev_meeting_url" class="form-control" placeholder="https://zoom.us/j/...">
            </div>

            <div class="col-md-6">
              <label class="form-label fw-bold small"><?= te('Kategorie') ?></label>
              <select name="category" id="ev_category" class="form-select">
                <option value="Termin"><?= te('Termin') ?></option>
                <option value="Meeting"><?= te('Meeting') ?></option>
                <option value="Anruf"><?= te('Anruf') ?></option>
                <option value="Deadline"><?= te('Deadline') ?></option>
                <option value="Sonstiges"><?= te('Sonstiges') ?></option>
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-label fw-bold small">Status</label>
              <select name="status" id="ev_status" class="form-select">
                <option value="Geplant"><?= te('Geplant') ?></option>
                <option value="Bestätigt"><?= te('Bestätigt') ?></option>
                <option value="Abgesagt"><?= te('Abgesagt') ?></option>
                <option value="Abgeschlossen"><?= te('Abgeschlossen') ?></option>
              </select>
            </div>

            <div class="col-12">
              <label class="form-label fw-bold small"><?= te('Farbe') ?></label>
              <div class="d-flex gap-2 flex-wrap">
                <?php foreach($preset_colors as $hex => $name): ?>
                <label class="color-swatch-label" title="<?= $name ?>">
                  <input type="radio" name="color" value="<?= $hex ?>" class="d-none color-radio" <?= $hex === '#4a90d9' ? 'checked' : '' ?>>
                  <span class="color-swatch" style="background:<?= $hex ?>;"></span>
                </label>
                <?php endforeach; ?>
              </div>
            </div>

            <div class="col-12">
              <label class="form-label fw-bold small"><?= te('Beschreibung / Notizen') ?></label>
              <textarea name="description" id="ev_description" class="form-control" rows="2" placeholder="<?= te('Agenda, Infos, Themen...') ?>"></textarea>
            </div>

            <div class="col-12">
              <label class="form-label fw-bold small"><i class="bi bi-person-plus me-1"></i><?= te('Kontakte einladen') ?></label>
              <input type="text" class="form-control form-control-sm mb-2" id="contactSearch" placeholder="<?= te('Kontakt suchen...') ?>" autocomplete="off">
              <div id="contactCheckList" style="max-height:150px;overflow-y:auto;border:1px solid var(--border-base);border-radius:6px;background:var(--surface-card);">
                <?php if (empty($all_contacts)): ?>
                <div class="p-3 text-muted small text-center"><?= te('Keine Kontakte vorhanden.') ?></div>
                <?php else: ?>
                <?php foreach($all_contacts as $c): ?>
                <label class="contact-item d-flex align-items-center gap-2 px-3 py-2" style="cursor:pointer;border-bottom:1px solid var(--border-subtle);margin:0;">
                  <input type="checkbox" name="contact_ids[]" value="<?= $c['id'] ?>" class="flex-shrink-0">
                  <span class="text-strong-c small fw-semibold"><?= htmlspecialchars($c['name']) ?></span>
                  <?php if($c['email']): ?><small class="text-muted ms-auto"><?= htmlspecialchars($c['email']) ?></small><?php endif; ?>
                </label>
                <?php endforeach; ?>
                <?php endif; ?>
              </div>
              <div class="small text-muted mt-1"><i class="bi bi-info-circle me-1"></i><?= te('Für jeden Kontakt wird ein ICS-Einladungslink generiert (öffnet direkt Outlook/Google Kalender).') ?></div>
            </div>
          </div>
        </div>

        <div class="modal-footer bg-subtle p-3">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?= te('Abbrechen') ?></button>
          <button type="submit" class="btn btn-primary fw-bold px-4"><i class="bi bi-check2 me-1"></i><?= te('Speichern') ?></button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- DELETE EVENT MODAL -->
<div class="modal fade" id="deleteEventModal" tabindex="-1">
  <div class="modal-dialog modal-sm modal-dialog-centered">
    <div class="modal-content border-0 shadow">
      <form method="POST">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="delete_event">
        <input type="hidden" name="event_id" id="delete_event_id">
        <div class="modal-header bg-danger text-white">
          <h6 class="modal-title m-0"><i class="bi bi-exclamation-triangle-fill me-2"></i><?= te('Termin löschen?') ?></h6>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body text-center py-4">
          <p class="mb-0 fw-bold"><?= te('Termin und alle Einladungen löschen?') ?></p>
        </div>
        <div class="modal-footer p-2 d-flex justify-content-between bg-subtle">
          <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal"><?= te('Abbrechen') ?></button>
          <button type="submit" class="btn btn-danger btn-sm px-3 fw-bold"><?= te('Ja, löschen') ?></button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php if ($ask_invite > 0 && $invite_count > 0): ?>
<!-- INVITE PROMPT MODAL -->
<div class="modal fade" id="invitePromptModal" tabindex="-1" data-bs-backdrop="static">
  <div class="modal-dialog modal-dialog-centered modal-sm">
    <div class="modal-content border-0 shadow">
      <div class="modal-header" style="background:var(--color-primary);">
        <h6 class="modal-title text-white fw-bold m-0"><i class="bi bi-envelope-plus me-2"></i><?= te('Einladungen versenden?') ?></h6>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body text-center py-4">
        <div class="mb-2" style="font-size:2.2rem;">📨</div>
        <p class="fw-bold mb-1"><?= te('Termin gespeichert!') ?></p>
        <p class="text-muted small mb-0"><?= te('Einladungs-E-Mail mit ICS-Kalenderlink an') ?> <strong><?= $invite_count ?> Kontakt<?= $invite_count > 1 ? 'e' : '' ?></strong> <?= te('senden?') ?></p>
      </div>
      <div class="modal-footer p-2 d-flex justify-content-between bg-subtle">
        <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal"><?= te('Später') ?></button>
        <form method="POST" class="d-inline">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="send_all_invites">
          <input type="hidden" name="event_id" value="<?= $ask_invite ?>">
          <button type="submit" class="btn btn-primary btn-sm fw-bold px-3">
            <i class="bi bi-send me-1"></i><?= te('Ja, jetzt senden') ?>
          </button>
        </form>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>

<script>
const calData = {
    tasks:    <?= json_encode($tasks_by_day,    JSON_HEX_TAG|JSON_HEX_APOS) ?>,
    invoices: <?= json_encode($invoices_by_day, JSON_HEX_TAG|JSON_HEX_APOS) ?>,
    events:   <?= json_encode($events_by_day,   JSON_HEX_TAG|JSON_HEX_APOS) ?>
};

const calYear  = <?= $year ?>;
const calMonth = <?= $month ?>;
const csrfToken = <?= json_encode(csrf_token()) ?>;

// Index events by id for fast lookup
const eventIndex = {};
Object.values(calData.events).forEach(evs => evs.forEach(ev => { eventIndex[ev.id] = ev; }));

let currentDetailDay = '';

function esc(str) {
    if (!str) return '';
    return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

// Click on day cell
function dayClick(day, year, month, dateStr) {
    const tasks    = calData.tasks[day]    || [];
    const invoices = calData.invoices[day] || [];
    const events   = calData.events[day]   || [];
    if (tasks.length > 0 || invoices.length > 0 || events.length > 0) {
        showDayDetail(day, year, month);
    } else {
        openNewModalForDay(dateStr);
    }
}

function openNewModalForDay(dateStr) {
    currentDetailDay = dateStr;
    openNewModal(dateStr);
}

function openNewModal(preDate) {
    document.getElementById('eventModalLabel').innerHTML = '<i class="bi bi-calendar-plus me-2"></i><?= te('Neuer Termin') ?>';
    document.getElementById('eventForm').reset();
    document.getElementById('ev_id').value = '';
    if (preDate) document.getElementById('ev_date').value = preDate;
    // Default color
    const r = document.querySelector('input[name="color"][value="#4a90d9"]');
    if (r) r.checked = true;
    const modal = bootstrap.Modal.getOrCreateInstance(document.getElementById('eventCreateModal'));
    modal.show();
}

function openEditModal(ev) {
    document.getElementById('eventModalLabel').innerHTML = '<i class="bi bi-pencil me-2"></i>Termin bearbeiten';
    document.getElementById('ev_id').value          = ev.id;
    document.getElementById('ev_title').value       = ev.title || '';
    document.getElementById('ev_date').value        = ev.event_date || '';
    document.getElementById('ev_start_time').value  = ev.start_time ? ev.start_time.substring(0,5) : '';
    document.getElementById('ev_end_time').value    = ev.end_time   ? ev.end_time.substring(0,5)   : '';
    document.getElementById('ev_location').value    = ev.location    || '';
    document.getElementById('ev_meeting_url').value = ev.meeting_url || '';
    document.getElementById('ev_description').value = ev.description || '';
    document.getElementById('ev_category').value    = ev.category || 'Termin';
    document.getElementById('ev_status').value      = ev.status   || 'Geplant';

    // Color
    const radios = document.querySelectorAll('input[name="color"]');
    radios.forEach(r => { r.checked = (r.value === ev.color); });
    if (![...radios].some(r => r.checked)) { const first = radios[0]; if (first) first.checked = true; }

    // Contacts
    const cids = (ev.contacts || []).map(c => c.id);
    document.querySelectorAll('#contactCheckList input[type=checkbox]').forEach(cb => {
        cb.checked = cids.includes(parseInt(cb.value));
    });

    const modal = bootstrap.Modal.getOrCreateInstance(document.getElementById('eventCreateModal'));
    modal.show();
}

// Contact search filter
document.getElementById('contactSearch').addEventListener('input', function() {
    const q = this.value.toLowerCase();
    document.querySelectorAll('#contactCheckList .contact-item').forEach(item => {
        item.style.display = item.textContent.toLowerCase().includes(q) ? '' : 'none';
    });
});

// Day detail panel
function showDayDetail(day, year, month) {
    const tasks    = calData.tasks[day]    || [];
    const invoices = calData.invoices[day] || [];
    const events   = calData.events[day]   || [];
    const card     = document.getElementById('day-detail-card');
    const title    = document.getElementById('day-detail-title');
    const body     = document.getElementById('day-detail-body');

    const d = String(day).padStart(2,'0');
    const m = String(month).padStart(2,'0');
    currentDetailDay = `${year}-${m}-${d}`;
    title.innerHTML  = `<i class="bi bi-calendar3 me-2" style="color:var(--color-primary);"></i>${d}.${m}.${year}`;

    let html = '';

    // Events
    events.forEach(ev => {
        const color   = ev.status === 'Abgesagt' ? '#6c757d' : ev.color;
        const timeStr = ev.start_time
            ? ev.start_time.substring(0,5) + (ev.end_time ? ' – '+ev.end_time.substring(0,5) : '') + ' Uhr'
            : <?= tjs('Ganztägig') ?>;

        const statusLabel = ev.status !== 'Geplant'
            ? `<span class="badge ms-1" style="font-size:9px;background:${color}22;color:${color};">${esc(ev.status)}</span>`
            : '';

        let meetingBtn = '';
        if (ev.meeting_url) {
            meetingBtn = `<a href="${esc(ev.meeting_url)}" target="_blank" rel="noopener"
                class="btn btn-xs mt-1 d-inline-flex align-items-center gap-1"
                style="font-size:var(--text-xs);background:var(--success-soft);color:var(--accent-success);border:1px solid var(--accent-success);padding:2px 8px;">
                <i class="bi bi-camera-video"></i> Meeting beitreten
            </a>`;
        }

        let contactsHtml = '';
        if (ev.contacts && ev.contacts.length > 0) {
            contactsHtml = '<div class="mt-2 pt-2" style="border-top:1px dashed var(--border-base);">';
            contactsHtml += '<div class="text-muted mb-1" style="font-size:10px;font-weight:600;text-transform:uppercase;letter-spacing:.5px;">Eingeladene Kontakte</div>';
            ev.contacts.forEach(c => {
                contactsHtml += `<div class="d-flex align-items-center gap-1 mb-1">
                    <i class="bi bi-person-fill small flex-shrink-0" style="color:${color};"></i>
                    <span class="small text-strong-c" style="max-width:100px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">${esc(c.name)}</span>
                    <div class="d-flex gap-1 ms-auto">
                        <button class="btn btn-xs btn-outline-secondary" style="padding:1px 5px;font-size:10px;"
                            onclick="copyLink('${esc(c.ics_link)}', this)" title=<?= tjs('ICS-Einladungslink kopieren') ?>>
                            <i class="bi bi-link-45deg"></i>
                        </button>
                        <button class="btn btn-xs" style="padding:1px 5px;font-size:10px;background:${color}22;color:${color};border:1px solid ${color}44;"
                            onclick="sendInviteEmail(${ev.id}, ${c.id})" title=<?= tjs('Einladung per E-Mail senden') ?>>
                            <i class="bi bi-envelope"></i>
                        </button>
                    </div>
                </div>`;
            });
            if (ev.contacts.length > 1) {
                contactsHtml += `<button class="btn btn-xs w-100 mt-1"
                    style="font-size:10px;background:${color}11;color:${color};border:1px solid ${color}33;"
                    onclick="sendAllInvites(${ev.id})">
                    <i class="bi bi-send me-1"></i><?= te('Alle einladen') ?>
                </button>`;
            }
            contactsHtml += '</div>';
        }

        html += `<div class="mb-3 p-2 rounded" style="background:${color}0d;border-left:3px solid ${color};">
            <div class="d-flex align-items-start justify-content-between gap-1 mb-1">
                <div class="flex-grow-1 min-w-0">
                    <div class="fw-semibold small text-strong-c">${esc(ev.title)}${statusLabel}</div>
                    <div class="text-muted" style="font-size:11px;"><i class="bi bi-clock me-1"></i>${timeStr}</div>
                    ${ev.location ? `<div class="text-muted" style="font-size:11px;"><i class="bi bi-geo-alt me-1"></i>${esc(ev.location)}</div>` : ''}
                    ${ev.category && ev.category !== 'Termin' ? `<div class="mt-1"><span class="badge" style="font-size:9px;background:${color}22;color:${color};">${esc(ev.category)}</span></div>` : ''}
                    ${meetingBtn}
                </div>
                <div class="d-flex gap-1 flex-shrink-0">
                    <button class="btn btn-xs" style="padding:2px 6px;font-size:10px;background:var(--surface-subtle);border:1px solid var(--border-base);"
                        onclick="openEditModal(eventIndex[${ev.id}])" title="Bearbeiten">
                        <i class="bi bi-pencil"></i>
                    </button>
                    <button class="btn btn-xs btn-outline-danger" style="padding:2px 6px;font-size:10px;"
                        onclick="triggerDeleteEvent(${ev.id})" title=<?= tjs('Löschen') ?>>
                        <i class="bi bi-trash"></i>
                    </button>
                </div>
            </div>
            ${ev.description ? `<div class="text-muted mt-1" style="font-size:11px;">${esc(ev.description)}</div>` : ''}
            ${contactsHtml}
        </div>`;
    });

    // Tasks
    tasks.forEach(t => {
        const now = Date.now();
        const dl  = new Date(t.deadline).getTime();
        let cls, icon;
        if (t.status === 'Erledigt')  { cls = 'tint-success'; icon = 'bi-check-circle-fill text-success'; }
        else if (dl < now)            { cls = 'tint-danger';  icon = 'bi-exclamation-triangle-fill text-danger'; }
        else                          { cls = 'tint-info';    icon = 'bi-briefcase text-info'; }

        html += `<div class="d-flex align-items-start gap-2 mb-2 p-2 rounded ${cls}">
            <i class="bi ${icon} mt-1 small flex-shrink-0"></i>
            <div>
                <div class="fw-semibold small text-strong-c">${esc(t.title)}</div>
                <div class="text-muted" style="font-size:11px;">${t.contact_name ? esc(t.contact_name)+' &middot; ' : ''}${esc(t.status)}</div>
            </div>
        </div>`;
    });

    // Invoices
    invoices.forEach(inv => {
        const cls  = inv.status === 'Bezahlt' ? 'tint-success' : (inv.status === <?= tjs('Überfällig') ?> ? 'tint-danger' : 'tint-warn');
        const icon = inv.status === 'Bezahlt' ? 'bi-check-circle-fill text-success' : (inv.status === <?= tjs('Überfällig') ?> ? 'bi-exclamation-triangle-fill text-danger' : 'bi-receipt text-warning');
        const amt  = parseFloat(inv.amount).toLocaleString('de-DE',{minimumFractionDigits:2,maximumFractionDigits:2});

        html += `<div class="d-flex align-items-start gap-2 mb-2 p-2 rounded ${cls}">
            <i class="bi ${icon} mt-1 small flex-shrink-0"></i>
            <div>
                <div class="fw-semibold small text-strong-c">${esc(inv.title)}</div>
                <div class="text-muted" style="font-size:11px;">${inv.contact_name ? esc(inv.contact_name)+' &middot; ' : ''}${amt} € &middot; ${esc(inv.status)}</div>
            </div>
        </div>`;
    });

    if (!html) html = '<p class="text-muted small m-0"><i class="bi bi-calendar-x me-1"></i><?= te('Keine Einträge für diesen Tag.') ?></p>';

    body.innerHTML = html;
    card.style.display = 'block';
    card.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
}

// Copy ICS link to clipboard
function copyLink(url, btn) {
    navigator.clipboard.writeText(url).then(() => {
        const orig = btn.innerHTML;
        btn.innerHTML = '<i class="bi bi-check2"></i>';
        btn.style.cssText = 'padding:1px 5px;font-size:10px;background:var(--state-success-bg);color:var(--state-success-fg);border:1px solid var(--state-success-fg);';
        setTimeout(() => {
            btn.innerHTML = orig;
            btn.style.cssText = '';
            btn.className = 'btn btn-xs btn-outline-secondary';
            btn.style.cssText = 'padding:1px 5px;font-size:10px;';
        }, 2000);
    }).catch(() => {
        prompt(<?= tjs('ICS-Link kopieren:') ?>, url);
    });
}

// Send invite email via form POST
function sendInviteEmail(eventId, contactId) {
    if (!confirm(<?= tjs('Einladung per E-Mail an diesen Kontakt senden?') ?>)) return;
    submitInviteForm('send_invite', eventId, contactId);
}

function sendAllInvites(eventId) {
    if (!confirm(<?= tjs('Einladungen an alle Kontakte dieses Termins senden?') ?>)) return;
    submitInviteForm('send_all_invites', eventId, null);
}

function submitInviteForm(action, eventId, contactId) {
    const form = document.createElement('form');
    form.method = 'POST';
    form.action = `calendar?year=${calYear}&month=${calMonth}`;
    const fields = { action, event_id: eventId, csrf_token: csrfToken };
    if (contactId !== null) fields.contact_id = contactId;
    for (const [k, v] of Object.entries(fields)) {
        const inp = document.createElement('input');
        inp.type = 'hidden'; inp.name = k; inp.value = v;
        form.appendChild(inp);
    }
    document.body.appendChild(form);
    form.submit();
}

// Delete event
const delEventModal = new bootstrap.Modal(document.getElementById('deleteEventModal'));
function triggerDeleteEvent(id) {
    document.getElementById('delete_event_id').value = id;
    delEventModal.show();
}

<?php if ($ask_invite > 0 && $invite_count > 0): ?>
document.addEventListener('DOMContentLoaded', function() {
    bootstrap.Modal.getOrCreateInstance(document.getElementById('invitePromptModal')).show();
});
<?php endif; ?>
</script>
<?php require 'includes/layout_end.php'; ?>
