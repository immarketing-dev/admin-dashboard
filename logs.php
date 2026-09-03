<?php
// 1. Zentrale Config laden
require_once 'config.php';

require_once 'includes/auth.php';

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
    $pdo->prepare("INSERT INTO logs (action_type, description) VALUES ('SYSTEM_RESET', ?)")
        ->execute(["Das System-Logbuch wurde manuell geleert."]);
        
    header("Location: logs");
    exit();
}

// Filter
$log_filter = trim($_GET['filter'] ?? '');
$log_search = trim($_GET['q'] ?? '');

$_log_limit = max(1, min(2000, (int)setting('log_limit', '200')));
if ($log_filter || $log_search) {
    $where = [];
    $params = [];
    if ($log_filter) { $where[] = "action_type LIKE ?"; $params[] = $log_filter . '%'; }
    // Die IP wird mitdurchsucht - sie steht seit Schemaversion 2 in einer
    // eigenen Spalte und nicht mehr in der Beschreibung.
    if ($log_search) { $where[] = "(action_type LIKE ? OR description LIKE ? OR ip LIKE ?)"; $params[] = "%$log_search%"; $params[] = "%$log_search%"; $params[] = "%$log_search%"; }
    $sql = "SELECT * FROM logs WHERE " . implode(' AND ', $where) . " ORDER BY created_at DESC LIMIT " . $_log_limit;
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
} else {
    $stmt = $pdo->query("SELECT * FROM logs ORDER BY created_at DESC LIMIT " . $_log_limit);
}
$logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

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
    if (strpos($action, 'CONTACT_') !== false) return 'bg-purple';

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
$page_title   = 'System-Logs';
$page_heading = 'System-Logs';
$current_page = basename($_SERVER['PHP_SELF']);
$header_actions = '
      <div class="header-actions d-flex gap-2">
        <form action="logs" method="POST" style="margin: 0;">
          ' . csrf_field() . '
          <input type="hidden" name="action" value="export_logs">
          <button type="submit" class="btn btn-success"><i class="bi bi-download"></i> <span class="d-none d-md-inline">Als .txt exportieren</span></button>
        </form>

        <button type="button" class="btn btn-outline-danger" onclick="triggerClearLogs()">
            <i class="bi bi-trash3" style="pointer-events:none;"></i> <span class="d-none d-md-inline">Logs leeren</span>
        </button>
      </div>';
$extra_head = <<<'CSS'
  <style>
      /* Spezifische Logbuch-Klassen (die nicht in der globalen design.css sind) */
      .log-container { background: #fff; border-radius: 10px; padding: 25px; box-shadow: 0 4px 15px rgba(0,0,0,0.03); border-top: 4px solid var(--color-primary); overflow-x: auto;}
      .log-table th { font-family: 'Poppins', sans-serif; color: #173b6c; font-weight: 600; font-size: 14px; border-bottom: 2px solid #e9ecef; padding-bottom: 15px;}
      .log-table td { padding: 15px 10px; vertical-align: middle; border-bottom: 1px solid #f8f9fa; font-size: 14px; color: #495057;}
      .log-badge { padding: 5px 10px; font-weight: 600; font-size: 11px; letter-spacing: 0.5px; border-radius: 4px; color: white; }
      .log-date { font-weight: 600; color: #050d18; }
      .log-time { color: #a8a9b4; font-size: 12px; margin-left: 5px; }
      .log-ip { font-family: monospace; font-size: 13px; color: #6c757d; white-space: nowrap; }
      .bg-purple { background-color: #6f42c1; color: white; }
  </style>
CSS;

require 'includes/head.php';
require 'includes/layout_start.php';
?>

    <!-- Filter & Suche -->
    <form method="GET" class="bg-white rounded-3 shadow-sm border p-3 mb-3 d-flex gap-2 flex-wrap align-items-center">
      <select name="filter" class="form-select form-select-sm" style="max-width:180px;" onchange="this.form.submit()">
        <option value="">Alle Typen</option>
        <?php foreach($log_prefixes as $pre): ?>
          <option value="<?= htmlspecialchars($pre) ?>" <?= $log_filter === $pre ? 'selected' : '' ?>><?= htmlspecialchars($pre) ?>*</option>
        <?php endforeach; ?>
      </select>
      <div class="input-group input-group-sm" style="max-width:260px;">
        <span class="input-group-text"><i class="bi bi-search"></i></span>
        <input type="text" name="q" class="form-control" placeholder="In Beschreibung/IP suchen…" value="<?= htmlspecialchars($log_search) ?>">
      </div>
      <button type="submit" class="btn btn-sm btn-primary fw-bold">Filtern</button>
      <?php if($log_filter || $log_search): ?>
        <a href="logs" class="btn btn-sm btn-outline-secondary">Zurücksetzen</a>
      <?php endif; ?>
      <span class="text-muted small ms-auto"><?= count($logs) ?> Einträge</span>
    </form>

    <div class="log-container">
      <?php if(count($logs) > 0): ?>
        <table class="table table-borderless log-table w-100 mb-0">
          <thead>
            <tr>
              <th width="18%">Zeitpunkt</th>
              <th width="18%">Aktion</th>
              <th width="14%">IP-Adresse</th>
              <th width="50%">Details</th>
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
        <div class="text-muted mt-3" style="font-size: 12px;"><i class="bi bi-info-circle"></i> <?= $log_filter || $log_search ? count($logs) . ' gefilterte Einträge (max. ' . ($_log_limit*2>2000?2000:$_log_limit*2) . ').' : 'Anzeige der letzten ' . $_log_limit . ' Einträge.' ?></div>
      <?php else: ?>
        <div class="text-center py-5"><i class="bi bi-journal-x text-muted" style="font-size: 3rem;"></i><h4 class="mt-3 text-muted">Logbuch ist leer</h4></div>
      <?php endif; ?>
    </div>

  <div class="modal fade" id="clearLogsModal" tabindex="-1">
      <div class="modal-dialog modal-sm modal-dialog-centered">
          <div class="modal-content border-0 shadow">
              <form action="logs" method="POST">
                  <input type="hidden" name="action" value="clear_logs">
                  
                  <div class="modal-header bg-danger text-white">
                      <h6 class="modal-title m-0"><i class="bi bi-exclamation-triangle-fill me-2"></i>Logbuch leeren?</h6>
                      <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                  </div>
                  <div class="modal-body text-center py-4">
                      <p class="mb-0 fw-bold">Möchtest du alle bisherigen Einträge wirklich unwiderruflich löschen?</p>
                  </div>
                  <div class="modal-footer p-2 d-flex justify-content-between bg-light">
                      <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Abbrechen</button>
                      <button type="submit" class="btn btn-danger btn-sm px-3 fw-bold">Ja, leeren</button>
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
<?php require 'includes/layout_end.php'; ?>