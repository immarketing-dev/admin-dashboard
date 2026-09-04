<?php
/**
 * Test fuer die Pruefung der Widget-Anordnung. Aufruf: php tools/test_dashboard_layout.php
 *
 * Die Anordnung kommt aus der Datenbank bzw. - in der Demo - aus der
 * Sitzung und damit letztlich aus einer POST-Sendung. Sie ist also
 * Fremdeingabe, und dashboard_layout_validate() ist die Stelle, die sie
 * baendigt. Was hier schiefgeht, sieht man auf der Startseite nicht als
 * Fehlermeldung, sondern als verrutschte oder fehlende Kachel.
 *
 * Laeuft ohne Datenbank: die drei Funktionen, die dashboard_layout.php aus
 * dem uebrigen Projekt braucht, sind hier als Attrappen definiert.
 */

// Attrappen. te() gibt den deutschen Text zurueck, setting() kennt nichts,
// und demo_* fehlen ganz - dashboard_layout.php prueft das mit
// function_exists() und geht dann den Datenbankweg.
function te(string $s): string { return $s; }
function setting(string $k, string $default = ''): string { return $default; }

require_once __DIR__ . '/../includes/dashboard_layout.php';

$standard = dashboard_widgets();
$namen    = array_keys($standard);

/** Kurzschreibweise: Anordnung pruefen und einen Widget-Eintrag herausgreifen. */
function pruef($eingang, string $id): array
{
    $l = dashboard_layout_validate($eingang);
    return $l['items'][$id];
}

// Ein realistischer, gueltiger Stand als Ausgangspunkt.
$gut = json_encode(['v' => 1, 'items' => [
    'leads' => ['x' => 0, 'y' => 0, 'w' => 6, 'h' => 5],
    'notes' => ['x' => 6, 'y' => 0, 'w' => 6, 'h' => 5],
], 'hidden' => ['webspace']]);

$checks = [];

// --- Vollstaendigkeit -------------------------------------------------
$leer = dashboard_layout_validate('');
$checks['leerer Stand: alle Widgets da']
    = count($leer['items']) === count($standard);
$checks['leerer Stand: Standardplaetze']
    = $leer['items']['leads']['x'] === $standard['leads']['x']
   && $leer['items']['leads']['w'] === $standard['leads']['w'];

$checks['kaputtes JSON: Standardlayout']
    = dashboard_layout_validate('{nicht: json')['items']['leads']['w'] === $standard['leads']['w'];
$checks['null: Standardlayout']
    = dashboard_layout_validate(null)['items']['notes']['x'] === $standard['notes']['x'];
$checks['JSON ohne items: Standardlayout']
    = dashboard_layout_validate('{"v":1}')['items']['monitor']['w'] === $standard['monitor']['w'];
$checks['items als Zeichenkette statt Objekt']
    = dashboard_layout_validate('{"items":"kaputt"}')['items']['monitor']['w'] === $standard['monitor']['w'];

// --- Uebernahme gueltiger Werte ---------------------------------------
$checks['gueltiger Stand wird uebernommen']
    = pruef($gut, 'leads')['w'] === 6 && pruef($gut, 'leads')['h'] === 5;
$checks['Array statt JSON-Text geht auch']
    = dashboard_layout_validate(json_decode($gut, true))['items']['leads']['w'] === 6;

// --- Widget kommt dazu / faellt weg -----------------------------------
$ohne_notes = json_encode(['items' => ['leads' => ['x' => 0, 'y' => 0, 'w' => 4, 'h' => 4]]]);
$checks['fehlendes Widget bekommt Standardplatz']
    = pruef($ohne_notes, 'notes')['x'] === $standard['notes']['x']
   && pruef($ohne_notes, 'notes')['w'] === $standard['notes']['w'];

$mit_fremd = json_encode(['items' => [
    'gibt_es_nicht' => ['x' => 0, 'y' => 0, 'w' => 4, 'h' => 4],
    'leads'         => ['x' => 0, 'y' => 0, 'w' => 4, 'h' => 4],
]]);
$fremd = dashboard_layout_validate($mit_fremd);
$checks['unbekanntes Widget faellt weg']
    = !isset($fremd['items']['gibt_es_nicht'])
   && count($fremd['items']) === count($standard);

// --- Grenzen ----------------------------------------------------------
$checks['zu breit wird auf 12 beschnitten']
    = pruef('{"items":{"leads":{"x":0,"y":0,"w":99,"h":4}}}', 'leads')['w'] === DASH_COLS;
$checks['zu schmal faellt auf min_w']
    = pruef('{"items":{"leads":{"x":0,"y":0,"w":1,"h":4}}}', 'leads')['w'] === $standard['leads']['min_w'];
$checks['zu flach faellt auf min_h']
    = pruef('{"items":{"projects":{"x":0,"y":0,"w":8,"h":1}}}', 'projects')['h'] === $standard['projects']['min_h'];
$checks['zu hoch wird beschnitten']
    = pruef('{"items":{"projects":{"x":0,"y":0,"w":8,"h":9999}}}', 'projects')['h'] === DASH_MAX_H;

$checks['negatives x wird 0']
    = pruef('{"items":{"leads":{"x":-5,"y":0,"w":4,"h":4}}}', 'leads')['x'] === 0;
$checks['negatives y wird 0']
    = pruef('{"items":{"leads":{"x":0,"y":-3,"w":4,"h":4}}}', 'leads')['y'] === 0;

// x + w darf nie ueber den rechten Rand hinauslaufen - sonst haengt die
// Kachel ausserhalb des Rasters und Gridstack schiebt sie beim Laden
// irgendwohin.
$rand = pruef('{"items":{"leads":{"x":11,"y":0,"w":6,"h":4}}}', 'leads');
$checks['x wird an den rechten Rand gezogen']
    = $rand['x'] + $rand['w'] <= DASH_COLS && $rand['x'] === DASH_COLS - $rand['w'];

// --- Werte, die keine Zahlen sind -------------------------------------
$checks['Text statt Zahl: Standard']
    = pruef('{"items":{"leads":{"x":"links","y":0,"w":4,"h":4}}}', 'leads')['x'] === $standard['leads']['x'];
$checks['null statt Zahl: Standard']
    = pruef('{"items":{"leads":{"x":null,"y":0,"w":4,"h":4}}}', 'leads')['w'] === $standard['leads']['w'];
$checks['Array statt Zahl: Standard']
    = pruef('{"items":{"leads":{"x":[1,2],"y":0,"w":4,"h":4}}}', 'leads')['x'] === $standard['leads']['x'];
$checks['Kommazahl wird ganzzahlig']
    = pruef('{"items":{"leads":{"x":2.7,"y":0,"w":4,"h":4}}}', 'leads')['x'] === 2;

// --- Ausgeblendete Widgets --------------------------------------------
$v = dashboard_layout_validate($gut);
$checks['ausgeblendet wird uebernommen']
    = $v['items']['webspace']['hidden'] === true && in_array('webspace', $v['hidden'], true);
$checks['sichtbare bleiben sichtbar']
    = $v['items']['leads']['hidden'] === false && !in_array('leads', $v['hidden'], true);
$checks['unbekannter Name in hidden wird ignoriert']
    = dashboard_layout_validate('{"hidden":["gibt_es_nicht"]}')['hidden'] === [];
$checks['hidden als Zeichenkette statt Liste']
    = dashboard_layout_validate('{"hidden":"webspace"}')['hidden'] === [];

// --- Begleitangaben ---------------------------------------------------
// min_w/min_h/handle/title stammen immer aus dashboard_widgets(), nie aus
// dem gespeicherten Stand - sonst koennte eine Sendung die Untergrenzen
// aushebeln.
$manipuliert = json_encode(['items' => ['leads' => [
    'x' => 0, 'y' => 0, 'w' => 4, 'h' => 4, 'min_w' => 1, 'min_h' => 1, 'handle' => 'bar',
]]]);
$m = pruef($manipuliert, 'leads');
$checks['Untergrenze kommt aus dem Code']
    = $m['min_w'] === $standard['leads']['min_w'] && $m['min_h'] === $standard['leads']['min_h'];
$checks['Griff kommt aus dem Code']
    = $m['handle'] === $standard['leads']['handle'];

// --- Jedes Widget einzeln ---------------------------------------------
// Ein Standardplatz, der selbst ausserhalb des Rasters laege, faellt sonst
// nirgends auf: die Pruefung wuerde ihn stillschweigend zurechtruecken.
$alle_im_raster = true;
foreach ($standard as $id => $s) {
    if ($s['x'] < 0 || $s['w'] < $s['min_w'] || $s['x'] + $s['w'] > DASH_COLS || $s['h'] < $s['min_h']) {
        echo "  Standardplatz von $id liegt ausserhalb des Rasters.\n";
        $alle_im_raster = false;
    }
}
$checks['alle Standardplaetze passen ins Raster'] = $alle_im_raster;
$checks['jedes Widget hat einen Titel']
    = count(array_filter($standard, fn($s) => trim((string) $s['title']) !== '')) === count($standard);
$checks['Griff ist title oder bar']
    = count(array_filter($standard, fn($s) => in_array($s['handle'], ['title', 'bar'], true))) === count($standard);

// ----------------------------------------------------------------------
$fail = 0;
foreach ($checks as $name => $ok) {
    echo ($ok ? 'PASS' : 'FAIL') . "  $name\n";
    if (!$ok) $fail = 1;
}
echo $fail === 0
    ? "OK: " . count($checks) . " Pruefungen bestanden (" . count($namen) . " Widgets).\n"
    : "FEHLGESCHLAGEN.\n";
exit($fail);
