<?php
/**
 * Datenauskunft zu einem Kontakt (Art. 15 DSGVO).
 *
 * Wer fragt, was über ihn gespeichert ist, hat Anspruch auf eine
 * vollständige Antwort — und zwar auf eine, die sich in einem Monat
 * geben lässt. Bis hierher hätte das geheißen: neun Tabellen von Hand
 * durchsehen und hoffen, keine zu vergessen. Genau dabei vergisst man
 * eine.
 *
 * Gesammelt wird über zwei Wege, weil ein Kontakt auf zwei Arten
 * auftaucht:
 *
 *   - über seine Kennung (contact_id und die Sonderfälle
 *     author_contact_id, uploaded_by_contact_id, feedback_by_contact_id)
 *   - über seine E-Mail-Adresse, denn im Posteingang steht eine Anfrage,
 *     bevor daraus ein Kontakt wird
 *
 * Was NICHT enthalten ist, steht in der Ausgabe selbst: das
 * Systemprotokoll. Dort stehen Namen in Freitext ("Neue Anfrage von
 * Anna Beispiel ins CRM übernommen"), nicht als Verweis — eine
 * Volltextsuche darüber würde Treffer erfinden und echte übersehen.
 * Ehrlicher ist der Hinweis, dass dort nachzusehen ist.
 */

/**
 * Die Abfragen, aus denen sich die Auskunft zusammensetzt.
 *
 * Als Tabelle und nicht als Folge von Aufrufen, damit eine neue
 * Beziehung an einer Stelle nachgetragen wird — und damit sichtbar
 * bleibt, was alles zu einem Menschen gespeichert wird.
 *
 * @return array<string, array{titel:string, sql:string, feld:string}>
 */
function auskunft_abfragen(): array
{
    return [
        'stammdaten' => [
            'titel' => 'Stammdaten',
            'sql'   => 'SELECT * FROM contacts WHERE id = :id',
            'feld'  => 'id',
        ],
        'projekte' => [
            'titel' => 'Projekte als Kunde',
            'sql'   => 'SELECT id, title, category, description, status, start_date, deadline,
                               client_feedback, feedback_at, created_at, deleted_at
                          FROM tasks WHERE contact_id = :id ORDER BY created_at',
            'feld'  => 'id',
        ],
        'projektbeteiligung' => [
            'titel' => 'Beteiligung an weiteren Projekten',
            'sql'   => 'SELECT tc.task_id, tc.role, t.title
                          FROM task_contacts tc
                          JOIN tasks t ON t.id = tc.task_id
                         WHERE tc.contact_id = :id ORDER BY tc.task_id',
            'feld'  => 'id',
        ],
        'projektbeitraege' => [
            'titel' => 'Beiträge im Projektaustausch',
            'sql'   => 'SELECT id, task_id, author_name, message, created_at
                          FROM project_comments WHERE author_contact_id = :id ORDER BY created_at',
            'feld'  => 'id',
        ],
        'dateien' => [
            'titel' => 'Über das Portal hochgeladene Dateien',
            'sql'   => 'SELECT id, task_id, file_name, uploaded_by_name, uploaded_at
                          FROM client_assets WHERE uploaded_by_contact_id = :id ORDER BY uploaded_at',
            'feld'  => 'id',
        ],
        'rechnungen' => [
            'titel' => 'Rechnungen und Ausgaben',
            'sql'   => 'SELECT id, type, title, invoice_number, amount, status,
                               record_date, due_date, notes, created_at, deleted_at
                          FROM finances WHERE contact_id = :id ORDER BY record_date',
            'feld'  => 'id',
        ],
        'angebote' => [
            'titel' => 'Angebote',
            'sql'   => 'SELECT id, quote_number, subject, status, total_amount,
                               valid_until, created_at, deleted_at
                          FROM quotes WHERE contact_id = :id ORDER BY created_at',
            'feld'  => 'id',
        ],
        'anfragen' => [
            'titel' => 'Supportanfragen',
            'sql'   => 'SELECT id, subject, message, status, priority, created_at
                          FROM support_tickets WHERE contact_id = :id ORDER BY created_at',
            'feld'  => 'id',
        ],
        'termine' => [
            'titel' => 'Termine mit Einladung',
            'sql'   => 'SELECT ce.id, ce.title, ce.event_date, ce.start_time, ce.location, ec.invited_at
                          FROM event_contacts ec
                          JOIN calendar_events ce ON ce.id = ec.event_id
                         WHERE ec.contact_id = :id ORDER BY ce.event_date',
            'feld'  => 'id',
        ],
        'wissen' => [
            'titel' => 'Freigegebene Wiki-Artikel',
            'sql'   => 'SELECT wa.id, wa.title
                          FROM wiki_client_shares ws
                          JOIN wiki_articles wa ON wa.id = ws.article_id
                         WHERE ws.contact_id = :id ORDER BY wa.title',
            'feld'  => 'id',
        ],
        'posteingang' => [
            'titel' => 'Anfragen über das Kontaktformular',
            'sql'   => 'SELECT id, name, email, phone, subject, message, source, created_at
                          FROM leads_inbox WHERE email = :email ORDER BY created_at',
            'feld'  => 'email',
        ],
    ];
}

/**
 * Trägt alles zusammen, was zu einem Kontakt gespeichert ist.
 *
 * @return array|null null, wenn es den Kontakt nicht gibt
 */
function auskunft_daten(PDO $pdo, int $kontakt_id): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM contacts WHERE id = ?');
    $stmt->execute([$kontakt_id]);
    $kontakt = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$kontakt) {
        return null;
    }

    $aus = [
        'erstellt_am' => date('c'),
        'hinweis'     => 'Auskunft nach Art. 15 DSGVO. Enthalten sind auch Datensätze, '
                       . 'die im Papierkorb liegen - sie sind bis zum endgültigen '
                       . 'Entfernen weiterhin gespeichert und an deleted_at zu '
                       . 'erkennen. Nicht enthalten ist das Systemprotokoll: dort '
                       . 'stehen Namen als Freitext und nicht als Verweis, eine '
                       . 'automatische Zuordnung wäre unzuverlässig. Es ist '
                       . 'gegebenenfalls von Hand zu durchsuchen.',
        'kontakt'     => ['id' => $kontakt_id, 'name' => $kontakt['name'] ?? ''],
        'bereiche'    => [],
    ];

    $email = trim((string) ($kontakt['email'] ?? ''));

    foreach (auskunft_abfragen() as $schluessel => $def) {
        // Ohne Adresse gibt es den Weg über die Adresse nicht — und eine
        // Abfrage mit leerem Wert träfe jeden Datensatz ohne Adresse.
        if ($def['feld'] === 'email' && $email === '') {
            $aus['bereiche'][$schluessel] = [
                'titel'  => $def['titel'],
                'anzahl' => 0,
                'hinweis' => 'Kein Eintrag: zu diesem Kontakt ist keine E-Mail-Adresse gespeichert.',
                'zeilen' => [],
            ];
            continue;
        }

        try {
            $s = $pdo->prepare($def['sql']);
            $s->execute($def['feld'] === 'email' ? [':email' => $email] : [':id' => $kontakt_id]);
            $zeilen = $s->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            // Eine Auskunft, in der ein Bereich fehlt, ohne dass es
            // dasteht, wäre schlimmer als eine mit einem Fehlerhinweis.
            error_log('Auskunft: Bereich ' . $schluessel . ' fehlgeschlagen: ' . $e->getMessage());
            $aus['bereiche'][$schluessel] = [
                'titel'   => $def['titel'],
                'anzahl'  => 0,
                'hinweis' => 'Dieser Bereich konnte nicht gelesen werden. Bitte im '
                           . 'Fehlerprotokoll des Servers nachsehen.',
                'zeilen'  => [],
            ];
            continue;
        }

        $aus['bereiche'][$schluessel] = [
            'titel'  => $def['titel'],
            'anzahl' => count($zeilen),
            'zeilen' => $zeilen,
        ];
    }

    return $aus;
}

/** Dateiname der Auskunft. */
function auskunft_dateiname(array $daten): string
{
    $name = (string) ($daten['kontakt']['name'] ?? 'Kontakt');
    // Nur Unbedenkliches: der Name landet in einem Content-Disposition.
    $name = preg_replace('/[^A-Za-z0-9ÄÖÜäöüß _-]/u', '', $name) ?? '';
    $name = trim(preg_replace('/\s+/', '_', $name) ?? '');
    if ($name === '') {
        $name = 'Kontakt';
    }
    return 'Auskunft_' . $name . '_' . date('Y-m-d') . '.json';
}

/** Die Auskunft als JSON, lesbar formatiert. */
function auskunft_json(array $daten): string
{
    return (string) json_encode(
        $daten,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
}

/**
 * Wie viele Datensätze insgesamt zusammenkommen.
 *
 * Für die Anzeige: eine Auskunft ohne einen einzigen Treffer ist
 * ungewöhnlich genug, um sie vor dem Verschicken anzusehen.
 */
function auskunft_umfang(array $daten): int
{
    $summe = 0;
    foreach ($daten['bereiche'] ?? [] as $bereich) {
        $summe += (int) ($bereich['anzahl'] ?? 0);
    }
    return $summe;
}
