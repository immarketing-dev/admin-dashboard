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
require_once __DIR__ . '/../includes/api_leads.php';

/** Antwortet mit JSON und beendet die Anfrage. */
function api_antwort(int $status, array $daten): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('X-Robots-Tag: noindex, nofollow', true);
    header('Cache-Control: no-store');
    echo json_encode($daten, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// ── Tür 1: nicht in der Demo ───────────────────────────────────────
// Der Datenbankbenutzer der Demo darf ohnehin nur lesen; hier bricht es
// mit einer verständlichen Antwort ab statt mit einem Datenbankfehler.
if (demo_mode()) {
    api_antwort(403, ['ok' => false, 'error' => 'Im Demo-Modus abgeschaltet.']);
}

// ── Tür 2: nur POST ────────────────────────────────────────────────
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    header('Allow: POST');
    api_antwort(405, ['ok' => false, 'error' => 'Nur POST.']);
}

// ── Tür 3: der Schlüssel ───────────────────────────────────────────
$erwartet = api_leads_schluessel();

// Kein Schlüssel eingerichtet heißt: dieser Weg ist nicht freigegeben.
// Bewusst nicht "dann eben ohne Prüfung" - das wäre ein offener
// Schreibzugang auf jeder Installation, die nichts eingestellt hat.
if ($erwartet === '') {
    api_antwort(503, [
        'ok'    => false,
        'error' => 'Kein API-Schlüssel eingerichtet. Einstellungen → System.',
    ]);
}

if (!hash_equals($erwartet, api_leads_schluessel_aus_anfrage($_SERVER))) {
    // Keine Auskunft darüber, was falsch war.
    api_antwort(401, ['ok' => false, 'error' => 'Nicht berechtigt.']);
}

// ── Tür 4: nicht zu oft ────────────────────────────────────────────
$ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
if (api_leads_zu_haeufig($pdo, $ip)) {
    header('Retry-After: 3600');
    api_antwort(429, ['ok' => false, 'error' => 'Zu viele Anfragen.']);
}

// Vor der Prüfung protokollieren, nicht danach: sonst zählt die Bremse
// nur die gelungenen, und wer das Formular flutet, bleibt ungezählt.
log_event($pdo, 'API_LEAD', 'Anfrage über die Schnittstelle empfangen.');

// ── Inhalt ─────────────────────────────────────────────────────────
$eingabe = api_leads_rumpf(
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
