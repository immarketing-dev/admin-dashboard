<?php
/**
 * Gemeinsamer <head>. Erwartet $page_title, optional $extra_head.
 * $extra_head ist beliebiges Head-Markup - Stylesheets, Scripts und
 * Links -, das am Ende von <head> unverändert ausgegeben wird.
 * Alle CDN-Referenzen tragen eine feste Version.
 */
$page_title = $page_title ?? 'Dashboard';
?>
<!DOCTYPE html>
<html lang="<?= lang() ?>">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="csrf-token" content="<?= htmlspecialchars(csrf_token(), ENT_QUOTES) ?>">
  <title><?= htmlspecialchars($page_title) ?> | <?= htmlspecialchars(setting('company_short', COMPANY_SHORT)) ?></title>

  <link href="<?= asset('assets/vendor/bootstrap/bootstrap.min.css') ?>" rel="stylesheet">
  <link href="<?= asset('assets/vendor/bootstrap-icons/bootstrap-icons.css') ?>" rel="stylesheet">
  <link href="<?= asset('assets/vendor/fonts/fonts.css') ?>" rel="stylesheet">

  <link rel="stylesheet" href="<?= asset('assets/css/tokens.css') ?>">
  <link rel="stylesheet" href="<?= asset('assets/css/app.css') ?>">
  <?php if (demo_mode()): ?>
  <link rel="stylesheet" href="<?= asset('assets/css/demo.css') ?>">
  <?php endif; ?>

  <?php require_once __DIR__ . '/theme.php'; ?>
  <?= $extra_head ?? '' ?>

  <!-- Bootstrap-Bundle bewusst im <head> und ohne defer: die Seiten legen
       ihre Modals beim Parsen an (new bootstrap.Modal(...)). Laedt das
       Bundle erst am Seitenende, wirft jedes dieser Skripte
       "bootstrap is not defined", der ganze Block bricht ab und die
       darin definierten Funktionen existieren nie - Schaltflaechen
       reagieren dann einfach nicht mehr. -->
  <script src="<?= asset('assets/vendor/bootstrap/bootstrap.bundle.min.js') ?>"></script>
</head>
