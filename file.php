<?php
/**
 * Ausliefern hochgeladener Dateien - mit Zugriffspruefung.
 *
 * Vorher lieferte der Webserver alles unter uploads/ direkt aus. Die
 * Verzeichnisse mit Kundenunterlagen sind jetzt gesperrt (je eine
 * .htaccess mit "Require all denied"), und dieser Weg ist der einzige,
 * der noch hineinfuehrt. Wer was sehen darf, entscheidet
 * includes/file_access.php - dieselbe Regel, nach der auch das Portal
 * seine Listen fuellt.
 *
 * Aufruf:
 *   file?type=invoice&id=12                  (angemeldeter Verwalter)
 *   file?type=invoice&id=12&token=abc…       (Kunde im Portal)
 *   …&dl=1                                    erzwingt den Download
 *
 * Bewusst OHNE includes/auth.php: das leitet Nichtangemeldete zum
 * Anmeldeformular um. Hier soll ein Kunde mit gueltiger Portalsitzung
 * ebenso durchkommen, und ein Unberechtigter eine schlichte 403 sehen -
 * keine Weiterleitung, die im Bild eines <img>-Tags als kaputtes Symbol
 * endet.
 */
require_once 'config.php';
require_once 'includes/session.php';
require_once 'includes/file_access.php';
app_session_start();

/** Bricht mit einem Status ab, ohne Einzelheiten zu verraten. */
function datei_abbruch(int $status, string $text): void
{
    http_response_code($status);
    header('Content-Type: text/plain; charset=utf-8');
    echo $text;
    exit;
}

$typ   = (string) ($_GET['type'] ?? '');
$id    = (int) ($_GET['id'] ?? 0);
$token = (string) ($_GET['token'] ?? '');

// ── Wer fragt? ─────────────────────────────────────────────────────
// null bedeutet Verwalter und damit Zugriff auf alles. Ein Kontakt
// bekommt nur, was ihm zusteht.
$kontakt_id  = null;
$berechtigt  = false;

if (demo_mode() || (isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true)) {
    $berechtigt = true;
} elseif (strlen($token) >= 10) {
    $stmt = $pdo->prepare('SELECT id FROM contacts WHERE deleted_at IS NULL AND portal_token = ?');
    $stmt->execute([$token]);
    $kontakt = $stmt->fetch(PDO::FETCH_ASSOC);

    // Der Token allein genuegt nicht - er steht in der Adresszeile und
    // damit im Verlauf, in Lesezeichen und in weitergeleiteten Links.
    // Erst die bestandene PIN-Pruefung macht die Sitzung gueltig, und
    // genau die haelt portal.php unter diesem Schluessel fest.
    if ($kontakt && !empty($_SESSION['portal_auth_' . $kontakt['id']])) {
        $kontakt_id = (int) $kontakt['id'];
        $berechtigt = true;
    }
}

if (!$berechtigt) {
    datei_abbruch(403, t('Kein Zugriff.'));
}

// ── Was wird verlangt? ─────────────────────────────────────────────
$treffer = datei_zugriff($pdo, $typ, $id, $kontakt_id);

// Bewusst dieselbe Antwort wie fuer eine fehlende Datei: eine 403 hier
// wuerde verraten, dass es den Eintrag gibt - und mit ihr, wie viele
// Rechnungen im laufenden Jahr geschrieben wurden.
if ($treffer === null) {
    datei_abbruch(404, t('Datei nicht gefunden.'));
}

$absolut = __DIR__ . '/' . $treffer['pfad'];

// Zweiter Riegel gegen Pfadausbruch, diesmal auf dem Dateisystem: der
// aufgeloeste Pfad muss unterhalb von uploads/ liegen. Faengt auch
// einen Symlink, den die Textpruefung in datei_pfad_erlaubt() nicht
// sehen kann.
$echt  = realpath($absolut);
$basis = realpath(__DIR__ . '/uploads');

if ($echt === false || $basis === false || strpos($echt, $basis . DIRECTORY_SEPARATOR) !== 0) {
    datei_abbruch(404, t('Datei nicht gefunden.'));
}
if (!is_file($echt) || !is_readable($echt)) {
    datei_abbruch(404, t('Datei nicht gefunden.'));
}

// ── Ausliefern ─────────────────────────────────────────────────────
$art  = datei_auslieferung($treffer['name'], isset($_GET['dl']));
$name = $treffer['name'];

// Der Dateiname geht zweimal heraus: als ASCII-Rueckfall fuer alte
// Browser und nach RFC 5987 kodiert fuer alles, was Umlaute kann.
$ascii = preg_replace('/[^\x20-\x7E]/', '_', $name);
$ascii = str_replace(['"', '\\'], '', (string) $ascii);

// Alles verwerfen, was bis hierher versehentlich ausgegeben wurde -
// ein einzelnes Leerzeichen vor dem Header machte das PDF kaputt.
while (ob_get_level() > 0) {
    ob_end_clean();
}

header('Content-Type: ' . $art['typ']);
header('Content-Disposition: ' . $art['disposition'] . '; filename="' . $ascii . '"'
     . "; filename*=UTF-8''" . rawurlencode($name));
header('Content-Length: ' . filesize($echt));
header('X-Content-Type-Options: nosniff');
// Kundenunterlagen gehoeren in keinen gemeinsamen Zwischenspeicher.
header('Cache-Control: private, no-store, max-age=0');
header('Pragma: no-cache');
// Falls doch einmal etwas Aktives durchrutscht: die Sandbox nimmt ihm
// Skripte, Formulare und den Zugriff auf den eigenen Origin.
header("Content-Security-Policy: default-src 'none'; sandbox");
header('Accept-Ranges: none');

readfile($echt);
