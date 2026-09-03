<?php
/**
 * Öffnet den Seitenrumpf: Sidebar, Overlay, Top-Header.
 * Erwartet $current_page und $page_heading, optional $header_actions
 * und $header_class (zusätzliche CSS-Klasse(n) am .top-header-Div,
 * z.B. "flex-wrap" bei vielen Header-Buttons).
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
    <?= $header_actions ?? '' ?>
  </div>
