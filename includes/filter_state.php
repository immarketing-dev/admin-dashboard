<?php
/**
 * Die eingestellte Ansicht einer Liste über eine Sendung hinweg erhalten.
 *
 * Die Listenseiten arbeiten nach dem Muster Sendung → Weiterleitung →
 * Anzeige: ein POST verarbeitet die Änderung und leitet auf die Liste
 * zurück, damit ein Neuladen die Änderung nicht wiederholt. Die
 * Weiterleitung baute ihr Ziel aber neu auf — `header("Location: tasks")`
 * — und warf damit jedes Mal alles weg, was in der Abfrage stand.
 *
 * Wer also nach "Offen" gefiltert hatte und dann einen Status änderte,
 * bekam hinterher wieder die vollständige Liste. Auf tasks.php betraf das
 * acht Filter; einer davon, die Suche, war bereits von Hand über ein
 * verstecktes Feld gerettet worden — die anderen sieben nicht.
 *
 * Diese Datei führt den Zustand stattdessen mit. Er kommt aus zwei
 * Quellen, in dieser Reihenfolge:
 *
 *  1. `$_POST['filter_state']` — für Formulare, die ihr Ziel ausdrücklich
 *     nennen müssen. filter_field() gibt das Feld aus.
 *  2. Die Abfrage der aufgerufenen Adresse. Ein Formular ohne
 *     action-Attribut sendet an genau die URL, auf der es steht, also
 *     einschließlich der Filter. Das ist der Normalfall.
 *
 * Was durchgereicht wird, steht in filter_params() — und zwar als feste
 * Liste je Seite, nicht als "alles, was ankommt". Zwei Gründe: eine
 * Statusmeldung wie msg=1 würde sonst nach jeder Änderung erneut
 * eingeblendet, und ein aus der Anfrage übernommenes Weiterleitungsziel
 * wäre eine offene Weiterleitung.
 */

/**
 * Die Abfrageparameter, die auf einer Seite die Ansicht beschreiben.
 *
 * Bewusst nur Filter, Suche, Sortierung und die gewählte Registerkarte -
 * alles, was jemand eingestellt hat und nach einer Änderung wiedersehen
 * will. NICHT enthalten: einmalige Rückmeldungen (msg, error, saved),
 * Ausgabeschalter (export) und Kennungen, die eine Einzelansicht öffnen
 * (detail, ticket_id) - die gehören zur Anfrage, nicht zur Ansicht.
 *
 * tools/test_filter_state.php prüft diese Liste gegen die Parameter, die
 * die Seiten wirklich auswerten, damit ein neuer Filter nicht still
 * vergessen wird.
 */
function filter_params(string $seite): array
{
    static $karte = [
        'tasks'    => ['q', 'status', 'category', 'contact', 'sort', 'start_month', 'created', 'deadline_filter'],
        'contacts' => ['search', 'type'],
        'finances' => ['tab', 'period', 'month', 'status', 'qstatus', 'type', 'search', 'only_recurring'],
        'tickets'  => ['search', 'status', 'priority'],
        'quotes'   => ['status'],
        'wiki'     => ['q', 'category', 'sort'],
    ];
    return $karte[$seite] ?? [];
}

/**
 * Der Zustand, den die eingegangene Sendung mitgebracht hat.
 *
 * Nur skalare Werte und nur bekannte Namen: was hier herauskommt, geht
 * gleich in eine Adresse.
 */
function filter_current(string $seite): array
{
    $quelle = $_POST['filter_state'] ?? ($_SERVER['QUERY_STRING'] ?? '');
    if (!is_string($quelle)) $quelle = '';

    // Eine überlange Abfrage ist kein normaler Aufruf - abschneiden, statt
    // sie in eine Kopfzeile zu schreiben.
    if (strlen($quelle) > 2000) $quelle = '';

    parse_str($quelle, $vorhanden);

    $werte = [];
    foreach (filter_params($seite) as $name) {
        if (!isset($vorhanden[$name])) continue;
        $wert = $vorhanden[$name];
        if (!is_scalar($wert)) continue;
        $wert = (string) $wert;
        if ($wert === '') continue;
        // Grosszuegig, aber begrenzt: ein Suchbegriff darf lang sein, eine
        // Adresse aber nicht ins Unermessliche wachsen.
        $werte[$name] = mb_substr($wert, 0, 200);
    }
    return $werte;
}

/**
 * Die Adresse der Liste mit den geltenden Filtern.
 *
 * $extra ergänzt oder überschreibt einzelne Werte - etwa msg=1 für eine
 * Rückmeldung. http_build_query kodiert alles neu; ein Zeilenumbruch aus
 * einer Sendung kann die Kopfzeile damit nicht aufbrechen.
 */
function filter_url(string $seite, array $extra = []): string
{
    $werte  = array_merge(filter_current($seite), $extra);
    $abfrage = http_build_query($werte);
    return $seite . ($abfrage !== '' ? '?' . $abfrage : '');
}

/** Weiterleitung auf die Liste, mit den Filtern von vorher. */
function filter_redirect(string $seite, array $extra = []): void
{
    header('Location: ' . filter_url($seite, $extra));
    exit();
}

/**
 * Verstecktes Feld mit dem aktuellen Zustand.
 *
 * Nur nötig, wenn ein Formular sein Ziel ausdrücklich nennen muss - etwa
 * weil es auf eine andere Seite sendet und von dort zurückgeleitet wird.
 * Ein Formular ohne action-Attribut braucht es nicht: es sendet ohnehin
 * an die Adresse mit den Filtern.
 */
function filter_field(string $seite): string
{
    $abfrage = http_build_query(filter_current($seite));
    if ($abfrage === '') return '';
    return '<input type="hidden" name="filter_state" value="'
         . htmlspecialchars($abfrage, ENT_QUOTES) . '">';
}
