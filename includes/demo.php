<?php
/**
 * Demo-Modus: ein öffentlich erreichbares, schreibgeschütztes Panel.
 *
 * Der Modus hebt die Anmeldung auf, damit jeder mit dem Link das Panel
 * ansehen kann - und muss im selben Zug jede Änderung unterbinden. Ohne
 * Anmeldung ist sonst jede der acht Versandstellen ein offenes Spam-Relay,
 * jede der vier Upload-Stellen ein öffentlicher Dateispeicher, und die
 * Überwachungs-URLs auf der Startseite lassen den Server beliebige
 * Adressen abrufen.
 *
 * Der Riegel greift deshalb an einer einzigen Stelle, bevor irgendein
 * Handler läuft: in includes/auth.php für die zwölf Admin-Seiten, in
 * portal.php für das Kundenportal. Das trägt, weil in diesem Projekt
 * KEIN Schreibzugriff über GET läuft - jede zustandsändernde Aktion ist
 * ein POST. tools/check_demo.php hält beides nach.
 *
 * Eingeschaltet wird der Modus ausschließlich über DEMO_MODE in der .env,
 * niemals über die Datenbank oder einen Parameter in der Adresszeile: eine
 * echte Installation soll nicht versehentlich hineinrutschen können.
 */

/** Text für jede Stelle, an der eine Änderung abgelehnt wird. */
const DEMO_HINWEIS = 'Dies ist eine Demo-Version. Änderungen werden nicht gespeichert.';

/**
 * Aktionen, die trotz Schreibschutz durchgelassen werden.
 *
 * Ausschließlich Sendungen, die nur die Sitzung betreffen und nichts in
 * die Datenbank schreiben. Die PIN-Prüfung des Portals steht hier, weil
 * sie sonst den Besucher aus genau dem Bereich aussperrt, den die Demo
 * zeigen soll; portal.php überspringt im Demo-Modus zusätzlich den
 * Fehlversuchszähler, der sonst schreiben würde.
 */
const DEMO_ERLAUBTE_AKTIONEN = [
    'verify_portal_pin',
    // Sprache und Farben: die Handler in settings.php schreiben im
    // Demo-Modus in die Sitzung statt in die Datenbank.
    'save_language', 'save_design', 'reset_design',
    // Die Anordnung der Widgets auf der Startseite. Derselbe Gedanke:
    // includes/dashboard_layout.php legt sie im Demo-Modus in der Sitzung
    // ab, damit jeder Besucher fuer sich schieben kann, ohne die Startseite
    // fuer alle anderen zu veraendern.
    'save_dashboard_layout', 'reset_dashboard_layout',
];

function demo_mode(): bool
{
    return defined('DEMO_MODE') && DEMO_MODE === true;
}

/**
 * Hält die Demo aus den Suchmaschinen.
 *
 * Als HTTP-Kopfzeile und nicht als Zeile in der .htaccess: der
 * Auslieferungsstand wird von tools/deploy.php aus dem Repository
 * erzeugt, eine von Hand ergänzte .htaccess-Zeile wäre beim nächsten
 * Durchlauf wieder weg. Hier steht sie versioniert und gilt für jede
 * Antwort - auch für PDFs, ICS-Dateien und JSON, die kein <meta> tragen.
 *
 * Warum überhaupt: die Demodaten sind erfundene Firmen mit erfundenen
 * Rechnungsbeträgen. Im Suchindex würden sie mit der echten Seite
 * konkurrieren - erst recht, wenn die Demo unter derselben Adresse in
 * einem Unterverzeichnis liegt.
 */
function demo_send_headers(): void
{
    if (!demo_mode() || headers_sent()) return;
    header('X-Robots-Tag: noindex, nofollow', true);
}

/**
 * Erkennt Anfragen, die eine Antwort erwarten statt einer Weiterleitung.
 *
 * Bewusst am Header und nicht an einer Liste von Aktionsnamen: eine Liste
 * veraltet still, sobald jemand einen weiteren AJAX-Aufruf ergänzt, und
 * der Besucher sähe dann eine Weiterleitung, wo das JavaScript JSON
 * erwartet. Die drei vorhandenen Aufrufer setzen den Header.
 */
function demo_ist_ajax(): bool
{
    return strcasecmp($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '', 'XMLHttpRequest') === 0;
}

/**
 * Ziel für die Rückleitung nach einer abgelehnten Sendung.
 *
 * Baut die aktuelle Adresse neu auf, statt einen Wert aus der Anfrage zu
 * übernehmen - eine Weiterleitung auf ein fremdes Ziel ist damit
 * ausgeschlossen. Die vorhandene Abfrage bleibt erhalten, weil das Portal
 * ohne seinen token-Parameter nicht mehr weiß, wer davorsitzt.
 */
function demo_ruecksprung(): string
{
    // Die .htaccess liefert die Seiten ohne Endung aus.
    $ziel = preg_replace('/\.php$/', '', basename($_SERVER['SCRIPT_NAME'] ?? 'index.php'));

    parse_str($_SERVER['QUERY_STRING'] ?? '', $params);
    $params['demo'] = 'blocked';

    // parse_str/http_build_query kodieren neu; die Länge deckelt einen
    // aufgeblähten Rücksprung ab.
    $abfrage = http_build_query($params);
    if (strlen($abfrage) > 500) {
        $abfrage = http_build_query(array_intersect_key($params, ['token' => 1, 'demo' => 1]));
    }

    return $ziel . ($abfrage !== '' ? '?' . $abfrage : '');
}

/** Lehnt eine schreibende Anfrage ab und beendet sie. */
function demo_reject(): void
{
    if (demo_ist_ajax()) {
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        // 'ok' und 'success': die vorhandenen Aufrufer prüfen mal das eine,
        // mal das andere Feld.
        echo json_encode([
            'ok'      => false,
            'success' => false,
            'demo'    => true,
            'error'   => DEMO_HINWEIS,
        ]);
        exit();
    }

    header('Location: ' . demo_ruecksprung());
    exit();
}

/**
 * Der Riegel. Nach dem Sitzungsstart und vor jedem Handler aufrufen.
 */
function demo_guard(): void
{
    if (!demo_mode()) return;
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') return;
    if (in_array($_POST['action'] ?? '', DEMO_ERLAUBTE_AKTIONEN, true)) return;

    demo_reject();
}

/**
 * Zugangscode des Demo-Portals.
 *
 * Steht hier, damit die PIN-Karte genau den Wert anzeigt, den
 * tools/seed_demo.php in die Datenbank schreibt - zwei getrennte
 * Angaben würden früher oder später auseinanderlaufen.
 */
function demo_portal_pin(): string
{
    $pin = (string) env('DEMO_PORTAL_PIN', '');
    return $pin !== '' ? $pin : '1234';
}

/**
 * Einstellungen, die ein Demo-Besucher fuer sich selbst aendern darf.
 *
 * Sprache, Farben und die Anordnung der Widgets auf der Startseite.
 * Die Wahl landet in der Sitzung, nicht in der Datenbank - aus zwei
 * Gruenden:
 *
 *  - Der Datenbankbenutzer der Demo darf nur lesen.
 *  - Waere es die Datenbank, saehe der naechste Besucher, was der
 *    vorherige eingestellt hat. Jeder soll fuer sich ausprobieren
 *    koennen, ohne die Demo fuer andere zu veraendern.
 *
 * Alles andere auf der Einstellungsseite bleibt gesperrt: Firmendaten,
 * Logo, Mailvorlagen und Protokollgrenzen sind Inhalt, nicht Ansicht.
 */
const DEMO_EIGENE_EINSTELLUNGEN = ['ui_language', 'color_primary', 'color_sidebar', 'dashboard_layout'];

/** Der vom Besucher gewaehlte Wert, sonst der uebergebene Standard. */
function demo_einstellung(string $schluessel, string $standard): string
{
    if (!demo_mode()) return $standard;
    if (!in_array($schluessel, DEMO_EIGENE_EINSTELLUNGEN, true)) return $standard;
    if (session_status() !== PHP_SESSION_ACTIVE) return $standard;

    $wert = $_SESSION['demo_' . $schluessel] ?? null;
    return ($wert === null || $wert === '') ? $standard : (string) $wert;
}

/** Merkt sich die Wahl des Besuchers fuer diese Sitzung. */
function demo_einstellung_setzen(string $schluessel, string $wert): void
{
    if (!demo_mode()) return;
    if (!in_array($schluessel, DEMO_EIGENE_EINSTELLUNGEN, true)) return;
    if (session_status() !== PHP_SESSION_ACTIVE) return;

    $_SESSION['demo_' . $schluessel] = $wert;
}

/** Verwirft die Wahl des Besuchers - fuer "auf Standard zuruecksetzen". */
function demo_einstellung_loeschen(string $schluessel): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) return;
    unset($_SESSION['demo_' . $schluessel]);
}
