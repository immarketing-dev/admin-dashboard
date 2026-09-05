<?php
/**
 * Papierkorb.
 *
 * Zeigt, was seit Migration 4 gelöscht wurde, und stellt es wieder her
 * oder entfernt es endgültig.
 *
 * Nach 30 Tagen wird geräumt. Zuständig dafür ist inzwischen der
 * nächtliche Lauf (cron_papierkorb) — die Frist gilt damit auch in
 * einer Installation, in der niemand diese Seite aufschlägt. Vorher
 * räumte allein sie auf, beim Öffnen, und wer nie hereinsah, behielt
 * das Gelöschte für immer.
 *
 * Die Seite räumt weiterhin selbst mit auf. Ohne eingerichteten Cron
 * wäre sonst gar niemand mehr dafür zuständig.
 */
require_once 'config.php';
require_once __DIR__ . '/includes/logging.php';
require_once 'includes/auth.php';
require_once 'includes/csrf.php';
// Wegen datei_pfad_erlaubt(): der Papierkorb raeumt jetzt auch die
// Dateien weg, und zwar nach derselben Schranke wie file.php.
require_once 'includes/file_access.php';

require_once __DIR__ . '/includes/trash_retention.php';

// ── Automatisches Aufräumen ─────────────────────────────────────────
// Laeuft weiterhin beim Oeffnen der Seite, damit eine Installation
// ohne eingerichteten Cron nicht ohne Aufraeumen dasteht. Der
// naechtliche Lauf erledigt dasselbe (cron_papierkorb), sodass die
// dreissig Tage auch dann gelten, wenn niemand hier hereinschaut.
//
// In der Demo darf das nicht: der dortige Datenbankbenutzer hat nur
// SELECT, das DELETE wuerde die Seite mit einer Ausnahme abbrechen
// lassen. Zu loeschen gaebe es dort ohnehin nichts.
// tools/check_demo.php fuehrt diese Stelle als dokumentierte Ausnahme.
[$geraeumt, $dateien_weg] = demo_mode() ? [0, 0] : papierkorb_verfallen($pdo);
if ($geraeumt > 0) {
    log_event($pdo, 'TRASH_PURGED', "$geraeumt Eintrag/Einträge nach " . AUFBEWAHRUNG_TAGE
        . ' Tagen endgültig entfernt' . ($dateien_weg > 0 ? ", dazu $dateien_weg Datei(en)." : '.'));
}

// ── Aktionen ────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $tabelle = $_POST['bereich'] ?? '';
    $id      = (int) ($_POST['id'] ?? 0);

    if (isset(PAPIERKORB[$tabelle]) && $id > 0) {
        if (($_POST['action'] ?? '') === 'restore') {
            $pdo->prepare("UPDATE $tabelle SET deleted_at = NULL WHERE id = ? AND deleted_at IS NOT NULL")
                ->execute([$id]);
            log_event($pdo, 'TRASH_RESTORED', PAPIERKORB[$tabelle]['label'] . ": Eintrag $id wiederhergestellt.");
            header("Location: trash?msg=restored#$tabelle"); exit();
        }
        if (($_POST['action'] ?? '') === 'purge') {
            // Nur was bereits im Papierkorb liegt - ein fehlgeleiteter
            // Aufruf darf keinen aktiven Datensatz treffen.
            $weg = papierkorb_dateien_entfernen(
                $pdo, $tabelle, 'id = ? AND deleted_at IS NOT NULL', [$id]
            );
            $pdo->prepare("DELETE FROM $tabelle WHERE id = ? AND deleted_at IS NOT NULL")->execute([$id]);
            log_event($pdo, 'TRASH_PURGED', PAPIERKORB[$tabelle]['label'] . ": Eintrag $id endgültig gelöscht"
                . ($weg > 0 ? ", dazu $weg Datei(en)." : '.'));
            header("Location: trash?msg=purged#$tabelle"); exit();
        }
    }
    header('Location: trash'); exit();
}

// ── Daten ───────────────────────────────────────────────────────────
$bereiche = [];
$gesamt   = 0;
foreach (PAPIERKORB as $tabelle => $def) {
    $rows = $pdo->query(
        "SELECT id, {$def['titel']} AS titel, {$def['zusatz']} AS zusatz, deleted_at,
                DATEDIFF(DATE_ADD(deleted_at, INTERVAL " . AUFBEWAHRUNG_TAGE . ' DAY), NOW()) AS rest
         FROM ' . $tabelle . ' WHERE deleted_at IS NOT NULL ORDER BY deleted_at DESC'
    )->fetchAll(PDO::FETCH_ASSOC);
    $bereiche[$tabelle] = $rows;
    $gesamt += count($rows);
}

$page_title   = 'Papierkorb';
$page_heading = 'Papierkorb';
$current_page = basename($_SERVER['SCRIPT_NAME']);

require 'includes/head.php';
require 'includes/layout_start.php';
?>

<?php if (isset($_GET['msg'])): ?>
  <div class="alert alert-success alert-dismissible fade show mb-3">
    <i class="bi bi-check-circle me-2"></i>
    <?= $_GET['msg'] === 'restored' ? te('Eintrag wiederhergestellt.') : te('Eintrag endgültig gelöscht.') ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
  </div>
<?php endif; ?>

<div class="widget-box widget-accent-left mb-4">
  <div class="d-flex align-items-center gap-3">
    <div class="icon-tile icon-tile-neutral"><i class="bi bi-trash3"></i></div>
    <div>
      <div class="fw-bold text-strong-c"><?= $gesamt ?> <?= te('Eintrag/Einträge im Papierkorb') ?></div>
      <div class="text-muted small">
        <?= te('Gelöschtes bleibt %d Tage wiederherstellbar und wird danach vom nächtlichen Lauf endgültig entfernt.', AUFBEWAHRUNG_TAGE) ?>
        <?php if ($geraeumt > 0): ?>
          <span class="text-strong-c"><?= te('Soeben aufgeräumt:') ?> <?= $geraeumt ?>.</span>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<?php foreach (PAPIERKORB as $tabelle => $def): $rows = $bereiche[$tabelle]; ?>
<div class="widget-box mb-4" id="<?= $tabelle ?>">
  <div class="widget-title">
    <span><i class="bi <?= $def['icon'] ?>"></i> <?= htmlspecialchars($def['label']) ?></span>
    <span class="widget-count"><?= count($rows) ?></span>
  </div>

  <?php if (!$rows): ?>
    <div class="text-muted small p-3 bg-subtle rounded-3 text-center border border-subtle-c">
      <i class="bi bi-check2-circle d-block mb-1" style="font-size:1.5rem;color:var(--text-faint);"></i>
      <?= te('Nichts gelöscht.') ?>
    </div>
  <?php else: ?>
    <div class="table-responsive">
      <table class="table table-hover align-middle mb-0">
        <thead>
          <tr>
            <th><?= te('Eintrag') ?></th>
            <th class="d-none d-md-table-cell"><?= te('Gelöscht am') ?></th>
            <th class="d-none d-md-table-cell"><?= te('Verbleibend') ?></th>
            <th class="text-end"><?= te('Aktion') ?></th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $r): $rest = (int) $r['rest']; ?>
          <tr>
            <td>
              <div class="fw-semibold text-strong-c"><?= htmlspecialchars($r['titel'] ?? te('(ohne Titel)')) ?></div>
              <?php if (trim((string) $r['zusatz']) !== ''): ?>
                <div class="text-muted" style="font-size:var(--text-2xs);"><?= htmlspecialchars($r['zusatz']) ?></div>
              <?php endif; ?>
            </td>
            <td class="d-none d-md-table-cell text-muted small">
              <?= date('d.m.Y H:i', strtotime($r['deleted_at'])) ?>
            </td>
            <td class="d-none d-md-table-cell">
              <span class="due-chip <?= $rest <= 3 ? 'due-overdue' : ($rest <= 7 ? 'due-today' : '') ?>">
                <?= $rest > 0 ? $rest . te(' Tage') : 'heute' ?>
              </span>
            </td>
            <td class="text-end">
              <div class="d-inline-flex gap-2">
                <form method="POST" class="d-inline">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="restore">
                  <input type="hidden" name="bereich" value="<?= $tabelle ?>">
                  <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                  <button class="btn btn-sm btn-outline-primary fw-bold">
                    <i class="bi bi-arrow-counterclockwise"></i>
                    <span class="btn-label"><?= te('Wiederherstellen') ?></span>
                  </button>
                </form>
                <form method="POST" class="d-inline"
                      onsubmit="return confirm('Diesen Eintrag endgültig löschen? Das lässt sich nicht rückgängig machen.')">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="purge">
                  <input type="hidden" name="bereich" value="<?= $tabelle ?>">
                  <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                  <button class="btn btn-sm btn-outline-danger" aria-label="<?= te('Endgültig löschen') ?>">
                    <i class="bi bi-trash3"></i>
                  </button>
                </form>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>
<?php endforeach; ?>

<?php require 'includes/layout_end.php'; ?>
