<?php
/**
 * Anordnung der Widgets auf der Startseite.
 *
 * Bis hierher lag die Anordnung fest im Markup: vier Bootstrap-Reihen mit
 * festen Spaltenbreiten. Jetzt liegt sie in den Daten, und das Markup
 * fragt sie ab. Diese Datei ist die einzige Stelle, an der ein Widget
 * bekannt gemacht wird - Standardplatz, Untergrenze und Beschriftung für
 * das Einblendmenü stehen zusammen in dashboard_widgets().
 *
 * Gespeichert wird als JSON unter dem Schlüssel 'dashboard_layout':
 *
 *   {"v":1,"items":{"leads":{"x":4,"y":0,"w":4,"h":4}},"hidden":["webspace"]}
 *
 * In der echten Installation in der settings-Tabelle, in der Demo in der
 * Sitzung des Besuchers. Der Demo-Weg ist kein Notbehelf, sondern der
 * Punkt: die Demo-Datenbank darf nur gelesen werden, und jeder Besucher
 * soll schieben können, ohne die Startseite für alle anderen zu
 * verändern. Dieselbe Überlegung trägt schon Sprache und Farbwahl, siehe
 * DEMO_EIGENE_EINSTELLUNGEN in includes/demo.php.
 */

/** Spalten des Rasters. Zwölf, wie bei Bootstrap - die alten Breiten gehen dadurch unverändert auf. */
const DASH_COLS = 12;

/** Obergrenze für die Höhe eines Widgets. Fängt unsinnige Werte ab, ohne zu gängeln. */
const DASH_MAX_H = 40;

/**
 * Alle Widgets der Startseite mit ihrem Standardplatz.
 *
 * x/y/w/h sind Rasterfelder, nicht Pixel. Die Werte bilden die vier
 * bisherigen Reihen nach: wer nie etwas verschiebt, sieht dieselbe Seite
 * wie vorher.
 *
 * min_w/min_h sind die Untergrenzen beim Ziehen. Sie sind nicht kosmetisch:
 * Projektliste und Notizfeld haben eine Mindesthöhe im CSS, unter der sie
 * aus ihrer Kachel herauswachsen.
 *
 * handle sagt, woran gezogen wird. 'title' heißt: an der Titelzeile des
 * Widgets. Die Kennzahlkarten und die drei schmalen Kacheln haben keine,
 * sie bekommen mit 'bar' einen eigenen schmalen Griff.
 */
function dashboard_widgets(): array
{
    static $w = null;
    if ($w !== null) return $w;

    $w = [
        // Reihe 1
        //
        // Die beiden Kennzahlkarten stehen übereinander in einer
        // schmalen Spalte, nicht nebeneinander. Vorher waren sie so
        // hoch wie die inhaltsreichen Kacheln daneben - bei
        // cellHeight 76 sind vier Reihen 340 Pixel, und darin stand
        // eine einzige Zahl. Die Anfragen daneben wurden dafür mitten
        // im Eintrag abgeschnitten.
        //
        // Zwei Reihen je Karte reichen für Symbol, Zahl und
        // Beschriftung; zusammen füllen sie dieselben vier Reihen, die
        // Zeile bleibt also gerade. Die frei gewordenen zwei Spalten
        // gehen an leads und tickets, die sie brauchen.
        'kpi_projects'    => ['x' => 0, 'y' => 0,  'w' => 2, 'h' => 2, 'min_w' => 2, 'min_h' => 2, 'handle' => 'bar',   'title' => te('Projekte (Kennzahl)')],
        'kpi_contacts'    => ['x' => 0, 'y' => 2,  'w' => 2, 'h' => 2, 'min_w' => 2, 'min_h' => 2, 'handle' => 'bar',   'title' => te('Kontakte (Kennzahl)')],
        'leads'           => ['x' => 2, 'y' => 0,  'w' => 5, 'h' => 4, 'min_w' => 3, 'min_h' => 3, 'handle' => 'title', 'title' => te('Neue Website-Anfragen')],
        'tickets'         => ['x' => 7, 'y' => 0,  'w' => 5, 'h' => 4, 'min_w' => 3, 'min_h' => 3, 'handle' => 'title', 'title' => te('Offene Support-Tickets')],
        // Reihe 2
        'portal_activity' => ['x' => 0, 'y' => 4,  'w' => 8, 'h' => 4, 'min_w' => 4, 'min_h' => 3, 'handle' => 'title', 'title' => te('Portal Aktivitäten')],
        'monitor'         => ['x' => 8, 'y' => 4,  'w' => 4, 'h' => 4, 'min_w' => 3, 'min_h' => 3, 'handle' => 'title', 'title' => te('System-Monitor')],
        // Reihe 3
        'deadlines'       => ['x' => 0, 'y' => 8,  'w' => 4, 'h' => 3, 'min_w' => 3, 'min_h' => 2, 'handle' => 'bar',   'title' => te('Deadlines')],
        'appointments'    => ['x' => 4, 'y' => 8,  'w' => 4, 'h' => 3, 'min_w' => 3, 'min_h' => 2, 'handle' => 'bar',   'title' => te('Termine')],
        'webspace'        => ['x' => 8, 'y' => 8,  'w' => 4, 'h' => 3, 'min_w' => 3, 'min_h' => 2, 'handle' => 'bar',   'title' => te('Webspace')],
        // Reihe 4
        'projects'        => ['x' => 0, 'y' => 11, 'w' => 8, 'h' => 6, 'min_w' => 4, 'min_h' => 4, 'handle' => 'title', 'title' => te('Laufende Projekte')],
        'notes'           => ['x' => 8, 'y' => 11, 'w' => 4, 'h' => 6, 'min_w' => 3, 'min_h' => 3, 'handle' => 'title', 'title' => te('Notizen')],
    ];
    return $w;
}

/**
 * Eine Rasterzahl in ihre Grenzen zwingen.
 *
 * Kein Wert und kein Zahlwert liefern den Standard - '' oder null aus einem
 * halb gefüllten JSON sollen nicht als 0 durchgehen. Alles andere wird
 * beschnitten statt abgelehnt: ein zu breit gezogenes Widget rutscht auf
 * die volle Breite, statt an seinen Ausgangsplatz zurückzuspringen.
 */
function dash_zahl($wert, int $min, int $max, int $standard): int
{
    if ($wert === null || $wert === '' || is_array($wert) || !is_numeric($wert)) return $standard;
    if ($max < $min) return $min;
    return max($min, min($max, (int) $wert));
}

/**
 * Prüft einen gespeicherten Stand und füllt ihn mit dem Standard auf.
 *
 * Der Rückgabewert hat immer genau die Widgets aus dashboard_widgets(),
 * jedes mit gültigen Koordinaten - der Aufrufer muss nichts mehr prüfen.
 *
 * Zwei Fälle, die im Betrieb wirklich vorkommen und deshalb nicht als
 * Fehler behandelt werden:
 *
 *  - Ein Widget fehlt im gespeicherten Stand, weil es das beim Speichern
 *    noch nicht gab. Es bekommt seinen Standardplatz, statt unsichtbar zu
 *    bleiben.
 *  - Der Stand nennt ein Widget, das es nicht mehr gibt. Es fällt weg,
 *    statt eine leere Kachel zu hinterlassen.
 *
 * Unbrauchbares JSON führt zum Standardlayout, nicht zu einer leeren
 * Startseite: eine kaputte Zeile in der settings-Tabelle soll das
 * Dashboard nicht unbenutzbar machen.
 */
function dashboard_layout_validate($json): array
{
    $defaults = dashboard_widgets();
    $layout   = ['items' => [], 'hidden' => []];

    $daten = is_array($json) ? $json : json_decode((string) $json, true);
    if (!is_array($daten)) $daten = [];

    $gespeichert = isset($daten['items']) && is_array($daten['items']) ? $daten['items'] : [];
    $versteckt   = isset($daten['hidden']) && is_array($daten['hidden']) ? $daten['hidden'] : [];

    foreach ($defaults as $id => $std) {
        $pos = isset($gespeichert[$id]) && is_array($gespeichert[$id]) ? $gespeichert[$id] : [];

        // Breite zuerst: sie begrenzt, wie weit rechts das Widget beginnen darf.
        $w = dash_zahl($pos['w'] ?? null, $std['min_w'], DASH_COLS, $std['w']);
        $h = dash_zahl($pos['h'] ?? null, $std['min_h'], DASH_MAX_H, $std['h']);
        $x = dash_zahl($pos['x'] ?? null, 0, DASH_COLS - $w, $std['x']);
        $y = dash_zahl($pos['y'] ?? null, 0, 500, $std['y']);

        $ist_versteckt = in_array($id, $versteckt, true);

        $layout['items'][$id] = [
            'x' => $x, 'y' => $y, 'w' => $w, 'h' => $h,
            'min_w'  => $std['min_w'],
            'min_h'  => $std['min_h'],
            'handle' => $std['handle'],
            'title'  => $std['title'],
            'hidden' => $ist_versteckt,
        ];
        if ($ist_versteckt) $layout['hidden'][] = $id;
    }

    return $layout;
}

/**
 * Die gültige Anordnung für diesen Besucher.
 *
 * demo_einstellung() liefert außerhalb der Demo den übergebenen Wert, hier
 * also den Eintrag aus der settings-Tabelle. In der Demo liefert es den
 * Stand aus der Sitzung.
 */
function dashboard_layout_load(): array
{
    $gespeichert = setting('dashboard_layout', '');
    if (function_exists('demo_einstellung')) {
        $gespeichert = demo_einstellung('dashboard_layout', $gespeichert);
    }
    return dashboard_layout_validate($gespeichert);
}

/**
 * Nimmt eine neue Anordnung entgegen.
 *
 * Gespeichert wird der geprüfte Stand, nicht der eingesandte: was in der
 * Datenbank landet, hat die Prüfung oben passiert und trägt nur bekannte
 * Widgetnamen.
 *
 * In der Demo geht derselbe Weg in die Sitzung. Das ist der Grund, warum
 * save_dashboard_layout in DEMO_ERLAUBTE_AKTIONEN stehen darf: die Aktion
 * fasst die Datenbank dort nicht an.
 */
function dashboard_layout_save(PDO $pdo, $eingang): array
{
    $layout = dashboard_layout_validate($eingang);

    $roh = ['v' => 1, 'items' => [], 'hidden' => $layout['hidden']];
    foreach ($layout['items'] as $id => $i) {
        $roh['items'][$id] = ['x' => $i['x'], 'y' => $i['y'], 'w' => $i['w'], 'h' => $i['h']];
    }
    $json = json_encode($roh, JSON_UNESCAPED_SLASHES);

    if (function_exists('demo_mode') && demo_mode()) {
        demo_einstellung_setzen('dashboard_layout', $json);
        return $layout;
    }

    $pdo->prepare("INSERT INTO settings (k,v) VALUES ('dashboard_layout',?) ON DUPLICATE KEY UPDATE v=?")
        ->execute([$json, $json]);

    return $layout;
}

/** Zurück auf den Standard: den gespeicherten Stand einfach wegwerfen. */
function dashboard_layout_reset(PDO $pdo): void
{
    if (function_exists('demo_mode') && demo_mode()) {
        demo_einstellung_loeschen('dashboard_layout');
        return;
    }
    $pdo->exec("DELETE FROM settings WHERE k = 'dashboard_layout'");
}

// ---------------------------------------------------------------------
// Markup
// ---------------------------------------------------------------------

/** Der einmal geladene Stand - dash_widget_open() und das Menü teilen ihn sich. */
function dash_layout(): array
{
    static $layout = null;
    if ($layout === null) $layout = dashboard_layout_load();
    return $layout;
}

/**
 * Öffnet die Kachel eines Widgets.
 *
 * Die Koordinaten stehen als gs-Attribute im Markup, nicht erst im
 * JavaScript. Gridstack liest sie beim Start und muss nichts umsortieren -
 * die Seite steht sofort richtig da, statt kurz im Standardlayout
 * aufzublitzen und dann zu springen.
 *
 * Ausgeblendete Kacheln werden trotzdem gerendert, nur unsichtbar: das
 * JavaScript legt sie beim Start beiseite und holt sie ohne Neuladen
 * zurück, wenn im Menü das Häkchen gesetzt wird.
 */
function dash_widget_open(string $id): void
{
    $i = dash_layout()['items'][$id] ?? null;
    if ($i === null) return; // unbekanntes Widget: nichts ausgeben statt kaputtes Markup

    printf(
        '<div class="grid-stack-item" gs-id="%s" gs-x="%d" gs-y="%d" gs-w="%d" gs-h="%d" gs-min-w="%d" gs-min-h="%d"%s>',
        htmlspecialchars($id, ENT_QUOTES),
        $i['x'], $i['y'], $i['w'], $i['h'], $i['min_w'], $i['min_h'],
        $i['hidden'] ? ' data-dash-hidden="1" style="display:none;"' : ''
    );
    echo '<div class="grid-stack-item-content">';

    // Widgets ohne Titelzeile brauchen eine eigene Fläche zum Anfassen.
    if ($i['handle'] === 'bar') {
        echo '<div class="dash-drag-bar" aria-hidden="true"><i class="bi bi-grip-horizontal"></i></div>';
    }

    // Der Ausblendknopf sitzt in der Kachel, nicht in der Titelzeile: die
    // Titelzeilen tragen bereits eigene Schaltflächen, und nicht jedes
    // Widget hat überhaupt eine.
    printf(
        '<button type="button" class="dash-hide-btn" data-dash-hide="%s" title="%s" aria-label="%s"><i class="bi bi-x-lg"></i></button>',
        htmlspecialchars($id, ENT_QUOTES),
        htmlspecialchars(te('Widget ausblenden'), ENT_QUOTES),
        htmlspecialchars(te('Widget ausblenden'), ENT_QUOTES)
    );
}

/** Schließt die Kachel: erst den Inhalt, dann das Rasterfeld. */
function dash_widget_close(): void
{
    echo '</div></div>';
}
