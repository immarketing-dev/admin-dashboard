<?php
/**
 * Papierkorb.
 *
 * Zeigt, was seit Migration 4 gelöscht wurde, und stellt es wieder her
 * oder entfernt es endgültig. Nach 30 Tagen räumt die Seite selbst auf —
 * bewusst hier und nicht bei jedem Seitenaufruf irgendwo im Panel: Wer
 * den Papierkorb öffnet, rechnet mit dem Aufräumen, und ein Nutzer, der
 * ihn nie öffnet, zahlt auch keine Abfragen dafür.
 */
require_once 'config.php';
require_once __DIR__ . '/includes/logging.php';
require_once 'includes/auth.php';
require_once 'includes/csrf.php';

/**
 * Die Bereiche des Papierkorbs. Nur Daten, deren Verlust wehtut —
 * Logs, Meilensteine, Kommentare und Dateien werden weiterhin sofort
 * gelöscht, dort wäre ein Papierkorb nur Ballast.
 */
const PAPIERKORB = [
    'contacts' => [
        'label'  => 'Kontakte',
        'icon'   => 'bi-people-fill',
        'titel'  => 'name',
        'zusatz' => "TRIM(CONCAT_WS(' · ', NULLIF(company,''), NULLIF(email,'')))",
    ],
    'tasks' => [
        'label'  => 'Projekte',
        'icon'   => 'bi-check2-square',
        'titel'  => 'title',
        'zusatz' => "TRIM(CONCAT_WS(' · ', status, NULLIF(category,'')))",
    ],
    'finances' => [
        'label'  => 'Finanzen',
        'icon'   => 'bi-currency-euro',
        'titel'  => "COALESCE(NULLIF(invoice_number,''), title)",
        'zusatz' => "TRIM(CONCAT_WS(' · ', CONCAT(FORMAT(amount, 2, 'de_DE'), ' €'), status))",
    ],
    'quotes' => [
        'label'  => 'Angebote',
        'icon'   => 'bi-file-earmark-text',
        'titel'  => "CONCAT(quote_number, ' · ', COALESCE(NULLIF(subject,''), 'Angebot'))",
        'zusatz' => "TRIM(CONCAT_WS(' · ', CONCAT(FORMAT(total_amount, 2, 'de_DE'), ' €'), status))",
    ],
];

const AUFBEWAHRUNG_TAGE = 30;

// ── Automatisches Aufräumen ─────────────────────────────────────────
// Läuft bei jedem Aufruf der Seite, also auch auf einem GET. In der
// Demo darf das nicht: der dortige Datenbankbenutzer hat nur SELECT,
// das DELETE würde die Seite mit einer Ausnahme abbrechen lassen. Und
// zu löschen gäbe es ohnehin nichts - der Bestand ist unveränderlich.
// tools/check_demo.php führt diese Stelle als dokumentierte Ausnahme.
$geraeumt = 0;
foreach (demo_mode() ? [] : array_keys(PAPIERKORB) as $tabelle) {
    $st = $pdo->prepare(
        "DELETE FROM $tabelle WHERE deleted_at IS NOT NULL"
        . ' AND deleted_at < DATE_SUB(NOW(), INTERVAL ' . AUFBEWAHRUNG_TAGE . ' DAY)'
    );
    $st->execute();
    $geraeumt += $st->rowCount();
}
if ($geraeumt > 0) {
    log_event($pdo, 'TRASH_PURGED', "$geraeumt Eintrag/Einträge nach " . AUFBEWAHRUNG_TAGE . ' Tagen endgültig entfernt.');
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
            $pdo->prepare("DELETE FROM $tabelle WHERE id = ? AND deleted_at IS NOT NULL")->execute([$id]);
            log_event($pdo, 'TRASH_PURGED', PAPIERKORB[$tabelle]['label'] . ": Eintrag $id endgültig gelöscht.");
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
$current_page = basename($_SERVER['PHP_SELF']);

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
      <div class="fw-bold text-strong-c"><?= $gesamt ?> Eintrag/Einträge im Papierkorb</div>
      <div class="text-muted small">
        Gelöschtes bleibt <?= AUFBEWAHRUNG_TAGE ?> Tage wiederherstellbar und wird danach
        beim Öffnen dieser Seite automatisch entfernt.
        <?php if ($geraeumt > 0): ?>
          <span class="text-strong-c">Soeben aufgeräumt: <?= $geraeumt ?>.</span>
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
                <?= $rest > 0 ? $rest . ' Tage' : 'heute' ?>
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
