<?php
// 1. Zentrale Config laden
require_once 'config.php';
require_once __DIR__ . '/includes/logging.php';

require_once 'includes/auth.php';

// ==========================================
// AJAX: STATUS UPDATE DURCH DRAG & DROP
// ==========================================
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['action']) && $_POST['action'] === 'update_status') {
    csrf_check();
    $task_id    = (int)$_POST['task_id'];
    $new_status = $_POST['new_status'];

    if (in_array($new_status, ['Offen', 'In Bearbeitung', 'Erledigt'])) {
        
        // Titel für ein schöneres Logbuch holen
        $stmt_title = $pdo->prepare("SELECT title FROM tasks WHERE deleted_at IS NULL AND id = ?");
        $stmt_title->execute([$task_id]);
        $task_title = $stmt_title->fetchColumn() ?: "Aufgabe #$task_id";

        $stmt = $pdo->prepare("UPDATE tasks SET status = ? WHERE id = ?");
        $stmt->execute([$new_status, $task_id]);
        
        // LOG: Status im Board geändert
        log_event($pdo, 'TASK_STATUS', "Kanban: '$task_title' auf '$new_status' verschoben.");
        
        echo "OK";
    } else {
        http_response_code(400);
        echo "Ungültiger Status.";
    }
    exit(); 
}

// ==========================================
// AUFGABEN LADEN (Inklusive Meilensteine)
// ==========================================
$stmt = $pdo->query("
    SELECT t.*, c.name AS client_name
    FROM tasks t
    LEFT JOIN contacts c ON t.contact_id = c.id
    WHERE t.deleted_at IS NULL AND t.status != 'Storniert'
    ORDER BY t.created_at DESC
");
$all_tasks = $stmt->fetchAll(PDO::FETCH_ASSOC);

$count_storniert = (int)$pdo->query("SELECT COUNT(*) FROM tasks WHERE deleted_at IS NULL AND status = 'Storniert'")->fetchColumn();

$tasks_todo = [];
$tasks_doing = [];
$tasks_done = [];

// Alle Meilensteine in einer einzigen Abfrage laden (verhindert N+1)
$all_task_ids = array_column($all_tasks, 'id');
$milestones_by_task = [];
if (!empty($all_task_ids)) {
    $placeholders = implode(',', array_fill(0, count($all_task_ids), '?'));
    $stmt_ms = $pdo->prepare("SELECT * FROM task_milestones WHERE task_id IN ($placeholders) ORDER BY created_at ASC");
    $stmt_ms->execute($all_task_ids);
    foreach ($stmt_ms->fetchAll(PDO::FETCH_ASSOC) as $ms) {
        $milestones_by_task[$ms['task_id']][] = $ms;
    }
}

// Meilensteine und Fortschritt für jede Aufgabe vorberechnen
foreach ($all_tasks as &$task) {
    $task['milestones'] = $milestones_by_task[$task['id']] ?? [];
    
    $done = 0;
    foreach($task['milestones'] as $m) { if($m['is_completed']) $done++; }
    $task['milestones_total'] = count($task['milestones']);
    $task['milestones_done'] = $done;
    $task['progress'] = $task['milestones_total'] > 0 ? round(($done / $task['milestones_total']) * 100) : 0;
    
    if ($task['status'] === 'Offen') {
        $tasks_todo[] = $task;
    } elseif ($task['status'] === 'In Bearbeitung') {
        $tasks_doing[] = $task;
    } elseif ($task['status'] === 'Erledigt') {
        $tasks_done[] = $task;
    }
    // Storniert und unbekannte Status werden ignoriert (SQL filtert bereits)
}
unset($task);

function deadline_badge(string $deadline): string {
    if (empty($deadline) || str_contains($deadline, '0000')) return '';
    $days = (int)round((strtotime($deadline) - strtotime('today')) / 86400);
    if ($days < 0)  return '<span class="k-badge k-badge-overdue"><i class="bi bi-alarm-fill me-1"></i>Überfällig</span>';
    if ($days === 0) return '<span class="k-badge k-badge-today"><i class="bi bi-alarm me-1"></i>Heute</span>';
    if ($days <= 3)  return '<span class="k-badge k-badge-soon"><i class="bi bi-clock me-1"></i>in ' . $days . ' T.</span>';
    return '<span class="k-badge" style="color:var(--text-muted);border-color:var(--border-base);"><i class="bi bi-calendar3 me-1"></i>' . date('d.m.', strtotime($deadline)) . '</span>';
}

$page_title   = 'Kanban Board';
$page_heading = 'Projekt Board';
$current_page = basename($_SERVER['SCRIPT_NAME']);
$header_actions = '
      <div class="d-flex gap-2 align-items-center flex-wrap" style="max-width: 560px;">
          <div class="input-group input-group-sm search-box" style="flex-grow: 1;">
              <span class="input-group-text bg-surface border-end-0 text-muted"><i class="bi bi-search"></i></span>
              <input type="text" id="boardSearch" class="form-control border-start-0 ps-0" placeholder="Board durchsuchen...">
          </div>
          <a href="tasks" class="btn btn-outline-primary btn-sm fw-bold"><i class="bi bi-card-list"></i> <span class="btn-label">Listenansicht</span></a>
      </div>';
// Sortable.js (Drag & Drop) wird nur hier gebraucht, daher hier statt in head.php.
$extra_head = '<script src="' . asset('assets/vendor/sortable/Sortable.min.js') . '"></script>';

require 'includes/head.php';
require 'includes/layout_start.php';
?>

    <div class="board-container">
      
      <div class="board-column">
        <div class="board-header header-todo">
          <span class="text-secondary"><i class="bi bi-circle me-2"></i> <?= te('Offen') ?></span>
          <span class="badge bg-secondary rounded-pill" id="count-todo"><?php echo count($tasks_todo); ?></span>
        </div>
        <div class="task-list" id="list-todo" data-status="Offen">
          <div class="empty-state"><?= te('Keine Aufgaben in dieser Spalte') ?></div>
          <?php foreach($tasks_todo as $task): 
              $safe_json = htmlspecialchars(json_encode($task), ENT_QUOTES, 'UTF-8');
          ?>
            <div class="kanban-card" data-task-id="<?php echo $task['id']; ?>">
              <div class="kanban-meta">
                  <?php if(!empty($task['category'])): ?>
                      <span class="k-badge text-primary border-primary-subtle"><?=htmlspecialchars($task['category'])?></span>
                  <?php endif; ?>
                  <?php if(!empty($task['client_name'])): ?>
                      <span class="k-badge"><i class="bi bi-person-fill"></i> <?=htmlspecialchars($task['client_name'])?></span>
                  <?php endif; ?>
                  <?= deadline_badge($task['deadline'] ?? '') ?>
              </div>
              
              <div class="d-flex align-items-start">
                  <i class="bi bi-grip-vertical drag-handle"></i>
                  <div class="kanban-card-title flex-grow-1" onclick='openTaskModal(<?=$safe_json?>)'>
                      <?php echo htmlspecialchars($task['title']); ?>
                  </div>
              </div>
              
              <div class="kanban-card-desc"><?php echo htmlspecialchars($task['description']); ?></div>
              
              <div class="kanban-card-footer">
                <small class="text-muted" style="font-size: 10px;"><i class="bi bi-calendar-event"></i> <?php echo date('d.m.', strtotime($task['created_at'])); ?></small>
                <?php if($task['milestones_total'] > 0): ?>
                    <span class="badge" style="background: <?=$task['progress']==100?'var(--accent-success)':'var(--color-primary)'?>; font-size: 10px; padding: 4px 6px;">
                        <?=$task['progress']?>% (<?=$task['milestones_done']?>/<?=$task['milestones_total']?>) <i class="bi bi-check2-all"></i>
                    </span>
                <?php endif; ?>
              </div>
              
              <?php if($task['milestones_total'] > 0): ?>
              <div class="progress mt-2" style="height: 4px; border-radius: 2px;">
                  <div class="progress-bar <?=$task['progress']==100?'bg-success':''?>" style="width: <?=$task['progress']?>%; <?=$task['progress']!=100?'background-color: var(--color-primary);':''?>"></div>
              </div>
              <?php endif; ?>
            </div>
          <?php endforeach; ?>
        </div>
      </div>

      <div class="board-column">
        <div class="board-header header-doing">
          <span style="color: var(--color-primary);"><i class="bi bi-play-circle-fill me-2"></i> <?= te('In Bearbeitung') ?></span>
          <span class="badge rounded-pill" style="background-color: var(--color-primary);" id="count-doing"><?php echo count($tasks_doing); ?></span>
        </div>
        <div class="task-list" id="list-doing" data-status="In Bearbeitung">
          <div class="empty-state"><?= te('Keine Aufgaben in dieser Spalte') ?></div>
          <?php foreach($tasks_doing as $task): 
              $safe_json = htmlspecialchars(json_encode($task), ENT_QUOTES, 'UTF-8');
          ?>
            <div class="kanban-card" data-task-id="<?php echo $task['id']; ?>">
              <div class="kanban-meta">
                  <?php if(!empty($task['category'])): ?>
                      <span class="k-badge text-primary border-primary-subtle"><?=htmlspecialchars($task['category'])?></span>
                  <?php endif; ?>
                  <?php if(!empty($task['client_name'])): ?>
                      <span class="k-badge"><i class="bi bi-person-fill"></i> <?=htmlspecialchars($task['client_name'])?></span>
                  <?php endif; ?>
                  <?= deadline_badge($task['deadline'] ?? '') ?>
              </div>
              
              <div class="d-flex align-items-start">
                  <i class="bi bi-grip-vertical drag-handle"></i>
                  <div class="kanban-card-title flex-grow-1" onclick='openTaskModal(<?=$safe_json?>)'>
                      <?php echo htmlspecialchars($task['title']); ?>
                  </div>
              </div>
              
              <div class="kanban-card-desc"><?php echo htmlspecialchars($task['description']); ?></div>
              
              <div class="kanban-card-footer">
                <small class="text-muted" style="font-size: 10px;"><i class="bi bi-calendar-event"></i> <?php echo date('d.m.', strtotime($task['created_at'])); ?></small>
                <?php if($task['milestones_total'] > 0): ?>
                    <span class="badge" style="background: <?=$task['progress']==100?'var(--accent-success)':'var(--color-primary)'?>; font-size: 10px; padding: 4px 6px;">
                        <?=$task['progress']?>% (<?=$task['milestones_done']?>/<?=$task['milestones_total']?>) <i class="bi bi-check2-all"></i>
                    </span>
                <?php endif; ?>
              </div>

              <?php if($task['milestones_total'] > 0): ?>
              <div class="progress mt-2" style="height: 4px; border-radius: 2px;">
                  <div class="progress-bar <?=$task['progress']==100?'bg-success':''?>" style="width: <?=$task['progress']?>%; <?=$task['progress']!=100?'background-color: var(--color-primary);':''?>"></div>
              </div>
              <?php endif; ?>
            </div>
          <?php endforeach; ?>
        </div>
      </div>

      <div class="board-column">
        <div class="board-header header-done">
          <span class="text-success"><i class="bi bi-check-circle-fill me-2"></i> <?= te('Erledigt') ?></span>
          <span class="badge bg-success rounded-pill" id="count-done"><?php echo count($tasks_done); ?></span>
        </div>
        <div class="task-list" id="list-done" data-status="Erledigt">
          <div class="empty-state"><?= te('Keine Aufgaben in dieser Spalte') ?></div>
          <?php foreach($tasks_done as $task): 
              $safe_json = htmlspecialchars(json_encode($task), ENT_QUOTES, 'UTF-8');
          ?>
            <div class="kanban-card" data-task-id="<?php echo $task['id']; ?>" style="opacity: 0.65; background-color: var(--surface-subtle);">
              <div class="kanban-meta">
                  <?php if(!empty($task['category'])): ?>
                      <span class="k-badge text-muted"><?=htmlspecialchars($task['category'])?></span>
                  <?php endif; ?>
                  <?php if(!empty($task['client_name'])): ?>
                      <span class="k-badge"><i class="bi bi-person-fill"></i> <?=htmlspecialchars($task['client_name'])?></span>
                  <?php endif; ?>
                  <?= deadline_badge($task['deadline'] ?? '') ?>
              </div>
              
              <div class="d-flex align-items-start">
                  <i class="bi bi-grip-vertical drag-handle"></i>
                  <div class="kanban-card-title flex-grow-1 text-decoration-line-through text-muted" onclick='openTaskModal(<?=$safe_json?>)'>
                      <?php echo htmlspecialchars($task['title']); ?>
                  </div>
              </div>
              
              <div class="kanban-card-desc"><?php echo htmlspecialchars($task['description']); ?></div>
              
              <div class="kanban-card-footer">
                <small class="text-muted" style="font-size: 10px;"><i class="bi bi-calendar-check"></i> <?php echo date('d.m.', strtotime($task['created_at'])); ?></small>
                <?php if($task['milestones_total'] > 0): ?>
                    <span class="badge" style="background: <?=$task['progress']==100?'var(--accent-success)':'var(--color-primary)'?>; font-size: 10px; padding: 4px 6px;">
                        <?=$task['progress']?>% (<?=$task['milestones_done']?>/<?=$task['milestones_total']?>) <i class="bi bi-check2-all"></i>
                    </span>
                <?php endif; ?>
              </div>

              <?php if($task['milestones_total'] > 0): ?>
              <div class="progress mt-2" style="height: 4px; border-radius: 2px;">
                  <div class="progress-bar <?=$task['progress']==100?'bg-success':''?>" style="width: <?=$task['progress']?>%; <?=$task['progress']!=100?'background-color: var(--color-primary);':''?>"></div>
              </div>
              <?php endif; ?>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
      
    </div>

    <?php if ($count_storniert > 0): ?>
    <div class="text-center pb-3">
        <a href="tasks?filter_status=Storniert" class="text-muted small text-decoration-none">
            <i class="bi bi-slash-circle me-1"></i><?= $count_storniert ?> <?= te('stornierte Aufgabe(n) — in Listenansicht anzeigen') ?>
        </a>
    </div>
    <?php endif; ?>

  <div class="modal fade" id="viewTaskModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
      <div class="modal-content border-0 shadow">
        <div class="modal-header bg-dark text-white">
          <h5 class="modal-title fw-bold" id="vt_title"></h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body p-4 bg-subtle">
          <div class="d-flex gap-2 mb-3" id="vt_badges"></div>
          
          <div class="row mb-4 bg-surface p-3 rounded border shadow-sm mx-0">
              <div class="col-6">
                  <small class="text-muted fw-bold d-block text-uppercase" style="font-size: 10px;"><?= te('Startdatum') ?></small>
                  <span id="vt_start" class="fw-bold text-strong-c">-</span>
              </div>
              <div class="col-6 border-start">
                  <small class="text-muted fw-bold d-block text-uppercase" style="font-size: 10px;"><?= te('Deadline') ?></small>
                  <span id="vt_deadline" class="fw-bold text-strong-c">-</span>
              </div>
          </div>

          <div class="mb-4">
              <small class="text-muted fw-bold d-block mb-1 text-uppercase" style="font-size: 10px;"><?= te('Beschreibung') ?></small>
              <div id="vt_desc" class="p-3 border rounded bg-surface text-dark shadow-sm" style="min-height: 80px; white-space: pre-wrap; font-size: 14px;"></div>
          </div>

          <div class="bg-surface p-3 border rounded shadow-sm">
              <div class="d-flex justify-content-between align-items-center mb-1">
                  <small class="text-muted fw-bold text-uppercase" style="font-size: 10px;"><?= te('Fortschritt') ?> (<span id="vt_progress_text"></span>)</small>
              </div>
              <div class="progress mb-3" style="height: 8px;">
                  <div id="vt_progress_bar" class="progress-bar" style="width: 0%; background-color: var(--color-primary);"></div>
              </div>
              
              <small class="text-muted fw-bold d-block mb-2 text-uppercase" style="font-size: 10px;"><?= te('Meilensteine') ?></small>
              <div id="vt_milestones" class="list-group list-group-flush border rounded"></div>
          </div>

        </div>
        <div class="modal-footer bg-subtle d-flex justify-content-between border-top-0">
            <button type="button" class="btn btn-secondary fw-bold" data-bs-dismiss="modal"><?= te('Schließen') ?></button>
            <a href="#" id="vt_edit_link" class="btn btn-primary fw-bold px-4"><i class="bi bi-pencil-square me-1"></i> <?= te('In Liste bearbeiten') ?></a>
        </div>
      </div>
    </div>
  </div>

  <script>

    // Funktion zum Aktualisieren der Zähler und Empty-States
    function updateCounters() {
        ['todo', 'doing', 'done'].forEach(status => {
            let list = document.getElementById('list-' + status);
            let cards = Array.from(list.querySelectorAll('.kanban-card'));
            let visibleCards = cards.filter(c => c.style.display !== 'none');
            
            document.getElementById('count-' + status).innerText = visibleCards.length;
            
            let emptyMsg = list.querySelector('.empty-state');
            if(emptyMsg) emptyMsg.style.display = visibleCards.length === 0 ? 'block' : 'none';
        });
    }

    // SortableJS Initialisierung
    const sortableOptions = {
        group: 'kanban', 
        animation: 200,  
        ghostClass: 'sortable-ghost',
        dragClass: 'sortable-drag',
        easing: 'cubic-bezier(1, 0, 0, 1)',
        filter: '.empty-state', // Platzhalter darf nicht gezogen werden
        onEnd: function (evt) {
            const itemEl = evt.item;  
            const toList = evt.to;    
            const taskId = itemEl.getAttribute('data-task-id');
            const newStatus = toList.getAttribute('data-status');
            const titleLink = itemEl.querySelector('.kanban-card-title');
            
            // Optisches Feedback anpassen
            if(newStatus === 'Erledigt') {
                itemEl.style.opacity = '0.65';
                itemEl.style.backgroundColor = 'var(--surface-subtle)';
                if(titleLink) {
                    titleLink.classList.add('text-decoration-line-through', 'text-muted');
                    titleLink.classList.remove('text-dark');
                }
            } else {
                itemEl.style.opacity = '1';
                itemEl.style.backgroundColor = '';
                if(titleLink) {
                    titleLink.classList.remove('text-decoration-line-through', 'text-muted');
                    titleLink.classList.add('text-dark');
                }
            }

            updateCounters(); // Zähler und Platzhalter sofort updaten

            // AJAX Request an den Server (mit CSRF-Token)
            const csrf = document.querySelector('meta[name="csrf-token"]').content;
            const xhr  = new XMLHttpRequest();
            xhr.open("POST", "board", true);
            xhr.setRequestHeader("Content-Type", "application/x-www-form-urlencoded");
            // Kennzeichnet die Anfrage als AJAX: der Demo-Riegel antwortet
            // darauf mit JSON statt mit einer Weiterleitung.
            xhr.setRequestHeader("X-Requested-With", "XMLHttpRequest");
            xhr.onload = function () {
                if (xhr.status !== 403) return;
                // Die Karte wurde nur im Browser verschoben. Kurz melden,
                // dann den tatsächlichen Stand wiederherstellen.
                if (window.demoHinweis) demoHinweis();
                setTimeout(function () { location.reload(); }, 1600);
            };
            xhr.send("action=update_status&task_id=" + taskId + "&new_status=" + encodeURIComponent(newStatus) + "&csrf_token=" + encodeURIComponent(csrf));
        },
    };

    new Sortable(document.getElementById('list-todo'), sortableOptions);
    new Sortable(document.getElementById('list-doing'), sortableOptions);
    new Sortable(document.getElementById('list-done'), sortableOptions);

    // Live-Suchfunktion (Client-Side Filter)
    document.getElementById('boardSearch').addEventListener('keyup', function() {
        let filter = this.value.toLowerCase();
        let cards = document.querySelectorAll('.kanban-card');
        cards.forEach(card => {
            let text = card.innerText.toLowerCase();
            card.style.display = text.includes(filter) ? '' : 'none';
        });
        updateCounters();
    });

    // Initiale Empty-States prüfen
    updateCounters();

    // LOGIK FÜR DAS DETAIL-MODAL
    const viewModal = new bootstrap.Modal(document.getElementById('viewTaskModal'));
    
    function openTaskModal(task) {
        document.getElementById('vt_title').innerText = task.title;
        
        let badges = '';
        const _sc = {'Offen':'status-offen',<?= tjs('In Bearbeitung') ?>:'status-in-bearbeitung','Erledigt':'status-erledigt','Storniert':'status-storniert'};
        badges += `<span class="status-badge ${_sc[task.status] || 'status-offen'}">${task.status}</span>`;
        if(task.category) badges += `<span class="badge border border-primary text-primary">${task.category}</span>`;
        if(task.client_name) badges += `<span class="badge bg-dark"><i class="bi bi-person"></i> ${task.client_name}</span>`;
        document.getElementById('vt_badges').innerHTML = badges;

        document.getElementById('vt_start').innerText = task.start_date && !task.start_date.includes('0000') ? formatDate(task.start_date) : <?= tjs('Nicht gesetzt') ?>;
        document.getElementById('vt_deadline').innerText = task.deadline && !task.deadline.includes('0000') ? formatDate(task.deadline) : <?= tjs('Nicht gesetzt') ?>;
        
        document.getElementById('vt_desc').innerText = task.description || <?= tjs('Keine Beschreibung vorhanden.') ?>;
        
        // Fortschrittsbalken im Modal
        document.getElementById('vt_progress_text').innerText = task.progress + '%';
        let pBar = document.getElementById('vt_progress_bar');
        pBar.style.width = task.progress + '%';
        
        if(task.progress === 100) {
            pBar.style.backgroundColor = 'var(--accent-success)';
        } else {
            pBar.style.backgroundColor = 'var(--color-primary)';
        }

        // Meilensteine als Liste aufbauen
        let msHtml = '';
        if(task.milestones && task.milestones.length > 0) {
            task.milestones.forEach(m => {
                let icon = m.is_completed == 1 ? '<i class="bi bi-check-circle-fill text-success"></i>' : '<i class="bi bi-circle text-muted"></i>';
                let strike = m.is_completed == 1 ? 'text-decoration-line-through text-muted' : 'text-dark fw-bold';
                msHtml += `<div class="list-group-item d-flex align-items-center gap-3 py-2 bg-surface"><span style="font-size:1.2rem;">${icon}</span> <span class="${strike}">${m.title}</span></div>`;
            });
        } else {
            msHtml = '<div class="list-group-item text-muted small py-3 text-center bg-surface"><?= te('Noch keine Meilensteine angelegt.') ?></div>';
        }
        document.getElementById('vt_milestones').innerHTML = msHtml;

        // Button leitet direkt zur Listenansicht um und filtert nach dem Titel
        document.getElementById('vt_edit_link').href = 'tasks?q=' + encodeURIComponent(task.title);

        viewModal.show();
    }
    
    // Hilfsfunktion für deutsches Datum Format (DD.MM.YYYY)
    function formatDate(dateStr) {
        let d = new Date(dateStr);
        return d.toLocaleDateString('de-DE', { day: '2-digit', month: '2-digit', year: 'numeric' });
    }
  </script>
<?php require 'includes/layout_end.php'; ?>