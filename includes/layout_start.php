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
 *
 * Die Lupe der globalen Suche sitzt am Ende derselben Gruppe. Sie stand
 * frueher als eigenes Kind zwischen Titel und Aktionen - und bekam beim
 * Umbruch eine eigene Zeile, weil Titel und Aktionen auf schmalen
 * Geräten je die volle Breite beanspruchen. Der Kopf war dadurch
 * dreizeilig. Deshalb wird .header-actions jetzt immer ausgegeben, auch
 * wenn eine Seite gar keine eigenen Schaltflächen mitbringt.
 */
$current_page = $current_page ?? basename($_SERVER['PHP_SELF']);
?>
<body>
<div class="sidebar-overlay" id="sidebar-overlay"></div>
<?php require __DIR__ . '/sidebar.php'; ?>

<div class="main-content">
<?php if (demo_mode()): ?>
  <div class="demo-strip" role="status">
    <i class="bi bi-eye" aria-hidden="true"></i>
    <span><strong><?= te('Demo-Version') ?></strong> &ndash; <?= te('alle Daten sind erfunden, Änderungen werden nicht gespeichert.') ?></span>
  </div>
<?php endif; ?>
  <div class="top-header<?= !empty($header_class) ? ' ' . htmlspecialchars($header_class, ENT_QUOTES) : '' ?>">
    <h2>
      <i class="bi bi-list mobile-toggle" id="mobile-toggle-btn"></i>
      <?= htmlspecialchars($page_heading ?? '') ?>
    </h2>
    <div class="header-actions">
      <?= $header_actions ?? '' ?>
      <button type="button" class="gsearch-trigger" data-gsearch-open
              aria-label="<?= te('Globale Suche öffnen') ?>" title="<?= te('Globale Suche (Strg+K)') ?>">
        <i class="bi bi-search" aria-hidden="true"></i>
        <span class="gsearch-trigger-text"><?= te('Suchen') ?></span>
        <kbd><?= te('Strg K') ?></kbd>
      </button>
    </div>
  </div>

<?php if (demo_mode() && ($_GET['demo'] ?? '') === 'blocked'): ?>
  <div class="demo-hinweis" role="alert">
    <i class="bi bi-info-circle" aria-hidden="true"></i>
    <span><?= te('Dies ist eine Demo-Version. Änderungen werden nicht gespeichert.') ?>
      <?= te('Zum Ansehen und Ausprobieren bleibt aber alles offen – Filter, Suche und jede Ansicht funktionieren.') ?></span>
  </div>
<?php endif; ?>

<?php require __DIR__ . "/search_overlay.php"; ?>
<script src="<?= asset('assets/js/search.js') ?>" defer></script>
