<?php
// demo_einstellung() gibt ausserhalb des Demo-Modus einfach den
// uebergebenen Wert zurueck - im Echtbetrieb aendert sich hier nichts.
$_tp = demo_einstellung('color_primary', setting('color_primary', COLOR_PRIMARY));
$_ts = demo_einstellung('color_sidebar', setting('color_sidebar', COLOR_SIDEBAR));
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
    /* In der Demo soll jeder Besuch mit der Vorgabe beginnen, und die Wahl
       des einen Besuchers darf den naechsten nicht erreichen. sessionStorage
       verhaelt sich wie localStorage, wird aber mit der Sitzung geleert.

       In try/catch mit Ersatzobjekt: in einem privaten Fenster wirft schon
       der Zugriff auf window.localStorage. Ohne diese Absicherung braeche
       das Skript ab - und mit ihm die Seitenleiste, die denselben Speicher
       benutzt. */
    try {
        window.ansichtSpeicher = window.<?= demo_mode() ? 'sessionStorage' : 'localStorage' ?>;
        window.ansichtSpeicher.getItem('darkMode');
    } catch (e) {
        window.ansichtSpeicher = null;
    }
    if (!window.ansichtSpeicher) {
        window.ansichtSpeicher = { getItem: function () { return null; }, setItem: function () {} };
    }
    var stored = window.ansichtSpeicher.getItem('darkMode');
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
