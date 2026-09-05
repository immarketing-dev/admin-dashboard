<?php
/**
 * Was Kunden im Portal getan haben — für die Startseite.
 *
 * Zwei Dinge sind hier zusammengelegt worden.
 *
 * ERSTENS die Herkunft: das Widget stand zweimal in index.php, einmal
 * für den Seitenaufbau und einmal für den AJAX-Abruf, der es alle paar
 * Sekunden ersetzt. Rund 130 Zeilen doppelt, Zeichen für Zeichen — eine
 * Änderung an einer Stelle wäre der anderen still davongelaufen, und
 * aufgefallen wäre es erst, wenn die Seite nach dem ersten Abruf anders
 * aussieht als beim Laden.
 *
 * ZWEITENS die Darstellung: vier feste Spalten in einer Kachel von acht
 * Rasterspalten lassen jeder Spalte rund 150 Pixel. Dateinamen und
 * Projekttitel wurden darin zu "Untertitel_fin…" und "Web…", während
 * leere Kategorien trotzdem ihr Viertel belegten und dort "Keine
 * Absegnungen" anzeigten. Eine gemeinsame Liste, nach Zeit sortiert,
 * gibt jedem Eintrag die volle Breite und zeigt gar nichts, wenn nichts
 * da ist.
 *
 * Die Kennungen der Arten bleiben, wie sie waren (upload, approval,
 * feedback, ms_comment): index.php entscheidet daran, welche Spalte beim
 * Ausblenden auf "gesehen" gesetzt wird.
 */

/**
 * Alle offenen Portal-Aktivitäten, neueste zuerst.
 *
 * @return array<int, array{art:string, id:int, badge:string, titel:string,
 *                          zitat:string, projekt:string, kunde:string, zeit:?string}>
 */
function portal_aktivitaeten(PDO $pdo): array
{
    $aus = [];

    foreach ($pdo->query(
        "SELECT ca.id, ca.file_name, ca.uploaded_at, t.title AS task_title, c.name AS client_name
           FROM client_assets ca
           JOIN tasks t ON ca.task_id = t.id
           JOIN contacts c ON t.contact_id = c.id
          WHERE ca.dashboard_seen = 0
            AND (ca.uploaded_by IS NULL OR ca.uploaded_by = 'client')
          ORDER BY ca.uploaded_at DESC"
    )->fetchAll(PDO::FETCH_ASSOC) as $z) {
        $aus[] = [
            'art'     => 'upload',
            'id'      => (int) $z['id'],
            'badge'   => 'DATEI',
            'titel'   => (string) $z['file_name'],
            'zitat'   => '',
            'projekt' => (string) $z['task_title'],
            'kunde'   => (string) $z['client_name'],
            'zeit'    => $z['uploaded_at'] ?? null,
        ];
    }

    foreach ($pdo->query(
        "SELECT tm.id, tm.title, tm.approved_at, t.title AS task_title, c.name AS client_name
           FROM task_milestones tm
           JOIN tasks t ON tm.task_id = t.id
           JOIN contacts c ON t.contact_id = c.id
          WHERE tm.approved_at IS NOT NULL AND tm.approval_seen = 0
          ORDER BY tm.approved_at DESC"
    )->fetchAll(PDO::FETCH_ASSOC) as $z) {
        $aus[] = [
            'art'     => 'approval',
            'id'      => (int) $z['id'],
            'badge'   => 'BESTÄTIGT',
            'titel'   => (string) $z['title'],
            'zitat'   => '',
            'projekt' => (string) $z['task_title'],
            'kunde'   => (string) $z['client_name'],
            'zeit'    => $z['approved_at'] ?? null,
        ];
    }

    foreach ($pdo->query(
        "SELECT t.id, t.title, t.client_feedback, t.feedback_at, c.name AS client_name
           FROM tasks t
           JOIN contacts c ON t.contact_id = c.id
          WHERE t.deleted_at IS NULL
            AND t.client_feedback IS NOT NULL AND t.client_feedback != ''
            AND t.feedback_seen = 0
          ORDER BY t.feedback_at DESC"
    )->fetchAll(PDO::FETCH_ASSOC) as $z) {
        $aus[] = [
            'art'     => 'feedback',
            'id'      => (int) $z['id'],
            'badge'   => 'NEUES FEEDBACK',
            'titel'   => (string) $z['title'],
            'zitat'   => (string) $z['client_feedback'],
            'projekt' => (string) $z['title'],
            'kunde'   => (string) $z['client_name'],
            'zeit'    => $z['feedback_at'] ?? null,
        ];
    }

    // milestone_comments kam mit Migration 6. In einer Datenbank, die
    // noch nicht migriert ist, darf die Kachel nicht die ganze Seite
    // mitreissen - dieselbe Ueberlegung wie in includes/sidebar.php.
    try {
        $zeilen = $pdo->query(
            "SELECT mc.id, mc.message, mc.created_at, tm.title AS ms_title,
                    t.title AS task_title, c.name AS client_name
               FROM milestone_comments mc
               JOIN task_milestones tm ON mc.milestone_id = tm.id
               JOIN tasks t ON tm.task_id = t.id
               JOIN contacts c ON t.contact_id = c.id
              WHERE mc.author = 'client' AND mc.admin_seen = 0
              ORDER BY mc.created_at DESC
              LIMIT 20"
        )->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log('Portal-Aktivitäten, Kommentare: ' . $e->getMessage());
        $zeilen = [];
    }

    foreach ($zeilen as $z) {
        $aus[] = [
            'art'     => 'ms_comment',
            'id'      => (int) $z['id'],
            'badge'   => 'KOMMENTAR',
            'titel'   => (string) $z['ms_title'],
            'zitat'   => (string) $z['message'],
            'projekt' => (string) $z['task_title'],
            'kunde'   => (string) $z['client_name'],
            'zeit'    => $z['created_at'] ?? null,
        ];
    }

    // Neueste zuerst. Ein Eintrag ohne Zeitstempel rutscht ans Ende,
    // statt sich zwischen die datierten zu mogeln.
    usort($aus, function ($a, $b) {
        $za = $a['zeit'] === null ? '' : (string) $a['zeit'];
        $zb = $b['zeit'] === null ? '' : (string) $b['zeit'];
        return strcmp($zb, $za);
    });

    return $aus;
}

/**
 * Die Farbe des Kennzeichens je Art.
 *
 * Als Funktion und nicht im Markup, damit beide Aufrufer dieselbe
 * Zuordnung benutzen.
 */
function portal_aktivitaet_farbe(string $art): string
{
    return match ($art) {
        'upload'     => 'text-primary',
        'approval'   => 'text-success',
        'feedback'   => 'text-warning',
        'ms_comment' => 'text-info',
        default      => 'text-muted',
    };
}

/**
 * Gibt die Liste aus.
 *
 * Schreibt direkt, statt eine Zeichenkette zu liefern: der AJAX-Zweig
 * gibt sie unverändert weiter, und die Seite bettet sie ein. Ein
 * Zwischenpuffer brächte hier nichts.
 */
function portal_aktivitaeten_rendern(array $eintraege): void
{
    if ($eintraege === []) {
        ?>
        <div class="text-muted small text-center py-4">
            <i class="bi bi-check2-circle d-block mb-2" style="font-size:1.6rem;color:var(--text-faint);"></i>
            <?= te('Nichts Offenes aus dem Portal.') ?>
        </div>
        <?php
        return;
    }

    foreach ($eintraege as $e) {
        ?>
        <div class="portal-activity-row portal-item-hover">
            <span class="portal-activity-badge <?= portal_aktivitaet_farbe($e['art']) ?>">
                <?= htmlspecialchars(datenwert($e['badge'])) ?>
            </span>

            <a href="tasks?q=<?= urlencode($e['projekt']) ?>" class="portal-activity-main">
                <div class="portal-activity-title"><?= htmlspecialchars($e['titel']) ?></div>
                <?php if ($e['zitat'] !== ''): ?>
                    <div class="portal-activity-quote">
                        „<?= htmlspecialchars(mb_strimwidth($e['zitat'], 0, 120, '…')) ?>“
                    </div>
                <?php endif; ?>
                <div class="portal-activity-meta">
                    <i class="bi bi-person"></i> <?= htmlspecialchars($e['kunde']) ?>
                    <?php if ($e['art'] !== 'feedback' && $e['projekt'] !== $e['titel']): ?>
                        · <?= htmlspecialchars($e['projekt']) ?>
                    <?php endif; ?>
                </div>
            </a>

            <form method="POST" class="portal-activity-dismiss">
                <?= csrf_field() ?>
                <input type="hidden" name="activity_type" value="<?= htmlspecialchars($e['art']) ?>">
                <input type="hidden" name="activity_id" value="<?= (int) $e['id'] ?>">
                <button type="submit" name="dismiss_portal_activity" class="btn-close"
                        style="font-size:.65rem;" title="<?= te('Ausblenden') ?>"></button>
            </form>
        </div>
        <?php
    }
}
