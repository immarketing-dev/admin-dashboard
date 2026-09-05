<?php
require_once 'config.php';
require_once __DIR__ . '/includes/logging.php';
require_once __DIR__ . '/includes/mail_log.php';
require_once 'includes/mail_templates.php';
require_once 'includes/auth.php';
require_once 'includes/filter_state.php';

// AJAX: Notizen laden
if (isset($_GET['ajax_notes'])) {
    header('Content-Type: application/json');
    $tid  = (int)($_GET['ticket_id'] ?? 0);
    $stmt = $pdo->prepare("SELECT * FROM ticket_notes WHERE ticket_id=? ORDER BY created_at ASC");
    $stmt->execute([$tid]);
    echo json_encode(array_map(fn($n) => [
        'id'         => $n['id'],
        'note_html'  => nl2br(htmlspecialchars($n['note'], ENT_QUOTES)),
        'created_at' => date('d.m.Y H:i', strtotime($n['created_at'])),
        'author'     => $n['author']    ?? 'admin',
        'is_public'  => (bool)($n['is_public'] ?? false),
    ], $stmt->fetchAll(PDO::FETCH_ASSOC)));
    exit();
}

// POST-Aktionen
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    csrf_check();
    $valid_prios = ['Niedrig', 'Mittel', 'Hoch', 'Kritisch'];

    // Ticket manuell erstellen
    if ($_POST['action'] === 'create_ticket') {
        $contact_id = (int)($_POST['contact_id'] ?? 0);
        $subject    = trim($_POST['subject']   ?? '');
        $message    = trim($_POST['message']   ?? '');
        $priority   = in_array($_POST['priority'] ?? '', $valid_prios) ? $_POST['priority'] : 'Mittel';
        if ($contact_id && $subject && $message) {
            $pdo->prepare("INSERT INTO support_tickets (contact_id, subject, message, status, priority) VALUES (?, ?, ?, 'Offen', ?)")
                ->execute([$contact_id, $subject, $message, $priority]);
            log_event($pdo, 'TICKET_CREATED', "Ticket '$subject' manuell angelegt.");
        }
        filter_redirect('tickets', ['msg' => 'created']);
    }

    // Status ändern (AJAX)
    if ($_POST['action'] === 'update_status') {
        $id  = (int)$_POST['ticket_id'];
        $new = $_POST['status'] ?? '';
        $s   = $pdo->prepare("SELECT subject FROM support_tickets WHERE id=?"); $s->execute([$id]); $sub = $s->fetchColumn();
        $pdo->prepare("UPDATE support_tickets SET status=? WHERE id=?")->execute([$new, $id]);
        log_event($pdo, 'TICKET_UPDATED', "Ticket '$sub' Status → '$new'.");
        echo "OK"; exit();
    }

    // Priorität ändern (AJAX)
    if ($_POST['action'] === 'update_priority') {
        $id   = (int)$_POST['ticket_id'];
        $prio = in_array($_POST['priority'] ?? '', $valid_prios) ? $_POST['priority'] : 'Mittel';
        $pdo->prepare("UPDATE support_tickets SET priority=? WHERE id=?")->execute([$prio, $id]);
        log_event($pdo, 'TICKET_PRIORITY', "Priorität von Ticket $id auf '$prio' gesetzt.");
        echo "OK"; exit();
    }

    // Notiz speichern (AJAX)
    if ($_POST['action'] === 'save_note') {
        header('Content-Type: application/json');
        $id   = (int)$_POST['ticket_id'];
        $note = trim($_POST['note'] ?? '');
        if (!$id || $note === '') { echo json_encode(['ok' => false]); exit(); }
        $pdo->prepare("INSERT INTO ticket_notes (ticket_id, note) VALUES (?, ?)")->execute([$id, $note]);
        log_event($pdo, 'TICKET_NOTE', "Interne Notiz zu Ticket $id gespeichert.");
        echo json_encode(['ok' => true, 'id' => (int)$pdo->lastInsertId()]);
        exit();
    }

    // Antwort senden (AJAX) — optional per E-Mail
    if ($_POST['action'] === 'send_reply') {
        header('Content-Type: application/json');
        $id        = (int)$_POST['ticket_id'];
        $to        = trim($_POST['to_email']      ?? '');
        $subject   = trim($_POST['reply_subject'] ?? '');
        $body      = trim($_POST['reply_body']    ?? '');
        $do_email  = !empty($_POST['send_email']) && $_POST['send_email'] === '1';
        if (!$id || !$body) { echo json_encode(['ok' => false, 'err' => 'Fehlende Felder']); exit(); }
        if ($do_email && !$to) { echo json_encode(['ok' => false, 'err' => 'Keine E-Mail-Adresse angegeben']); exit(); }

        // Kontakt + Ticket-Betreff für die E-Mail
        $row_contact = $pdo->prepare("SELECT c.name, c.portal_token, st.subject AS ticket_subject
            FROM support_tickets st LEFT JOIN contacts c ON st.contact_id = c.id WHERE st.id = ?");
        $row_contact->execute([$id]);
        $contact_row    = $row_contact->fetch(PDO::FETCH_ASSOC);
        $contact_name   = $contact_row['name']           ?? '';
        $ticket_subject = $contact_row['ticket_subject'] ?? $subject;
        $portal_token   = $contact_row['portal_token']   ?? '';
        $proto       = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $portal_url  = $proto . '://' . $_SERVER['HTTP_HOST']
                     . rtrim(dirname($_SERVER['PHP_SELF']), '/\\')
                     . '/portal?token=' . urlencode($portal_token) . '#support';
        $company     = setting('company_short', COMPANY_SHORT);
        $site        = setting('main_website',  MAIN_WEBSITE);

        try {
            if ($do_email) {
                require_once __DIR__ . '/vendor/autoload.php';

                // ── HTML-Mailvorlage ──────────────────────────────
                $preview   = mb_strimwidth(strip_tags($body), 0, 200, '…');
                $body_html = nl2br(htmlspecialchars($body, ENT_QUOTES));
                $first     = htmlspecialchars(explode(' ', $contact_name)[0] ?: 'Kunde', ENT_QUOTES);
                // Wortlaut aus der Vorlage (Einstellungen > E-Mail-Vorlagen).
                // Vorher stand hier ein fest verdrahtetes Tabellen-Layout.
                $_m = mail_render('ticket_reply', [
                    'kunde'   => explode(' ', $contact_name)[0] ?: 'Kunde',
                    'betreff' => $ticket_subject,
                    'antwort' => $body,
                    'firma'   => $company,
                ], $portal_url);
                // ────────────────────────────────────────────────────
                $mail = new PHPMailer\PHPMailer\PHPMailer(true);
                $mail->isSMTP();
                $mail->Host       = SMTP_HOST;
                $mail->SMTPAuth   = true;
                $mail->Username   = SMTP_USER;
                $mail->Password   = SMTP_PASS;
                $mail->SMTPSecure = (SMTP_PORT == 587) ? 'tls' : 'ssl';
                $mail->Port       = SMTP_PORT;
                $mail->CharSet    = 'UTF-8';
                $mail->setFrom(SMTP_USER, $company);
                $mail->addAddress($to, $contact_name);
                $mail->Subject  = $_m['subject'];
                $mail->isHTML(true);
                $mail->Body     = $_m['html'];
                $mail->AltBody  = "Hallo $first,\n\nSie haben eine neue Antwort auf Ihre Support-Anfrage erhalten.\n\nBetreff: $ticket_subject\n\nAntwort:\n$body\n\nZum Portal: $portal_url\n\n-- $company";
                $mail->send();
                mail_protokollieren($pdo, 'ticket_reply', $to, $_m['subject'], true,
                    null, 'Ticket #' . $id);
                // Interner Log-Eintrag (nur für Admin sichtbar)
                $pdo->prepare("INSERT INTO ticket_notes (ticket_id, note, author, is_public) VALUES (?, ?, 'admin', 0)")
                    ->execute([$id, "📧 E-Mail gesendet an: $to"]);
                log_event($pdo, 'TICKET_REPLY', "Antwort auf Ticket #$id per E-Mail an $to gesendet.");
            }
            // Öffentliche Antwort immer speichern (sichtbar im Kundenportal)
            $pdo->prepare("INSERT INTO ticket_notes (ticket_id, note, author, is_public) VALUES (?, ?, 'admin', 1)")
                ->execute([$id, $body]);
            $pdo->prepare("UPDATE support_tickets SET status='In Bearbeitung' WHERE id=? AND status='Offen'")->execute([$id]);
            if (!$do_email) {
                log_event($pdo, 'TICKET_REPLY', "Antwort auf Ticket #$id im Portal gespeichert (keine E-Mail).");
            }
            echo json_encode(['ok' => true, 'email_sent' => $do_email]);
        } catch (\Throwable $e) {
            echo json_encode(['ok' => false, 'err' => $e->getMessage()]);
        }
        exit();
    }

    // Ticket löschen
    if ($_POST['action'] === 'delete_ticket') {
        $id = (int)$_POST['ticket_id'];
        $s  = $pdo->prepare("SELECT subject FROM support_tickets WHERE id=?"); $s->execute([$id]); $sub = $s->fetchColumn();
        $pdo->prepare("DELETE FROM ticket_notes WHERE ticket_id=?")->execute([$id]);
        $pdo->prepare("DELETE FROM support_tickets WHERE id=?")->execute([$id]);
        log_event($pdo, 'TICKET_DELETED', "Ticket '$sub' gelöscht.");
        filter_redirect('tickets');
    }
}

// FILTER & DATEN LADEN
$search_query  = trim($_GET['search']   ?? '');
$filter_status = $_GET['status']        ?? 'all';
$filter_prio   = $_GET['priority']      ?? 'all';

$sql = "SELECT t.*, c.name AS contact_name, c.company, c.email AS contact_email,
               DATEDIFF(NOW(), t.created_at) AS age_days,
               (SELECT COUNT(*) FROM ticket_notes tn WHERE tn.ticket_id = t.id) AS notes_count
        FROM support_tickets t
        LEFT JOIN contacts c ON t.contact_id = c.id
        WHERE 1=1";
$params = [];
if ($filter_status !== 'all') { $sql .= " AND t.status=?";   $params[] = $filter_status; }
if ($filter_prio   !== 'all') { $sql .= " AND t.priority=?"; $params[] = $filter_prio; }
if (!empty($search_query)) {
    $sql .= " AND (t.subject LIKE ? OR t.message LIKE ? OR c.name LIKE ? OR c.company LIKE ?)";
    $term = "%$search_query%";
    array_push($params, $term, $term, $term, $term);
}
$sql .= " ORDER BY FIELD(t.priority,'Kritisch','Hoch','Mittel','Niedrig'), t.status='Erledigt', t.created_at DESC";
$stmt = $pdo->prepare($sql); $stmt->execute($params);
$tickets = $stmt->fetchAll(PDO::FETCH_ASSOC);

// KPI-Zahlen
$kpi_rows = $pdo->query("SELECT status, COUNT(*) cnt FROM support_tickets GROUP BY status")->fetchAll(PDO::FETCH_KEY_PAIR);
$kpi_open  = (int)($kpi_rows['Offen']          ?? 0);
$kpi_wip   = (int)($kpi_rows['In Bearbeitung'] ?? 0);
$kpi_done  = (int)($kpi_rows['Erledigt']       ?? 0);
$kpi_total = $kpi_open + $kpi_wip + $kpi_done;

$all_contacts = $pdo->query("SELECT id, name, company FROM contacts WHERE deleted_at IS NULL ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);

// Prioritäts-Farben
$prio_css = ['Kritisch' => 'var(--accent-danger)', 'Hoch' => 'var(--accent-warning)', 'Mittel' => 'var(--color-primary)', 'Niedrig' => 'var(--text-faint)'];
function prio_badge(string $p): string {
    global $prio_css;
    $c = $prio_css[$p] ?? 'var(--text-faint)';
    return "<span style=\"background:{$c}22;color:{$c};font-size:10px;font-weight:700;padding:2px 8px;border-radius:4px;text-transform:uppercase;letter-spacing:.3px;white-space:nowrap;\">" . htmlspecialchars($p) . "</span>";
}
$page_title   = 'Support-Tickets';
$page_heading = 'Support Zentrale';
$current_page = basename($_SERVER['PHP_SELF']);
$header_actions = '
      <button class="btn btn-primary btn-sm fw-bold" data-bs-toggle="modal" data-bs-target="#newTicketModal">
        <i class="bi bi-plus-lg"></i> Neues Ticket
      </button>';

require 'includes/head.php';
require 'includes/layout_start.php';
?>

    <?php if (isset($_GET['msg']) && $_GET['msg'] === 'created'): ?>
    <div class="alert alert-success alert-dismissible fade show mb-3 shadow-sm">
      <i class="bi bi-check-circle-fill me-2"></i> <?= te('Ticket wurde erfolgreich erstellt.') ?>
      <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>

    <!-- KPI Cards -->
    <div class="row g-3 mb-4">
      <div class="col-6 col-md-3">
        <div class="widget-box widget-accent-left text-center py-3">
          <div class="fw-bold lh-1 mb-1" style="font-size:2rem;color:var(--accent-danger);"><?= $kpi_open ?></div>
          <div class="label-xs"><?= te('Offen') ?></div>
        </div>
      </div>
      <div class="col-6 col-md-3">
        <div class="widget-box text-center py-3" style="border-top:4px solid var(--color-primary);">
          <div class="fw-bold lh-1 mb-1" style="font-size:2rem;color:var(--color-primary);"><?= $kpi_wip ?></div>
          <div class="label-xs"><?= te('In Bearbeitung') ?></div>
        </div>
      </div>
      <div class="col-6 col-md-3">
        <div class="widget-box widget-accent-left text-center py-3">
          <div class="fw-bold lh-1 mb-1" style="font-size:2rem;color:var(--accent-success);"><?= $kpi_done ?></div>
          <div class="label-xs"><?= te('Erledigt') ?></div>
        </div>
      </div>
      <div class="col-6 col-md-3">
        <div class="widget-box widget-accent-left text-center py-3">
          <div class="fw-bold lh-1 mb-1" style="font-size:2rem;color:var(--text-muted);"><?= $kpi_total ?></div>
          <div class="label-xs"><?= te('Gesamt') ?></div>
        </div>
      </div>
    </div>

    <!-- Filter -->
    <form class="filter-bar">
      <div class="input-group input-group-sm search-box">
        <span class="input-group-text bg-surface border-end-0 text-muted"><i class="bi bi-search"></i></span>
        <input type="text" name="search" class="form-control border-start-0 ps-0" placeholder="<?= te('Kunde, Betreff oder Nachricht…') ?>" value="<?= htmlspecialchars($search_query) ?>">
      </div>
      <select name="status" class="form-select form-select-sm w-auto" onchange="this.form.submit()">
        <option value="all"><?= te('Alle Status') ?></option>
        <option value="Offen"          <?= $filter_status === 'Offen'          ? 'selected' : '' ?>><?= te('Offen') ?></option>
        <option value="In Bearbeitung" <?= $filter_status === 'In Bearbeitung' ? 'selected' : '' ?>><?= te('In Bearbeitung') ?></option>
        <option value="Erledigt"       <?= $filter_status === 'Erledigt'       ? 'selected' : '' ?>><?= te('Erledigt') ?></option>
      </select>
      <select name="priority" class="form-select form-select-sm w-auto" onchange="this.form.submit()">
        <option value="all"><?= te('Alle Prioritäten') ?></option>
        <option value="Kritisch" <?= $filter_prio === 'Kritisch' ? 'selected' : '' ?>><?= te('🔴 Kritisch') ?></option>
        <option value="Hoch"     <?= $filter_prio === 'Hoch'     ? 'selected' : '' ?>><?= te('🟠 Hoch') ?></option>
        <option value="Mittel"   <?= $filter_prio === 'Mittel'   ? 'selected' : '' ?>><?= te('🟡 Mittel') ?></option>
        <option value="Niedrig"  <?= $filter_prio === 'Niedrig'  ? 'selected' : '' ?>><?= te('⚪ Niedrig') ?></option>
      </select>
      <button type="submit" class="btn btn-primary btn-sm"><?= te('Suchen') ?></button>
      <?php if ($search_query || $filter_status !== 'all' || $filter_prio !== 'all'): ?>
        <a href="tickets" class="btn btn-outline-secondary btn-sm" title="<?= te('Filter zurücksetzen') ?>"><i class="bi bi-x-circle"></i></a>
      <?php endif; ?>
    </form>

    <!-- Tabelle -->
    <div class="bg-surface rounded shadow-sm overflow-hidden">
      <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
          <thead class="bg-subtle small text-uppercase fw-bold text-muted">
            <tr>
              <th style="width:130px;"><?= te('Datum / Alter') ?></th>
              <th style="width:105px;"><?= te('Priorität') ?></th>
              <th><?= te('Kunde') ?></th>
              <th><?= te('Betreff') ?></th>
              <th style="width:165px;">Status</th>
              <th class="text-center" style="width:90px;"><?= te('Aktionen') ?></th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($tickets)): ?>
              <tr>
                <td colspan="6" class="text-center py-5 text-muted">
                  <i class="bi bi-inbox fs-1 d-block mb-2"></i>
                  <?= te('Keine Tickets gefunden.') ?>
                </td>
              </tr>
            <?php endif; ?>
            <?php foreach ($tickets as $t):
              $safe_json  = htmlspecialchars(json_encode($t, JSON_HEX_TAG | JSON_HEX_APOS), ENT_QUOTES, 'UTF-8');
              $s_class    = 'status-' . strtolower(str_replace(' ', '-', $t['status']));
              $age        = (int)$t['age_days'];
              $is_old     = $age >= 3 && $t['status'] !== 'Erledigt';
              $prio       = $t['priority'] ?? 'Mittel';
              $row_style  = $t['status'] === 'Erledigt' ? 'opacity:.6' : ($is_old ? 'background:var(--danger-soft)' : '');
            ?>
            <tr style="<?= $row_style ?>" id="row_<?= $t['id'] ?>">
              <td class="small">
                <div class="fw-bold text-muted"><?= date('d.m.Y', strtotime($t['created_at'])) ?></div>
                <div class="<?= $is_old ? 'text-danger fw-bold' : 'text-muted' ?>" style="font-size:10px;">
                  <?= $is_old ? '<i class="bi bi-clock-history me-1"></i>' : '' ?><?= $age ?><?= te('d alt') ?>
                </div>
              </td>
              <td><?= prio_badge($prio) ?></td>
              <td>
                <div class="fw-bold text-strong-c"><?= htmlspecialchars($t['contact_name'] ?? '–') ?></div>
                <div class="small text-muted"><?= htmlspecialchars($t['company'] ?? '') ?></div>
              </td>
              <td>
                <span class="fw-bold d-block text-primary" style="cursor:pointer;" onclick='viewTicket(<?= $safe_json ?>)'><?= htmlspecialchars($t['subject']) ?></span>
                <div class="d-flex align-items-center gap-2 mt-1">
                  <small class="text-muted text-truncate d-inline-block" style="max-width:260px;"><?= htmlspecialchars(mb_strimwidth($t['message'], 0, 100, '…')) ?></small>
                  <?php if ((int)$t['notes_count'] > 0): ?>
                    <span class="badge rounded-pill" style="background:var(--color-primary);font-size:9px;" title="<?= $t['notes_count'] ?> Notiz(en)">
                      <i class="bi bi-chat-dots-fill"></i> <?= $t['notes_count'] ?>
                    </span>
                  <?php endif; ?>
                </div>
              </td>
              <td>
                <div class="dropdown">
                  <span class="status-badge <?= $s_class ?> dropdown-toggle" role="button" data-bs-toggle="dropdown"><?= $t['status'] ?></span>
                  <ul class="dropdown-menu shadow-sm">
                    <li><a class="dropdown-item py-1 small" href="#" onclick="quickStatus(<?= $t['id'] ?>, 'Offen'); return false;"><?= te('Offen') ?></a></li>
                    <li><a class="dropdown-item py-1 small text-primary fw-bold" href="#" onclick="quickStatus(<?= $t['id'] ?>, 'In Bearbeitung'); return false;"><?= te('In Bearbeitung') ?></a></li>
                    <li><a class="dropdown-item py-1 small text-success fw-bold" href="#" onclick="quickStatus(<?= $t['id'] ?>, 'Erledigt'); return false;"><?= te('Erledigt') ?></a></li>
                  </ul>
                </div>
              </td>
              <td class="text-center">
                <button class="btn-icon text-primary" onclick='viewTicket(<?= $safe_json ?>)' title="<?= te('Öffnen') ?>"><i class="bi bi-eye-fill"></i></button>
                <button class="btn-icon text-danger" onclick="triggerDelete(<?= $t['id'] ?>)" title="<?= te('Löschen') ?>"><i class="bi bi-trash3-fill"></i></button>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>

  <!-- ══════════════════ Ticket-Detail Modal ══════════════════ -->
  <div class="modal fade" id="ticketModal" tabindex="-1" data-bs-backdrop="static" data-bs-keyboard="false">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
      <div class="modal-content border-0 shadow">

        <div class="modal-header bg-dark text-white gap-3 flex-wrap">
          <div class="d-flex align-items-center gap-2 flex-grow-1 min-width-0">
            <span id="tm_prio_badge"></span>
            <h5 class="modal-title fw-bold m-0 text-truncate" id="tm_subject"></h5>
          </div>
          <select id="tm_status_sel" class="form-select form-select-sm w-auto" onchange="quickStatusModal()" style="min-width:150px;"></select>
          <button type="button" class="btn-close btn-close-white flex-shrink-0" data-bs-dismiss="modal"></button>
        </div>

        <div class="modal-body p-0 bg-subtle">
          <div class="row g-0">

            <!-- Links: Nachricht + Notizen + Antwort -->
            <div class="col-lg-8 p-4 border-end d-flex flex-column gap-4">

              <div>
                <div class="label-xs mb-2"><?= te('Nachricht des Kunden') ?></div>
                <div class="bg-surface p-3 rounded border shadow-sm" id="tm_message"
                     style="min-height:110px;white-space:pre-wrap;font-size:14px;color:var(--text-body);"></div>
              </div>

              <div>
                <div class="d-flex align-items-center justify-content-between mb-2">
                  <span class="label-xs"><?= te('Interne Notizen') ?> <span class="text-muted fw-normal" id="tm_notes_count"></span></span>
                </div>
                <div id="tm_notes_list" class="d-flex flex-column gap-2 mb-3" style="max-height:200px;overflow-y:auto;"></div>
                <div class="d-flex gap-2">
                  <textarea id="tm_new_note" class="form-control form-control-sm" rows="2"
                            placeholder="<?= te('Interne Notiz hinzufügen…') ?>" style="resize:none;"></textarea>
                  <button class="btn btn-outline-secondary btn-sm align-self-end px-3 fw-bold" onclick="saveNote()" title="<?= te('Notiz speichern') ?>">
                    <i class="bi bi-plus-lg"></i>
                  </button>
                </div>
              </div>

              <div>
                <div class="label-xs mb-2"><?= te('Antwort senden') ?></div>
                <div class="bg-surface p-3 rounded border shadow-sm">
                  <div class="row g-2 mb-2">
                    <div class="col-sm-6">
                      <input type="email" id="tm_reply_to" class="form-control form-control-sm" placeholder="<?= te('An: E-Mail-Adresse') ?>">
                    </div>
                    <div class="col-sm-6">
                      <input type="text" id="tm_reply_subject" class="form-control form-control-sm" placeholder="<?= te('Betreff') ?>">
                    </div>
                  </div>
                  <textarea id="tm_reply_body" class="form-control form-control-sm mb-2" rows="4"
                            placeholder="<?= te('Ihre Antwort…') ?>" style="resize:vertical;"></textarea>
                  <div class="d-flex align-items-center justify-content-between gap-2 flex-wrap">
                    <div class="form-check form-check-sm mb-0">
                      <input class="form-check-input" type="checkbox" id="tm_send_email" checked>
                      <label class="form-check-label small text-muted" for="tm_send_email">
                        <i class="bi bi-envelope me-1"></i><?= te('Per E-Mail an Kunden senden') ?>
                      </label>
                    </div>
                    <div class="d-flex align-items-center gap-2 flex-shrink-0">
                      <small id="tm_reply_status" class="text-muted"></small>
                      <a href="#" id="tm_mailto_link" class="btn btn-outline-secondary btn-sm" title="<?= te('Mailto öffnen') ?>">
                        <i class="bi bi-envelope me-1"></i><?= te('Mailto') ?>
                      </a>
                      <button class="btn btn-primary btn-sm fw-bold" onclick="sendReply()">
                        <i class="bi bi-send me-1"></i><?= te('Antworten') ?>
                      </button>
                    </div>
                  </div>
                </div>
              </div>

            </div>

            <!-- Rechts: Ticket-Info -->
            <div class="col-lg-4 p-4 d-flex flex-column gap-3">
              <div>
                <div class="label-xs mb-1"><?= te('Absender') ?></div>
                <div class="fw-bold text-primary" id="tm_sender"></div>
                <div class="small text-muted" id="tm_email_display"></div>
              </div>
              <div>
                <div class="label-xs mb-1"><?= te('Eingegangen') ?></div>
                <div class="fw-bold small text-strong-c" id="tm_date"></div>
                <div class="small text-muted" id="tm_age"></div>
              </div>
              <div>
                <div class="label-xs mb-1"><?= te('Priorität ändern') ?></div>
                <select id="tm_prio_sel" class="form-select form-select-sm" onchange="updatePriority()">
                  <option value="Niedrig"><?= te('⚪ Niedrig') ?></option>
                  <option value="Mittel"><?= te('🟡 Mittel') ?></option>
                  <option value="Hoch"><?= te('🟠 Hoch') ?></option>
                  <option value="Kritisch"><?= te('🔴 Kritisch') ?></option>
                </select>
              </div>
              <div class="mt-auto pt-3 border-top">
                <a href="#" id="tm_profile_btn" class="btn btn-outline-secondary btn-sm w-100 fw-bold">
                  <i class="bi bi-person-badge me-1"></i><?= te('Kundenprofil öffnen') ?>
                </a>
              </div>
            </div>

          </div>
        </div>

      </div>
    </div>
  </div>

  <!-- ══════════════════ Neues Ticket Modal ══════════════════ -->
  <div class="modal fade" id="newTicketModal" tabindex="-1" data-bs-backdrop="static" data-bs-keyboard="false">
    <div class="modal-dialog">
      <div class="modal-content border-0 shadow">
        <form method="POST">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="create_ticket">
          <div class="modal-header bg-primary text-white">
            <h5 class="modal-title fw-bold"><i class="bi bi-plus-circle-fill me-2"></i><?= te('Neues Ticket anlegen') ?></h5>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
          </div>
          <div class="modal-body p-4">
            <div class="mb-3">
              <label class="form-label fw-bold small"><?= te('Kunde *') ?></label>
              <select name="contact_id" class="form-select" required>
                <option value=""><?= te('– Kunde auswählen –') ?></option>
                <?php foreach ($all_contacts as $c): ?>
                  <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['name']) ?><?= $c['company'] ? ' (' . htmlspecialchars($c['company']) . ')' : '' ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="mb-3">
              <label class="form-label fw-bold small"><?= te('Priorität') ?></label>
              <select name="priority" class="form-select">
                <option value="Niedrig"><?= te('⚪ Niedrig') ?></option>
                <option value="Mittel" selected><?= te('🟡 Mittel') ?></option>
                <option value="Hoch"><?= te('🟠 Hoch') ?></option>
                <option value="Kritisch"><?= te('🔴 Kritisch') ?></option>
              </select>
            </div>
            <div class="mb-3">
              <label class="form-label fw-bold small"><?= te('Betreff *') ?></label>
              <input type="text" name="subject" class="form-control" required placeholder="<?= te('Kurze Beschreibung des Problems') ?>">
            </div>
            <div class="mb-0">
              <label class="form-label fw-bold small"><?= te('Nachricht *') ?></label>
              <textarea name="message" class="form-control" rows="5" required placeholder="<?= te('Detaillierte Beschreibung…') ?>"></textarea>
            </div>
          </div>
          <div class="modal-footer bg-subtle d-flex justify-content-between">
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?= te('Abbrechen') ?></button>
            <button type="submit" class="btn btn-primary fw-bold px-4"><i class="bi bi-save me-1"></i><?= te('Ticket erstellen') ?></button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <!-- ══════════════════ Löschen Modal ══════════════════ -->
  <div class="modal fade" id="deleteTicketModal" tabindex="-1">
    <div class="modal-dialog modal-sm modal-dialog-centered">
      <div class="modal-content border-0 shadow">
        <form method="POST">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="delete_ticket">
          <input type="hidden" name="ticket_id" id="del_id">
          <div class="modal-header bg-danger text-white">
            <h6 class="modal-title m-0"><i class="bi bi-exclamation-triangle-fill me-2"></i><?= te('Ticket löschen?') ?></h6>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
          </div>
          <div class="modal-body text-center py-4">
            <p class="mb-0 fw-bold"><?= te('Ticket und alle Notizen endgültig löschen?') ?></p>
          </div>
          <div class="modal-footer p-2 d-flex justify-content-between bg-subtle">
            <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal"><?= te('Abbrechen') ?></button>
            <button type="submit" class="btn btn-danger btn-sm px-3 fw-bold"><?= te('Ja, löschen') ?></button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <script>
    const tModal   = new bootstrap.Modal(document.getElementById('ticketModal'));
    const delModal = new bootstrap.Modal(document.getElementById('deleteTicketModal'));
    const csrf     = () => document.querySelector('meta[name="csrf-token"]').content;

    const prioColors = { Kritisch: 'var(--accent-danger)', Hoch: 'var(--accent-warning)', Mittel: 'var(--color-primary)', Niedrig: 'var(--text-faint)' };
    let _ticket = null;

    function viewTicket(t) {
        _ticket = t;

        document.getElementById('tm_subject').textContent       = t.subject;
        document.getElementById('tm_sender').textContent        = t.contact_name || '–';
        document.getElementById('tm_email_display').textContent = t.contact_email || <?= tjs('Keine E-Mail hinterlegt') ?>;

        const d = new Date(t.created_at);
        document.getElementById('tm_date').textContent = d.toLocaleDateString('de-DE') + ' ' + d.toLocaleTimeString('de-DE', { hour: '2-digit', minute: '2-digit' }) + ' Uhr';
        document.getElementById('tm_age').textContent  = (t.age_days || 0) + <?= tjs(' Tage alt') ?>;

        document.getElementById('tm_message').textContent = t.message;

        // Prio-Badge im Header
        const pc = prioColors[t.priority] || 'var(--text-faint)';
        document.getElementById('tm_prio_badge').innerHTML =
            `<span style="background:${pc}33;color:${pc};font-size:10px;font-weight:700;padding:2px 8px;border-radius:4px;text-transform:uppercase;letter-spacing:.3px;">${t.priority || 'Mittel'}</span>`;

        // Prio-Select rechts
        document.getElementById('tm_prio_sel').value = t.priority || 'Mittel';

        // Status-Select im Header
        const sSel = document.getElementById('tm_status_sel');
        sSel.innerHTML = '';
        ['Offen', <?= tjs('In Bearbeitung') ?>, 'Erledigt'].forEach(s => {
            const o = new Option(s, s, s === t.status, s === t.status);
            sSel.appendChild(o);
        });

        // Antwort vorausfüllen
        document.getElementById('tm_reply_to').value      = t.contact_email || '';
        document.getElementById('tm_reply_subject').value = 'Re: ' + t.subject;
        document.getElementById('tm_reply_body').value    = '';
        document.getElementById('tm_reply_status').textContent = '';
        document.getElementById('tm_mailto_link').href =
            `mailto:${t.contact_email || ''}?subject=${encodeURIComponent('Re: ' + t.subject)}&body=${encodeURIComponent('\n\n' + <?= tjs('--- Ursprüngliche Anfrage ---') ?> + '\n' + t.message)}`;

        document.getElementById('tm_profile_btn').href = 'contacts?search=' + encodeURIComponent(t.contact_name || '');

        loadNotes(t.id);
        tModal.show();
    }

    function loadNotes(ticketId) {
        fetch('tickets?ajax_notes=1&ticket_id=' + ticketId)
            .then(r => r.json())
            .then(notes => {
                const list  = document.getElementById('tm_notes_list');
                const count = document.getElementById('tm_notes_count');
                count.textContent = notes.length > 0 ? `(${notes.length})` : '';
                if (notes.length === 0) {
                    list.innerHTML = '<div class="text-muted small text-center py-2 border rounded bg-surface"><?= te('Noch keine Notizen.') ?></div>';
                    return;
                }
                list.innerHTML = notes.map(n => {
                    const isClient = n.author === 'client';
                    const isPublic = n.is_public && !isClient;
                    const bg     = isClient ? 'var(--state-success-bg)' : (isPublic ? 'var(--state-info-bg)' : 'var(--surface-card)');
                    const border = isClient ? 'var(--state-success-fg)' : (isPublic ? 'var(--state-info-fg)' : 'var(--border-base)');
                    const pill   = isClient
                        ? `<span style="font-size:9px;background:var(--success-soft);color:var(--accent-success);border-radius:3px;padding:1px 5px;font-weight:700;text-transform:uppercase;">Kunde</span>`
                        : (isPublic
                            ? `<span style="font-size:9px;background:var(--accent-soft);color:var(--color-primary);border-radius:3px;padding:1px 5px;font-weight:700;text-transform:uppercase;">👁 Sichtbar</span>`
                            : '');
                    return `<div class="border rounded p-2 shadow-sm" style="background:${bg};border-color:${border}!important;">
                        ${pill ? `<div class="mb-1">${pill}</div>` : ''}
                        <div style="font-size:13px;white-space:pre-wrap;">${n.note_html}</div>
                        <div class="text-muted mt-1" style="font-size:10px;">${n.created_at}</div>
                    </div>`;
                }).join('');
                list.scrollTop = list.scrollHeight;
            });
    }

    function saveNote() {
        const note = document.getElementById('tm_new_note').value.trim();
        if (!note || !_ticket) return;
        fetch('tickets', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({ action: 'save_note', ticket_id: _ticket.id, note, csrf_token: csrf() })
        }).then(r => r.json()).then(resp => {
            if (resp.ok) {
                document.getElementById('tm_new_note').value = '';
                loadNotes(_ticket.id);
                // Notiz-Badge in Tabelle aktualisieren
                const row = document.getElementById('row_' + _ticket.id);
                if (row) {
                    let badge = row.querySelector('.rounded-pill');
                    if (!badge) {
                        const msgCell = row.querySelector('.d-flex.align-items-center.gap-2');
                        if (msgCell) msgCell.insertAdjacentHTML('beforeend', `<span class="badge rounded-pill" style="background:var(--color-primary);font-size:9px;"><i class="bi bi-chat-dots-fill"></i> 1</span>`);
                    } else {
                        const cur = parseInt(badge.textContent.trim()) || 0;
                        badge.innerHTML = `<i class="bi bi-chat-dots-fill"></i> ${cur + 1}`;
                    }
                }
            }
        });
    }

    function sendReply() {
        const to        = document.getElementById('tm_reply_to').value.trim();
        const subject   = document.getElementById('tm_reply_subject').value.trim();
        const body      = document.getElementById('tm_reply_body').value.trim();
        const sendEmail = document.getElementById('tm_send_email').checked;
        const statusEl  = document.getElementById('tm_reply_status');
        if (!body) {
            statusEl.textContent = <?= tjs('Bitte Antworttext eingeben.') ?>;
            statusEl.className   = 'text-danger small';
            return;
        }
        if (sendEmail && !to) {
            statusEl.textContent = <?= tjs('Bitte E-Mail-Adresse angeben.') ?>;
            statusEl.className   = 'text-danger small';
            return;
        }
        statusEl.textContent = sendEmail ? <?= tjs('Wird gesendet…') ?> : <?= tjs('Wird gespeichert…') ?>;
        statusEl.className   = 'text-muted small';
        fetch('tickets', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({ action: 'send_reply', ticket_id: _ticket.id, to_email: to, reply_subject: subject, reply_body: body, send_email: sendEmail ? '1' : '0', csrf_token: csrf() })
        }).then(r => r.text()).then(raw => {
            let resp;
            try { resp = JSON.parse(raw); }
            catch (e) {
                statusEl.textContent = <?= tjs('Serverfehler – bitte Logs prüfen.') ?>;
                statusEl.className   = 'text-danger small';
                console.error('send_reply: ungültige Serverantwort', raw);
                return;
            }
            if (resp.ok) {
                statusEl.textContent = resp.email_sent ? <?= tjs('✓ Gesendet & gespeichert') ?> : <?= tjs('✓ Im Portal gespeichert') ?>;
                statusEl.className   = 'text-success small fw-bold';
                document.getElementById('tm_reply_body').value = '';
                loadNotes(_ticket.id);
                updateRowBadge(_ticket.id, <?= tjs('In Bearbeitung') ?>);
                document.getElementById('tm_status_sel').value = <?= tjs('In Bearbeitung') ?>;
            } else {
                statusEl.textContent = <?= tjs('Fehler: ') ?> + (resp.err || <?= tjs('Unbekannter Fehler') ?>);
                statusEl.className   = 'text-danger small';
            }
        }).catch(err => {
            statusEl.textContent = <?= tjs('Netzwerkfehler: ') ?> + err.message;
            statusEl.className   = 'text-danger small';
        });
    }

    function updatePriority() {
        if (!_ticket) return;
        const prio = document.getElementById('tm_prio_sel').value;
        fetch('tickets', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({ action: 'update_priority', ticket_id: _ticket.id, priority: prio, csrf_token: csrf() })
        });
        const pc = prioColors[prio] || 'var(--text-faint)';
        document.getElementById('tm_prio_badge').innerHTML =
            `<span style="background:${pc}33;color:${pc};font-size:10px;font-weight:700;padding:2px 8px;border-radius:4px;text-transform:uppercase;letter-spacing:.3px;">${prio}</span>`;
    }

    function quickStatusModal() {
        if (!_ticket) return;
        quickStatus(_ticket.id, document.getElementById('tm_status_sel').value);
    }

    function quickStatus(id, newStatus) {
        fetch('tickets', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({ action: 'update_status', ticket_id: id, status: newStatus, csrf_token: csrf() })
        }).then(() => {
            updateRowBadge(id, newStatus);
            const row = document.getElementById('row_' + id);
            if (row) row.style.opacity = newStatus === 'Erledigt' ? '0.6' : '1';
        });
    }

    function updateRowBadge(id, newStatus) {
        const row = document.getElementById('row_' + id);
        if (!row) return;
        const badge = row.querySelector('.status-badge');
        if (badge) {
            badge.className = 'status-badge status-' + newStatus.toLowerCase().replace(/ /g, '-') + ' dropdown-toggle';
            badge.textContent = newStatus;
        }
    }

    function triggerDelete(id) {
        document.getElementById('del_id').value = id;
        delModal.show();
    }

    // Modal-State beim Schließen zurücksetzen
    document.getElementById('ticketModal').addEventListener('hidden.bs.modal', () => {
        _ticket = null;
        document.getElementById('tm_status_sel').innerHTML = '';
        document.getElementById('tm_notes_list').innerHTML = '';
        document.getElementById('tm_notes_count').textContent = '';
    });
  </script>
<?php require 'includes/layout_end.php'; ?>
