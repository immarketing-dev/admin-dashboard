<?php
// 1. Zentrale Config laden
require_once 'config.php';
require_once __DIR__ . '/includes/logging.php';

require_once 'includes/auth.php';
require_once 'includes/mail_log.php';
// Fuer die Bezeichnungen der Vorlagen in der Mailansicht.
require_once 'includes/mail_templates.php';

// ==========================================
// AKTION: LOGS EXPORTIEREN (Als .txt Datei)
// ==========================================
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['action'])) {
    csrf_check();
}

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['action']) && $_POST['action'] === 'export_logs') {
    $stmt = $pdo->query("SELECT * FROM logs ORDER BY created_at DESC");
    $all_logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

    header('Content-Type: text/plain; charset=utf-8');
    header('Content-Disposition: attachment; filename="system_logs_' . date('Y-m-d_H-i') . '.txt"');

    echo "====================================================\n";
    echo " " . strtoupper(COMPANY_SHORT) . " - SYSTEM-LOGBUCH EXPORT\n";
    echo " Generiert am: " . date('d.m.Y H:i:s') . " Uhr\n";
    echo "====================================================\n\n";

    foreach($all_logs as $log) {
        $date = date('d.m.Y H:i:s', strtotime($log['created_at']));
        $type = str_pad(strtoupper($log['action_type']), 20, " ");
        // Die IP steht seit Schemaversion 2 in einer eigenen Spalte. Alte
        // Zeilen und alle Nicht-Login-Ereignisse haben keine.
        $ip   = str_pad(($log['ip'] ?? '') !== '' ? $log['ip'] : '-', 15, " ");
        $desc = str_replace("\n", " ", $log['description']);
        echo "[$date] | $type | $ip | $desc\n";
    }
    exit();
}

// ==========================================
// AKTION: Logs leeren
// ==========================================
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['action']) && $_POST['action'] === 'clear_logs') {
    // 1. Logbuch komplett leeren
    $pdo->exec("TRUNCATE TABLE logs"); 
    
    // 2. Direkt im Anschluss protokollieren, dass geleert wurde
    log_event($pdo, 'SYSTEM_RESET', "Das System-Logbuch wurde manuell geleert.");
        
    header("Location: logs");
    exit();
}

// ── Filter, Zeitraum und Blättern ───────────────────────────────────
$log_filter = trim($_GET['filter'] ?? '');
$log_search = trim($_GET['q'] ?? '');
$log_range  = $_GET['range'] ?? '30';
if (!in_array($log_range, ['1', '7', '30', 'all'], true)) $log_range = '30';

// Wie viele Zeilen je Seite. Die Einstellung bleibt die Obergrenze.
$per_page = max(20, min(500, (int)setting('log_limit', '200')));
$page     = max(1, (int)($_GET['page'] ?? 1));

$where  = [];
$params = [];
if ($log_filter) { $where[] = 'action_type LIKE ?'; $params[] = $log_filter . '%'; }
// Die IP wird mitdurchsucht - sie steht seit Schemaversion 2 in einer
// eigenen Spalte und nicht mehr in der Beschreibung.
if ($log_search) {
    $where[]  = '(action_type LIKE ? OR description LIKE ? OR ip LIKE ?)';
    $params[] = "%$log_search%"; $params[] = "%$log_search%"; $params[] = "%$log_search%";
}
if ($log_range !== 'all') {
    // Eingesetzt statt gebunden, weil MySQL hinter INTERVAL einen
    // Zahlenausdruck erwartet und ein gebundener Wert dort als
    // Zeichenkette ankommt. Der Wert stammt aus der Adresszeile, wird
    // hier aber auf int gecastet - damit steht nur eine Zahl im SQL.
    $where[]  = 'created_at >= DATE_SUB(NOW(), INTERVAL ' . (int) $log_range . ' DAY)';
}
$wsql = $where ? ' WHERE ' . implode(' AND ', $where) : '';

$cnt = $pdo->prepare("SELECT COUNT(*) FROM logs$wsql");
$cnt->execute($params);
$log_total  = (int)$cnt->fetchColumn();
$page_count = max(1, (int)ceil($log_total / $per_page));
if ($page > $page_count) $page = $page_count;
$offset = ($page - 1) * $per_page;

// LIMIT/OFFSET als Zahlen einsetzen: beide sind oben auf einen Bereich
// begrenzt, und als gebundene Parameter behandelt MySQL sie je nach
// Treibereinstellung als Zeichenkette und lehnt sie ab.
$stmt = $pdo->prepare("SELECT * FROM logs$wsql ORDER BY created_at DESC LIMIT $per_page OFFSET $offset");
$stmt->execute($params);
$logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ── Kennzahlen ──────────────────────────────────────────────────────
$log_stats = [
    'gesamt' => (int)$pdo->query('SELECT COUNT(*) FROM logs')->fetchColumn(),
    'heute'  => (int)$pdo->query('SELECT COUNT(*) FROM logs WHERE DATE(created_at) = CURDATE()')->fetchColumn(),
];
$log_stats['fehlversuche'] = (int)$pdo->query(
    "SELECT COUNT(*) FROM logs WHERE action_type IN ('LOGIN_FAILED','PORTAL_PIN_FAILED','PORTAL_PIN_LOCKED','SYSTEM_LOCKOUT')
     AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)"
)->fetchColumn();
$log_stats['letzte_anmeldung'] = $pdo->query(
    "SELECT created_at FROM logs WHERE action_type = 'LOGIN_SUCCESS' ORDER BY created_at DESC LIMIT 1"
)->fetchColumn() ?: null;

// Alle verfügbaren Präfixe für Filter-Dropdown
$log_types_raw = $pdo->query("SELECT DISTINCT action_type FROM logs ORDER BY action_type ASC")->fetchAll(PDO::FETCH_COLUMN);
$log_prefixes = [];
foreach ($log_types_raw as $t) {
    $parts = explode('_', $t);
    $prefix = $parts[0] . (isset($parts[1]) ? '_' : '');
    $log_prefixes[$prefix] = true;
}
$log_prefixes = array_keys($log_prefixes);

// ==========================================
// BADGE-STYLING (MIT ROT FÜR ALLE LÖSCHUNGEN)
// ==========================================
function getLogBadgeClass($action) {
    if (strpos($action, 'LOGIN_SUCCESS') !== false) return 'bg-success';
    if (strpos($action, 'LOGIN_FAILED') !== false) return 'bg-danger';
    if (strpos($action, 'LOGOUT') !== false) return 'bg-secondary';

    // Globale Regel: Alles was "_DELETED" oder "_DELETE" hat, wird ROT
    if (strpos($action, '_DELETED') !== false || strpos($action, '_DELETE') !== false) return 'bg-danger';

    // Portal
    if (strpos($action, 'PORTAL_') !== false) return 'bg-primary';

    // Meilensteine
    if (strpos($action, 'MILESTONE_COMPLETED') !== false) return 'bg-success';
    if (strpos($action, 'MILESTONE_') !== false) return 'bg-info text-dark';

    // Aufgaben / Projekte
    if (strpos($action, 'TASK_') !== false) return 'bg-primary';

    // Angebote
    if (strpos($action, 'QUOTE_CONVERTED') !== false) return 'bg-success';
    if (strpos($action, 'QUOTE_EMAIL') !== false) return 'bg-info text-dark';
    if (strpos($action, 'QUOTE_') !== false) return 'bg-secondary';

    // Kontakte
    if (strpos($action, 'CONTACT_') !== false) return 'log-badge-contact';

    // Wiki & Anhänge
    if (strpos($action, 'WIKI_') !== false) return 'bg-warning text-dark';

    // Finanzen & Rechnungen
    if (strpos($action, 'FINANCE_') !== false) return 'bg-success';
    if (strpos($action, 'INVOICE_') !== false) return 'bg-dark';

    // Tickets
    if (strpos($action, 'TICKET_') !== false) return 'bg-info text-dark';

    // Leads / Anfragen
    if (strpos($action, 'LEAD_') !== false) return 'bg-warning text-dark';

    // Assets (Dateien)
    if (strpos($action, 'ASSET_') !== false) return 'bg-primary';

    // E-Mail
    if (strpos($action, 'MAIL_ERROR') !== false) return 'bg-danger';
    if (strpos($action, 'MAIL_') !== false) return 'bg-info text-dark';

    // Monitor & System
    if (strpos($action, 'MONITOR_') !== false) return 'bg-dark';
    if (strpos($action, 'SYSTEM_') !== false) return 'bg-warning text-dark';

    return 'bg-secondary';
}
// Zwei Ansichten auf derselben Seite: das Ereignisprotokoll (was ist im
// Panel geschehen) und das Mailprotokoll (was ist hinausgegangen). Sie
// beantworten verschiedene Fragen, teilen sich aber Rahmen, Filterleiste
// und die Erwartung, hier nachzusehen.
$log_view = ($_GET['view'] ?? '') === 'mail' ? 'mail' : 'events';

$mail_filter = in_array($_GET['mstatus'] ?? '', ['sent', 'failed'], true) ? $_GET['mstatus'] : '';
$mail_zahlen = mail_protokoll_zahlen($pdo);
$mails       = $log_view === 'mail' ? mail_protokoll($pdo, $mail_filter, (int) setting('log_limit', '200')) : [];

$page_title   = 'System-Logs';
$page_heading = 'System-Logs';
$current_page = basename($_SERVER['PHP_SELF']);
// Exportieren und Leeren betreffen das Ereignisprotokoll. In der
// Mailansicht bleiben sie weg - ein Knopf, der etwas anderes leert als
// das, was man gerade ansieht, ist eine Falle.
$header_actions = $log_view === 'mail' ? '' : '
      <div class="d-flex gap-2">
        <form action="systemlogs" method="POST" style="margin: 0;">
          ' . csrf_field() . '
          <input type="hidden" name="action" value="export_logs">
          <button type="submit" class="btn btn-success btn-sm fw-bold"><i class="bi bi-download"></i> <span class="btn-label">Als .txt exportieren</span></button>
        </form>

        <button type="button" class="btn btn-outline-danger btn-sm fw-bold" onclick="triggerClearLogs()">
            <i class="bi bi-trash3" style="pointer-events:none;"></i> <span class="btn-label">Logs leeren</span>
        </button>
      </div>';
$extra_head = <<<'CSS'
  <style>
      /* Spezifische Logbuch-Klassen (die nicht in der globalen design.css sind) */
      .log-container { background: var(--surface-card); border-radius: 10px; padding: 25px; box-shadow: var(--elev-rest); border-top: 3px solid var(--color-primary); overflow-x: auto;}
      .log-table th { font-family: 'Poppins', sans-serif; color: var(--text-heading); font-weight: 600; font-size: 14px; border-bottom: 2px solid var(--border-subtle); padding-bottom: 15px;}
      .log-table td { padding: 15px 10px; vertical-align: middle; border-bottom: 1px solid var(--border-subtle); font-size: 14px; color: var(--text-body);}
      .log-badge { padding: 5px 10px; font-weight: 600; font-size: 11px; letter-spacing: 0.5px; border-radius: 4px; color: white; }
      .log-date { font-weight: 600; color: var(--text-strong); }
      .log-time { color: var(--text-muted); font-size: var(--text-sm); margin-left: 5px; }
      .log-ip { font-family: monospace; font-size: 13px; color: var(--text-muted); white-space: nowrap; }
  </style>
CSS;

require 'includes/head.php';
require 'includes/layout_start.php';
?>

<ul class="nav nav-tabs mb-4">
  <li class="nav-item">
    <a class="nav-link <?= $log_view === 'events' ? 'active' : '' ?>" href="systemlogs">
      <i class="bi bi-journal-text me-1"></i> <?= te('Ereignisse') ?>
    </a>
  </li>
  <li class="nav-item">
    <a class="nav-link <?= $log_view === 'mail' ? 'active' : '' ?>" href="systemlogs?view=mail">
      <i class="bi bi-envelope me-1"></i> <?= te('Versendete E-Mails') ?>
      <?php if ($mail_zahlen['fehler'] > 0): ?>
        <span class="badge bg-danger ms-1"><?= (int) $mail_zahlen['fehler'] ?></span>
      <?php endif; ?>
    </a>
  </li>
</ul>

<?php if ($log_view === 'mail'): ?>

    <div class="row g-3 mb-3 row-cols-2 row-cols-lg-4">
      <div class="col">
        <div class="widget-box widget-accent-left h-100 d-flex align-items-center gap-3">
          <div class="icon-tile icon-tile-primary"><i class="bi bi-envelope"></i></div>
          <div>
            <div class="label-xs"><?= te('Sendungen gesamt') ?></div>
            <div class="fw-bold fs-5 lh-1 text-strong-c"><?= number_format($mail_zahlen['gesamt'], 0, ',', '.') ?></div>
          </div>
        </div>
      </div>
      <div class="col">
        <div class="widget-box widget-accent-left h-100 d-flex align-items-center gap-3">
          <div class="icon-tile <?= $mail_zahlen['fehler'] > 0 ? 'icon-tile-danger' : 'icon-tile-success' ?>">
            <i class="bi <?= $mail_zahlen['fehler'] > 0 ? 'bi-exclamation-triangle' : 'bi-check2' ?>"></i>
          </div>
          <div>
            <div class="label-xs"><?= te('Fehlgeschlagen') ?></div>
            <div class="fw-bold fs-5 lh-1 text-strong-c"><?= number_format($mail_zahlen['fehler'], 0, ',', '.') ?></div>
          </div>
        </div>
      </div>
    </div>

    <form method="GET" class="filter-bar mb-3 d-flex flex-wrap gap-2 align-items-center">
      <input type="hidden" name="view" value="mail">
      <div class="btn-group btn-group-sm" role="group">
        <a class="btn <?= $mail_filter === ''       ? 'btn-primary' : 'btn-outline-secondary' ?>" href="systemlogs?view=mail"><?= te('Alle') ?></a>
        <a class="btn <?= $mail_filter === 'sent'   ? 'btn-primary' : 'btn-outline-secondary' ?>" href="systemlogs?view=mail&amp;mstatus=sent"><?= te('Zugestellt') ?></a>
        <a class="btn <?= $mail_filter === 'failed' ? 'btn-primary' : 'btn-outline-secondary' ?>" href="systemlogs?view=mail&amp;mstatus=failed"><?= te('Fehlgeschlagen') ?></a>
      </div>
      <span class="text-muted small ms-auto"><?= te('%d Einträge angezeigt', count($mails)) ?></span>
    </form>

    <div class="log-container">
      <?php if (!$mails): ?>
        <div class="text-center py-5 text-muted">
          <i class="bi bi-envelope-open fs-1 d-block mb-2"></i>
          <?= te('Noch keine Sendungen aufgezeichnet.') ?>
        </div>
      <?php else: ?>
        <table class="table table-borderless log-table w-100 mb-0">
          <thead>
            <tr>
              <th width="15%"><?= te('Zeitpunkt') ?></th>
              <th width="13%"><?= te('Vorlage') ?></th>
              <th width="20%"><?= te('Empfänger') ?></th>
              <th width="32%"><?= te('Betreff') ?></th>
              <th width="20%"><?= te('Bezug') ?></th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($mails as $m): ?>
            <tr>
              <td>
                <span class="log-date"><?= date('d.m.Y', strtotime((string) $m['created_at'])) ?></span>
                <span class="log-time"><?= date('H:i:s', strtotime((string) $m['created_at'])) ?></span>
              </td>
              <td>
                <?php
                  // Die Bezeichnung aus mail_templates(), sonst der rohe
                  // Schluessel - eine Mail ohne Vorlage (etwa die
                  // Angebotsreaktion aus dem Portal) hat dort keinen Eintrag.
                  $_tpl = mail_templates()[$m['template']] ?? null;
                ?>
                <span class="log-badge log-badge-contact"><?= htmlspecialchars($_tpl['label'] ?? $m['template'] ?: '–') ?></span>
              </td>
              <td class="log-ip"><?= htmlspecialchars((string) $m['recipient']) ?></td>
              <td>
                <?= htmlspecialchars((string) $m['subject']) ?>
                <?php if ($m['status'] === 'failed'): ?>
                  <div class="small text-danger mt-1">
                    <i class="bi bi-exclamation-triangle-fill me-1"></i>
                    <?= htmlspecialchars((string) ($m['error'] ?? '')) ?>
                  </div>
                <?php endif; ?>
              </td>
              <td class="small text-muted"><?= htmlspecialchars((string) ($m['context'] ?? '')) ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </div>

<?php else: ?>

    <!-- Filter & Suche -->
    <div class="row g-3 mb-3 row-cols-2 row-cols-lg-4">
      <div class="col">
        <div class="widget-box widget-accent-left h-100 d-flex align-items-center gap-3">
          <div class="icon-tile icon-tile-primary"><i class="bi bi-journal-text"></i></div>
          <div>
            <div class="label-xs"><?= te('Einträge gesamt') ?></div>
            <div class="fw-bold fs-5 lh-1 text-strong-c"><?= number_format($log_stats['gesamt'], 0, ',', '.') ?></div>
          </div>
        </div>
      </div>
      <div class="col">
        <div class="widget-box widget-accent-left h-100 d-flex align-items-center gap-3">
          <div class="icon-tile icon-tile-primary"><i class="bi bi-calendar-day"></i></div>
          <div>
            <div class="label-xs"><?= te('Heute') ?></div>
            <div class="fw-bold fs-5 lh-1 text-strong-c"><?= number_format($log_stats['heute'], 0, ',', '.') ?></div>
          </div>
        </div>
      </div>
      <div class="col">
        <div class="widget-box widget-accent-left h-100 d-flex align-items-center gap-3">
          <?php $warn = $log_stats['fehlversuche'] > 0; ?>
          <div class="icon-tile <?= $warn ? 'icon-tile-danger' : 'icon-tile-primary' ?>">
            <i class="bi bi-shield-exclamation"></i>
          </div>
          <div>
            <div class="label-xs"><?= te('Fehlversuche (7 Tage)') ?></div>
            <div class="fw-bold fs-5 lh-1 <?= $warn ? 'text-danger' : 'text-strong-c' ?>">
              <?= number_format($log_stats['fehlversuche'], 0, ',', '.') ?>
            </div>
          </div>
        </div>
      </div>
      <div class="col">
        <div class="widget-box widget-accent-left h-100 d-flex align-items-center gap-3">
          <div class="icon-tile icon-tile-primary"><i class="bi bi-box-arrow-in-right"></i></div>
          <div style="min-width:0;">
            <div class="label-xs"><?= te('Letzte Anmeldung') ?></div>
            <div class="fw-bold lh-1 text-strong-c" style="font-size:var(--text-base-plus);">
              <?= $log_stats['letzte_anmeldung']
                    ? date('d.m.Y H:i', strtotime($log_stats['letzte_anmeldung']))
                    : '—' ?>
            </div>
          </div>
        </div>
      </div>
    </div>

    <form method="GET" class="filter-bar">
      <select name="filter" class="form-select form-select-sm" style="max-width:180px;" onchange="this.form.submit()">
        <option value=""><?= te('Alle Typen') ?></option>
        <?php foreach($log_prefixes as $pre): ?>
          <option value="<?= htmlspecialchars($pre) ?>" <?= $log_filter === $pre ? 'selected' : '' ?>><?= htmlspecialchars($pre) ?>*</option>
        <?php endforeach; ?>
      </select>
      <select name="range" class="form-select form-select-sm w-auto" onchange="this.form.submit()"
              aria-label="<?= te('Zeitraum') ?>">
        <?php foreach(['1'=>'Heute','7'=>'7 Tage','30'=>'30 Tage','all'=>'Gesamter Zeitraum'] as $v=>$t): ?>
          <option value="<?= $v ?>" <?= $log_range === $v ? 'selected' : '' ?>><?= $t ?></option>
        <?php endforeach; ?>
      </select>
      <div class="input-group input-group-sm search-box" style="max-width:260px;">
        <span class="input-group-text"><i class="bi bi-search"></i></span>
        <input type="text" name="q" class="form-control" placeholder="<?= te('In Beschreibung/IP suchen…') ?>" value="<?= htmlspecialchars($log_search) ?>">
      </div>
      <button type="submit" class="btn btn-sm btn-primary fw-bold"><?= te('Filtern') ?></button>
      <?php if($log_filter || $log_search || $log_range !== '30'): ?>
        <a href="systemlogs" class="btn btn-sm btn-outline-secondary"><?= te('Zurücksetzen') ?></a>
      <?php endif; ?>
      <span class="text-muted small ms-auto">
        <?= number_format($log_total, 0, ',', '.')  ?> <?= te('Treffer') ?><?php
          if ($page_count > 1) echo ' · Seite ' . $page . ' von ' . $page_count;
        ?>
      </span>
    </form>

    <div class="log-container">
      <?php if(count($logs) > 0): ?>
        <table class="table table-borderless log-table w-100 mb-0">
          <thead>
            <tr>
              <th width="18%"><?= te('Zeitpunkt') ?></th>
              <th width="18%"><?= te('Aktion') ?></th>
              <th width="14%"><?= te('IP-Adresse') ?></th>
              <th width="50%"><?= te('Details') ?></th>
            </tr>
          </thead>
          <tbody>
            <?php foreach($logs as $log): ?>
              <tr>
                <td>
                  <span class="log-date"><?php echo date('d.m.Y', strtotime($log['created_at'])); ?></span>
                  <span class="log-time"><?php echo date('H:i:s', strtotime($log['created_at'])); ?></span>
                </td>
                <td>
                  <span class="badge log-badge <?php echo getLogBadgeClass($log['action_type']); ?>">
                    <?php echo htmlspecialchars($log['action_type']); ?>
                  </span>
                </td>
                <td class="log-ip"><?php echo ($log['ip'] ?? '') !== '' ? htmlspecialchars($log['ip']) : '—'; ?></td>
                <td><?php echo htmlspecialchars($log['description']); ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>

        <?php if($page_count > 1):
          // Beim Blättern die gesetzten Filter mitnehmen - sonst landet man
          // auf Seite 2 einer anderen Liste als der, die man gerade ansieht.
          $qs = function (int $seite) use ($log_filter, $log_search, $log_range) {
              $teile = array_filter([
                  'filter' => $log_filter,
                  'q'      => $log_search,
                  'range'  => $log_range !== '30' ? $log_range : '',
                  'page'   => $seite > 1 ? (string)$seite : '',
              ], fn($v) => $v !== '' && $v !== null);
              return 'systemlogs' . ($teile ? '?' . http_build_query($teile) : '');
          };
          $von_ = max(1, $page - 2);
          $bis_ = min($page_count, $page + 2);
        ?>
        <nav class="d-flex justify-content-center mt-3" aria-label="<?= te('Seiten') ?>">
          <ul class="pagination pagination-sm mb-0">
            <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
              <a class="page-link" href="<?= htmlspecialchars($qs(max(1, $page - 1))) ?>" aria-label="<?= te('Zurück') ?>">
                <i class="bi bi-chevron-left"></i>
              </a>
            </li>
            <?php if($von_ > 1): ?>
              <li class="page-item"><a class="page-link" href="<?= htmlspecialchars($qs(1)) ?>">1</a></li>
              <?php if($von_ > 2): ?>
                <li class="page-item disabled"><span class="page-link">&hellip;</span></li>
              <?php endif; ?>
            <?php endif; ?>
            <?php for($i = $von_; $i <= $bis_; $i++): ?>
              <li class="page-item <?= $i === $page ? 'active' : '' ?>">
                <a class="page-link" href="<?= htmlspecialchars($qs($i)) ?>"><?= $i ?></a>
              </li>
            <?php endfor; ?>
            <?php if($bis_ < $page_count): ?>
              <?php if($bis_ < $page_count - 1): ?>
                <li class="page-item disabled"><span class="page-link">&hellip;</span></li>
              <?php endif; ?>
              <li class="page-item">
                <a class="page-link" href="<?= htmlspecialchars($qs($page_count)) ?>"><?= $page_count ?></a>
              </li>
            <?php endif; ?>
            <li class="page-item <?= $page >= $page_count ? 'disabled' : '' ?>">
              <a class="page-link" href="<?= htmlspecialchars($qs(min($page_count, $page + 1))) ?>" aria-label="<?= te('Weiter') ?>">
                <i class="bi bi-chevron-right"></i>
              </a>
            </li>
          </ul>
        </nav>
        <?php endif; ?>
        <div class="text-muted mt-3" style="font-size: 12px;"><i class="bi bi-info-circle"></i> <?= $log_filter || $log_search ? count($logs) . te(' gefilterte Einträge (max. ') . ($_log_limit*2>2000?2000:$_log_limit*2) . ').' : te('Anzeige der letzten ') . $_log_limit . te(' Einträge.') ?></div>
      <?php else: ?>
        <div class="text-center py-5"><i class="bi bi-journal-x text-muted" style="font-size: 3rem;"></i><h4 class="mt-3 text-muted"><?= te('Logbuch ist leer') ?></h4></div>
      <?php endif; ?>
    </div>

  <div class="modal fade" id="clearLogsModal" tabindex="-1">
      <div class="modal-dialog modal-sm modal-dialog-centered">
          <div class="modal-content border-0 shadow">
              <form action="systemlogs" method="POST">
                  <input type="hidden" name="action" value="clear_logs">
                  
                  <div class="modal-header bg-danger text-white">
                      <h6 class="modal-title m-0"><i class="bi bi-exclamation-triangle-fill me-2"></i><?= te('Logbuch leeren?') ?></h6>
                      <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                  </div>
                  <div class="modal-body text-center py-4">
                      <p class="mb-0 fw-bold"><?= te('Möchtest du alle bisherigen Einträge wirklich unwiderruflich löschen?') ?></p>
                  </div>
                  <div class="modal-footer p-2 d-flex justify-content-between bg-subtle">
                      <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal"><?= te('Abbrechen') ?></button>
                      <button type="submit" class="btn btn-danger btn-sm px-3 fw-bold"><?= te('Ja, leeren') ?></button>
                  </div>
              </form>
          </div>
      </div>
  </div>
  

  <script>
    const clrLogsModal = new bootstrap.Modal(document.getElementById('clearLogsModal'));

    function triggerClearLogs() {
        clrLogsModal.show();
    }

  </script>
<?php endif; // Ende der Ansichtsumschaltung ?>

<?php require 'includes/layout_end.php'; ?>