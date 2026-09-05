<?php
/**
 * Die Seite, die eine Rolle sieht, wenn sie irgendwo hingerät, wohin
 * sie nicht darf.
 *
 * Eine eigene Seite und keine Weiterleitung aufs Dashboard: eine
 * Weiterleitung sieht aus wie ein Fehler, und man probiert es noch
 * dreimal. Hier steht stattdessen, was los ist — und ein Weg zurück.
 *
 * Bewusst ohne Angabe, was auf der Seite zu sehen wäre. Dass es sie
 * gibt, weiß der Benutzer ohnehin; was darauf steht, geht ihn nichts an.
 */
$page_title   = 'Kein Zugriff';
$page_heading = 'Kein Zugriff';
$current_page = 'index.php';

require __DIR__ . '/head.php';
require __DIR__ . '/layout_start.php';
?>

<div class="widget-box widget-accent-left" style="max-width: 40rem;">
  <div class="d-flex align-items-start gap-3">
    <div class="icon-tile icon-tile-warning"><i class="bi bi-shield-lock"></i></div>
    <div>
      <div class="fw-bold fs-5 text-strong-c mb-1"><?= te('Dieser Bereich ist für Ihre Rolle gesperrt.') ?></div>
      <p class="text-muted mb-3">
        <?php // datenwert(): der Rollenname kommt aus rollen(). ?>
        <?= te('Ihre Rolle: %s. Wenn Sie hier arbeiten müssen, kann die Verwaltung sie ändern.',
               datenwert(rollen()[aktuelle_rolle()]['label'] ?? '—')) ?>
      </p>
      <a href="index" class="btn btn-primary btn-sm"><i class="bi bi-arrow-left me-1"></i><?= te('Zur Übersicht') ?></a>
    </div>
  </div>
</div>

<?php require __DIR__ . '/layout_end.php'; ?>
