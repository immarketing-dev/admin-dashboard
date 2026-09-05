<?php
/**
 * Zweisprachigkeit: Deutsch und Englisch.
 *
 * Der deutsche Text ist der Schluessel. Im Code steht t('Speichern'),
 * lang/en.php bildet 'Speichern' => 'Save' ab. Das hat drei Gruende:
 *
 *  - Der Code bleibt lesbar. Ein t('common.save') sagt beim Ueberfliegen
 *    nichts darueber, was auf der Schaltflaeche steht.
 *  - Es sind keine tausend Schluesselnamen zu erfinden und einheitlich zu
 *    halten.
 *  - Fehlt eine Uebersetzung, erscheint der deutsche Text - nie ein leeres
 *    Feld und nie ein roher Schluessel.
 *
 * Der Preis: aendert jemand den deutschen Text, geht die Zuordnung still
 * verloren. Genau dagegen meldet tools/check_i18n.php verwaiste Eintraege.
 *
 * ── Was hier NICHT uebersetzt wird ──────────────────────────────────
 * Datenbankwerte. In tasks.status steht 'Offen', in finances.status
 * 'Bezahlt', und saemtliche Filter, Abfragen und ENUM-Definitionen haengen
 * daran. Diese Werte bleiben deutsch in der Datenbank; uebersetzt wird
 * ausschliesslich bei der Anzeige. Wuerde beim Speichern uebersetzt,
 * faenden die Filter ihre eigenen Datensaetze nicht mehr, und der Bestand
 * waere nach dem ersten Umschalten gemischt.
 */

const SPRACHEN = ['de', 'en'];

/**
 * Die aktuelle Sprache.
 *
 * Standard ist die Einstellung ui_language. Das Portal setzt sie ueber
 * sprache_setzen() auf die Sprache des jeweiligen Kontakts um - so kann
 * das Panel deutsch bleiben, waehrend ein einzelner Kunde sein Portal auf
 * Englisch sieht.
 */
function lang(): string
{
    // Eine gesetzte Sprache gilt immer und sofort - auch wenn lang()
    // vorher schon einmal gefragt wurde.
    if (isset($GLOBALS['__sprache'])) return $GLOBALS['__sprache'];

    $sprache = 'de';
    // setting() steht erst zur Verfuegung, wenn config.php durch ist -
    // deshalb erst beim ersten Aufruf nachsehen, nicht beim Einbinden.
    if (function_exists('setting')) {
        $gesetzt = setting('ui_language', 'de');
        if (in_array($gesetzt, SPRACHEN, true)) $sprache = $gesetzt;
    }

    // In der Demo darf jeder Besucher fuer sich umschalten; die Wahl
    // liegt in seiner Sitzung und beruehrt niemanden sonst.
    if (function_exists('demo_einstellung')) {
        $eigen = demo_einstellung('ui_language', $sprache);
        if (in_array($eigen, SPRACHEN, true)) $sprache = $eigen;
    }
    $GLOBALS['__sprache'] = $sprache;
    return $sprache;
}

/**
 * Setzt die Sprache fuer diese Anfrage.
 *
 * Das Portal nutzt das, um der Sprache des jeweiligen Kontakts zu folgen,
 * waehrend das Panel bei der eingestellten bleibt. Vor der ersten Ausgabe
 * aufrufen.
 */
function sprache_setzen(string $sprache): void
{
    if (in_array($sprache, SPRACHEN, true)) {
        $GLOBALS['__sprache'] = $sprache;
    }
}

/**
 * Uebersetzt einen Text.
 *
 * Weitere Argumente werden wie bei sprintf eingesetzt:
 *   t('%d Einträge gelöscht.', 5)
 */
function t(string $text, ...$werte): string
{
    static $tabellen = [];

    $sprache = lang();
    if (!isset($tabellen[$sprache])) {
        $datei = dirname(__DIR__) . '/lang/' . $sprache . '.php';
        // Deutsch braucht keine Tabelle - der Schluessel ist der Text.
        $tabellen[$sprache] = ($sprache !== 'de' && is_file($datei)) ? require $datei : [];
    }

    $aus = $tabellen[$sprache][$text] ?? $text;
    return $werte === [] ? $aus : vsprintf($aus, $werte);
}

/**
 * Datum in der Schreibweise der aktuellen Sprache.
 *
 * IntlDateFormatter waere der Lehrbuchweg, ist aber auf einfachen
 * Hosting-Paketen oft nicht installiert - und das Panel soll ohne
 * Voraussetzungen laufen.
 */
function fmt_datum($wann, string $stil = 'datum'): string
{
    if ($wann === null || $wann === '' || $wann === '0000-00-00') return '';
    $zeit = is_numeric($wann) ? (int) $wann : strtotime((string) $wann);
    if ($zeit === false) return (string) $wann;

    $muster = [
        'de' => ['datum' => 'd.m.Y', 'datum_zeit' => 'd.m.Y H:i', 'zeit' => 'H:i',
                 'kurz'  => 'd.m.',  'monat_jahr' => 'm/Y'],
        'en' => ['datum' => 'M j, Y', 'datum_zeit' => 'M j, Y g:i A', 'zeit' => 'g:i A',
                 'kurz'  => 'M j',    'monat_jahr' => 'm/Y'],
    ];
    $sprache = lang();
    $format = $muster[$sprache][$stil] ?? $muster[$sprache]['datum'];

    return date($format, $zeit);
}

/** Geldbetrag in der Schreibweise der aktuellen Sprache. */
function fmt_betrag($betrag, bool $mit_waehrung = true): string
{
    $zahl = (float) $betrag;
    if (lang() === 'en') {
        $text = number_format($zahl, 2, '.', ',');
        return $mit_waehrung ? '€' . $text : $text;
    }
    $text = number_format($zahl, 2, ',', '.');
    return $mit_waehrung ? $text . ' €' : $text;
}

/** Ganze Zahl mit Tausendertrennzeichen. */
function fmt_zahl($zahl): string
{
    return lang() === 'en'
        ? number_format((float) $zahl, 0, '.', ',')
        : number_format((float) $zahl, 0, ',', '.');
}

/**
 * Wie t(), aber HTML-sicher.
 *
 * Der Regelfall in Vorlagen. Uebersetzungen sind zwar eigener Text und
 * kein Fremdeingabe, enthalten aber Zeichen wie & - und die gehoeren in
 * HTML maskiert, sonst erzeugen sie ungueltiges Markup.
 */
function te(string $text, ...$werte): string
{
    return htmlspecialchars(t($text, ...$werte), ENT_QUOTES, 'UTF-8');
}

/**
 * Die Monatsnamen, an einer Stelle.
 *
 * Sie standen dreimal im Projekt (calendar.php, finances.php,
 * tasks.php) und gingen an allen drei Stellen ungefiltert in die
 * Ausgabe. Uebersetzt werden sie ueber datenwert() an der
 * Anzeigestelle - dort sind sie eine Variable, hier stehen die
 * Literale.
 *
 * Der Schluessel ist zweistellig, weil finances.php mit date('m')
 * hineingreift.
 */
function monatsnamen(): array
{
    return [
        '01' => 'Januar',    '02' => 'Februar',  '03' => 'März',
        '04' => 'April',     '05' => 'Mai',      '06' => 'Juni',
        '07' => 'Juli',      '08' => 'August',   '09' => 'September',
        '10' => 'Oktober',   '11' => 'November', '12' => 'Dezember',
    ];
}

/**
 * Uebersetzt einen Datenbankwert fuer die Anzeige.
 *
 * In tasks.status steht 'Offen', in finances.status 'Bezahlt' - deutsche
 * Zeichenketten, an denen Filter, Abfragen und ENUM-Definitionen haengen.
 * Diese Funktion uebersetzt sie ausschliesslich fuer die Ausgabe. Sie darf
 * NIE auf einem Weg landen, der zurueck in die Datenbank fuehrt: sonst
 * stuende dort nach dem ersten Umschalten 'Paid' neben 'Bezahlt', und die
 * Filter fanden ihre eigenen Datensaetze nicht mehr.
 *
 * Ein unbekannter Wert kommt unveraendert zurueck - eine neue Statusstufe
 * verschwindet dadurch nicht, sie bleibt nur deutsch, bis jemand sie hier
 * ergaenzt.
 */
function datenwert(string $wert): string
{
    static $tabellen = [];
    $sprache = lang();

    if (!isset($tabellen[$sprache])) {
        $tabellen[$sprache] = [
            // Projekt- und Rechnungszustaende
            'Offen'            => t('Offen'),
            'In Bearbeitung'   => t('In Bearbeitung'),
            'Erledigt'         => t('Erledigt'),
            'Storniert'        => t('Storniert'),
            'Bezahlt'          => t('Bezahlt'),
            'Überfällig'       => t('Überfällig'),
            // Angebotszustaende
            'Entwurf'          => t('Entwurf'),
            'Gesendet'         => t('Gesendet'),
            'Angenommen'       => t('Angenommen'),
            'Abgelehnt'        => t('Abgelehnt'),
            'Rückfrage'        => t('Rückfrage'),
            // Kontaktarten
            'Kunde'            => t('Kunde'),
            'Interessent'      => t('Interessent'),
            'Geschäftspartner' => t('Geschäftspartner'),
            'Lieferant'        => t('Lieferant'),
            // Dringlichkeiten
            'Niedrig'          => t('Niedrig'),
            'Mittel'           => t('Mittel'),
            'Hoch'             => t('Hoch'),
            'Kritisch'         => t('Kritisch'),
            // Terminarten
            'Termin'           => t('Termin'),
            'Meeting'          => t('Meeting'),
            'Anruf'            => t('Anruf'),
            'Deadline'         => t('Deadline'),
            'Sonstiges'        => t('Sonstiges'),
            // Altersstufen der offenen Posten (includes/reports.php).
            // Zusammengesetzt aus OP_STUFEN; wer die Stufen aendert,
            // bekommt hier deutsche Namen zurueck, bis er sie ergaenzt.
            'nicht fällig'     => t('nicht fällig'),
            '1–30 Tage'        => t('1–30 Tage'),
            '31–60 Tage'       => t('31–60 Tage'),
            '61–90 Tage'       => t('61–90 Tage'),
            'über 90 Tage'     => t('über 90 Tage'),
            // Rollen und ihre Erklaerungen (includes/users.php). Sie
            // kommen aus rollen() und stehen an der Anzeigestelle als
            // Variable - dasselbe wie bei den Intervallen darunter.
            'Verwaltung'       => t('Verwaltung'),
            'Mitarbeit'        => t('Mitarbeit'),
            'Buchhaltung'      => t('Buchhaltung'),
            'Sieht und ändert alles, einschließlich Einstellungen und Benutzern.'
                => t('Sieht und ändert alles, einschließlich Einstellungen und Benutzern.'),
            'Projekte, Aufgaben, Kontakte, Tickets, Wiki und Kalender. Keine Finanzen, keine Einstellungen.'
                => t('Projekte, Aufgaben, Kontakte, Tickets, Wiki und Kalender. Keine Finanzen, keine Einstellungen.'),
            'Finanzen, Angebote, Auswertungen und Kontakte. Keine Projekte, keine Einstellungen.'
                => t('Finanzen, Angebote, Auswertungen und Kontakte. Keine Projekte, keine Einstellungen.'),
            // Wiederholungsintervalle (includes/recurring.php)
            'Monatlich'        => t('Monatlich'),
            'Vierteljährlich'  => t('Vierteljährlich'),
            'Jährlich'         => t('Jährlich'),
            // Seitenüberschriften. Sie stehen auf jeder Seite als
            // Zuweisung an $page_heading und werden in
            // includes/layout_start.php ausgegeben - an der Ausgabestelle
            // ist es eine Variable, hier steht das Literal.
            'Übersicht'          => t('Übersicht'),
            'Kalender'           => t('Kalender'),
            'Projekte & Aufgaben'=> t('Projekte & Aufgaben'),
            'Projekt Board'      => t('Projekt Board'),
            'Wiki & Snippets'    => t('Wiki & Snippets'),
            'CRM & Kontakte'     => t('CRM & Kontakte'),
            'Finanz-Zentrale'    => t('Finanz-Zentrale'),
            'Auswertungen'       => t('Auswertungen'),
            'Support Zentrale'   => t('Support Zentrale'),
            'System-Logs'        => t('System-Logs'),
            'Papierkorb'         => t('Papierkorb'),
            'Einstellungen'      => t('Einstellungen'),
            'Kein Zugriff'       => t('Kein Zugriff'),
            // Beschriftungen der Mailvorlagen (includes/mail_templates.php).
            // Sie erreichen settings.php als Variable.
            'Meilenstein abgeschlossen'
                => t('Meilenstein abgeschlossen'),
            'Geht an den Projektkontakt, sobald ein Meilenstein abgehakt wird.'
                => t('Geht an den Projektkontakt, sobald ein Meilenstein abgehakt wird.'),
            'Portal-Zugang'
                => t('Portal-Zugang'),
            'Die Einladung mit Zugangslink und QR-Code, versendet aus den Kontakten.'
                => t('Die Einladung mit Zugangslink und QR-Code, versendet aus den Kontakten.'),
            'Antwort auf eine Support-Anfrage'
                => t('Antwort auf eine Support-Anfrage'),
            'Geht an den Kunden, wenn Sie eine Anfrage öffentlich beantworten.'
                => t('Geht an den Kunden, wenn Sie eine Anfrage öffentlich beantworten.'),
            'Termineinladung'
                => t('Termineinladung'),
            'Die Einladung aus dem Kalender, mit Kalenderdatei im Anhang.'
                => t('Die Einladung aus dem Kalender, mit Kalenderdatei im Anhang.'),
            'Passwort zurücksetzen'
                => t('Passwort zurücksetzen'),
            'Geht an Sie selbst, wenn Sie im Anmeldebild "Passwort vergessen" benutzen.'
                => t('Geht an Sie selbst, wenn Sie im Anmeldebild "Passwort vergessen" benutzen.'),
            'Angebot versenden (Vorbelegung)'
                => t('Angebot versenden (Vorbelegung)'),
            'Rechnung versenden (Vorbelegung)'
                => t('Rechnung versenden (Vorbelegung)'),
            'Zahlungserinnerung (Vorbelegung)'
                => t('Zahlungserinnerung (Vorbelegung)'),
            'Füllt Betreff und Text im Versandfenster vor. Reiner Text, kein Rahmen.'
                => t('Füllt Betreff und Text im Versandfenster vor. Reiner Text, kein Rahmen.'),
            // Abschnitte der Auswertung (reports.php). Sie stehen im
            // Hinweis, wenn einer davon nicht geladen werden konnte.
            'Offene Posten nach Alter'        => t('Offene Posten nach Alter'),
            'Umsatz je Kunde'                 => t('Umsatz je Kunde'),
            'Geleistet, noch nicht berechnet' => t('Geleistet, noch nicht berechnet'),
            // Kennzeichen der Portal-Aktivitaeten
            // (includes/portal_activity.php). Sie stehen dort im
            // Datensatz und erreichen die Ausgabe als Variable.
            'DATEI'            => t('DATEI'),
            'BESTÄTIGT'        => t('BESTÄTIGT'),
            'NEUES FEEDBACK'   => t('NEUES FEEDBACK'),
            'KOMMENTAR'        => t('KOMMENTAR'),
            // Monatsnamen (monatsnamen()). Sie erreichen die Ausgabe
            // in calendar.php, finances.php und tasks.php als Variable.
            'Januar'           => t('Januar'),
            'Februar'          => t('Februar'),
            'März'             => t('März'),
            'April'            => t('April'),
            'Mai'              => t('Mai'),
            'Juni'             => t('Juni'),
            'Juli'             => t('Juli'),
            'August'           => t('August'),
            'September'        => t('September'),
            'Oktober'          => t('Oktober'),
            'November'         => t('November'),
            'Dezember'         => t('Dezember'),
            // Farbnamen der Termine (calendar.php). Sie stehen dort als
            // Werte in $preset_colors und erreichen die Ausgabe als
            // Variable.
            'Blau'             => t('Blau'),
            'Grün'             => t('Grün'),
            'Orange'           => t('Orange'),
            'Rot'              => t('Rot'),
            'Lila'             => t('Lila'),
            'Türkis'           => t('Türkis'),
            'Pink'             => t('Pink'),
            'Dunkel'           => t('Dunkel'),
            // Terminzustaende
            'Geplant'          => t('Geplant'),
            'Bestätigt'        => t('Bestätigt'),
            'Abgeschlossen'    => t('Abgeschlossen'),
            'Abgesagt'         => t('Abgesagt'),
        ];
    }

    return $tabellen[$sprache][$wert] ?? $wert;
}

/**
 * Uebersetzter Text als fertiges JavaScript-Literal, mit Anfuehrungszeichen.
 *
 *   alert(<?= tjs('Wirklich löschen?') ?>);
 *
 * te() waere hier falsch: es maskiert fuer HTML, und ein &#039; mitten in
 * einer JavaScript-Zeichenkette erscheint dem Benutzer woertlich. json_encode
 * erzeugt dagegen ein gueltiges Literal samt Anfuehrungszeichen.
 *
 * Die HEX-Schalter sind kein Zierrat: ohne JSON_HEX_TAG beendet ein
 * </script> im Text den Script-Block, und der Rest der Seite landet als
 * Text im Browser.
 */
function tjs(string $text, ...$werte): string
{
    return json_encode(
        t($text, ...$werte),
        JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE
    );
}
