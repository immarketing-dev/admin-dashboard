<?php
/**
 * Die Beteiligten eines Projekts: Auswahl im Formular und Abgleich in der
 * Datenbank.
 *
 * Beide Fenster auf der Projektseite - "Projekt bearbeiten" und
 * "Beteiligte am Projekt" - lassen dieselbe Menge Personen wählen. Bis
 * hierher taten sie das auf zwei verschiedene Arten: einmal als
 * Mehrfach-Auswahlfeld mit Strg-Klick, einmal als Liste mit Kreuzen und
 * einem Dropdown zum Einzeln-Hinzufügen. Jetzt teilen sie sich die
 * Auswahl hier - und den Abgleich gleich mit, denn sonst stünde dieselbe
 * Vergleichslogik zweimal im Code.
 */

// rollen() und benutzer_anzeige() fuer die Auswahl der Zustaendigen.
require_once __DIR__ . '/users.php';

/**
 * Bringt die Beteiligten eines Projekts auf den übergebenen Stand.
 *
 * Abgleich statt Neuschreiben: wer schon dabei ist, behält seinen Eintrag
 * und damit added_at - der Verlauf im Portal bliebe sonst nicht stehen.
 *
 * Der Hauptansprechpartner wird immer aufgenommen, auch wenn er in $soll
 * fehlt. Er ist über contact_id am Projekt hinterlegt, und ein Projekt
 * ohne seinen eigenen Kunden in der Beteiligtenliste hätte im Portal
 * einen Zugang weniger als es soll. Aus demselben Grund wird die Rolle
 * jedes Mal neu gesetzt: der Hauptkontakt kann gewechselt haben, und dann
 * trüge der alte weiterhin 'owner'.
 *
 * @param int   $haupt Kontakt-ID des Hauptansprechpartners, 0 wenn keiner.
 * @param array $soll  Kontakt-IDs der weiteren Beteiligten, beliebig roh.
 */
function task_members_abgleichen(PDO $pdo, int $task_id, int $haupt, array $soll): void
{
    if ($task_id <= 0) return;

    $soll = array_values(array_unique(array_filter(array_map('intval', $soll), fn($id) => $id > 0)));
    if ($haupt > 0) array_unshift($soll, $haupt);
    $soll = array_values(array_unique($soll));

    $ist_st = $pdo->prepare("SELECT contact_id FROM task_contacts WHERE task_id = ?");
    $ist_st->execute([$task_id]);
    $ist = array_map('intval', $ist_st->fetchAll(PDO::FETCH_COLUMN));

    foreach (array_diff($ist, $soll) as $weg) {
        $pdo->prepare("DELETE FROM task_contacts WHERE task_id = ? AND contact_id = ?")
            ->execute([$task_id, $weg]);
    }
    foreach (array_diff($soll, $ist) as $neu) {
        $pdo->prepare("INSERT IGNORE INTO task_contacts (task_id, contact_id, role) VALUES (?, ?, 'member')")
            ->execute([$task_id, $neu]);
    }

    $pdo->prepare("UPDATE task_contacts SET role = 'member' WHERE task_id = ?")->execute([$task_id]);
    if ($haupt > 0) {
        $pdo->prepare("UPDATE task_contacts SET role = 'owner' WHERE task_id = ? AND contact_id = ?")
            ->execute([$task_id, $haupt]);
    }
}

/**
 * Die Auswahlliste: je Person ein Kästchen.
 *
 * Vorher stand hier ein <select multiple>. Das verlangt gedrückte Strg-
 * oder Befehlstaste, zeigt nicht an, dass es das verlangt, und ist auf
 * einem Berührungsbildschirm gar nicht zu bedienen - ein Fingertipp
 * verwirft dort die ganze bisherige Auswahl.
 *
 * Das Suchfeld darüber ist kein Beiwerk: die Liste zeigt alle Kontakte,
 * und ab ein paar Dutzend ist Scrollen keine Auswahl mehr. Es filtert im
 * Browser und sendet nichts.
 *
 * $praefix trennt die beiden Fenster: ihre Kästchen stehen gleichzeitig
 * im Dokument und brauchen eigene id-Werte.
 *
 * Gruppiert nach Kontakttyp (Kunden, Geschäftspartner, Interessenten):
 * vorher stand ein Geschäftspartner zwischen zwei Kunden, und nur ein
 * Kürzel in der Zeile unterschied ihn. Leere Gruppen fehlen.
 */
function task_members_auswahl(array $kontakte, string $praefix): string
{
    $h = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES);

    // Gruppen in fester Reihenfolge. Ein unbekannter Typ zaehlt zu den
    // Kunden - das ist auch der Standardwert der Spalte.
    $gruppen = [
        'Kunde'            => ['titel' => te('Kunden'),           'zeilen' => ''],
        'Geschäftspartner' => ['titel' => te('Geschäftspartner'), 'zeilen' => ''],
        'Interessent'      => ['titel' => te('Interessenten'),    'zeilen' => ''],
    ];

    foreach ($kontakte as $k) {
        $id  = (int) $k['id'];
        $typ = (string) ($k['contact_type'] ?? '');
        if (!isset($gruppen[$typ])) $typ = 'Kunde';
        $teile = [];
        if (!empty($k['company']))                          $teile[] = $h($k['company']);
        if ($typ === 'Geschäftspartner')                    $teile[] = te('Partner');
        // Ohne Portal-Zugang sieht die Person nichts - das gehoert an die
        // Stelle, an der man sie auswaehlt, nicht in eine Fussnote.
        if (empty($k['portal_token']))                      $teile[] = te('kein Portal-Zugang');

        $gruppen[$typ]['zeilen'] .= '<label class="member-row" data-member-row>'
                 . '<input class="form-check-input" type="checkbox" name="member_ids[]"'
                 . ' value="' . $id . '" id="' . $h($praefix) . '_m' . $id . '">'
                 . '<span class="member-text">'
                 . '<span class="member-name" data-member-name>' . $h($k['name']) . '</span>'
                 . ($teile ? '<span class="member-meta">' . implode(' · ', $teile) . '</span>' : '')
                 . '</span>'
                 . '<span class="member-owner-tag" hidden>' . te('Hauptansprechpartner') . '</span>'
                 . '</label>';
    }

    $zeilen = '';
    foreach ($gruppen as $g) {
        if ($g['zeilen'] === '') continue;
        $zeilen .= '<div class="member-group" data-member-group>' . $g['titel'] . '</div>' . $g['zeilen'];
    }

    if ($zeilen === '') {
        $zeilen = '<p class="text-muted small m-0 p-2">' . te('Keine Kontakte vorhanden.') . '</p>';
    }

    return '<div class="member-picker" data-member-picker>'
         . '<input type="search" class="form-control form-control-sm member-filter"'
         . ' data-member-filter placeholder="' . te('Person suchen …') . '"'
         . ' aria-label="' . te('Person suchen …') . '" autocomplete="off">'
         . '<div class="member-list" data-member-list>' . $zeilen . '</div>'
         . '<p class="member-empty text-muted small m-0 p-2" hidden>' . te('Niemand gefunden.') . '</p>'
         . '</div>';
}

/**
 * Bringt die Zuständigen eines Projekts auf den übergebenen Stand.
 *
 * Dasselbe Verfahren wie task_members_abgleichen(): Abgleich statt
 * Neuschreiben, der Lead ist immer dabei, die Rolle wird jedes Mal neu
 * gesetzt. Dazu kommt der Spiegel: tasks.assigned_user_id (Migration 18)
 * bleibt bestehen und trägt den Lead, damit der Seed gültig bleibt und
 * jeder Leser der alten Spalte den richtigen Wert sieht.
 *
 * Unbekannte Benutzer werden vorher aussortiert. INSERT IGNORE übergeht
 * doppelte Schlüssel, aber nicht auf jeder Datenbank auch einen
 * fehlenden Fremdschlüssel - und ein Fehler mitten im Abgleich
 * hinterließe einen halben Stand.
 *
 * @param int   $lead Benutzer-ID des Hauptzuständigen, 0 wenn keiner.
 * @param array $soll Benutzer-IDs der weiteren Zuständigen, beliebig roh.
 */
function task_users_abgleichen(PDO $pdo, int $task_id, int $lead, array $soll): void
{
    if ($task_id <= 0) return;

    $soll = array_values(array_unique(array_filter(array_map('intval', $soll), fn($id) => $id > 0)));
    if ($lead > 0) array_unshift($soll, $lead);
    $soll = array_values(array_unique($soll));

    if ($soll) {
        $ph = implode(',', array_fill(0, count($soll), '?'));
        $st = $pdo->prepare("SELECT id FROM users WHERE id IN ($ph)");
        $st->execute($soll);
        $bekannt = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
        $soll = array_values(array_intersect($soll, $bekannt));
    }
    if ($lead > 0 && !in_array($lead, $soll, true)) $lead = 0;

    $ist_st = $pdo->prepare("SELECT user_id FROM task_users WHERE task_id = ?");
    $ist_st->execute([$task_id]);
    $ist = array_map('intval', $ist_st->fetchAll(PDO::FETCH_COLUMN));

    foreach (array_diff($ist, $soll) as $weg) {
        $pdo->prepare("DELETE FROM task_users WHERE task_id = ? AND user_id = ?")
            ->execute([$task_id, $weg]);
    }
    foreach (array_diff($soll, $ist) as $neu) {
        $pdo->prepare("INSERT IGNORE INTO task_users (task_id, user_id, role) VALUES (?, ?, 'member')")
            ->execute([$task_id, $neu]);
    }

    $pdo->prepare("UPDATE task_users SET role = 'member' WHERE task_id = ?")->execute([$task_id]);
    if ($lead > 0) {
        $pdo->prepare("UPDATE task_users SET role = 'lead' WHERE task_id = ? AND user_id = ?")
            ->execute([$task_id, $lead]);
    }
    // Der Spiegel in tasks: wer die alte Spalte liest, sieht den Lead.
    $pdo->prepare("UPDATE tasks SET assigned_user_id = ? WHERE id = ?")
        ->execute([$lead > 0 ? $lead : null, $task_id]);
}

/**
 * Die Auswahlliste der Zuständigen: je Benutzer ein Kästchen.
 *
 * Gleiches Markup wie die Kontaktauswahl, damit Suche und Sperre im
 * Browser für beide gelten. Abgeschaltete Benutzer stehen mit
 * Kennzeichen in der Liste: eine bestehende Zuordnung soll sichtbar
 * bleiben und sich entfernen lassen.
 */
function task_users_auswahl(array $benutzer, string $praefix): string
{
    $h = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES);

    $zeilen = '';
    foreach ($benutzer as $u) {
        $id    = (int) $u['id'];
        $teile = [];
        $rolle = rollen()[(string) ($u['role'] ?? '')]['label'] ?? '';
        if ($rolle !== '')                       $teile[] = $h(datenwert($rolle));
        if ((int) ($u['is_active'] ?? 1) !== 1)  $teile[] = te('inaktiv');

        $zeilen .= '<label class="member-row" data-member-row>'
                 . '<input class="form-check-input" type="checkbox" name="user_ids[]"'
                 . ' value="' . $id . '" id="' . $h($praefix) . '_u' . $id . '">'
                 . '<span class="member-text">'
                 . '<span class="member-name" data-member-name>' . $h(benutzer_anzeige($u)) . '</span>'
                 . ($teile ? '<span class="member-meta">' . implode(' · ', $teile) . '</span>' : '')
                 . '</span>'
                 . '<span class="member-owner-tag" hidden>' . te('Hauptzuständig') . '</span>'
                 . '</label>';
    }

    if ($zeilen === '') {
        $zeilen = '<p class="text-muted small m-0 p-2">' . te('Keine Benutzer vorhanden.') . '</p>';
    }

    return '<div class="member-picker" data-member-picker>'
         . '<input type="search" class="form-control form-control-sm member-filter"'
         . ' data-member-filter placeholder="' . te('Benutzer suchen …') . '"'
         . ' aria-label="' . te('Benutzer suchen …') . '" autocomplete="off">'
         . '<div class="member-list" data-member-list>' . $zeilen . '</div>'
         . '<p class="member-empty text-muted small m-0 p-2" hidden>' . te('Niemand gefunden.') . '</p>'
         . '</div>';
}
