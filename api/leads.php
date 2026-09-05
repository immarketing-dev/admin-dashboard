<?php
/**
 * Nimmt Anfragen von der eigenen Website entgegen.
 *
 * Bis hierher lautete die Anleitung, aus der Website heraus ein
 * `INSERT INTO leads_inbox` abzusetzen — also direkt in die
 * Panel-Datenbank zu schreiben. Das setzt voraus, dass beide auf
 * derselben Maschine liegen, und verteilt die Zugangsdaten auf ein
 * zweites Projekt.
 *
 * Aufruf:
 *
 *     POST /api/leads
 *     X-Api-Key: <Schlüssel aus den Einstellungen>
 *     Content-Type: application/json
 *
 *     {"name":"Anna Beispiel","email":"anna@example.com",
 *      "subject":"Anfrage","message":"…","source":"Kontaktformular"}
 *
 * Antwortet mit JSON und einem sprechenden Status: 201 bei Annahme,
 * 400 bei fehlenden Feldern, 401 ohne gültigen Schlüssel, 429 bei zu
 * vielen Anfragen, 405 bei falscher Methode.
 *
 * ── Der Schlüssel gehört auf den Server, nicht in die Seite ────────
 * Er berechtigt zum Schreiben. Steht er in einem Formular oder in
 * JavaScript, ist er veröffentlicht. Deshalb gibt es hier bewusst kein
 * CORS: der Aufruf gehört in den Formularhandler der Website.
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/api_keys.php';
require_once __DIR__ . '/../includes/api_leads.php';

// Demo-Modus, Methode, Schlüssel, Bremse - alle vier hinter einem
// Aufruf, damit ein Endpunkt nicht eine davon vergessen kann.
api_tuer($pdo, 'leads', 'API_LEAD', API_LEADS_MAX_PRO_STUNDE);

// ── Inhalt ─────────────────────────────────────────────────────────
$eingabe = api_rumpf(
    (string) file_get_contents('php://input'),
    (string) ($_SERVER['CONTENT_TYPE'] ?? ''),
    $_POST
);

$geprueft = api_leads_pruefen($eingabe);

if (!$geprueft['ok']) {
    // Der Honigtopf bekommt dieselbe Antwort wie eine angenommene
    // Anfrage. Wer erfährt, dass er erkannt wurde, baut die Erkennung
    // nach - und die Anfrage ist ohnehin nicht von einem Menschen.
    if ($geprueft['fehler'] === ['spam']) {
        api_antwort(201, ['ok' => true, 'id' => 0]);
    }
    api_antwort(400, ['ok' => false, 'error' => 'Ungültige Anfrage.', 'details' => $geprueft['fehler']]);
}

$id = api_leads_speichern($pdo, $geprueft['werte']);

log_event(
    $pdo,
    'LEAD_RECEIVED',
    'Neue Anfrage über die Schnittstelle: ' . $geprueft['werte']['name']
    . ' (' . $geprueft['werte']['source'] . ').'
);

api_antwort(201, ['ok' => true, 'id' => $id]);
