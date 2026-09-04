<?php
/**
 * Die Demodaten selbst.
 *
 * Erwartet ein verbundenes $pdo und die Helfer aus seed_demo_lib.php.
 * Wird von tools/seed_demo.php (gegen MySQL) und von
 * tools/test_seed_demo.php (gegen SQLite) eingebunden.
 */


// Beim Aufruf über tools/seed_demo.php ist $start bereits gesetzt; beim
// Testlauf über tools/test_seed_demo.php nicht.
$start = $start ?? microtime(true);

// ── Tabellen leeren ─────────────────────────────────────────────────
$leeren = [
    'event_contacts', 'calendar_events', 'wiki_client_shares', 'wiki_attachments',
    'wiki_articles', 'ticket_notes', 'support_tickets', 'quotes', 'finances',
    'time_entries', 'client_assets', 'project_comments', 'milestone_comments',
    'task_contacts', 'task_milestones', 'tasks', 'leads_inbox', 'contacts',
    'monitored_urls', 'sso_tokens', 'logs', 'users',
];
$pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
foreach ($leeren as $t) {
    $pdo->exec('TRUNCATE TABLE ' . $t);
}
$pdo->exec('SET FOREIGN_KEY_CHECKS = 1');

// settings bleibt bestehen: dort steht schema_version, und ohne die
// läuft run_migrations() beim nächsten Seitenaufruf noch einmal los.
$pdo->exec("DELETE FROM settings WHERE k <> 'schema_version'");
echo "  Tabellen geleert\n";

// ── Einstellungen ───────────────────────────────────────────────────
// Ein erfundenes Unternehmen. Die Demo darf nirgends nach einer echten
// Firma aussehen - weder in der Kopfzeile noch auf einer Rechnung.
$einstellungen = [
    'company_name'    => 'Musterwerk Digital',
    'company_short'   => 'Musterwerk',
    'company_street'  => 'Lindenallee 27',
    'company_zip'     => '04109',
    'company_city'    => 'Leipzig',
    'company_country' => 'Deutschland',
    'company_email'   => 'hallo@musterwerk.example',
    'company_phone'   => '+49 341 5550110',
    'company_website' => 'https://musterwerk.example',
    'company_tax_id'  => 'DE000000000',
    'bank_name'       => 'Musterbank Leipzig',
    'bank_iban'       => 'DE02120300000000202051',
    'bank_bic'        => 'BYLADEM1001',
    'admin_email'     => 'hallo@musterwerk.example',
    'support_email'   => 'support@musterwerk.example',
    'log_limit'       => '200',
    // Die Demo startet auf Englisch. Wer umschaltet, aendert das nur
    // fuer seine eigene Sitzung - der naechste Besucher faengt wieder
    // hier an.
    'ui_language'     => 'en',
];
foreach ($einstellungen as $sk => $sv) {
    $pdo->prepare('INSERT INTO settings (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v)')
        ->execute([$sk, $sv]);
}
echo "  Einstellungen gesetzt\n";

// ── Benutzer ────────────────────────────────────────────────────────
// Im Demo-Modus wird nie ein Passwort geprüft; der Datensatz existiert
// nur, damit auth_is_first_run() nicht den Einrichtungsdialog auslöst.
// Der Hash gehört absichtlich zu keinem bekannten Passwort.
ins('users', [
    'email'         => 'demo@musterwerk.example',
    'password_hash' => password_hash(bin2hex(random_bytes(24)), PASSWORD_DEFAULT),
    'created_at'    => zeit(-420, '08:00'),
]);
echo "  Benutzer angelegt\n";

// ── Kontakte ────────────────────────────────────────────────────────
// Alle Namen, Firmen und Domains sind erfunden. .example ist laut
// RFC 2606 für genau diesen Zweck reserviert und kann niemandem gehören.
$pin_hash = password_hash(demo_portal_pin(), PASSWORD_DEFAULT);

$k_daten = [
    'hofmann' => [
        'name' => 'Lena Hofmann', 'company' => 'Hofmann & Partner Steuerberatung',
        'email' => 'l.hofmann@hofmann-partner.example', 'phone' => '+49 30 5550142',
        'website' => 'https://hofmann-partner.example',
        'street' => 'Kantstraße 14', 'zip' => '10623', 'city' => 'Berlin',
        'contact_type' => 'Kunde', 'source' => 'Empfehlung',
        'notes' => "Ansprechpartnerin für alle Web-Themen.\nRechnungen bitte gesammelt zum Quartalsende.",
        'tage' => -390,
    ],
    'brandt' => [
        'name' => 'Marco Brandt', 'company' => 'Brandt Elektrotechnik GmbH',
        'email' => 'm.brandt@brandt-elektro.example', 'phone' => '+49 341 5550187',
        'website' => 'https://brandt-elektro.example',
        'street' => 'Industriering 8', 'zip' => '04347', 'city' => 'Leipzig',
        'contact_type' => 'Kunde', 'source' => 'Website-Formular',
        'notes' => 'Firmierung laut Handelsregister: "Brandt Elektrotechnik GmbH & Co. KG". '
                 . 'Erreichbar meist nur vormittags, Freigaben laufen über das Portal.',
        'tage' => -350,
    ],
    'weiss' => [
        'name' => 'Sonja Weiß', 'company' => 'Weiß Naturkosmetik',
        'email' => 's.weiss@weiss-naturkosmetik.example', 'phone' => '+49 351 5550233',
        'website' => 'https://weiss-naturkosmetik.example',
        'street' => 'Bautzner Straße 61', 'zip' => '01099', 'city' => 'Dresden',
        'contact_type' => 'Kunde', 'source' => 'Messe',
        'notes' => "Shop-Projekt mit hoher Priorität.\nDie Versandkostenlogik ist der kritische Teil.",
        'tage' => -270,
    ],
    'krueger' => [
        'name' => 'Tobias Krüger', 'company' => 'Krüger Media',
        'email' => 't.krueger@krueger-media.example', 'phone' => '+49 40 5550319',
        'website' => 'https://krueger-media.example',
        'street' => 'Hafenstraße 3', 'zip' => '20359', 'city' => 'Hamburg',
        'contact_type' => 'Geschäftspartner', 'source' => 'Netzwerk',
        'notes' => 'Übernimmt Text und Fotografie. Rechnet direkt an den Endkunden ab.',
        'tage' => -320,
    ],
    'demir' => [
        'name' => 'Aylin Demir', 'company' => "Studio Demir & O'Neill",
        'email' => 'a.demir@studio-demir.example', 'phone' => '+49 89 5550478',
        'website' => 'https://studio-demir.example',
        'street' => 'Türkenstraße 52', 'zip' => '80799', 'city' => 'München',
        'contact_type' => 'Geschäftspartner', 'source' => 'Empfehlung',
        'notes' => 'Motion Design und Videoschnitt. Sehr zuverlässig bei Deadlines.',
        'tage' => -200,
    ],
    'sandmann' => [
        'name' => 'Peter Sandmann', 'company' => 'Sandmann Immobilien',
        'email' => 'p.sandmann@sandmann-immo.example', 'phone' => '+49 221 5550620',
        'website' => 'https://sandmann-immo.example',
        'street' => 'Ringstraße 90', 'zip' => '50667', 'city' => 'Köln',
        'contact_type' => 'Interessent', 'source' => 'Kaltakquise',
        'notes' => 'Budget für das kommende Jahr noch offen. Im Herbst erneut nachfassen.',
        'tage' => -45,
    ],
];

$k = [];   // Schlüssel => id
foreach ($k_daten as $schluessel => $d) {
    // Der Interessent hat noch keinen Portalzugang - so zeigt die Demo
    // beide Zustände: eingeladen und noch nicht eingeladen.
    $mit_portal = $schluessel !== 'sandmann';
    $k[$schluessel] = ins('contacts', [
        'name'         => $d['name'],
        'company'      => $d['company'],
        'email'        => $d['email'],
        'phone'        => $d['phone'],
        'website'      => $d['website'],
        'street'       => $d['street'],
        'zip'          => $d['zip'],
        'city'         => $d['city'],
        'country'      => 'Deutschland',
        'contact_type' => $d['contact_type'],
        'source'       => $d['source'],
        'notes'        => $d['notes'],
        'portal_token' => $mit_portal ? demo_token($schluessel) : null,
        'portal_pin'   => $mit_portal ? $pin_hash : null,
        'created_at'   => zeit($d['tage'], '10:15'),
    ]);
}
echo '  ' . count($k) . " Kontakte angelegt\n";

// ── Posteingang ─────────────────────────────────────────────────────
$leads = [
    ['Nina Alt', 'n.alt@altbau-planung.example', '+49 30 5550901', 'Anfrage Relaunch',
     "Guten Tag,\n\nwir planen für das kommende Jahr einen Relaunch unserer Website und suchen dafür Unterstützung. Können Sie uns ein grobes Budget nennen?\n\nViele Grüße\nNina Alt",
     'Kontaktformular', -3],
    ['Jonas Reimer', 'j.reimer@reimer-baeckerei.example', null, 'Onlineshop für Backwaren',
     "Hallo, wir möchten unsere Backwaren regional online anbieten - gern in einem 'schlanken', schnellen Auftritt. Ist so etwas mit Ihrem Shop-System möglich?",
     'Kontaktformular', -8],
    ['Claudia Berg', 'c.berg@bergpraxis.example', '+49 761 5550444', 'Terminbuchung einbinden',
     "Wir suchen eine Lösung, um Termine direkt auf der Praxisseite buchbar zu machen. Gerne ein kurzes Gespräch.",
     'Telefon', -16],
    ['Ruben Falk', 'r.falk@falk-consulting.example', null, 'Wartungsvertrag',
     "Können Sie bestehende Seiten auch nur warten, ohne sie neu zu entwickeln?",
     'Kontaktformular', -29],
];
foreach ($leads as [$ln, $le, $lt, $lb, $lm, $lq, $ltage]) {
    ins('leads_inbox', ['name' => $ln, 'email' => $le, 'phone' => $lt, 'subject' => $lb,
                        'message' => $lm, 'source' => $lq, 'created_at' => zeit($ltage, '14:20')]);
}
echo '  ' . count($leads) . " Anfragen im Posteingang\n";

// ── Projekte ────────────────────────────────────────────────────────
// Acht Projekte über alle vier Zustände verteilt, damit Board, Filter
// und Kennzahlen jeweils etwas zu zeigen haben.
$p_daten = [
    'relaunch' => [
        'title' => 'Website-Relaunch Hofmann & Partner', 'category' => 'Webdesign',
        'description' => "Vollständiger Relaunch des Kanzleiauftritts: neue Struktur, eigenes Layout, Redaktionssystem für das Team.\n\nBesonderheit: die Mandantenbereiche müssen barrierefrei erreichbar bleiben.",
        'status' => 'In Bearbeitung', 'kontakt' => 'hofmann', 'start' => -120, 'frist' => 25,
    ],
    'shop' => [
        'title' => 'Onlineshop Weiß Naturkosmetik', 'category' => 'E-Commerce',
        'description' => "Aufbau des Shops mit 120 Artikeln, Staffelpreisen und einer Versandkostenlogik nach Gewicht.\n\nAnbindung an die vorhandene Warenwirtschaft über CSV-Import.",
        'status' => 'In Bearbeitung', 'kontakt' => 'weiss', 'start' => -75, 'frist' => 40,
    ],
    'brandtweb' => [
        'title' => 'Firmenauftritt Brandt Elektrotechnik', 'category' => 'Webdesign',
        'description' => 'Neuer Firmenauftritt mit Referenzbereich und Stellenanzeigen. Abgeschlossen und übergeben.',
        'status' => 'Erledigt', 'kontakt' => 'brandt', 'start' => -300, 'frist' => -212,
    ],
    'kampagne' => [
        'title' => 'Kampagnenseite Frühjahr', 'category' => 'Marketing',
        'description' => 'Einseitige Kampagnenseite mit Anmeldeformular, gemeinsam mit Krüger Media umgesetzt.',
        'status' => 'Erledigt', 'kontakt' => 'krueger', 'start' => -180, 'frist' => -141,
    ],
    'wartung' => [
        'title' => 'Wartung & Hosting', 'category' => 'Wartung',
        'description' => "Laufende Pflege: Aktualisierungen, Sicherungen, Erreichbarkeitsprüfung.\n\nAbrechnung monatlich.",
        'status' => 'Offen', 'kontakt' => 'hofmann', 'start' => -30, 'frist' => 330,
    ],
    'imagefilm' => [
        'title' => 'Landingpage zum Imagefilm', 'category' => 'Webdesign',
        'description' => 'Begleitende Landingpage zum neuen Imagefilm, inklusive Videohosting ohne externe Tracker.',
        'status' => 'In Bearbeitung', 'kontakt' => 'demir', 'start' => -40, 'frist' => 12,
    ],
    'print' => [
        'title' => 'Broschüre & Printsatz', 'category' => 'Branding',
        'description' => 'Zwölfseitige Broschüre im Anschluss an den Firmenauftritt. Satz und Druckvorstufe.',
        'status' => 'Offen', 'kontakt' => 'brandt', 'start' => -10, 'frist' => 55,
    ],
    'seo' => [
        'title' => 'SEO-Audit Sandmann Immobilien', 'category' => 'SEO',
        'description' => 'Audit war beauftragt, wurde vom Kunden aus Budgetgründen zurückgezogen.',
        'status' => 'Storniert', 'kontakt' => 'sandmann', 'start' => -40, 'frist' => -6,
    ],
];

$p = [];
foreach ($p_daten as $schluessel => $d) {
    $p[$schluessel] = ins('tasks', [
        'title'       => $d['title'],
        'category'    => $d['category'],
        'description' => $d['description'],
        'status'      => $d['status'],
        'contact_id'  => $k[$d['kontakt']],
        'start_date'  => tag($d['start']),
        'deadline'    => tag($d['frist']),
        'created_at'  => zeit($d['start'], '09:05'),
    ]);
}

// Ein Projekt trägt eine Rückmeldung aus dem Portal - damit die Startseite
// den Hinweis "neues Feedback" zeigen kann und der Name des Verfassers
// nachweislich mitgeführt wird.
$pdo->prepare('UPDATE tasks SET client_feedback = ?, feedback_seen = 0,
                                feedback_by_contact_id = ?, feedback_by_name = ?, feedback_at = ?
               WHERE id = ?')
    ->execute([
        "Die neue Startseite gefällt uns sehr gut. Zwei Kleinigkeiten: das Team-Foto bitte etwas höher setzen und im Kontaktformular fehlt noch das Feld für die Mandantennummer.",
        $k['hofmann'], 'Lena Hofmann', zeit(-4, '16:42'), $p['relaunch'],
    ]);
echo '  ' . count($p) . " Projekte angelegt\n";

// ── Beteiligte ──────────────────────────────────────────────────────
// Mehrere Kontakte an einem Projekt: genau der Fall, für den das Portal
// die Mitgliedschaft kennt.
$mitglieder = [
    'relaunch'  => [['hofmann', 'owner'], ['krueger', 'member']],
    'shop'      => [['weiss', 'owner'], ['demir', 'member'], ['krueger', 'member']],
    'brandtweb' => [['brandt', 'owner']],
    'kampagne'  => [['krueger', 'owner'], ['demir', 'member']],
    'wartung'   => [['hofmann', 'owner']],
    'imagefilm' => [['demir', 'owner'], ['krueger', 'member']],
    'print'     => [['brandt', 'owner']],
    'seo'       => [['sandmann', 'owner']],
];
$anz_mitglieder = 0;
foreach ($mitglieder as $pk => $liste) {
    foreach ($liste as $i => [$kk, $rolle]) {
        ins('task_contacts', [
            'task_id'    => $p[$pk],
            'contact_id' => $k[$kk],
            'role'       => $rolle,
            'added_at'   => zeit($p_daten[$pk]['start'] + $i, '09:20'),
        ]);
        $anz_mitglieder++;
    }
}
echo "  $anz_mitglieder Beteiligungen verknüpft\n";

// ── Meilensteine ────────────────────────────────────────────────────
// waiting_on kennt drei Werte: '' (niemand), 'us' (wir), 'them' (Kunde).
$meilensteine = [
    'relaunch' => [
        ['Konzept und Sitemap', 1, -105, ''],
        ['Entwurf Startseite', 1, -78, ''],
        ['Entwürfe Unterseiten', 1, -46, ''],
        ['Umsetzung im Redaktionssystem', 0, null, 'us'],
        ['Inhalte einpflegen', 0, null, 'them'],
        ['Abnahme und Umschaltung', 0, null, ''],
    ],
    'shop' => [
        ['Anforderungen abgestimmt', 1, -68, ''],
        ['Artikelimport eingerichtet', 1, -40, ''],
        ['Versandkostenlogik', 0, null, 'us'],
        ['Zahlungsarten freischalten', 0, null, 'them'],
        ['Testbestellungen', 0, null, ''],
    ],
    'brandtweb' => [
        ['Konzept', 1, -288, ''],
        ['Gestaltung', 1, -262, ''],
        ['Umsetzung', 1, -230, ''],
        ['Abnahme', 1, -213, ''],
    ],
    'kampagne' => [
        ['Text und Bildauswahl', 1, -172, ''],
        ['Umsetzung', 1, -155, ''],
        ['Auswertung nachgereicht', 1, -139, ''],
    ],
    'imagefilm' => [
        ['Videoschnitt final', 1, -18, ''],
        ['Seitenaufbau', 0, null, 'us'],
        ['Freigabe Untertitel', 0, null, 'them'],
    ],
    'wartung' => [
        ['Sicherungskonzept dokumentiert', 1, -22, ''],
        ['Aktualisierungen Quartal 1', 0, null, 'us'],
    ],
    'print' => [
        ['Gliederung abgestimmt', 0, null, 'them'],
        ['Satz', 0, null, ''],
    ],
];
$ms = [];
$anz_ms = 0;
foreach ($meilensteine as $pk => $liste) {
    foreach ($liste as $i => [$titel, $fertig, $freigabe, $wartet]) {
        $id = ins('task_milestones', [
            'task_id'       => $p[$pk],
            'title'         => $titel,
            'is_completed'  => $fertig,
            'approved_at'   => $freigabe !== null ? zeit($freigabe, '11:30') : null,
            'approval_seen' => $freigabe !== null ? 1 : 0,
            'waiting_on'    => $wartet,
            'created_at'    => zeit($p_daten[$pk]['start'] + $i * 3, '09:40'),
        ]);
        $ms[$pk][] = $id;
        $anz_ms++;
    }
}
echo "  $anz_ms Meilensteine angelegt\n";

// ── Kommentare an Meilensteinen ─────────────────────────────────────
// author kennt zwei Werte: 'client' und 'admin'. Der Name wird seit
// Migration 7 mitgeschrieben, damit das Portal den Verfasser benennen
// kann statt pauschal "Sie" zu schreiben.
$ms_kommentare = [
    ['relaunch', 3, 'admin',  'Musterwerk',   'Die Vorlagen stehen. Sobald die Texte da sind, pflegen wir sie ein.', -12],
    ['relaunch', 3, 'client', 'Lena Hofmann', 'Die Texte für "Über uns" kommen bis Freitag, der Rest nächste Woche.', -11],
    ['relaunch', 4, 'client', 'Lena Hofmann', 'Wir sammeln die Inhalte gerade im Team. Zwei Kollegen fehlen noch.', -6],
    ['relaunch', 4, 'admin',  'Musterwerk',   'Kein Problem. Wichtig wären zuerst die Leistungsseiten.', -5],
    ['shop',     2, 'admin',  'Musterwerk',   'Die Staffelung ab 10 Stück ist eingebaut. Bitte einmal gegenprüfen.', -9],
    ['shop',     2, 'client', 'Sonja Weiß',   'Passt. Nur bei den Sets stimmt das Gewicht noch nicht.', -8],
    ['shop',     3, 'client', 'Sonja Weiß',   'Der Zahlungsanbieter hat uns freigeschaltet, Zugangsdaten folgen.', -3],
    ['imagefilm',2, 'client', 'Aylin Demir',  'Untertitel sind fertig, ich lade die Datei gleich hoch.', -2],
    ['brandtweb',3, 'client', 'Marco Brandt', 'Alles geprüft, von unserer Seite freigegeben. Danke!', -214],
];
$anz_msk = 0;
foreach ($ms_kommentare as [$pk, $idx, $autor, $name, $text, $tage]) {
    if (!isset($ms[$pk][$idx])) continue;
    ins('milestone_comments', [
        'milestone_id' => $ms[$pk][$idx],
        'author'       => $autor,
        'author_name'  => $name,
        'admin_seen'   => $tage < -7 ? 1 : 0,
        'message'      => $text,
        'created_at'   => zeit($tage, '13:15'),
    ]);
    $anz_msk++;
}

// ── Projektaustausch ────────────────────────────────────────────────
$p_kommentare = [
    ['relaunch', 'hofmann', 'Lena Hofmann', 'Können wir den Termin für die Umschaltung auf einen Montag legen? Freitags ist bei uns Hochbetrieb.', -7],
    ['relaunch', null,      'Musterwerk',   'Ja, wir planen die Umschaltung für einen Montagmorgen. Ich trage den Termin in den Kalender ein.', -7],
    ['relaunch', 'krueger', 'Tobias Krüger','Die Fotos vom Team sind bearbeitet und liegen im Portal.', -9],
    ['shop',     'weiss',   'Sonja Weiß',   'Wir haben die Artikelliste um 14 Produkte erweitert. Neue Datei ist hochgeladen.', -5],
    ['shop',     null,      'Musterwerk',   'Angekommen, danke. Der Import läuft heute Nachmittag.', -5],
    ['shop',     'demir',   'Aylin Demir',  'Die Produktvideos sind geschnitten, Auflösung 1080p wie besprochen.', -13],
    ['imagefilm',null,      'Musterwerk',   'Seitenaufbau steht zu etwa achtzig Prozent. Rest bis Ende der Woche.', -1],
];
$anz_pk = 0;
foreach ($p_kommentare as [$pk, $kk, $name, $text, $tage]) {
    ins('project_comments', [
        'task_id'           => $p[$pk],
        'author_contact_id' => $kk !== null ? $k[$kk] : null,
        'author_name'       => $name,
        'message'           => $text,
        'admin_seen'        => $kk === null ? 1 : ($tage < -6 ? 1 : 0),
        'created_at'        => zeit($tage, '15:05'),
    ]);
    $anz_pk++;
}
echo "  $anz_msk Meilenstein-Kommentare, $anz_pk Beiträge im Projektaustausch\n";

// ── Erfasste Zeiten ─────────────────────────────────────────────────
// Deterministisch gestreut: derselbe Seed erzeugt dieselben Zahlen,
// sonst schwanken die Kennzahlen bei jedem Befüllen.
mt_srand(20260904);
$zeit_notizen = [
    'Konzeption', 'Abstimmung mit dem Kunden', 'Umsetzung Layout', 'Texte eingepflegt',
    'Fehlerbehebung', 'Testdurchlauf', 'Besprechung', 'Vorbereitung Abnahme',
];
$anz_zeit = 0;
foreach (['relaunch' => 28, 'shop' => 22, 'brandtweb' => 18, 'kampagne' => 9,
          'imagefilm' => 11, 'wartung' => 6, 'print' => 3] as $pk => $anzahl) {
    $spanne = abs($p_daten[$pk]['start']);
    for ($i = 0; $i < $anzahl; $i++) {
        ins('time_entries', [
            'task_id'          => $p[$pk],
            'duration_minutes' => 30 * mt_rand(1, 8),
            'note'             => $zeit_notizen[mt_rand(0, count($zeit_notizen) - 1)],
            'created_at'       => zeit(-mt_rand(1, $spanne), sprintf('%02d:%02d', mt_rand(8, 17), mt_rand(0, 5) * 10)),
        ]);
        $anz_zeit++;
    }
}
echo "  $anz_zeit Zeiteinträge erfasst\n";

// ── Dateien im Portal ───────────────────────────────────────────────
// Die Einträge zeigen auf echte Platzhalterdateien: ein Verweis auf eine
// nicht vorhandene Datei würde in der Demo beim Klick ins Leere laufen.
$asset_dir = dirname(__DIR__) . '/uploads/client_assets';
if (!is_dir($asset_dir)) {
    mkdir($asset_dir, 0755, true);
}
// 1x1-Pixel-PNG, damit auch ein Bildverweis auf eine gültige Datei zeigt.
$png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');

$dateien = [
    ['relaunch',  'Texte_Startseite.txt',   'txt', 'hofmann', 'Lena Hofmann',  -11],
    ['relaunch',  'Teamfotos_Auswahl.png',  'png', 'krueger', 'Tobias Krüger', -9],
    ['shop',      'Artikelliste_neu.csv',   'csv', 'weiss',   'Sonja Weiß',    -5],
    ['shop',      'Logo_Freisteller.png',   'png', 'weiss',   'Sonja Weiß',    -34],
    ['imagefilm', 'Untertitel_final.txt',   'txt', 'demir',   'Aylin Demir',   -2],
];
$inhalt_txt = "Platzhalter der Demo-Version.\n\nDiese Datei enthält keine echten Inhalte - sie existiert, damit der\nDownload im Portal funktioniert.\n";
$inhalt_csv = "Artikelnummer;Bezeichnung;Preis\n1001;Handcreme 50 ml;12,90\n1002;Duschgel 200 ml;9,50\n1003;Geschenkset klein;24,00\n";
$anz_datei = 0;
foreach ($dateien as [$pk, $name, $typ, $kk, $anzeige, $tage]) {
    $ziel = $asset_dir . '/' . $name;
    if ($typ === 'png')      file_put_contents($ziel, $png);
    elseif ($typ === 'csv')  file_put_contents($ziel, $inhalt_csv);
    else                     file_put_contents($ziel, $inhalt_txt);

    ins('client_assets', [
        'task_id'                => $p[$pk],
        'file_name'              => $name,
        'file_path'              => 'uploads/client_assets/' . $name,
        'dashboard_seen'         => $tage < -7 ? 1 : 0,
        'uploaded_by'            => 'client',
        'uploaded_by_contact_id' => $k[$kk],
        'uploaded_by_name'       => $anzeige,
        'uploaded_at'            => zeit($tage, '12:00'),
    ]);
    $anz_datei++;
}
echo "  $anz_datei Dateien im Portal hinterlegt\n";

// ── Finanzen ────────────────────────────────────────────────────────
// Zwölf Monate am Stück. Drei Rechnungen ergeben keine Kurve - die
// Auswertungen auf der Finanzseite brauchen einen echten Verlauf.
$re_zaehler = [];
$re_nummer = function (string $datum) use (&$re_zaehler): string {
    $jahr = date('Y', strtotime($datum));
    $re_zaehler[$jahr] = ($re_zaehler[$jahr] ?? 0) + 1;
    return 'RE-' . $jahr . '-' . str_pad((string) $re_zaehler[$jahr], 3, '0', STR_PAD_LEFT);
};

$einnahme_texte = [
    ['Webdesign - Konzept und Entwurf', 'hofmann'],
    ['Umsetzung Redaktionssystem',      'hofmann'],
    ['Shop-Entwicklung, Teilleistung',  'weiss'],
    ['Firmenauftritt, Schlussrechnung', 'brandt'],
    ['Kampagnenseite',                  'krueger'],
    ['Wartung und Hosting, Monatspauschale', 'hofmann'],
    ['Landingpage Imagefilm, Anzahlung', 'demir'],
];
$ausgabe_texte = [
    ['Serverkosten', 89.00], ['Softwarelizenzen', 49.00], ['Bürobedarf', 34.50],
    ['Berufshaftpflicht', 118.00], ['Fachliteratur', 42.90], ['Bahnfahrt Kundentermin', 76.40],
    ['Fremdleistung Text und Foto', 640.00], ['Fremdleistung Videoschnitt', 880.00],
    ['Steuerberatung', 165.00], ['Domainverlängerungen', 58.00],
];

$anz_fin = 0;
for ($m = 11; $m >= 0; $m--) {
    $monat_start = (int) ((date('j') - 1) + $m * 30);

    // Einnahmen: zwei bis drei je Monat
    $anzahl_e = 2 + ($m % 2);
    for ($i = 0; $i < $anzahl_e; $i++) {
        [$titel, $kk] = $einnahme_texte[($m * 3 + $i) % count($einnahme_texte)];
        // min(..., -1): im laufenden Monat schiebt $i sonst ueber heute
        // hinaus, und die Uebersicht zeigt Buchungen mit Datum in der Zukunft.
        $datum = tag(min(-$monat_start + $i * 4, -1));
        $faellig = date('Y-m-d', strtotime($datum . ' +14 days'));

        // Alles ab zwei Monaten zurück ist bezahlt. In den jüngsten
        // Monaten steht bewusst beides offen - sonst zeigt die Übersicht
        // nie einen offenen Posten.
        // Die erste Rechnung eines Monats ist immer beglichen, sonst zeigt
        // die Finanzseite im laufenden Monat 0,00 EUR Einnahmen. Die
        // uebrigen bleiben in den zwei juengsten Monaten offen bzw.
        // ueberfaellig - ohne offene Posten waere die Uebersicht ebenso
        // unrealistisch.
        if ($m >= 2 || $i === 0)                      $status = 'Bezahlt';
        elseif (strtotime($faellig) < time())          $status = 'Überfällig';
        else                                           $status = 'Offen';

        ins('finances', [
            'type'           => 'INCOME',
            'title'          => $titel,
            'contact_id'     => $k[$kk],
            'amount'         => 480.00 + (($m * 7 + $i * 13) % 9) * 185.00,
            'status'         => $status,
            'record_date'    => $datum,
            'due_date'       => $faellig,
            'invoice_number' => $re_nummer($datum),
            'notes'          => $status === 'Überfällig' ? 'Zahlungserinnerung versendet.' : null,
            'created_at'     => $datum . ' 10:00:00',
        ]);
        $anz_fin++;
    }

    // Ausgaben: drei bis vier je Monat
    $anzahl_a = 3 + ($m % 2);
    for ($i = 0; $i < $anzahl_a; $i++) {
        [$titel, $betrag] = $ausgabe_texte[($m * 4 + $i) % count($ausgabe_texte)];
        $datum = tag(min(-$monat_start + 2 + $i * 5, -1));
        ins('finances', [
            'type'         => 'EXPENSE',
            'title'        => $titel,
            'custom_name'  => in_array($titel, ['Fremdleistung Text und Foto'], true) ? 'Krüger Media'
                              : (in_array($titel, ['Fremdleistung Videoschnitt'], true) ? 'Studio Demir' : null),
            'amount'       => $betrag,
            'status'       => 'Bezahlt',
            'record_date'  => $datum,
            'is_recurring' => in_array($titel, ['Serverkosten', 'Softwarelizenzen', 'Berufshaftpflicht'], true) ? 1 : 0,
            'created_at'   => $datum . ' 17:30:00',
        ]);
        $anz_fin++;
    }
}
echo "  $anz_fin Finanzeinträge über zwölf Monate\n";

// ── Angebote ────────────────────────────────────────────────────────
// Positionen als JSON: {desc, qty, price}, wie quotes.php sie liest.
$angebote = [
    [
        'nummer' => 'ANG-' . date('Y') . '-001', 'betreff' => 'Website-Relaunch',
        'kontakt' => 'hofmann', 'status' => 'Angenommen', 'tage' => -128, 'gueltig' => -98,
        'intro' => 'vielen Dank für Ihre Anfrage. Gern unterbreiten wir Ihnen folgendes Angebot für den Relaunch Ihres Kanzleiauftritts.',
        'positionen' => [
            ['desc' => 'Konzept, Struktur und Sitemap', 'qty' => 1, 'price' => 780.00],
            ['desc' => 'Gestaltung Startseite',          'qty' => 1, 'price' => 950.00],
            ['desc' => 'Gestaltung Unterseiten',         'qty' => 6, 'price' => 240.00],
            ['desc' => 'Umsetzung im Redaktionssystem',  'qty' => 1, 'price' => 2400.00],
        ],
    ],
    [
        'nummer' => 'ANG-' . date('Y') . '-002', 'betreff' => 'Onlineshop Naturkosmetik',
        'kontakt' => 'weiss', 'status' => 'Angenommen', 'tage' => -84, 'gueltig' => -54,
        'intro' => 'wie besprochen erhalten Sie hier unser Angebot für den Aufbau Ihres Onlineshops.',
        'positionen' => [
            ['desc' => 'Shop-Grundsystem und Einrichtung', 'qty' => 1, 'price' => 1900.00],
            ['desc' => 'Artikelimport inkl. Abgleich',     'qty' => 1, 'price' => 680.00],
            ['desc' => 'Versandkostenlogik nach Gewicht',  'qty' => 1, 'price' => 540.00],
            ['desc' => 'Schulung, je Stunde',              'qty' => 3, 'price' => 95.00],
        ],
    ],
    [
        'nummer' => 'ANG-' . date('Y') . '-003', 'betreff' => 'Broschüre und Printsatz',
        'kontakt' => 'brandt', 'status' => 'Gesendet', 'tage' => -12, 'gueltig' => 18,
        'intro' => 'anbei unser Angebot für die zwölfseitige Broschüre im Anschluss an Ihren Firmenauftritt.',
        'positionen' => [
            ['desc' => 'Satz, je Seite',            'qty' => 12, 'price' => 65.00],
            ['desc' => 'Bildbearbeitung',           'qty' => 1,  'price' => 320.00],
            ['desc' => 'Druckvorstufe und Prüfung', 'qty' => 1,  'price' => 180.00],
        ],
    ],
    [
        'nummer' => 'ANG-' . date('Y') . '-004', 'betreff' => 'SEO-Audit',
        'kontakt' => 'sandmann', 'status' => 'Abgelehnt', 'tage' => -44, 'gueltig' => -14,
        'intro' => 'gern prüfen wir Ihren Auftritt auf Auffindbarkeit. Unser Vorschlag im Überblick.',
        'positionen' => [
            ['desc' => 'Technische Analyse',        'qty' => 1, 'price' => 690.00],
            ['desc' => 'Inhaltliche Bewertung',     'qty' => 1, 'price' => 480.00],
            ['desc' => 'Maßnahmenplan und Bericht', 'qty' => 1, 'price' => 350.00],
        ],
    ],
    [
        'nummer' => 'ANG-' . date('Y') . '-005', 'betreff' => 'Wartungspaket Jahresvertrag',
        'kontakt' => 'hofmann', 'status' => 'Gesendet', 'tage' => -5, 'gueltig' => 25,
        'intro' => 'für die laufende Pflege Ihrer Seite schlagen wir folgendes Paket vor.',
        'positionen' => [
            ['desc' => 'Aktualisierungen und Sicherungen, monatlich', 'qty' => 12, 'price' => 89.00],
            ['desc' => 'Erreichbarkeitsprüfung, jährlich',            'qty' => 1,  'price' => 120.00],
        ],
    ],
    [
        'nummer' => 'ANG-' . date('Y') . '-006', 'betreff' => 'Landingpage Imagefilm',
        'kontakt' => 'demir', 'status' => 'Entwurf', 'tage' => -2, 'gueltig' => 28,
        'intro' => 'Entwurf - noch nicht versendet.',
        'positionen' => [
            ['desc' => 'Gestaltung und Umsetzung', 'qty' => 1, 'price' => 1250.00],
            ['desc' => 'Videohosting, Einrichtung', 'qty' => 1, 'price' => 260.00],
        ],
    ],
];
foreach ($angebote as $a) {
    $summe = 0.0;
    foreach ($a['positionen'] as $pos) $summe += $pos['qty'] * $pos['price'];
    ins('quotes', [
        'quote_number' => $a['nummer'],
        'subject'      => $a['betreff'],
        'intro_text'   => $a['intro'],
        'contact_id'   => $k[$a['kontakt']],
        'status'       => $a['status'],
        'tax_type'     => 'kleinunternehmer',
        'items'        => json_encode($a['positionen'], JSON_UNESCAPED_UNICODE),
        'total_amount' => $summe,
        'valid_until'  => tag($a['gueltig']),
        'created_at'   => zeit($a['tage'], '11:00'),
    ]);
}
echo '  ' . count($angebote) . " Angebote in allen Zuständen\n";

// ── Supportanfragen ─────────────────────────────────────────────────
$tickets = [
    ['hofmann', 'Kontaktformular verschickt keine E-Mails',
     "Seit gestern kommen bei uns keine Nachrichten aus dem Kontaktformular mehr an. Der Absender bekommt aber eine Bestätigung.",
     'In Bearbeitung', 'Hoch', -2,
     [['admin', 'Prüfung läuft. Der Postausgang zeigt eine Ablehnung durch den Empfangsserver.', 1, -2],
      ['admin', 'SPF-Eintrag der Kundendomain war unvollständig. Korrektur beantragt.', 0, -1]]],
    ['weiss', 'Artikelbilder werden verzerrt dargestellt',
     "Auf der Kategorieseite sind die Bilder in die Breite gezogen, in der Detailansicht stimmen sie.",
     'Offen', 'Mittel', -1, []],
    ['brandt', 'Neue Stellenanzeige einpflegen',
     "Wir würden gern zwei Stellen ausschreiben. Können Sie das übernehmen oder machen wir das selbst?",
     'Erledigt', 'Niedrig', -22,
     [['admin', 'Zugang zum Redaktionsbereich erklärt, kurze Anleitung im Wiki hinterlegt.', 1, -21]]],
    ['krueger', 'Zugriff auf Bildarchiv',
     "Ich komme im Portal nicht an die Fotos vom letzten Shooting.",
     'Erledigt', 'Mittel', -35,
     [['admin', 'Projektmitgliedschaft ergänzt, Zugriff funktioniert jetzt.', 1, -35]]],
    ['weiss', 'Frage zur Rechnungsstellung',
     "Können wir künftig quartalsweise abrechnen statt monatlich?",
     'Offen', 'Niedrig', -6, []],
];
$anz_notiz = 0;
foreach ($tickets as [$kk, $betreff, $text, $status, $prio, $tage, $notizen]) {
    $tid = ins('support_tickets', [
        'contact_id' => $k[$kk],
        'subject'    => $betreff,
        'message'    => $text,
        'status'     => $status,
        'priority'   => $prio,
        'created_at' => zeit($tage, '08:45'),
    ]);
    foreach ($notizen as [$autor, $notiz, $oeffentlich, $ntage]) {
        ins('ticket_notes', [
            'ticket_id'  => $tid,
            'note'       => $notiz,
            'author'     => $autor,
            'is_public'  => $oeffentlich,
            'created_at' => zeit($ntage, '11:20'),
        ]);
        $anz_notiz++;
    }
}
echo '  ' . count($tickets) . " Supportanfragen mit $anz_notiz Notizen\n";

// ── Wiki ────────────────────────────────────────────────────────────
$artikel = [
    ['Zugang zum Redaktionsbereich', 'Anleitungen', 'redaktion,login,anleitung', 1,
     "## Anmeldung\n\nDen Redaktionsbereich erreichen Sie unter `/redaktion`. Die Zugangsdaten haben Sie per E-Mail erhalten.\n\n## Eine Seite bearbeiten\n\n1. Links im Menü die gewünschte Seite auswählen\n2. Auf **Bearbeiten** klicken\n3. Änderungen vornehmen und **Speichern**\n\nDie Änderung ist sofort öffentlich sichtbar.\n\n## Bilder\n\nBilder bitte in der Mediathek hochladen, nicht direkt in den Text ziehen. Die Mediathek erzeugt automatisch kleinere Fassungen für Mobilgeräte.", -180],
    ['Bildgrößen und Dateiformate', 'Anleitungen', 'bilder,formate,upload', 0,
     "## Ablage\n\nDruckvorlagen liegen im Netzlaufwerk unter `S:\\\\Marketing\\\\Vorlagen\\\\2026`. Bitte von dort kopieren, nicht per E-Mail weiterreichen.\n\n## Empfohlene Größen\n\n| Einsatzort | Breite | Format |\n|---|---|---|\n| Kopfbild | 1920 px | JPG |\n| Artikelbild | 1200 px | JPG |\n| Logo | 600 px | PNG |\n\n## Hinweise\n\n- Fotos als JPG, Grafiken und Logos als PNG\n- Keine Dateien über 2 MB hochladen\n- Dateinamen ohne Umlaute und Leerzeichen", -150],
    ['Ablauf einer Abnahme', 'Prozesse', 'abnahme,freigabe,portal', 1,
     "Jeder Meilenstein durchläuft dieselben Schritte:\n\n1. Wir stellen das Ergebnis im Portal bereit\n2. Sie prüfen und geben frei oder melden Änderungswünsche\n3. Nach der Freigabe gilt der Schritt als abgenommen\n\nOffene Punkte bleiben im Portal sichtbar, damit beide Seiten denselben Stand sehen.", -120],
    ['Sicherungen und Wiederherstellung', 'Betrieb', 'backup,sicherung,notfall', 0,
     "## Was gesichert wird\n\n- Datenbank: täglich, 14 Tage Vorhaltung\n- Dateien: wöchentlich, 8 Wochen Vorhaltung\n\n## Wiederherstellung\n\nIm Ernstfall genügt eine kurze Nachricht. Der Stand des Vortags ist in der Regel innerhalb einer Stunde wieder da.", -95],
    ['Barrierefreiheit: worauf wir achten', 'Grundlagen', 'barrierefrei,kontrast,alt-texte', 0,
     "## Kontraste\n\nText muss sich deutlich vom Hintergrund abheben. Wir prüfen jede Farbkombination.\n\n## Alternativtexte\n\nJedes inhaltstragende Bild bekommt einen Alternativtext. Reine Dekoration bleibt leer.\n\n## Bedienung ohne Maus\n\nAlle Funktionen müssen sich mit der Tastatur erreichen lassen.", -70],
    ['Wie Rechnungen zustande kommen', 'Prozesse', 'rechnung,abrechnung', 0,
     "Abgerechnet wird nach tatsächlichem Aufwand, sofern nichts anderes vereinbart ist. Die erfassten Zeiten sind im Portal einsehbar.\n\nZahlungsziel sind 14 Tage.", -40],
    ['Checkliste vor dem Livegang', 'Prozesse', 'checkliste,livegang,test', 1,
     "- [ ] Alle Inhalte eingepflegt\n- [ ] Formulare getestet\n- [ ] Impressum und Datenschutz geprüft\n- [ ] Weiterleitungen der alten Adressen eingerichtet\n- [ ] Erreichbarkeitsprüfung aktiviert\n- [ ] Sicherung vor der Umschaltung erstellt", -25],
    ['Häufige Fragen zum Portal', 'Anleitungen', 'portal,faq,zugang', 0,
     "**Ich habe meinen Zugangscode vergessen.**\nEine kurze Nachricht genügt, wir setzen ihn zurück.\n\n**Können mehrere Personen aus unserem Haus das Portal nutzen?**\nJa. Jede Person bekommt einen eigenen Zugang und wird dem Projekt als Beteiligte hinzugefügt.\n\n**Sehen andere Kunden unsere Dateien?**\nNein. Sichtbar ist ausschließlich, was zum eigenen Projekt gehört.", -15],
];
$art = [];
foreach ($artikel as [$titel, $kat, $tags, $gepinnt, $inhalt, $tage]) {
    $art[] = ins('wiki_articles', [
        'title'      => $titel,
        'content'    => $inhalt,
        'category'   => $kat,
        'tags'       => $tags,
        'is_pinned'  => $gepinnt,
        'created_at' => zeit($tage, '10:30'),
        'updated_at' => zeit((int) ($tage / 2), '14:10'),
    ]);
}

// Ein Anhang mit echter Platzhalterdatei, damit der Download nicht ins Leere geht.
$wiki_dir = dirname(__DIR__) . '/uploads/wiki';
if (!is_dir($wiki_dir)) mkdir($wiki_dir, 0755, true);
file_put_contents($wiki_dir . '/Checkliste_Livegang.txt',
    "Checkliste vor dem Livegang\n\n- Alle Inhalte eingepflegt\n- Formulare getestet\n- Impressum und Datenschutz geprüft\n- Weiterleitungen eingerichtet\n- Sicherung erstellt\n");
ins('wiki_attachments', [
    'article_id'  => $art[6],
    'file_name'   => 'Checkliste_Livegang.txt',
    'file_path'   => 'uploads/wiki/Checkliste_Livegang.txt',
    'uploaded_at' => zeit(-24, '09:15'),
]);

// Einzelne Artikel sind für Kunden im Portal freigegeben.
foreach ([[0, 'hofmann'], [0, 'brandt'], [2, 'hofmann'], [2, 'weiss'],
          [7, 'hofmann'], [7, 'weiss'], [7, 'krueger']] as [$idx, $kk]) {
    ins('wiki_client_shares', [
        'article_id' => $art[$idx],
        'contact_id' => $k[$kk],
        'created_at' => zeit(-30, '10:00'),
    ]);
}
echo '  ' . count($artikel) . " Wiki-Artikel, davon 3 im Portal freigegeben\n";

// ── Kalender ────────────────────────────────────────────────────────
$termine = [
    ['Abstimmung Relaunch',        'Durchsprache der offenen Punkte zur Startseite.', 'Videokonferenz', 'https://meet.example/abstimmung-relaunch', -9,  '10:00', '11:00', 'Meeting', '#4a90d9', 'Abgeschlossen', ['hofmann', 'krueger']],
    ['Rückruf Sandmann',           'Nachfassen zum Audit-Angebot.',                   '',              '', -6,  '14:30', '15:00', 'Anruf',   '#9b59b6', 'Abgeschlossen', ['sandmann']],
    ['Shop: Testbestellungen',     'Gemeinsamer Durchlauf aller Zahlungsarten.',      'Videokonferenz', 'https://meet.example/shop-test', -2, '09:00', '10:30', 'Meeting', '#4a90d9', 'Abgeschlossen', ['weiss']],
    ['Abgabe Untertitel',          'Untertiteldatei zur Landingpage.',                '',              '', 1,  null,    null,    'Deadline','#e67e22', 'Geplant',       ['demir']],
    ['Jour fixe Musterwerk',       'Wöchentliche interne Planung.',                   'Büro',          '', 2,  '09:00', '09:30', 'Termin',  '#2ecc71', 'Bestätigt',     []],
    ['Vor-Ort-Termin Brandt',      'Fotos für die Broschüre, Werkshalle.',            'Industriering 8, Leipzig', '', 5, '13:00', '16:00', 'Termin', '#2ecc71', 'Bestätigt', ['brandt', 'krueger']],
    ['Umschaltung Relaunch',       'Livegang der neuen Kanzleiseite.',                '',              '', 12, '07:00', '09:00', 'Deadline','#e67e22', 'Geplant',       ['hofmann']],
    ['Abnahme Landingpage',        'Gemeinsame Abnahme mit Studio Demir.',            'Videokonferenz', 'https://meet.example/abnahme-landing', 14, '11:00', '12:00', 'Meeting', '#4a90d9', 'Geplant', ['demir']],
    ['Quartalsabrechnung',         'Rechnungen für das Quartal erstellen.',           '',              '', 21, null,    null,    'Deadline','#e67e22', 'Geplant',       []],
    ['Strategiegespräch Weiß',     'Planung der zweiten Ausbaustufe des Shops.',      'Bautzner Straße 61, Dresden', '', 27, '10:00', '12:00', 'Termin', '#2ecc71', 'Geplant', ['weiss']],
    ['Wartungsfenster',            'Aktualisierungen aller betreuten Seiten.',        '',              '', 33, '20:00', '22:00', 'Termin',  '#95a5a6', 'Geplant',       []],
    ['Nachfassen Broschüre',       'Rückmeldung zum Angebot einholen.',               '',              '', -1, '11:00', '11:15', 'Anruf',   '#9b59b6', 'Abgesagt',      ['brandt']],
];
$anz_einl = 0;
foreach ($termine as [$titel, $besch, $ort, $url, $tage, $von, $bis, $kat, $farbe, $status, $gaeste]) {
    $eid = ins('calendar_events', [
        'title'       => $titel,
        'description' => $besch,
        'location'    => $ort,
        'meeting_url' => $url,
        'event_date'  => tag($tage),
        'start_time'  => $von,
        'end_time'    => $bis,
        'category'    => $kat,
        'color'       => $farbe,
        'status'      => $status,
        'ics_uid'     => demo_token('event-' . $titel) . '@musterwerk.example',
        'created_at'  => zeit($tage - 14, '09:00'),
    ]);
    foreach ($gaeste as $kk) {
        ins('event_contacts', [
            'event_id'     => $eid,
            'contact_id'   => $k[$kk],
            'invite_token' => substr(demo_token('einladung-' . $titel . '-' . $kk), 0, 64),
            'invited_at'   => zeit($tage - 13, '09:10'),
        ]);
        $anz_einl++;
    }
}
echo '  ' . count($termine) . " Termine mit $anz_einl Einladungen\n";

// ── Überwachte Adressen ─────────────────────────────────────────────
// Im Demo-Modus werden diese Adressen nie abgerufen; index.php liefert
// dort einen abgeleiteten Zustand. Die Einträge existieren nur, damit
// das Widget nicht leer bleibt.
foreach ([
    ['Hofmann & Partner',   'https://hofmann-partner.example'],
    ['Brandt Elektro',      'https://brandt-elektro.example'],
    ['Weiß Naturkosmetik',  'https://weiss-naturkosmetik.example'],
    ['Musterwerk Digital',  'https://musterwerk.example'],
] as [$name, $adresse]) {
    ins('monitored_urls', ['url_name' => $name, 'url_link' => $adresse, 'created_at' => zeit(-200, '08:00')]);
}
echo "  4 überwachte Adressen\n";

// ── Protokoll ───────────────────────────────────────────────────────
// Eine plausible Vorgeschichte, damit die Systemprotokoll-Seite Filter,
// Zeitraum und Blätternavigation überhaupt zeigen kann. Die Adressen
// stammen aus den für Dokumentation reservierten Bereichen (RFC 5737).
$log_vorlagen = [
    ['LOGIN_SUCCESS',   'Erfolgreiche Anmeldung.'],
    ['CONTACT_ADD',     'Neuer Kontakt angelegt.'],
    ['CONTACT_EDIT',    'Kontaktdaten geändert.'],
    ['TASK_ADD',        'Projekt angelegt.'],
    ['TASK_STATUS',     'Kanban: Karte verschoben.'],
    ['MILESTONE_ADD',   'Meilenstein hinzugefügt.'],
    ['MILESTONE_DONE',  'Meilenstein abgeschlossen.'],
    ['MILESTONE_WAITING','Zuständigkeit für einen Schritt geändert.'],
    ['PROJECT_REPLY',   'Antwort im Projektaustausch.'],
    ['TIME_MANUAL',     'Zeit manuell erfasst.'],
    ['INVOICE_ADD',     'Rechnung erstellt.'],
    ['INVOICE_PAID',    'Rechnung als bezahlt markiert.'],
    ['QUOTE_ADD',       'Angebot erstellt.'],
    ['QUOTE_SEND',      'Angebot versendet.'],
    ['TICKET_ADD',      'Supportanfrage eingegangen.'],
    ['TICKET_REPLY',    'Antwort auf eine Supportanfrage.'],
    ['WIKI_ADD',        'Wiki-Artikel angelegt.'],
    ['PORTAL_PIN_SET',  'Zugangscode im Portal vergeben.'],
    ['PORTAL_UPLOAD',   'Datei über das Portal hochgeladen.'],
    ['SETTINGS_COMPANY','Unternehmensangaben gespeichert.'],
];
$log_ips = ['192.0.2.14', '192.0.2.77', '198.51.100.23', '198.51.100.9', '203.0.113.41'];
mt_srand(4711);
$anz_log = 0;
for ($i = 0; $i < 140; $i++) {
    [$typ, $text] = $log_vorlagen[mt_rand(0, count($log_vorlagen) - 1)];
    ins('logs', [
        'action_type' => $typ,
        'description' => $text,
        'ip'          => $log_ips[mt_rand(0, count($log_ips) - 1)],
        'created_at'  => zeit(-mt_rand(0, 45), sprintf('%02d:%02d', mt_rand(7, 20), mt_rand(0, 59))),
    ]);
    $anz_log++;
}
// Ein paar Fehlversuche, damit die Kennzahl auf der Protokollseite nicht
// dauerhaft auf null steht.
foreach ([-1, -2, -2, -5] as $tage) {
    ins('logs', [
        'action_type' => 'LOGIN_FAILED',
        'description' => 'Fehlgeschlagene Anmeldung.',
        'ip'          => '203.0.113.41',
        'created_at'  => zeit($tage, '03:12'),
    ]);
    $anz_log++;
}
echo "  $anz_log Protokolleinträge\n";

// ── Abschluss ───────────────────────────────────────────────────────
$dauer = round(microtime(true) - $start, 1);
echo "\nFertig in {$dauer}s.\n\n";
echo "Portal-Zugänge (Code: " . demo_portal_pin() . "):\n";
$basis = defined('BASE_URL') && BASE_URL !== '' ? BASE_URL : 'https://demo.example';
foreach ($k_daten as $schluessel => $d) {
    if ($schluessel === 'sandmann') continue;
    echo '  ' . str_pad($d['name'], 16) . $basis . '/portal?token=' . demo_token($schluessel) . "\n";
}
echo "\nHinweis: der Datenbankbenutzer der laufenden Demo braucht nur SELECT.\n";
echo "Siehe docs/DEMO.md.\n";
