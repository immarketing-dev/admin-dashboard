<?php
/**
 * Verknüpfungen zwischen Projekten.
 *
 * Ein Projekt knüpft oft an ein älteres an: die Broschüre folgt dem
 * Firmenauftritt, die Wartung dem Relaunch. Bis hierher stand das, wenn
 * überhaupt, als Satz in der Beschreibung - nicht anklickbar, nicht
 * auswertbar, und auf dem alten Projekt stand nichts.
 *
 * Gerichtet, mit Art: task_id knüpft an linked_task_id an. Beide Karten
 * zeigen die Beziehung in ihrer Leserichtung ("Folgeprojekt von X" auf
 * der neuen, "Fortgesetzt in Y" auf der alten). Ein Paar steht genau
 * einmal in der Tabelle; wer A→B speichert, ersetzt damit ein
 * bestehendes B→A.
 */

require_once __DIR__ . '/logging.php';

/**
 * Die Arten und ihre Lesart, je Richtung.
 *
 * 'vor' steht auf der Karte, die anknüpft; 'zurueck' auf der Karte, an
 * die angeknüpft wird. %s ist der Titel des Gegenübers. Über t(), nicht
 * te(): der Platzhalter wird später mit einem fertigen Link gefüllt,
 * und der darf nicht maskiert werden. Das Muster selbst ist unser
 * eigenes Literal.
 *
 * @return array<string, array{label: string, vor: string, zurueck: string}>
 */
function task_link_arten(): array
{
    return [
        'follow_up' => ['label' => t('Folgeprojekt'), 'vor' => t('Folgeprojekt von %s'), 'zurueck' => t('Fortgesetzt in %s')],
        'part_of'   => ['label' => t('Teilprojekt'),  'vor' => t('Teilprojekt von %s'),  'zurueck' => t('Teilprojekt: %s')],
        'related'   => ['label' => t('Verwandt'),     'vor' => t('Verwandt mit %s'),     'zurueck' => t('Verwandt mit %s')],
    ];
}

/** Eine bekannte Art, sonst 'related'. */
function task_link_art(string $kind): string
{
    return isset(task_link_arten()[$kind]) ? $kind : 'related';
}

/**
 * Die Verknüpfungen aus einer Sendung.
 *
 * Drei gleichlange Felder: link_task[], link_kind[], link_note[]. Eine
 * Zeile ohne Ziel (leere Auswahl) fällt weg; die übrigen bleiben ihrer
 * Position nach zugeordnet, damit Art und Notiz zum richtigen Projekt
 * gehören.
 *
 * @return array<int, array{task: int, kind: string, note: string}>
 */
function task_links_aus_formular(array $post): array
{
    $ziele = (array) ($post['link_task'] ?? []);
    $arten = (array) ($post['link_kind'] ?? []);
    $noten = (array) ($post['link_note'] ?? []);
    $aus   = [];
    foreach ($ziele as $i => $roh) {
        $ziel = (int) $roh;
        if ($ziel <= 0) continue;
        $aus[] = [
            'task' => $ziel,
            'kind' => task_link_art((string) ($arten[$i] ?? '')),
            'note' => mb_substr(trim((string) ($noten[$i] ?? '')), 0, 255),
        ];
    }
    return $aus;
}

/**
 * Bringt die Verknüpfungen eines Projekts auf den übergebenen Stand.
 *
 * $links ist die vollständige Liste (siehe task_links_aus_formular()).
 * Verworfen werden Selbstverweis, gelöschte und unbekannte Ziele - mit
 * Protokolleintrag, damit ein Fehler im Formular nicht still verpufft.
 * Ein doppeltes Ziel in derselben Sendung: das erste gewinnt. Ein
 * bestehendes Paar wird aktualisiert, nicht neu angelegt (die Kennung
 * bleibt). Der Gegenverweis Ziel→Projekt wird gelöscht, damit dasselbe
 * Paar nicht zweimal steht. Was nicht mehr gesendet wird, wird gelöscht.
 */
function task_links_abgleichen(PDO $pdo, int $task_id, array $links): void
{
    if ($task_id <= 0) return;

    // Gültige Ziele: vorhanden und nicht gelöscht.
    $ziel_ids = array_values(array_unique(array_filter(
        array_map(fn($l) => (int) ($l['task'] ?? 0), $links), fn($id) => $id > 0)));
    $gueltig = [];
    if ($ziel_ids) {
        $ph = implode(',', array_fill(0, count($ziel_ids), '?'));
        $st = $pdo->prepare("SELECT id FROM tasks WHERE deleted_at IS NULL AND id IN ($ph)");
        $st->execute($ziel_ids);
        $gueltig = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
    }

    $soll = [];   // ziel_id => ['kind', 'note']
    foreach ($links as $l) {
        $ziel = (int) ($l['task'] ?? 0);
        if ($ziel <= 0) continue;
        if (isset($soll[$ziel])) continue;               // erstes gewinnt
        if ($ziel === $task_id || !in_array($ziel, $gueltig, true)) {
            log_event($pdo, 'TASK_LINK_REJECTED',
                "Verknüpfung von Projekt $task_id auf $ziel verworfen: "
                . ($ziel === $task_id ? 'Selbstverweis.' : 'Ziel gelöscht oder unbekannt.'));
            continue;
        }
        $soll[$ziel] = [
            'kind' => task_link_art((string) ($l['kind'] ?? '')),
            'note' => mb_substr(trim((string) ($l['note'] ?? '')), 0, 255),
        ];
    }

    $ist_st = $pdo->prepare("SELECT linked_task_id, kind, note FROM task_links WHERE task_id = ?");
    $ist_st->execute([$task_id]);
    $ist = [];
    foreach ($ist_st->fetchAll(PDO::FETCH_ASSOC) as $z) {
        $ist[(int) $z['linked_task_id']] = ['kind' => $z['kind'], 'note' => $z['note']];
    }

    foreach (array_diff_key($ist, $soll) as $weg => $_) {
        $pdo->prepare("DELETE FROM task_links WHERE task_id = ? AND linked_task_id = ?")
            ->execute([$task_id, $weg]);
    }
    foreach ($soll as $ziel => $w) {
        // Der Gegenverweis weicht: das Paar steht danach nur noch in
        // dieser Richtung.
        $pdo->prepare("DELETE FROM task_links WHERE task_id = ? AND linked_task_id = ?")
            ->execute([$ziel, $task_id]);
        if (isset($ist[$ziel])) {
            if ($ist[$ziel] !== $w) {
                $pdo->prepare("UPDATE task_links SET kind = ?, note = ? WHERE task_id = ? AND linked_task_id = ?")
                    ->execute([$w['kind'], $w['note'], $task_id, $ziel]);
            }
        } else {
            $pdo->prepare("INSERT INTO task_links (task_id, linked_task_id, kind, note) VALUES (?, ?, ?, ?)")
                ->execute([$task_id, $ziel, $w['kind'], $w['note']]);
        }
    }
}

/**
 * Die Verknüpfungen mehrerer Projekte, beide Richtungen, in einer
 * Abfrage. Gelöschte Gegenüber fehlen - sie kehren mit dem
 * Wiederherstellen zurück.
 *
 * @param  int[] $task_ids
 * @return array<int, array<int, array{id:int, ziel_id:int, titel:string, status:string, kunde:string, kind:string, richtung:string, note:string}>>
 */
function task_links_laden(PDO $pdo, array $task_ids): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $task_ids), fn($i) => $i > 0)));
    if (!$ids) return [];
    $ph = implode(',', array_fill(0, count($ids), '?'));

    $st = $pdo->prepare(
        "SELECT l.id, l.task_id AS eigen, l.linked_task_id AS ziel, 'vor' AS richtung, l.kind, l.note,
                t.title, t.status, c.name AS kunde
           FROM task_links l
           JOIN tasks t ON t.id = l.linked_task_id
           LEFT JOIN contacts c ON c.id = t.contact_id
          WHERE l.task_id IN ($ph) AND t.deleted_at IS NULL
         UNION ALL
         SELECT l.id, l.linked_task_id, l.task_id, 'zurueck', l.kind, l.note,
                t.title, t.status, c.name
           FROM task_links l
           JOIN tasks t ON t.id = l.task_id
           LEFT JOIN contacts c ON c.id = t.contact_id
          WHERE l.linked_task_id IN ($ph) AND t.deleted_at IS NULL"
    );
    $st->execute(array_merge($ids, $ids));

    $aus = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $z) {
        $aus[(int) $z['eigen']][] = [
            'id'       => (int) $z['id'],
            'ziel_id'  => (int) $z['ziel'],
            'titel'    => (string) $z['title'],
            'status'   => (string) $z['status'],
            'kunde'    => (string) ($z['kunde'] ?? ''),
            'kind'     => (string) $z['kind'],
            'richtung' => (string) $z['richtung'],
            'note'     => (string) $z['note'],
        ];
    }
    foreach ($aus as &$liste) {
        usort($liste, fn($a, $b) => [$a['richtung'] !== 'vor', $a['titel']] <=> [$b['richtung'] !== 'vor', $b['titel']]);
    }
    unset($liste);
    return $aus;
}

/**
 * Die Lesart als HTML. $ziel_html ist bereits maskiert (oder ein
 * fertiger Link) - das Muster stammt aus task_link_arten() und ist
 * unser eigenes Literal.
 */
function task_link_text(string $kind, string $richtung, string $ziel_html): string
{
    $art    = task_link_arten()[task_link_art($kind)];
    $muster = $richtung === 'zurueck' ? $art['zurueck'] : $art['vor'];
    return sprintf($muster, $ziel_html);
}

/**
 * Der Formularblock "Anknüpfen an": Zeilen werden im Browser aus der
 * Vorlage gebaut (siehe linksSetzen() in tasks.php). Die Projektliste
 * zeigt "Titel · Firma/Name · Jahr"; gelöschte Projekte fehlen. Das
 * eigene Projekt blendet der Browser beim Öffnen aus - die Liste ist
 * einmal je Seite gerendert.
 *
 * @param array $projekte         Zeilen aus tasks (id, title, start_date, contact_id, deleted_at)
 * @param array $kontakte_nach_id contact_id => ['name', 'company']
 */
function task_links_auswahl(array $projekte, array $kontakte_nach_id, string $praefix): string
{
    $h = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES);

    $optionen = '<option value="">' . te('-- Projekt wählen --') . '</option>';
    foreach ($projekte as $p) {
        if (!empty($p['deleted_at'])) continue;
        $k     = $kontakte_nach_id[(int) ($p['contact_id'] ?? 0)] ?? null;
        $teile = [$h($p['title'])];
        if ($k) $teile[] = $h(($k['company'] ?? '') ?: ($k['name'] ?? ''));
        if (!empty($p['start_date']) && strpos((string) $p['start_date'], '0000') !== 0) {
            $teile[] = substr((string) $p['start_date'], 0, 4);
        }
        $optionen .= '<option value="' . (int) $p['id'] . '" data-link-option>' . implode(' · ', $teile) . '</option>';
    }

    $arten = '';
    foreach (task_link_arten() as $kind => $a) {
        $arten .= '<option value="' . $h($kind) . '">' . $h($a['label']) . '</option>';
    }

    return '<div class="link-picker" data-link-picker>'
         . '<div data-link-rows></div>'
         . '<button type="button" class="btn btn-sm btn-outline-secondary" data-link-add>'
         . '<i class="bi bi-plus-lg me-1"></i>' . te('Verknüpfung') . '</button>'
         . '<template data-link-template>'
         . '<div class="task-link-row" data-link-row>'
         . '<select name="link_task[]" class="form-select form-select-sm" aria-label="' . te('Projekt') . '">' . $optionen . '</select>'
         . '<select name="link_kind[]" class="form-select form-select-sm" aria-label="' . te('Art') . '">' . $arten . '</select>'
         . '<input type="text" name="link_note[]" class="form-control form-control-sm" maxlength="255"'
         . ' placeholder="' . te('Notiz (optional)') . '" aria-label="' . te('Notiz (optional)') . '">'
         . '<button type="button" class="btn btn-sm btn-link text-danger p-0" data-link-remove'
         . ' aria-label="' . te('Entfernen') . '"><i class="bi bi-x-lg"></i></button>'
         . '</div>'
         . '</template>'
         . '</div>';
}
