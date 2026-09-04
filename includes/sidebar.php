<?php
// $current_page muss vor dem Einbinden gesetzt sein (basename($_SERVER['PHP_SELF']))

// Notification-Counts für Badges
$_sb_leads   = (int)$pdo->query("SELECT COUNT(*) FROM leads_inbox")->fetchColumn();
$_sb_tickets = (int)$pdo->query("SELECT COUNT(*) FROM support_tickets WHERE status != 'Erledigt'")->fetchColumn();
$_sb_portal  = (int)$pdo->query("SELECT (SELECT COUNT(*) FROM client_assets WHERE dashboard_seen=0 AND (uploaded_by IS NULL OR uploaded_by='client')) + (SELECT COUNT(*) FROM task_milestones WHERE approved_at IS NOT NULL AND approval_seen=0) + (SELECT COUNT(*) FROM tasks WHERE deleted_at IS NULL AND client_feedback IS NOT NULL AND client_feedback!='' AND feedback_seen=0)")->fetchColumn();
try { $_sb_portal += (int)$pdo->query("SELECT COUNT(*) FROM milestone_comments WHERE author='client' AND admin_seen=0")->fetchColumn(); } catch (PDOException $e) {}
// Beitraege aus der Projekt-Diskussion (Migration 6). In try/catch, damit
// eine noch nicht migrierte Datenbank die Seitenleiste nicht kippt.
try { $_sb_portal += (int)$pdo->query("SELECT COUNT(*) FROM project_comments WHERE author_contact_id IS NOT NULL AND admin_seen=0")->fetchColumn(); } catch (PDOException $e) {}
$_sb_open_inv = (int)$pdo->query("SELECT COUNT(*) FROM finances WHERE deleted_at IS NULL AND type='INCOME' AND status IN ('Offen','Überfällig')")->fetchColumn();
?>
<script>if(localStorage.getItem('sidebarCollapsed')==='1')document.body.classList.add('sidebar-collapsed');</script>
<button id="sidebarCollapseBtn" aria-label="Sidebar einklappen" title="Sidebar einklappen"><i class="bi bi-chevron-left ci"></i></button>
<div class="sidebar" id="sidebar">
  <div class="brand">
    <i class="bi bi-grid brand-icon"></i>
    <span class="brand-text"><?= htmlspecialchars(setting('company_short', APP_NAME)) ?></span>
  </div>
  <ul class="nav flex-column">
    <li class="nav-group">Übersicht</li>
    <li class="nav-item">
      <a class="nav-link <?= ($current_page == 'index.php') ? 'active' : '' ?>" href="index">
        <i class="bi bi-grid-1x2-fill"></i> <span class="nav-text">Dashboard</span>
        <?php /* $_sb_portal wurde mit fünf Abfragen je Seitenaufruf ermittelt und
                 nirgends benutzt. Das Abzeichen zählt jetzt beides: neue Anfragen
                 über die Website und ungesehene Vorgänge aus dem Kundenportal. */ ?>
        <?php $_sb_dash = $_sb_leads + $_sb_portal; ?>
        <?php if($_sb_dash > 0): ?><span class="sidebar-badge" title="<?= $_sb_leads ?> Anfrage(n), <?= $_sb_portal ?> Portal-Vorgang/-Vorgänge"><?= $_sb_dash ?></span><?php endif; ?>
      </a>
    </li>
    <li class="nav-item"><a class="nav-link <?= ($current_page == 'calendar.php') ? 'active' : '' ?>" href="calendar"><i class="bi bi-calendar3"></i> <span class="nav-text">Kalender</span></a></li>

    <li class="nav-group">Arbeit</li>
    <li class="nav-item"><a class="nav-link <?= ($current_page == 'tasks.php') ? 'active' : '' ?>" href="tasks"><i class="bi bi-check2-square"></i> <span class="nav-text">Projekte &amp; Aufgaben</span></a></li>
    <li class="nav-item"><a class="nav-link <?= ($current_page == 'board.php') ? 'active' : '' ?>" href="board"><i class="bi bi-kanban"></i> <span class="nav-text">Kanban Board</span></a></li>
    <li class="nav-item"><a class="nav-link <?= ($current_page == 'wiki.php') ? 'active' : '' ?>" href="wiki"><i class="bi bi-book-half"></i> <span class="nav-text">Wiki / Wissen</span></a></li>

    <li class="nav-group">Geschäft</li>
    <li class="nav-item"><a class="nav-link <?= ($current_page == 'contacts.php') ? 'active' : '' ?>" href="contacts"><i class="bi bi-people-fill"></i> <span class="nav-text">Kontakte</span></a></li>
    <li class="nav-item"><a class="nav-link <?= ($current_page == 'quotes.php') ? 'active' : '' ?>" href="quotes"><i class="bi bi-file-earmark-text"></i> <span class="nav-text">Angebote</span></a></li>
    <li class="nav-item">
      <a class="nav-link <?= ($current_page == 'finances.php') ? 'active' : '' ?>" href="finances">
        <i class="bi bi-currency-euro"></i> <span class="nav-text">Finanzen</span>
        <?php if($_sb_open_inv > 0): ?><span class="sidebar-badge sidebar-badge-warning"><?= $_sb_open_inv ?></span><?php endif; ?>
      </a>
    </li>
    <li class="nav-item">
      <a class="nav-link <?= ($current_page == 'tickets.php') ? 'active' : '' ?>" href="tickets">
        <i class="bi bi-life-preserver"></i> <span class="nav-text">Support-Tickets</span>
        <?php if($_sb_tickets > 0): ?><span class="sidebar-badge sidebar-badge-danger"><?= $_sb_tickets ?></span><?php endif; ?>
      </a>
    </li>

    <li class="nav-group">System</li>
    <li class="nav-item"><a class="nav-link <?= ($current_page == 'systemlogs.php') ? 'active' : '' ?>" href="systemlogs"><i class="bi bi-journal-text"></i> <span class="nav-text">System-Logs</span></a></li>
    <li class="nav-item"><a class="nav-link <?= ($current_page == 'trash.php') ? 'active' : '' ?>" href="trash"><i class="bi bi-trash3"></i> <span class="nav-text">Papierkorb</span></a></li>
    <li class="nav-item"><a class="nav-link <?= ($current_page == 'settings.php') ? 'active' : '' ?>" href="settings"><i class="bi bi-gear"></i> <span class="nav-text">Einstellungen</span></a></li>
    <li class="nav-item mt-4"><a class="nav-link text-danger" href="logout"><i class="bi bi-box-arrow-right"></i> <span class="nav-text">Logout</span></a></li>
</ul>
</div>
