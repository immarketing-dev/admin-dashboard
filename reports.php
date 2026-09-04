<?php
/**
 * Auswertungen und Stundenzettel.
 *
 * Die Daten dafür lagen seit Schemaversion 9 vollständig vor - ein
 * Stundensatz an Kunde und Projekt, erfasste Minuten mit Kennzeichen
 * "abgerechnet", Rechnungsbeträge mit Fälligkeit. Ausgewertet wurde
 * nichts davon: finances.php zeichnet Einnahmen gegen Ausgaben über die
 * Zeit, und time_entries hatte überhaupt keine eigene Ansicht.
 *
 * Deshalb keine neue Tabelle und keine Migration - diese Seite ist
 * ausschließlich Lesen. Die Abfragen und alles, was rechnet, stehen in
 * includes/reports.php und werden von tools/test_reports.php geprüft.
 */
require_once 'config.php';
require_once __DIR__ . '/includes/logging.php';
require_once 'includes/reports.php';
require_once 'includes/auth.php';

$tab   = $_GET['tab'] ?? 'auswertung';
$modus = in_array($_GET['modus'] ?? '', ['week', 'month', 'year'], true) ? $_GET['modus'] : 'month';
$anker = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['anker'] ?? '') ? $_GET['anker'] : date('Y-m-d');

$standardsatz = (float) str_replace(',', '.', setting('default_hourly_rate', '60'));
$zeitraum     = zeitraum_grenzen($modus, $anker);

// ---------------------------------------------------------------------
// CSV des Stundenzettels
// ---------------------------------------------------------------------
// Reines Lesen, deshalb auf einem GET. Derselbe Aufbau wie der
// vorhandene Finanz-Export in finances.php, einschließlich der
// Byte-Order-Mark: ohne sie zeigt Excel Umlaute als Buchstabensalat.
if (($_GET['export'] ?? '') === 'timesheet') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=Stundenzettel_' . $zeitraum['von'] . '_' . $zeitraum['bis'] . '.csv');

    $aus = fopen('php://output', 'w');
    fprintf($aus, chr(0xEF) . chr(0xBB) . chr(0xBF));
    fputcsv($aus, ['Datum', 'Uhrzeit', 'Projekt', 'Kunde', 'Notiz', 'Minuten', 'Stunden', 'Abgerechnet'], ';', '"', '');

    foreach (zeiteintraege($pdo, $zeitraum['von'], $zeitraum['bis']) as $e) {
        fputcsv($aus, [
            date('d.m.Y', strtotime((string) $e['created_at'])),
            date('H:i',   strtotime((string) $e['created_at'])),
            $e['projekt'],
            $e['kunde'],
            $e['note'],
            (int) $e['duration_minutes'],
            str_replace('.', ',', (string) stunden((int) $e['duration_minutes'])),
            $e['billed_at'] ? 'Ja' : 'Nein',
        ], ';', '"', '');
    }
    fclose($aus);
    exit();
}

// ---------------------------------------------------------------------
// Daten
// ---------------------------------------------------------------------
$jahre = umsatz_jahre($pdo);
$jahr  = (int) ($_GET['jahr'] ?? date('Y'));
if (!in_array($jahr, $jahre, true)) {
    $jahr = (int) $jahre[0];
}

if ($tab === 'timesheet') {
    $eintraege   = zeiteintraege($pdo, $zeitraum['von'], $zeitraum['bis']);
    $nach_tag    = zeiten_nach_tag($eintraege);
    $nach_projekt = zeiten_nach_projekt($eintraege);
    $summe_min   = array_sum(array_column($eintraege, 'duration_minutes'));
    $offen_min   = 0;
    foreach ($eintraege as $e) {
        if (empty($e['billed_at'])) $offen_min += (int) $e['duration_minutes'];
    }
} else {
    $posten     = offene_posten($pdo);
    $stufen     = offene_posten_verteilen($posten, date('Y-m-d'));
    $umsatz     = umsatz_je_kunde($pdo, $jahr);
    $projekte   = zeit_je_projekt($pdo, $standardsatz);

    $summe_offen = array_sum(array_column($stufen, 'betrag'));
    $summe_umsatz_bezahlt = array_sum(array_column($umsatz, 'bezahlt'));
    $summe_umsatz_offen   = array_sum(array_column($umsatz, 'offen'));
    // Nur Projekte mit offener Zeit - die Frage lautet "was ist geleistet
    // und noch nicht in Rechnung", nicht "was wurde je erfasst".
    $offene_projekte = array_values(array_filter($projekte, fn($p) => $p['offen'] > 0));
    $summe_offen_wert = array_sum(array_column($offene_projekte, 'offen_wert'));
    $summe_offen_min  = array_sum(array_column($offene_projekte, 'offen'));
}


$page_title   = 'Auswertungen';
$page_heading = 'Auswertungen';
$current_page = 'reports.php';

require 'includes/head.php';
require 'includes/layout_start.php';
?>

<ul class="nav nav-tabs mb-4">
  <li class="nav-item">
    <a class="nav-link <?= $tab !== 'timesheet' ? 'active' : '' ?>" href="reports">
      <i class="bi bi-graph-up-arrow me-1"></i> <?= te('Auswertung') ?>
    </a>
  </li>
  <li class="nav-item">
    <a class="nav-link <?= $tab === 'timesheet' ? 'active' : '' ?>" href="reports?tab=timesheet&amp;modus=<?= $modus ?>&amp;anker=<?= $anker ?>">
      <i class="bi bi-clock-history me-1"></i> <?= te('Stundenzettel') ?>
    </a>
  </li>
</ul>

<?php if ($tab !== 'timesheet'): ?>

  <!-- ============ Offene Posten nach Alter ============ -->
  <div class="widget-box widget-accent-left mb-4">
    <div class="widget-title">
      <span><i class="bi bi-hourglass-split me-2"></i><?= te('Offene Posten nach Alter') ?></span>
      <span class="widget-count <?= $summe_offen > 0 ? 'widget-count-warning' : '' ?>">
        <?= number_format($summe_offen, 2, ',', '.') ?> €
      </span>
    </div>

    <?php if (!$posten): ?>
      <div class="text-center py-5 text-muted"><i class="bi bi-check2-circle fs-1 d-block mb-2"></i><?= te('Keine offenen Rechnungen.') ?></div>
    <?php else: ?>
      <?php $max_stufe = max(array_column($stufen, 'betrag')) ?: 1; ?>
      <div class="report-stufen mb-3">
        <?php foreach ($stufen as $i => $s): ?>
          <?php
            // Die Ampel trägt hier tatsächlich Status: je älter ein Posten,
            // desto ernster. "Nicht fällig" ist neutral, nicht gut.
            $klasse = $i === 0 ? '' : ($i === 1 ? 'report-bar-warn' : ($i === 2 ? 'report-bar-warn' : 'report-bar-danger'));
          ?>
          <div class="report-stufe">
            <?php // datenwert() statt te(): der Name ist hier eine Variable. ?>
            <div class="report-stufe-name"><?= htmlspecialchars(datenwert($s['name'])) ?></div>
            <div class="report-stufe-betrag"><?= number_format($s['betrag'], 2, ',', '.') ?> €</div>
            <div class="report-bar">
              <div class="report-bar-fill <?= $klasse ?>" style="width: <?= balken($s['betrag'], $max_stufe) ?>%"></div>
            </div>
            <div class="report-stufe-anzahl"><?= te('%d Rechnung(en)', $s['anzahl']) ?></div>
          </div>
        <?php endforeach; ?>
      </div>

      <div class="table-responsive">
        <table class="table table-sm align-middle mb-0">
          <thead>
            <tr class="section-label">
              <th><?= te('Nummer') ?></th>
              <th><?= te('Kunde') ?></th>
              <th><?= te('Fällig') ?></th>
              <th class="text-center"><?= te('Überfällig') ?></th>
              <th class="text-center"><?= te('Erinnert') ?></th>
              <th class="text-end"><?= te('Betrag') ?></th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($posten as $p): ?>
            <?php $tage = tage_ueberfaellig($p['due_date'], date('Y-m-d')); ?>
            <tr>
              <td class="report-num"><?= htmlspecialchars($p['invoice_number'] ?: $p['title']) ?></td>
              <td><?= htmlspecialchars($p['kunde']) ?></td>
              <td class="report-num"><?= $p['due_date'] ? date('d.m.Y', strtotime($p['due_date'])) : '–' ?></td>
              <td class="text-center">
                <?php if ($tage > 0): ?>
                  <span class="due-chip <?= $tage > 30 ? 'due-overdue' : 'due-soon' ?>"><?= te('%d Tage', $tage) ?></span>
                <?php else: ?>
                  <span class="text-muted">–</span>
                <?php endif; ?>
              </td>
              <td class="text-center">
                <?= (int) $p['reminder_count'] > 0
                    ? '<i class="bi bi-bell-fill text-warning"></i> ' . (int) $p['reminder_count']
                    : '<span class="text-muted">–</span>' ?>
              </td>
              <td class="text-end fw-bold report-num"><?= number_format((float) $p['amount'], 2, ',', '.') ?> €</td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>

  <!-- ============ Umsatz je Kunde ============ -->
  <div class="widget-box widget-accent-left mb-4">
    <div class="widget-title">
      <span><i class="bi bi-people me-2"></i><?= te('Umsatz je Kunde') ?></span>
      <form method="GET" class="d-inline">
        <select name="jahr" class="form-select form-select-sm report-jahr" onchange="this.form.submit()">
          <?php foreach ($jahre as $j): ?>
            <option value="<?= $j ?>" <?= $j === $jahr ? 'selected' : '' ?>><?= $j ?></option>
          <?php endforeach; ?>
        </select>
      </form>
    </div>

    <?php if (!$umsatz): ?>
      <div class="text-center py-5 text-muted"><i class="bi bi-graph-up fs-1 d-block mb-2"></i><?= te('Für dieses Jahr gibt es keine Einnahmen.') ?></div>
    <?php else: ?>
      <p class="section-hint">
        <?= te('Bezahlt: %s € · Offen: %s €',
               number_format($summe_umsatz_bezahlt, 2, ',', '.'),
               number_format($summe_umsatz_offen, 2, ',', '.')) ?>
      </p>
      <?php $max_umsatz = max(array_map(fn($u) => $u['bezahlt'] + $u['offen'], $umsatz)) ?: 1; ?>
      <div class="table-responsive">
        <table class="table table-sm align-middle mb-0">
          <thead>
            <tr class="section-label">
              <th><?= te('Kunde') ?></th>
              <th style="width:40%"><?= te('Anteil') ?></th>
              <th class="text-end"><?= te('Bezahlt') ?></th>
              <th class="text-end"><?= te('Offen') ?></th>
              <th class="text-center"><?= te('Rechnungen') ?></th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($umsatz as $u): ?>
            <tr>
              <td class="fw-semibold text-strong-c"><?= htmlspecialchars($u['kunde']) ?></td>
              <td>
                <?php // Zwei Segmente in einer Spur: bezahlt zuerst, offen daneben. ?>
                <div class="report-bar">
                  <div class="report-bar-fill report-bar-success" style="width: <?= balken($u['bezahlt'], $max_umsatz) ?>%"></div>
                  <div class="report-bar-fill report-bar-warn" style="width: <?= balken($u['offen'], $max_umsatz) ?>%"></div>
                </div>
              </td>
              <td class="text-end report-num"><?= number_format($u['bezahlt'], 2, ',', '.') ?> €</td>
              <td class="text-end report-num <?= $u['offen'] > 0 ? 'text-warning fw-semibold' : 'text-muted' ?>">
                <?= $u['offen'] > 0 ? number_format($u['offen'], 2, ',', '.') . ' €' : '–' ?>
              </td>
              <td class="text-center text-muted"><?= (int) $u['anzahl'] ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>

  <!-- ============ Nicht abgerechnete Zeit ============ -->
  <div class="widget-box widget-accent-left mb-4">
    <div class="widget-title">
      <span><i class="bi bi-stopwatch me-2"></i><?= te('Geleistet, noch nicht berechnet') ?></span>
      <span class="widget-count <?= $summe_offen_wert > 0 ? 'widget-count-primary' : '' ?>">
        <?= number_format($summe_offen_wert, 2, ',', '.') ?> €
      </span>
    </div>

    <?php if (!$offene_projekte): ?>
      <div class="text-center py-5 text-muted"><i class="bi bi-check2-all fs-1 d-block mb-2"></i><?= te('Jede erfasste Stunde ist abgerechnet.') ?></div>
    <?php else: ?>
      <p class="section-hint">
        <?= te('%s Stunden aus %d Projekt(en). Bewertet mit dem heute geltenden Satz (Projekt vor Kunde vor Voreinstellung).',
               stunden_lesbar($summe_offen_min), count($offene_projekte)) ?>
      </p>
      <div class="table-responsive">
        <table class="table table-sm align-middle mb-0">
          <thead>
            <tr class="section-label">
              <th><?= te('Projekt') ?></th>
              <th><?= te('Kunde') ?></th>
              <th class="text-end"><?= te('Erfasst') ?></th>
              <th class="text-end"><?= te('Berechnet') ?></th>
              <th class="text-end"><?= te('Offen') ?></th>
              <th class="text-end"><?= te('Satz') ?></th>
              <th class="text-end"><?= te('Wert') ?></th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($offene_projekte as $p): ?>
            <tr>
              <td class="fw-semibold text-strong-c"><?= htmlspecialchars($p['title']) ?></td>
              <td class="text-muted"><?= htmlspecialchars($p['kunde']) ?></td>
              <td class="text-end report-num"><?= stunden_lesbar($p['minuten']) ?></td>
              <td class="text-end report-num text-muted"><?= stunden_lesbar($p['berechnet']) ?></td>
              <td class="text-end report-num fw-semibold"><?= stunden_lesbar($p['offen']) ?></td>
              <td class="text-end report-num text-muted"><?= number_format($p['satz'], 2, ',', '.') ?> €</td>
              <td class="text-end report-num fw-bold"><?= number_format($p['offen_wert'], 2, ',', '.') ?> €</td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>

<?php else: ?>

  <!-- ============ Stundenzettel ============ -->
  <div class="filter-bar mb-4">
    <div class="d-flex flex-wrap gap-2 align-items-center">
      <div class="btn-group btn-group-sm" role="group">
        <?php foreach (['week' => 'Woche', 'month' => 'Monat', 'year' => 'Jahr'] as $m => $label): ?>
          <a class="btn <?= $modus === $m ? 'btn-primary' : 'btn-outline-secondary' ?>"
             href="reports?tab=timesheet&amp;modus=<?= $m ?>&amp;anker=<?= $anker ?>"><?= te($label) ?></a>
        <?php endforeach; ?>
      </div>

      <div class="btn-group btn-group-sm ms-2" role="group">
        <a class="btn btn-outline-secondary" title="<?= te('Zurück') ?>"
           href="reports?tab=timesheet&amp;modus=<?= $modus ?>&amp;anker=<?= zeitraum_verschieben($modus, $anker, -1) ?>"><i class="bi bi-chevron-left"></i></a>
        <a class="btn btn-outline-secondary" title="<?= te('Heute') ?>"
           href="reports?tab=timesheet&amp;modus=<?= $modus ?>"><?= te('Heute') ?></a>
        <a class="btn btn-outline-secondary" title="<?= te('Weiter') ?>"
           href="reports?tab=timesheet&amp;modus=<?= $modus ?>&amp;anker=<?= zeitraum_verschieben($modus, $anker, 1) ?>"><i class="bi bi-chevron-right"></i></a>
      </div>

      <span class="fw-bold ms-2 text-strong-c"><?= htmlspecialchars($zeitraum['titel']) ?></span>

      <a class="btn btn-sm btn-outline-secondary ms-auto"
         href="reports?export=timesheet&amp;modus=<?= $modus ?>&amp;anker=<?= $anker ?>">
        <i class="bi bi-download me-1"></i><span class="btn-label"><?= te('CSV') ?></span>
      </a>
    </div>
  </div>

  <div class="row g-3 mb-4">
    <div class="col-md-4">
      <div class="widget-box widget-accent-left">
        <div class="section-label"><?= te('Erfasst') ?></div>
        <div class="widget-count widget-count-primary"><?= stunden_lesbar($summe_min) ?></div>
      </div>
    </div>
    <div class="col-md-4">
      <div class="widget-box widget-accent-left">
        <div class="section-label"><?= te('Davon offen') ?></div>
        <div class="widget-count <?= $offen_min > 0 ? 'widget-count-warning' : '' ?>"><?= stunden_lesbar($offen_min) ?></div>
      </div>
    </div>
    <div class="col-md-4">
      <div class="widget-box widget-accent-left">
        <div class="section-label"><?= te('Einträge') ?></div>
        <div class="widget-count"><?= count($eintraege) ?></div>
      </div>
    </div>
  </div>

  <?php if (!$eintraege): ?>
    <div class="widget-box">
      <div class="text-center py-5 text-muted">
        <i class="bi bi-clock fs-1 d-block mb-2"></i>
        <?= te('In diesem Zeitraum wurde keine Zeit erfasst.') ?>
      </div>
    </div>
  <?php else: ?>

    <div class="row g-3">
      <div class="col-lg-8">
        <div class="widget-box widget-accent-left">
          <div class="widget-title"><span><i class="bi bi-calendar3 me-2"></i><?= te('Nach Tag') ?></span></div>

          <?php foreach ($nach_tag as $tag => $daten): ?>
            <div class="report-tag">
              <div class="report-tag-kopf">
                <span class="fw-bold text-strong-c"><?= date('D, d.m.Y', strtotime($tag)) ?></span>
                <span class="report-num fw-bold"><?= stunden_lesbar($daten['minuten']) ?></span>
              </div>
              <?php foreach ($daten['eintraege'] as $e): ?>
                <div class="report-eintrag">
                  <span class="report-eintrag-zeit report-num"><?= date('H:i', strtotime((string) $e['created_at'])) ?></span>
                  <span class="report-eintrag-projekt"><?= htmlspecialchars((string) $e['projekt']) ?></span>
                  <span class="report-eintrag-notiz text-muted"><?= htmlspecialchars((string) ($e['note'] ?? '')) ?></span>
                  <span class="report-eintrag-dauer report-num"><?= stunden_lesbar((int) $e['duration_minutes']) ?></span>
                  <span class="report-eintrag-status">
                    <?php if ($e['billed_at']): ?>
                      <i class="bi bi-receipt text-success" title="<?= te('Abgerechnet') ?>"></i>
                    <?php else: ?>
                      <i class="bi bi-circle text-muted" title="<?= te('Noch nicht abgerechnet') ?>"></i>
                    <?php endif; ?>
                  </span>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endforeach; ?>
        </div>
      </div>

      <div class="col-lg-4">
        <div class="widget-box widget-accent-left">
          <div class="widget-title"><span><i class="bi bi-bar-chart me-2"></i><?= te('Nach Projekt') ?></span></div>
          <?php $max_p = max(array_column($nach_projekt, 'minuten')) ?: 1; ?>
          <?php foreach ($nach_projekt as $p): ?>
            <div class="report-projekt">
              <div class="d-flex justify-content-between gap-2">
                <span class="fw-semibold text-strong-c text-truncate"><?= htmlspecialchars($p['projekt']) ?></span>
                <span class="report-num"><?= stunden_lesbar($p['minuten']) ?></span>
              </div>
              <div class="report-bar">
                <div class="report-bar-fill" style="width: <?= balken((float) $p['minuten'], (float) $max_p) ?>%"></div>
              </div>
              <div class="text-muted label-xs">
                <?= htmlspecialchars($p['kunde']) ?>
                <?php if ($p['offen'] > 0): ?>
                  · <?= te('%s offen', stunden_lesbar($p['offen'])) ?>
                <?php endif; ?>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>

  <?php endif; ?>

<?php endif; ?>

<?php require 'includes/layout_end.php'; ?>
