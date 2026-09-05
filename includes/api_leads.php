<?php
/**
 * Anfragen von außen annehmen.
 *
 * Die README erklärte zur Anbindung des eigenen Kontaktformulars ein
 * `INSERT INTO leads_inbox` — die Anleitung lautete also, aus der
 * Website heraus direkt in die Panel-Datenbank zu schreiben. Das setzt
 * voraus, dass beide auf derselben Maschine liegen, und verteilt die
 * Zugangsdaten auf ein zweites Projekt.
 *
 * Hier steht, was der Endpunkt entscheidet; api/leads.php ist nur die
 * Tür davor. Getrennt, damit sich beides ohne HTTP prüfen lässt.
 *
 * ── Der Schlüssel gehört nicht in den Browser ──────────────────────
 * Er wird im Header mitgeschickt und berechtigt zum Schreiben. Wer ihn
 * in ein Formular oder in JavaScript legt, hat ihn veröffentlicht.
 * Deshalb gibt es hier bewusst KEIN CORS: der Aufruf gehört auf den
 * Server der Website, nicht in die Seite.
 */

require_once __DIR__ . '/logging.php';
require_once __DIR__ . '/api_keys.php';

/** Wie viele Anfragen eine IP je Stunde stellen darf. */
const API_LEADS_MAX_PRO_STUNDE = 30;

/** Längengrenzen, passend zu den Spalten von leads_inbox. */
const API_LEADS_GRENZEN = [
    'name'    => 255,
    'email'   => 255,
    'phone'   => 50,
    'subject' => 255,
    'source'  => 100,
    'message' => 5000,
];

// Schluessel, Bremse, Antwortform und das Lesen des Rumpfes stehen seit
// dem zweiten Endpunkt in includes/api_keys.php: eine wortgleiche
// zweite Fassung waere dieselbe Pruefung an zwei Stellen zu pflegen
// gewesen.

/**
 * Prüft und säubert die Felder einer Anfrage.
 *
 * Gibt entweder die fertigen Werte zurück oder eine Liste von
 * Beanstandungen. Gekürzt statt abgelehnt, wo die Länge das einzige
 * Problem ist: eine zu lange Betreffzeile ist kein Grund, eine
 * Kundenanfrage wegzuwerfen.
 *
 * @return array{ok: bool, fehler: array<int, string>, werte: array<string, ?string>}
 */
function api_leads_pruefen(array $eingabe): array
{
    $fehler = [];

    // Steuerzeichen gehoeren in kein Feld. Sie kommen aus keiner Tastatur,
    // richten in Protokollen und CSV-Ausgaben Schaden an und sind das
    // erste, was ein Fuzzer probiert. In der Nachricht bleiben Zeilen-
    // umbruch und Tabulator stehen - das ist Fliesstext.
    foreach (['name', 'email', 'phone', 'subject', 'source'] as $feld) {
        if (isset($eingabe[$feld]) && is_string($eingabe[$feld])) {
            $eingabe[$feld] = preg_replace('/[\x00-\x1F\x7F]/u', '', $eingabe[$feld]) ?? '';
        }
    }
    if (isset($eingabe['message']) && is_string($eingabe['message'])) {
        $eingabe['message'] = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $eingabe['message']) ?? '';
    }
    // Der Honigtopf. Ein Feld, das kein Mensch ausfüllt, weil es im
    // Formular unsichtbar ist - ein ausgefülltes stammt von einem
    // Skript. Die Antwort ist trotzdem freundlich: wer erfährt, dass er
    // erkannt wurde, baut die Erkennung nach.
    $honig = trim((string) ($eingabe['website'] ?? ''));
    if ($honig !== '') {
        return ['ok' => false, 'fehler' => ['spam'], 'werte' => []];
    }

    $name = trim((string) ($eingabe['name'] ?? ''));
    if ($name === '') {
        $fehler[] = 'Das Feld "name" fehlt.';
    }

    // Eine spitze Klammer in Name, Betreff, Telefon oder Quelle ist nie
    // echt. Die Ausgabe filtert zwar - aber ein Name, der wie ein Tag
    // aussieht, hat im Bestand nichts zu suchen, und er wandert von hier
    // aus weiter: index.php uebernimmt eine angenommene Anfrage
    // unveraendert in contacts.
    //
    // Die Nachricht ist ausgenommen: dort ist "5 < 10" ein Satz.
    foreach (['name' => 'name', 'subject' => 'subject',
              'phone' => 'phone', 'source' => 'source'] as $feld => $anzeige) {
        $wert = trim((string) ($eingabe[$feld] ?? ''));
        if ($wert !== '' && preg_match('/[<>]/', $wert)) {
            $fehler[] = 'Das Feld "' . $anzeige . '" enthält unerlaubte Zeichen.';
        }
    }

    $email = trim((string) ($eingabe['email'] ?? ''));
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $fehler[] = 'Die Adresse in "email" ist nicht gültig.';
    }

    // Ohne jede Rückrufmöglichkeit ist die Anfrage wertlos - man kann
    // nicht antworten.
    $phone = trim((string) ($eingabe['phone'] ?? ''));
    if ($email === '' && $phone === '') {
        $fehler[] = 'Es fehlt eine Rückrufmöglichkeit: "email" oder "phone".';
    }
    // Bewusst keine Formatpruefung darueber hinaus: Durchwahlen,
    // Laendervorwahlen und Zusaetze wie "(mobil)" sind alle echt. Eine
    // Nummer ganz ohne Ziffer ist es nicht.
    if ($phone !== '' && !preg_match('/\d/', $phone)) {
        $fehler[] = 'Das Feld "phone" enthält keine Ziffer.';
    }

    if ($fehler) {
        return ['ok' => false, 'fehler' => $fehler, 'werte' => []];
    }

    $werte = [
        'name'    => $name,
        'email'   => $email !== '' ? $email : null,
        'phone'   => $phone !== '' ? $phone : null,
        'subject' => trim((string) ($eingabe['subject'] ?? '')) ?: null,
        'message' => trim((string) ($eingabe['message'] ?? '')) ?: null,
        'source'  => trim((string) ($eingabe['source'] ?? '')) ?: 'API',
    ];

    foreach (API_LEADS_GRENZEN as $feld => $grenze) {
        if ($werte[$feld] !== null) {
            $werte[$feld] = mb_substr($werte[$feld], 0, $grenze);
        }
    }

    return ['ok' => true, 'fehler' => [], 'werte' => $werte];
}

/**
 * Schreibt die Anfrage in den Eingang.
 *
 * @return int Kennung des neuen Eintrags
 */
function api_leads_speichern(PDO $pdo, array $werte): int
{
    $pdo->prepare(
        'INSERT INTO leads_inbox (name, email, phone, subject, message, source)
         VALUES (?, ?, ?, ?, ?, ?)'
    )->execute([
        $werte['name'], $werte['email'], $werte['phone'],
        $werte['subject'], $werte['message'], $werte['source'],
    ]);

    return (int) $pdo->lastInsertId();
}


