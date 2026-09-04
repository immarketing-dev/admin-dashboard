<?php
/**
 * Prueft die Annahmen, auf denen der Demo-Modus ruht.
 *
 * Der Schreibschutz der oeffentlichen Demo greift an genau einer Stelle:
 * includes/auth.php weist jede POST-Anfrage ab, bevor ein Handler
 * laeuft. Das traegt nur, solange zwei Dinge gelten:
 *
 *   1. Jede Seite, die POST verarbeitet, bindet auth.php ein oder hat
 *      einen eigenen Riegel.
 *   2. Auf dem reinen Anzeigepfad wird nicht geschrieben.
 *
 * Punkt 2 galt beim Entwurf nicht: trash.php raeumt beim Oeffnen auf und
 * setzte damit ein DELETE auf einen GET ab. Aufgefallen ist das erst
 * beim Nachmessen - deshalb dieser Test.
 *
 * Aufruf: php tools/check_demo.php
 */

$wurzel = dirname(__DIR__);
$fail   = 0;

/**
 * Seiten mit Schreibzugriff im Anzeigepfad, die bewusst so bleiben.
 *
 * Je Ausnahme steht dabei, woran der Schutz haengt. Der Test prueft,
 * dass diese Zeichenkette in der Datei auch wirklich vorkommt - eine
 * Ausnahme, deren Begruendung wegfaellt, faellt damit auf.
 */
const AUSNAHMEN = [
    'trash.php' => [
        'wache' => 'demo_mode()',
        'grund' => 'Raeumt abgelaufene Eintraege beim Anzeigen auf; im Demo-Modus uebersprungen.',
    ],
    'sso.php' => [
        'wache' => 'if (!SSO_ENABLED)',
        'grund' => 'Bricht ohne SSO_ENABLED mit 404 ab, bevor geschrieben wird; '
                 . 'config.php erzwingt SSO_ENABLED=false im Demo-Modus.',
    ],
];

/** Seiten, die POST verarbeiten, aber bewusst ohne auth.php auskommen. */
const OHNE_AUTH = [
    'login.php'  => 'Im Demo-Modus wird sofort weitergeleitet, das Formular erscheint nie.',
    'portal.php' => 'Eigene Tuer (PIN); ruft demo_guard() selbst auf.',
];

/**
 * Quelltext ohne Kommentare.
 *
 * Alle Anwesenheitspruefungen hier laufen darueber. Sonst genuegt ein
 * vorangestelltes // vor demo_guard(), und der Test meldet weiterhin
 * alles in Ordnung - genau das ist beim ersten Gegentest passiert.
 */
function code_ohne_kommentare(string $pfad): string
{
    $aus = '';
    foreach (token_get_all(file_get_contents($pfad)) as $t) {
        if (is_array($t)) {
            if ($t[0] === T_COMMENT || $t[0] === T_DOC_COMMENT) continue;
            $aus .= $t[1];
        } else {
            $aus .= $t;
        }
    }
    return $aus;
}

/**
 * Sucht Schreibzugriffe, die beim blossen Anzeigen der Seite ausgefuehrt
 * werden: auf oberster Ebene (also nicht in einer Funktion, die erst ein
 * Handler aufruft) und vor dem POST-Riegel.
 */
function schreibzugriffe_im_anzeigepfad(string $pfad): array
{
    $code = file_get_contents($pfad);

    // Ab welcher Zeile beginnt die Verarbeitung von Sendungen? Alles
    // davor laeuft auch bei einem GET.
    //
    // Neben dem ueblichen REQUEST_METHOD-Vergleich zaehlt auch der erste
    // Zugriff auf $_POST: tasks.php etwa oeffnet seinen AJAX-Block mit
    // isset($_POST['ajax_action']), und der ist bei einem GET ebenso
    // falsch. Ohne diesen Zusatz meldet der Test dort fuenf Fundstellen,
    // die keine sind.
    $riegel = PHP_INT_MAX;
    foreach (preg_split('/\R/', $code) as $nr => $zeile) {
        if (preg_match('/REQUEST_METHOD.{0,40}[\'"]POST[\'"]/', $zeile)
            || preg_match('/\$_POST\s*\[/', $zeile)) {
            $riegel = $nr + 1;
            break;
        }
    }

    $tokens = token_get_all($code);
    $tiefe = 0;
    $funktionsrumpf = [];      // Klammertiefen, bei denen eine Funktion begann
    $erwarte_rumpf = false;
    $treffer = [];

    foreach ($tokens as $t) {
        if (is_string($t)) {
            if ($t === '{') {
                $tiefe++;
                if ($erwarte_rumpf) { $funktionsrumpf[] = $tiefe; $erwarte_rumpf = false; }
            } elseif ($t === '}') {
                if ($funktionsrumpf !== [] && end($funktionsrumpf) === $tiefe) array_pop($funktionsrumpf);
                $tiefe--;
            } elseif ($t === ';' && $erwarte_rumpf) {
                // abstrakte Methode oder Interface-Signatur
                $erwarte_rumpf = false;
            }
            continue;
        }

        [$id, $text, $zeile] = $t;

        if ($id === T_FUNCTION || $id === T_FN) {
            $erwarte_rumpf = true;
            continue;
        }

        // Nur Zeichenketten koennen SQL enthalten.
        if ($id !== T_CONSTANT_ENCAPSED_STRING && $id !== T_ENCAPSED_AND_WHITESPACE) {
            continue;
        }
        if (!preg_match('/\b(INSERT\s+INTO|UPDATE\s+[a-z_`]|DELETE\s+FROM|TRUNCATE\s+TABLE)/i', $text)) {
            continue;
        }
        if ($funktionsrumpf !== []) continue;   // liegt in einer Funktion
        if ($zeile >= $riegel) continue;        // liegt hinter dem POST-Riegel

        $treffer[] = $zeile . ': ' . trim(preg_replace('/\s+/', ' ', substr($text, 0, 70)));
    }

    return $treffer;
}

// ---------------------------------------------------------------------
echo "=== Pruefung 1: der Riegel sitzt ===\n";
// ---------------------------------------------------------------------
$riegelstellen = [
    'includes/auth.php' => 'demo_guard()',
    'portal.php'        => 'demo_guard()',
    'includes/demo.php' => 'function demo_guard',
    // Ohne eigenen Cookie-Namen wuerde eine Demo unter derselben
    // Host-Adresse die Anmeldung der echten Installation aushebeln.
    'includes/session.php' => 'demo_mode()',
    'config.php'        => "define('DEMO_MODE'",
];
$fehlend = [];
foreach ($riegelstellen as $datei => $muster) {
    if (strpos(code_ohne_kommentare($wurzel . '/' . $datei), $muster) === false) {
        $fehlend[] = "$datei erwartet: $muster";
    }
}
if (strpos(code_ohne_kommentare($wurzel . '/login.php'), 'demo_mode()') === false) {
    $fehlend[] = 'login.php behandelt den Demo-Modus nicht';
}
// invoice.php prueft die Anmeldung selbst statt auth.php einzubinden und
// braucht den Riegel deshalb ebenfalls selbst.
if (strpos(code_ohne_kommentare($wurzel . '/invoice.php'), 'demo_guard()') === false) {
    $fehlend[] = 'invoice.php ruft demo_guard() nicht auf';
}
// cron.php laeuft ohne Sitzung und ohne auth.php - es ist ein GET, der
// schreibt und Mails verschickt. Pruefung 3 sieht das nicht, weil das SQL
// in includes/cron_tasks.php steht und nicht in der Datei selbst. Der
// Riegel muss deshalb hier eigens nachgehalten werden.
if (strpos(code_ohne_kommentare($wurzel . '/cron.php'), 'demo_mode()') === false) {
    $fehlend[] = 'cron.php prueft den Demo-Modus nicht';
}
// Ohne Token waere cron.php ein offener Endpunkt, der Mails an Kunden
// ausloest - auf jeder Installation, die die .env nicht angefasst hat.
if (strpos(code_ohne_kommentare($wurzel . '/cron.php'), 'hash_equals') === false) {
    $fehlend[] = 'cron.php prueft den CRON_TOKEN nicht mit hash_equals';
}
// SSO darf in der Demo nicht von der .env abhaengen - sso.php schreibt,
// bevor ein POST im Spiel ist.
if (!preg_match('/define\(\s*[\'"]SSO_ENABLED[\'"].*!\s*DEMO_MODE/s',
                code_ohne_kommentare($wurzel . '/config.php'))) {
    $fehlend[] = 'config.php erzwingt SSO_ENABLED=false im Demo-Modus nicht';
}
if ($fehlend === []) {
    echo "OK: Riegel, Schalter und Weiterleitung sind an ihren Stellen.\n";
} else {
    foreach ($fehlend as $f) echo "FEHLER: $f\n";
    $fail = 1;
}
echo "\n";

// ---------------------------------------------------------------------
echo "=== Pruefung 2: jede POST-Seite ist gedeckt ===\n";
// ---------------------------------------------------------------------
$ungedeckt = [];
$geprueft  = 0;
foreach (glob($wurzel . '/*.php') as $pfad) {
    $name = basename($pfad);
    $code = code_ohne_kommentare($pfad);
    if (!preg_match('/REQUEST_METHOD.{0,40}[\'"]POST[\'"]/', $code)) continue;
    $geprueft++;

    $hat_auth  = strpos($code, "includes/auth.php") !== false;
    $hat_eigen = strpos($code, 'demo_guard()') !== false;
    if ($hat_auth || $hat_eigen) continue;
    if (isset(OHNE_AUTH[$name]) && strpos($code, 'demo_mode()') !== false) continue;

    $ungedeckt[] = $name;
}
if ($ungedeckt === []) {
    echo "OK: alle $geprueft POST-verarbeitenden Seiten laufen durch einen Riegel.\n";
} else {
    foreach ($ungedeckt as $n) {
        echo "FEHLER: $n verarbeitet POST, bindet aber weder auth.php ein noch ruft demo_guard() auf.\n";
    }
    $fail = 1;
}
echo "\n";

// ---------------------------------------------------------------------
echo "=== Pruefung 3: kein Schreibzugriff beim blossen Anzeigen ===\n";
// ---------------------------------------------------------------------
$offen = [];
foreach (glob($wurzel . '/*.php') as $pfad) {
    $name = basename($pfad);
    $treffer = schreibzugriffe_im_anzeigepfad($pfad);
    if ($treffer === []) continue;

    if (isset(AUSNAHMEN[$name])) {
        if (strpos(code_ohne_kommentare($pfad), AUSNAHMEN[$name]['wache']) === false) {
            echo "FEHLER: $name ist als Ausnahme gefuehrt, aber die begruendende Wache '"
               . AUSNAHMEN[$name]['wache'] . "' steht nicht mehr in der Datei.\n";
            $fail = 1;
        }
        continue;
    }
    foreach ($treffer as $t) $offen[] = "$name:$t";
}
if ($offen === []) {
    echo 'OK: ausserhalb der ' . count(AUSNAHMEN) . " dokumentierten Ausnahme(n) schreibt keine Seite beim Anzeigen.\n";
    foreach (AUSNAHMEN as $n => $a) echo "  Ausnahme $n: " . $a['grund'] . "\n";
} else {
    foreach ($offen as $o) {
        echo "FEHLER: Schreibzugriff im Anzeigepfad - $o\n";
    }
    echo "  Solche Stellen brechen die Demo ab (der Datenbankbenutzer hat nur SELECT).\n";
    echo "  Entweder hinter den POST-Riegel verschieben oder mit demo_mode() ausnehmen\n";
    echo "  und in AUSNAHMEN in dieser Datei begruenden.\n";
    $fail = 1;
}
echo "\n";

// ---------------------------------------------------------------------
echo "=== Pruefung 4: der Uptime-Abruf schweigt in der Demo ===\n";
// ---------------------------------------------------------------------
// Der einzige Zugriff nach aussen, der ohne POST zustande kommt. Ohne
// Sperre liesse sich der Server ueber die Ueberwachungsliste auf
// beliebige Adressen ansetzen.
$index = file_get_contents($wurzel . '/index.php');
$pos_demo = strpos($index, 'demo_mode()');
$pos_curl = strpos($index, 'curl_init');
if ($pos_demo !== false && $pos_curl !== false && $pos_demo < $pos_curl) {
    echo "OK: index.php prueft den Demo-Modus vor dem ersten curl_init().\n";
} else {
    echo "FEHLER: in index.php steht die Demo-Pruefung nicht vor curl_init().\n";
    $fail = 1;
}
echo "\n";

// ---------------------------------------------------------------------
echo "=== Pruefung 5: die Ausnahmeliste bleibt klein ===\n";
// ---------------------------------------------------------------------
// DEMO_ERLAUBTE_AKTIONEN laesst einzelne POSTs durch. Dort darf nur
// stehen, was allein die Sitzung beruehrt - eine Aktion mit
// Datenbankzugriff waere ein Loch im Schreibschutz.
// Je Aktion: wo ihr Handler steht, und wo die Verzweigung auf den
// Demo-Modus steht. Meist dieselbe Datei - beim Dashboard-Layout nicht:
// index.php nimmt die Sendung entgegen, die Fallunterscheidung sitzt in
// includes/dashboard_layout.php, wo auch die Datenbank angefasst wuerde.
$bekannt = [
    // Beruehrt nur die Sitzung (portal.php ueberspringt im Demo-Modus
    // zusaetzlich den Fehlversuchszaehler).
    'verify_portal_pin'       => null,
    // Sprache und Farben darf ein Besucher fuer sich aendern. Die
    // Handler in settings.php schreiben dann in die Sitzung statt in
    // die Datenbank - genau das prueft die Schleife darunter.
    'save_language'           => ['settings.php', 'settings.php'],
    'save_design'             => ['settings.php', 'settings.php'],
    'reset_design'            => ['settings.php', 'settings.php'],
    // Die Anordnung der Widgets auf der Startseite - derselbe Gedanke:
    // in der Demo landet sie in der Sitzung, nicht in der Datenbank.
    'save_dashboard_layout'   => ['index.php', 'includes/dashboard_layout.php'],
    'reset_dashboard_layout'  => ['index.php', 'includes/dashboard_layout.php'],
];

// Eine durchgelassene Aktion, deren Handler nicht auf den Demo-Modus
// verzweigt, schriebe dort in die Datenbank - und scheiterte am
// SELECT-only-Benutzer, mitten in der Verarbeitung.
foreach ($bekannt as $aktion => $dateien) {
    if ($dateien === null) continue;
    [$handler_datei, $verzweigung_datei] = $dateien;

    $handler = code_ohne_kommentare($wurzel . '/' . $handler_datei);
    if (strpos($handler, "=== '$aktion'") === false) {
        echo "FEHLER: kein Handler fuer $aktion in $handler_datei gefunden.\n";
        $fail = 1;
        continue;
    }

    // Steht die Verzweigung in derselben Datei, muss sie in Reichweite des
    // Handlers liegen; liegt sie in einer eigenen Datei, genuegt dort ihre
    // Anwesenheit - die Datei tut nichts anderes.
    if ($verzweigung_datei === $handler_datei) {
        $pos   = strpos($handler, "=== '$aktion'");
        $block = substr($handler, $pos, 1400);
    } else {
        $block = code_ohne_kommentare($wurzel . '/' . $verzweigung_datei);
    }
    if (strpos($block, 'demo_mode()') === false) {
        echo "FEHLER: Handler $aktion laesst den Demo-Modus unberuecksichtigt"
           . " (erwartet in $verzweigung_datei).\n";
        $fail = 1;
    }
}
require_once $wurzel . '/includes/env.php';
if (!defined('DEMO_MODE')) define('DEMO_MODE', false);
require_once $wurzel . '/includes/demo.php';

$unbekannt = array_diff(DEMO_ERLAUBTE_AKTIONEN, array_keys($bekannt));
if ($unbekannt === []) {
    echo 'OK: ' . count(DEMO_ERLAUBTE_AKTIONEN) . " durchgelassene Aktion(en), alle bekannt: "
       . implode(', ', DEMO_ERLAUBTE_AKTIONEN) . "\n";
} else {
    echo 'FEHLER: unbekannte Aktion(en) in DEMO_ERLAUBTE_AKTIONEN: '
       . implode(', ', $unbekannt) . "\n";
    echo "  Jede Aktion dort umgeht den Schreibschutz. Sie darf ausschliesslich die\n";
    echo "  Sitzung veraendern. Ist das geprueft, in \$bekannt in dieser Datei ergaenzen.\n";
    $fail = 1;
}
echo "\n";

// ---------------------------------------------------------------------
echo "=== Pruefung 6: DEMO_MODE ist im Beispiel aus ===\n";
// ---------------------------------------------------------------------
$beispiel = file_get_contents($wurzel . '/.env.example');
if (preg_match('/^DEMO_MODE\s*=\s*(.+)$/mi', $beispiel, $m)) {
    $wert = strtolower(trim($m[1]));
    if (in_array($wert, ['1', 'true', 'yes', 'on'], true)) {
        echo "FEHLER: .env.example setzt DEMO_MODE auf '$wert'. Wer die Datei kopiert,\n";
        echo "  bekaeme eine Installation ohne Anmeldung.\n";
        $fail = 1;
    } else {
        echo "OK: .env.example setzt DEMO_MODE=$wert.\n";
    }
} else {
    echo "FEHLER: DEMO_MODE fehlt in .env.example.\n";
    $fail = 1;
}
echo "\n";

echo "=== Zusammenfassung ===\n";
echo $fail
    ? "FEHLGESCHLAGEN: mindestens eine Pruefung ist fehlgeschlagen (siehe oben).\n"
    : "OK: der Demo-Modus haelt seine Annahmen ein.\n";
exit($fail);
