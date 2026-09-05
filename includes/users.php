<?php
/**
 * Mehrere Benutzer, mit Rollen.
 *
 * Die README versprach ein Panel "for freelancers **and small
 * agencies**". `users` hatte vier Spalten: id, email, password_hash,
 * created_at. Kein Name, keine Rolle, kein Zustand. Angelegt wurde ein
 * Benutzer nur beim allerersten Start; eine Oberfläche zum Anlegen eines
 * zweiten gab es nicht. `logs` hielt fest, *dass* etwas geschah, nie
 * durch wen. Das Panel war für genau eine Person.
 *
 * ── Drei Rollen, keine Rechtematrix ────────────────────────────────
 * Eine frei zusammenstellbare Rechteverwaltung wäre für ein Werkzeug
 * dieser Größe zu viel: sie kostet eine eigene Oberfläche, und in der
 * Praxis stellt sie niemand um. Drei Rollen decken ab, wofür man in
 * einem kleinen Büro tatsächlich trennt:
 *
 *   - Verwaltung   sieht alles, einschließlich Einstellungen.
 *   - Mitarbeit    arbeitet an Projekten, sieht keine Finanzen.
 *   - Buchhaltung  sieht Finanzen und Auswertungen, keine Projekte.
 *
 * Die Zuordnung steht als Liste im Code, nicht in der Datenbank. Das
 * heißt: eine neue Seite muss hier eingetragen werden, sonst ist sie
 * gesperrt. Das ist die richtige Richtung — eine vergessene Seite ist
 * dann unerreichbar und nicht versehentlich offen.
 */

require_once __DIR__ . '/logging.php';

/**
 * Die Rollen und was sie bedeuten.
 *
 * @return array<string, array{label: string, hint: string}>
 */
function rollen(): array
{
    return [
        'admin' => [
            'label' => 'Verwaltung',
            'hint'  => 'Sieht und ändert alles, einschließlich Einstellungen und Benutzern.',
        ],
        'staff' => [
            'label' => 'Mitarbeit',
            'hint'  => 'Projekte, Aufgaben, Kontakte, Tickets, Wiki und Kalender. Keine Finanzen, keine Einstellungen.',
        ],
        'accounting' => [
            'label' => 'Buchhaltung',
            'hint'  => 'Finanzen, Angebote, Auswertungen und Kontakte. Keine Projekte, keine Einstellungen.',
        ],
    ];
}

/** Ist das eine Rolle, die wir kennen? */
function rolle_gueltig(string $rolle): bool
{
    return isset(rollen()[$rolle]);
}

/**
 * Welche Rolle darf welche Seite sehen.
 *
 * Eine Seite, die hier fehlt, ist für alle außer der Verwaltung
 * gesperrt — siehe seite_erlaubt(). Lieber eine vergessene Seite, die
 * niemand erreicht, als eine, die versehentlich offen steht.
 *
 * @return array<string, array<int, string>>
 */
function seitenrechte_vorgabe(): array
{
    return [
        // Für alle: der Einstieg und die Zeitplanung.
        'index.php'      => ['admin', 'staff', 'accounting'],
        'calendar.php'   => ['admin', 'staff', 'accounting'],
        'contacts.php'   => ['admin', 'staff', 'accounting'],
        'ajax_poll.php'  => ['admin', 'staff', 'accounting'],
        'ajax_search.php'=> ['admin', 'staff', 'accounting'],
        'event_ics.php'  => ['admin', 'staff', 'accounting'],

        // Die Arbeit.
        'tasks.php'      => ['admin', 'staff'],
        'board.php'      => ['admin', 'staff'],
        'wiki.php'       => ['admin', 'staff'],
        'tickets.php'    => ['admin', 'staff'],

        // Das Geld.
        'finances.php'   => ['admin', 'accounting'],
        'quotes.php'     => ['admin', 'accounting'],
        'invoice.php'    => ['admin', 'accounting'],
        'reports.php'    => ['admin', 'accounting'],

        // Alles Übrige bleibt der Verwaltung: Einstellungen, Protokoll,
        // Papierkorb.
        'settings.php'   => ['admin'],
        'systemlogs.php' => ['admin'],
        'trash.php'      => ['admin'],
    ];
}

/**
 * Seiten, deren Zuordnung sich nicht ändern lässt.
 *
 * settings.php bleibt der Verwaltung vorbehalten, und zwar zwingend:
 * wer die Einstellungen öffnen darf, darf auch diese Matrix ändern und
 * sich damit jedes weitere Recht selbst geben. Eine Rechteverwaltung,
 * die ihre eigene Freigabe zur Wahl stellt, verwaltet nichts.
 */
const SEITEN_FEST = ['settings.php'];

/**
 * Die geltende Zuordnung: Vorgabe, überlagert von der Einstellung.
 *
 * Gespeichert wird als JSON unter 'page_roles'. Überlagert wird nur,
 * was dort steht und in der Vorgabe vorkommt - eine später
 * hinzugekommene Seite behält damit ihre Vorgabe, statt durch eine
 * veraltete gespeicherte Matrix auf 'niemand' zu fallen.
 */
function seitenrechte(): array
{
    static $zwischenspeicher = null;
    if ($zwischenspeicher !== null) {
        return $zwischenspeicher;
    }

    $rechte = seitenrechte_vorgabe();

    // setting() gibt es nur, wenn config.php geladen ist. Die Tests
    // binden users.php einzeln ein.
    $roh = function_exists('setting') ? trim(setting('page_roles', '')) : '';
    if ($roh === '') {
        return $zwischenspeicher = $rechte;
    }

    $gespeichert = json_decode($roh, true);
    if (!is_array($gespeichert)) {
        error_log('page_roles ist kein gültiges JSON - Vorgabe bleibt in Kraft.');
        return $zwischenspeicher = $rechte;
    }

    $zwischenspeicher = seitenrechte_zusammenfuehren($rechte, $gespeichert);
    return $zwischenspeicher;
}

/**
 * Legt eine gespeicherte Matrix über die Vorgabe.
 *
 * Getrennt von seitenrechte(), weil hier die Schranken sitzen und die
 * sich ohne Datenbank prüfen lassen sollen.
 */
function seitenrechte_zusammenfuehren(array $vorgabe, array $gespeichert): array
{
    foreach ($gespeichert as $seite => $rollen) {
        // Nur bekannte Seiten, und nichts Festgeschriebenes.
        if (!isset($vorgabe[$seite]) || in_array($seite, SEITEN_FEST, true)) {
            continue;
        }
        if (!is_array($rollen)) {
            continue;
        }

        $gueltig = [];
        foreach ($rollen as $r) {
            if (is_string($r) && rolle_gueltig($r) && !in_array($r, $gueltig, true)) {
                $gueltig[] = $r;
            }
        }

        // Die Verwaltung steht immer drin. seite_erlaubt() lässt sie
        // ohnehin überall durch; sie hier wegzulassen hieße, eine
        // Zuordnung zu speichern, die etwas anderes behauptet als gilt.
        if (!in_array('admin', $gueltig, true)) {
            array_unshift($gueltig, 'admin');
        }

        $vorgabe[$seite] = $gueltig;
    }

    return $vorgabe;
}

/**
 * Prüft eine Matrix aus dem Formular und macht daraus JSON.
 *
 * @return string JSON, oder '' wenn sie der Vorgabe entspricht - dann
 *                zieht die Einstellung künftige Änderungen an der
 *                Vorgabe automatisch mit.
 */
function seitenrechte_speicherform(array $eingabe): string
{
    $vorgabe = seitenrechte_vorgabe();
    $neu     = seitenrechte_zusammenfuehren($vorgabe, $eingabe);

    if ($neu === $vorgabe) {
        return '';
    }
    return (string) json_encode($neu, JSON_UNESCAPED_SLASHES);
}

/**
 * Darf diese Rolle diese Seite sehen?
 *
 * Eine unbekannte Seite ist gesperrt, außer für die Verwaltung. So ist
 * eine neu hinzugefügte Seite zunächst nur für sie sichtbar, statt für
 * alle — und der Fehler fällt beim ersten Aufruf auf, nicht in einer
 * Datenschutzfrage später.
 */
function seite_erlaubt(string $rolle, string $seite): bool
{
    if ($rolle === 'admin') {
        return true;
    }
    $rechte = seitenrechte()[$seite] ?? null;
    if ($rechte === null) {
        return false;
    }
    return in_array($rolle, $rechte, true);
}

/** Die Rolle aus der laufenden Sitzung, sonst '' . */
function aktuelle_rolle(): string
{
    $rolle = (string) ($_SESSION['admin_role'] ?? '');

    return rolle_gueltig($rolle) ? $rolle : '';
}

/** Ist der Angemeldete Verwaltung? */
function ist_verwaltung(): bool
{
    return aktuelle_rolle() === 'admin';
}

// ---------------------------------------------------------------------
// Datenbank
// ---------------------------------------------------------------------

/**
 * Alle Benutzer, für die Verwaltungsliste.
 */
function benutzer_liste(PDO $pdo): array
{
    return $pdo->query(
        'SELECT id, email, name, role, is_active, created_at,
                (totp_secret IS NOT NULL AND totp_confirmed_at IS NOT NULL) AS totp
           FROM users ORDER BY role ASC, email ASC'
    )->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/** Ein Benutzer, oder null. */
function benutzer(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM users WHERE id = ?');
    $stmt->execute([$id]);
    $zeile = $stmt->fetch(PDO::FETCH_ASSOC);

    return $zeile ?: null;
}

/**
 * Wie viele aktive Verwalter gibt es?
 *
 * Grundlage für die einzige Regel, die dieses Modell unbedingt braucht:
 * der letzte darf sich nicht selbst entfernen oder herabstufen. Sonst
 * bleibt eine Installation ohne jemanden, der Benutzer anlegen kann —
 * und der Weg zurück führt nur über die Datenbank.
 */
function anzahl_verwalter(PDO $pdo, ?int $ausser = null): int
{
    $sql = "SELECT COUNT(*) FROM users WHERE role = 'admin' AND is_active = 1";
    $werte = [];
    if ($ausser !== null) {
        $sql .= ' AND id <> ?';
        $werte[] = $ausser;
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($werte);

    return (int) $stmt->fetchColumn();
}

/**
 * Darf dieser Benutzer geändert oder abgeschaltet werden?
 *
 * Nein, wenn er der letzte aktive Verwalter ist und die Änderung ihn
 * aus dieser Rolle nähme.
 */
function letzter_verwalter(PDO $pdo, int $user_id): bool
{
    $u = benutzer($pdo, $user_id);
    if (!$u || $u['role'] !== 'admin' || (int) $u['is_active'] !== 1) {
        return false;
    }
    return anzahl_verwalter($pdo, $user_id) === 0;
}

/**
 * Legt einen Benutzer an.
 *
 * Ohne Passwort: der neue Benutzer bekommt einen Rücksetzlink und wählt
 * es selbst. Ein vom Verwalter vergebenes Passwort müsste über einen
 * Kanal übermittelt werden, der es preisgibt — und würde erfahrungsgemäß
 * nie geändert.
 *
 * @return array{ok: bool, id: int, fehler: string}
 */
function benutzer_anlegen(PDO $pdo, string $email, string $name, string $rolle): array
{
    $email = trim($email);
    $name  = trim($name);

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'id' => 0, 'fehler' => 'Keine gültige E-Mail-Adresse.'];
    }
    if (!rolle_gueltig($rolle)) {
        return ['ok' => false, 'id' => 0, 'fehler' => 'Unbekannte Rolle.'];
    }

    $stmt = $pdo->prepare('SELECT COUNT(*) FROM users WHERE email = ?');
    $stmt->execute([$email]);
    if ((int) $stmt->fetchColumn() > 0) {
        return ['ok' => false, 'id' => 0, 'fehler' => 'Diese Adresse ist schon vergeben.'];
    }

    // Ein Platzhalter, mit dem sich niemand anmelden kann: der Hash
    // gehört zu keinem gewählten Passwort. Der Weg hinein führt über
    // "Passwort vergessen".
    $platzhalter = password_hash(bin2hex(random_bytes(32)), PASSWORD_BCRYPT, ['cost' => 10]);

    $pdo->prepare(
        'INSERT INTO users (email, password_hash, name, role, is_active) VALUES (?, ?, ?, ?, 1)'
    )->execute([$email, $platzhalter, mb_substr($name, 0, 255), $rolle]);

    $id = (int) $pdo->lastInsertId();

    log_event($pdo, 'USER_CREATED', 'Benutzer angelegt: ' . $email . ' (' . $rolle . ').');

    return ['ok' => true, 'id' => $id, 'fehler' => ''];
}

/**
 * Ändert Name und Rolle.
 *
 * @return array{ok: bool, fehler: string}
 */
function benutzer_aendern(PDO $pdo, int $id, string $name, string $rolle): array
{
    if (!rolle_gueltig($rolle)) {
        return ['ok' => false, 'fehler' => 'Unbekannte Rolle.'];
    }
    $u = benutzer($pdo, $id);
    if (!$u) {
        return ['ok' => false, 'fehler' => 'Benutzer nicht gefunden.'];
    }

    // Der letzte Verwalter bleibt Verwalter. Sonst stünde die
    // Installation ohne jemanden da, der Benutzer anlegen kann.
    if ($rolle !== 'admin' && letzter_verwalter($pdo, $id)) {
        return ['ok' => false, 'fehler' => 'Das ist der letzte Verwalter – die Rolle lässt sich nicht ändern.'];
    }

    $pdo->prepare('UPDATE users SET name = ?, role = ? WHERE id = ?')
        ->execute([mb_substr(trim($name), 0, 255), $rolle, $id]);

    log_event($pdo, 'USER_UPDATED', 'Benutzer ' . $u['email'] . ' geändert (Rolle: ' . $rolle . ').');

    return ['ok' => true, 'fehler' => ''];
}

/**
 * Schaltet einen Benutzer ab oder wieder an.
 *
 * Abschalten statt löschen: an einem Benutzer hängen Protokolleinträge
 * und erfasste Zeiten. Wer geht, soll sich nicht mehr anmelden können —
 * seine Spuren bleiben aber lesbar, sonst fehlt später die halbe
 * Nachvollziehbarkeit.
 *
 * @return array{ok: bool, fehler: string}
 */
function benutzer_umschalten(PDO $pdo, int $id, bool $aktiv): array
{
    $u = benutzer($pdo, $id);
    if (!$u) {
        return ['ok' => false, 'fehler' => 'Benutzer nicht gefunden.'];
    }
    if (!$aktiv && letzter_verwalter($pdo, $id)) {
        return ['ok' => false, 'fehler' => 'Das ist der letzte Verwalter – er lässt sich nicht abschalten.'];
    }

    $pdo->prepare('UPDATE users SET is_active = ? WHERE id = ?')->execute([$aktiv ? 1 : 0, $id]);

    log_event(
        $pdo,
        $aktiv ? 'USER_ENABLED' : 'USER_DISABLED',
        'Benutzer ' . $u['email'] . ($aktiv ? ' wieder freigeschaltet.' : ' abgeschaltet.')
    );

    return ['ok' => true, 'fehler' => ''];
}

/**
 * Der Anzeigename eines Benutzers.
 *
 * Der Name, sonst der Teil der Adresse vor dem @. "anna" ist immer noch
 * besser als "anna@beispiel-firma-gmbh.example".
 */
function benutzer_anzeige(array $user): string
{
    $name = trim((string) ($user['name'] ?? ''));
    if ($name !== '') {
        return $name;
    }
    $email = (string) ($user['email'] ?? '');
    $at = strpos($email, '@');

    return $at === false ? $email : substr($email, 0, $at);
}
