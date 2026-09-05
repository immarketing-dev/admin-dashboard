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


