<?php
/**
 * Öffnet den Seitenrumpf: Sidebar, Overlay, Top-Header.
 * Erwartet $current_page und $page_heading, optional $header_actions
 * und $header_class (zusätzliche CSS-Klasse(n) am .top-header-Div).
 *
 * $header_actions wird in <div class="header-actions"> gefasst. Dort
 * regelt app.css Umbruch und Abstände zentral - die Seiten liefern nur
 * noch die Schaltflächen, nicht mehr deren Anordnung. Zum Ausblenden
 * einer Beschriftung auf sehr schmalen Geräten die Beschriftung einer
 * Nebenaktion in <span class="btn-label"> setzen.
 */
$current_page = $current_page ?? basename($_SERVER['PHP_SELF']);
?>
<body>
<div class="sidebar-overlay" id="sidebar-overlay"></div>
<?php require __DIR__ . '/sidebar.php'; ?>

<div class="main-content">
  <div class="top-header<?= !empty($header_class) ? ' ' . htmlspecialchars($header_class, ENT_QUOTES) : '' ?>">
    <h2>
      <i class="bi bi-list mobile-toggle" id="mobile-toggle-btn"></i>
      <?= htmlspecialchars($page_heading ?? '') ?>
    </h2>
    <button type="button" class="gsearch-trigger" data-gsearch-open
            aria-label="Globale Suche öffnen" title="Globale Suche (Strg+K)">
      <i class="bi bi-search" aria-hidden="true"></i>
      <span class="gsearch-trigger-text">Suchen</span>
      <kbd>Strg K</kbd>
    </button>
    <?php if (!empty($header_actions)): ?>
      <div class="header-actions"><?= $header_actions ?></div>
    <?php endif; ?>
  </div>

<?php require __DIR__ . "/search_overlay.php"; ?>
<script src="assets/js/search.js" defer></script>
