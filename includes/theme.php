<?php
$_tp = setting('color_primary', COLOR_PRIMARY);
$_ts = setting('color_sidebar', COLOR_SIDEBAR);
$_fav = setting('favicon', '');
?>
<script>if(localStorage.getItem('darkMode')==='1')document.documentElement.setAttribute('data-theme','dark');</script>
<style>:root{--color-primary:<?= htmlspecialchars($_tp) ?>;--color-sidebar:<?= htmlspecialchars($_ts) ?>}</style>
<?php if ($_fav && file_exists(__DIR__ . '/../' . $_fav)): ?>
<link rel="icon" type="image/<?= pathinfo($_fav, PATHINFO_EXTENSION) === 'ico' ? 'x-icon' : 'png' ?>" href="<?= htmlspecialchars($_fav) ?>?v=<?= filemtime(__DIR__ . '/../' . $_fav) ?>">
<?php endif; ?>
