<?php
/**
 * Test fuer den Erhalt der Filter ueber eine Sendung hinweg.
 * Aufruf: php tools/test_filter_state.php
 *
 * Der Zustand einer Liste kommt aus der Abfrage einer Adresse und geht in
 * eine Location-Kopfzeile zurueck - beides Fremdeingabe an einer Stelle,
 * die man leicht falsch macht. Geprueft wird deshalb nicht nur, dass die
 * Filter erhalten bleiben, sondern auch, dass nichts durchrutscht, was
 * dort nicht hingehoert.
 */

require_once __DIR__ . '/../includes/filter_state.php';

$wurzel = dirname(__DIR__);
$checks = [];

/** Setzt die Anfrage neu und liefert die gebaute Adresse. */
function url(string $seite, string $abfrage, array $extra = [], ?string $post = null): string
{
    $_SERVER['QUERY_STRING'] = $abfrage;
    if ($post === null) unset($_POST['filter_state']);
    else                $_POST['filter_state'] = $post;
    return filter_url($seite, $extra);
}

// --- Die Filter ueberleben ---------------------------------------------
// Die Reihenfolge folgt filter_params(), nicht der eingegangenen Abfrage -
// dadurch sieht dieselbe Ansicht immer gleich aus.
$checks['Filter bleiben erhalten']
    = url('tasks', 'status=Offen&q=relaunch') === 'tasks?q=relaunch&status=Offen';
$checks['ohne Filter bleibt die nackte Adresse']
    = url('tasks', '') === 'tasks';
$checks['alle acht Filter von tasks gehen mit']
    = count(filter_params('tasks')) === 8
   && substr_count(url('tasks', 'q=a&status=b&category=c&contact=1&sort=d&start_month=e&created=f&deadline_filter=g'), '=') === 8;

// --- Nur, was auf der Liste steht --------------------------------------
$checks['unbekannter Parameter faellt weg']
    = url('tasks', 'status=Offen&schadhaft=1') === 'tasks?status=Offen';
$checks['Rueckmeldungen werden nicht wiederholt']
    = url('finances', 'msg=invoice_created&period=month') === 'finances?period=month';
$checks['Parameter einer anderen Seite zaehlen nicht']
    = url('quotes', 'contact=3&status=Gesendet') === 'quotes?status=Gesendet';
$checks['leerer Wert wird weggelassen']
    = url('tasks', 'status=&q=x') === 'tasks?q=x';

// --- Zusaetze -----------------------------------------------------------
$checks['Zusatz kommt dazu']
    = url('tickets', 'search=abc', ['msg' => 'created']) === 'tickets?search=abc&msg=created';
$checks['Zusatz sticht den gespeicherten Wert']
    = url('finances', 'tab=invoices', ['tab' => 'quotes']) === 'finances?tab=quotes';

// --- Herkunft: verstecktes Feld schlaegt die Adresse --------------------
$checks['verstecktes Feld hat Vorrang']
    = url('tasks', 'status=Erledigt', [], 'status=Offen') === 'tasks?status=Offen';
$checks['ohne Feld zaehlt die Adresse']
    = url('tasks', 'status=Erledigt') === 'tasks?status=Erledigt';

// --- Nichts darf die Kopfzeile aufbrechen ------------------------------
// Ein Zeilenumbruch in einer Location-Kopfzeile haengt eine zweite
// Kopfzeile an - damit liesse sich eine Antwort umschreiben.
$boese = url('tasks', 'q=' . urlencode("abc\r\nSet-Cookie: a=b"));
$checks['Zeilenumbruch wird kodiert']
    = strpos($boese, "\r") === false && strpos($boese, "\n") === false;
$checks['Zeilenumbruch geht nicht verloren, sondern wird kodiert']
    = strpos($boese, '%0D%0A') !== false;

$fremd = url('tasks', 'q=' . urlencode('https://example.org/'));
$checks['fremde Adresse bleibt ein Suchwort']
    = strpos($fremd, 'tasks?') === 0 && strpos($fremd, '?q=https%3A%2F%2F') !== false;
$checks['Doppelpunkt und Schraegstriche werden kodiert']
    = strpos($fremd, '://') === false;

// Ein Wert, der eine Liste ist, hat in einer Adresse nichts zu suchen.
$checks['Feld-Array wird verworfen']
    = url('tasks', 'status[]=a&status[]=b&q=ok') === 'tasks?q=ok';

// --- Groessen -----------------------------------------------------------
$lang = url('tasks', 'q=' . str_repeat('a', 500));
$checks['langer Wert wird gekuerzt']
    = strlen($lang) < 260 && strpos($lang, 'tasks?q=aaa') === 0;
$checks['ueberlange Abfrage wird verworfen']
    = url('tasks', 'q=x&' . str_repeat('z=1&', 800)) === 'tasks';

// --- Das versteckte Feld ------------------------------------------------
$_SERVER['QUERY_STRING'] = 'status=Offen&q=<script>';
unset($_POST['filter_state']);
$feld = filter_field('tasks');
$checks['Feld traegt den Zustand']
    = strpos($feld, 'name="filter_state"') !== false && strpos($feld, 'status=Offen') !== false;
$checks['Feld maskiert spitze Klammern']
    = strpos($feld, '<script>') === false;
$_SERVER['QUERY_STRING'] = '';
$checks['ohne Filter kein Feld'] = filter_field('tasks') === '';

// --- Abgleich mit den Seiten -------------------------------------------
// Ein neuer Filter, der hier nicht eingetragen wird, geht nach jeder
// Aenderung still verloren - genau der Fehler, den diese Datei verhindert.
// Deshalb der Abgleich gegen die Parameter, die die Seiten auswerten.
$ausnahmen = [
    // Einmalige Rueckmeldungen und Ausgabeschalter - sie beschreiben keine
    // Ansicht und duerfen nach einer Aenderung nicht erneut greifen.
    'msg', 'error', 'saved', 'export', 'detail', 'demo', 'upload_err',
    'invited', 'invite_count', 'ask_invite',
    // Kennungen, die eine Einzelansicht oeffnen, und interne Schalter.
    'ticket_id', 'ajax_notes', 'ajax_widget', 'ajax_upload', 'token', 'id',
    'year', 'month_view', 'page',
];
$fehlend = [];
foreach (['tasks', 'contacts', 'finances', 'tickets', 'quotes', 'wiki'] as $seite) {
    $code = file_get_contents($wurzel . '/' . $seite . '.php');
    preg_match_all('/\$_GET\[\s*[\'"]([a-z_]+)[\'"]\s*\]/i', $code, $m);
    foreach (array_unique($m[1]) as $name) {
        if (in_array($name, filter_params($seite), true)) continue;
        if (in_array($name, $ausnahmen, true)) continue;
        $fehlend[] = "$seite: $name";
    }
}
if ($fehlend) {
    echo "  Nicht eingeordnet: " . implode(', ', $fehlend) . "\n";
    echo "  Entweder in filter_params() aufnehmen (dann bleibt er erhalten)\n";
    echo "  oder oben unter \$ausnahmen (dann ist er bewusst einmalig).\n";
}
$checks['jeder Abfrageparameter ist eingeordnet'] = $fehlend === [];

// Umgekehrt: nichts auf der Liste, das die Seite gar nicht auswertet.
$tote = [];
foreach (['tasks', 'contacts', 'finances', 'tickets', 'quotes', 'wiki'] as $seite) {
    $code = file_get_contents($wurzel . '/' . $seite . '.php');
    foreach (filter_params($seite) as $name) {
        if (strpos($code, "\$_GET['$name']") === false && strpos($code, "\$_GET[\"$name\"]") === false) {
            $tote[] = "$seite: $name";
        }
    }
}
if ($tote) echo "  Auf der Liste, aber nirgends gelesen: " . implode(', ', $tote) . "\n";
$checks['kein toter Eintrag auf der Liste'] = $tote === [];

// --- Die Seiten binden das Modul auch ein ------------------------------
$ohne = [];
foreach (['tasks', 'contacts', 'finances', 'tickets', 'quotes', 'wiki'] as $seite) {
    $code = file_get_contents($wurzel . '/' . $seite . '.php');
    if (strpos($code, 'filter_redirect(') !== false && strpos($code, "filter_state.php") === false) {
        $ohne[] = $seite;
    }
}
$checks['jede Seite bindet das Modul ein'] = $ohne === [];

// --- Keine Weiterleitung baut ihr Ziel noch selbst ---------------------
// Ausnahme: ein Wechsel auf eine andere Seite - dort gelten die Filter
// dieser Liste nicht.
$selbstgebaut = [];
foreach (['tasks', 'contacts', 'finances', 'tickets', 'quotes', 'wiki'] as $seite) {
    $code = file_get_contents($wurzel . '/' . $seite . '.php');
    if (preg_match_all('/header\(\s*["\']Location: (' . $seite . ')[^"\']*/', $code, $m)) {
        foreach ($m[0] as $t) $selbstgebaut[] = "$seite: " . trim($t);
    }
}
if ($selbstgebaut) echo "  Selbst gebaut: " . implode(' | ', $selbstgebaut) . "\n";
$checks['keine Seite baut ihr Rücksprungziel selbst'] = $selbstgebaut === [];

// ----------------------------------------------------------------------
$fail = 0;
foreach ($checks as $name => $ok) {
    echo ($ok ? 'PASS' : 'FAIL') . "  $name\n";
    if (!$ok) $fail = 1;
}
echo $fail === 0
    ? 'OK: ' . count($checks) . " Pruefungen bestanden.\n"
    : "FEHLGESCHLAGEN.\n";
exit($fail);
