<?php
$_tp = setting('color_primary', COLOR_PRIMARY);
$_ts = setting('color_sidebar', COLOR_SIDEBAR);
$_fav = setting('favicon', '');
?>
<script>
/* Dunkles Thema setzen, bevor der Rumpf gerendert wird - sonst blitzt
   kurz die helle Fassung auf.

   Eine gespeicherte Wahl gilt immer. Fehlt sie, folgt nur das Kundenportal
   der Systemeinstellung des Geraets ($theme_follow_system): Kunden kommen
   ohne Vorwahl auf die Seite und stellen dort nichts ein. Das Admin-Panel
   startet weiterhin hell, bis jemand umschaltet. */
(function () {
    var stored = localStorage.getItem('darkMode');
    var follow = <?= !empty($theme_follow_system) ? 'true' : 'false' ?>;
    var dark   = stored === '1'
              || (stored === null && follow
                  && window.matchMedia
                  && window.matchMedia('(prefers-color-scheme: dark)').matches);
    if (dark) document.documentElement.setAttribute('data-theme', 'dark');
})();
</script>
<style>:root{--color-primary:<?= htmlspecialchars($_tp) ?>;--color-sidebar:<?= htmlspecialchars($_ts) ?>}</style>
<?php if ($_fav && file_exists(__DIR__ . '/../' . $_fav)): ?>
<link rel="icon" type="image/<?= pathinfo($_fav, PATHINFO_EXTENSION) === 'ico' ? 'x-icon' : 'png' ?>" href="<?= htmlspecialchars($_fav) ?>?v=<?= filemtime(__DIR__ . '/../' . $_fav) ?>">
<?php endif; ?>
