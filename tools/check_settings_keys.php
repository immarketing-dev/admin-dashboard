<?php
/**
 * Prueft, dass geschriebene und gelesene Einstellungsschluessel
 * zueinander passen.
 *
 * Anlass: der Demo-Seed setzte fuenf Schluessel, die keine einzige Zeile
 * las - company_email, company_phone, company_website, bank_name und
 * company_tax_id - waehrend die vier, die gelesen werden
 * (company_vat_id, company_tax_number, bank_holder, ein zweistelliges
 * company_country), gar nicht gesetzt wurden. Sichtbar war das nur
 * daran, dass in der Demo Kontoinhaber und Steuernummer leer blieben und
 * die XRechnung fehlende Pflichtangaben meldete. Monatelang unbemerkt.
 *
 * Der Fehler ist still, weil setting() einen Standardwert hat. Ein
 * Schluessel, den niemand schreibt, liefert einfach immer den Standard;
 * einer, den niemand liest, kostet nur eine Zeile in der Tabelle.
 *
 * Geprueft wird in drei Richtungen:
 *
 *   1. Jeder Schluessel, den settings.php speichert, wird auch gelesen.
 *   2. Jeder Schluessel, den der Demo-Seed setzt, wird auch gelesen.
 *   3. Jeder gelesene Schluessel wird irgendwo geschrieben.
 *
 * Aufruf: php tools/check_settings_keys.php
 */

chdir(dirname(__DIR__));

$fehler  = 0;
$quellen = array_merge(glob('*.php'), glob('includes/*.php'), glob('api/*.php'));

/** Kommentare entfernen, sonst zaehlen dort genannte Namen mit. */
function ohne_kommentar(string $s): string
{
    return preg_replace('#//[^\n]*#', '', $s) ?? $s;
}

// ── Was gelesen wird ────────────────────────────────────────────────
$gelesen = [];
foreach ($quellen as $datei) {
    if (preg_match_all('/\bsetting\(\s*\'([a-z0-9_]+)\'/', file_get_contents($datei), $treffer)) {
        foreach ($treffer[1] as $k) {
            $gelesen[$k][] = $datei;
        }
    }
}

// ── Was settings.php speichert ──────────────────────────────────────
// Drei Formen kommen dort vor: eine Liste $keys, ein foreach ueber ein
// Array-Literal, und ein Schluessel direkt im VALUES.
$settings    = file_get_contents('settings.php');
$geschrieben = [];

foreach ([
    '/\$keys\s*=\s*\[(.*?)\];/s',
    '/foreach\s*\(\s*\[([^\]]*)\]\s*as\s*\$k\b/s',
] as $muster) {
    if (preg_match_all($muster, $settings, $bloecke)) {
        foreach ($bloecke[1] as $block) {
            if (preg_match_all('/\'([a-z][a-z0-9_]{2,})\'/', ohne_kommentar($block), $namen)) {
                foreach ($namen[1] as $k) $geschrieben[$k] = true;
            }
        }
    }
}
if (preg_match_all('/VALUES\s*\(\s*\'([a-z][a-z0-9_]{2,})\'\s*,\s*\?/', $settings, $direkt)) {
    foreach ($direkt[1] as $k) $geschrieben[$k] = true;
}

// ── Was der Seed setzt ──────────────────────────────────────────────
$seed      = file_get_contents('tools/seed_demo_data.php');
$seed_keys = [];
if (preg_match('/\$einstellungen\s*=\s*\[(.*?)\n\];/s', $seed, $m)) {
    if (preg_match_all('/\'([a-z][a-z0-9_]{2,})\'\s*=>/', ohne_kommentar($m[1]), $namen)) {
        $seed_keys = array_values(array_unique($namen[1]));
    }
}

// ── Wo sonst noch ein Wert entstehen kann ───────────────────────────
$sonst = file_get_contents('includes/migrations.php') . file_get_contents('install/schema.sql');
foreach ($quellen as $datei) {
    $sonst .= file_get_contents($datei);
}
$sonst .= $seed;

// ── 1 ───────────────────────────────────────────────────────────────
echo "=== Pruefung 1: was settings.php speichert, wird gelesen ===\n";
$tot = [];
foreach (array_keys($geschrieben) as $k) {
    if (!isset($gelesen[$k])) $tot[] = $k;
}
if ($tot === []) {
    echo 'OK: alle ' . count($geschrieben) . " gespeicherten Schluessel werden gelesen.\n";
} else {
    foreach ($tot as $k) {
        echo "FEHLER: settings.php speichert '$k', aber kein setting('$k') liest es.\n";
    }
    echo "  Entweder fehlt die Auswertung, oder das Feld gehoert entfernt.\n";
    $fehler++;
}

// ── 2 ───────────────────────────────────────────────────────────────
echo "\n=== Pruefung 2: was der Demo-Seed setzt, wird gelesen ===\n";
$tot = [];
foreach ($seed_keys as $k) {
    if ($k === 'schema_version') continue;
    if (!isset($gelesen[$k])) $tot[] = $k;
}
if ($seed_keys === []) {
    echo "FEHLER: im Seed wurde kein Einstellungsblock gefunden - Muster pruefen.\n";
    $fehler++;
} elseif ($tot === []) {
    echo 'OK: alle ' . count($seed_keys) . " Schluessel des Seeds werden gelesen.\n";
} else {
    foreach ($tot as $k) {
        echo "FEHLER: tools/seed_demo_data.php setzt '$k', aber niemand liest es.\n";
    }
    echo "  In der Demo bleibt das zugehoerige Feld leer.\n";
    $fehler++;
}

// ── 3 ───────────────────────────────────────────────────────────────
echo "\n=== Pruefung 3: was gelesen wird, wird auch geschrieben ===\n";
$ohne = [];
foreach ($gelesen as $k => $wo) {
    if (isset($geschrieben[$k]) || in_array($k, $seed_keys, true)) continue;
    // Steht der Name sonst als Literal irgendwo? Dann setzt ihn eine
    // Migration, das Schema oder eine Seite auf eigenem Weg.
    if (substr_count($sonst, "'" . $k . "'") > count($wo)) continue;

    // Zusammengesetzte Schluessel: api_schluessel() liest
    // setting('api_key_' . $zweck), und settings.php schreibt unter
    // demselben zusammengesetzten Namen. Als ganzes Literal steht
    // der Schluessel dann nur an der Lesestelle. Taucht ein Praefix
    // davon gefolgt von einer Verkettung auf, ist der Schluessel
    // erklaert.
    $zusammengesetzt = false;
    for ($i = strlen($k) - 1; $i >= 3; $i--) {
        $praefix = substr($k, 0, $i);
        if (preg_match('/\'' . preg_quote($praefix, '/') . '\'\s*\./', $sonst)) {
            $zusammengesetzt = true;
            break;
        }
    }
    if ($zusammengesetzt) continue;
    $ohne[$k] = $wo;
}
if ($ohne === []) {
    echo 'OK: alle ' . count($gelesen) . " gelesenen Schluessel haben eine Quelle.\n";
} else {
    foreach ($ohne as $k => $wo) {
        echo "FEHLER: setting('$k') in " . implode(', ', array_unique($wo))
           . " - aber nichts setzt den Wert.\n";
    }
    echo "  Der Aufruf liefert immer den Standardwert.\n";
    $fehler++;
}

echo "\n=== Zusammenfassung ===\n";
if ($fehler === 0) {
    echo 'OK: ' . count($gelesen) . ' gelesene, ' . count($geschrieben)
       . ' gespeicherte und ' . count($seed_keys) . " im Seed gesetzte Schluessel passen zusammen.\n";
    exit(0);
}
echo "FEHLGESCHLAGEN: $fehler von 3 Pruefungen.\n";
exit(1);
