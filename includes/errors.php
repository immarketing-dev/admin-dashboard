<?php
/**
 * Auffangnetz fuer unbehandelte Fehler.
 *
 * display_errors steht auf 0 - richtig, denn ein Stapelspeicherauszug im
 * Browser verraet Pfade, Abfragen und manchmal Zugangsdaten. Die Folge
 * war bisher aber eine vollstaendig leere Seite: kein Hinweis, keine
 * Fehlernummer, nichts, wonach man im Protokoll suchen koennte. Wer den
 * Fehler meldet, kann nur sagen "es geht nicht".
 *
 * Jetzt bekommt jeder Fehler eine kurze Kennung. Sie steht auf der Seite
 * UND im Fehlerprotokoll des Servers - damit ist die eine Zeile
 * auffindbar, die zu dieser einen Meldung gehoert.
 */

/** Kurze, gut vorlesbare Kennung: Zeitpunkt plus Zufall. */
function fehler_kennung(): string
{
    return date('ymd-Hi') . '-' . strtoupper(bin2hex(random_bytes(2)));
}

/**
 * Erwartet der Aufrufer JSON?
 *
 * Die Poll- und Suchendpunkte liefern JSON; bekaemen sie im Fehlerfall
 * HTML, scheiterte im Browser das Auswerten der Antwort und die eigentliche
 * Ursache verschwaende hinter einem Syntaxfehler in der Konsole.
 */
function fehler_will_json(): bool
{
    $skript = basename($_SERVER['SCRIPT_NAME'] ?? '');
    if (strpos($skript, 'ajax_') === 0) {
        return true;
    }
    return isset($_SERVER['HTTP_X_REQUESTED_WITH'])
        || strpos($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') !== false;
}

/**
 * Baut die Fehlerseite als HTML. Reine Erzeugung, keine Ausgabe - so
 * laesst sie sich pruefen, ohne dass ein Ausgabepuffer im Spiel ist.
 */
function fehler_seite_html(string $kennung): string
{
    $k = htmlspecialchars($kennung, ENT_QUOTES);
    return '<!DOCTYPE html><html lang="de"><head><meta charset="utf-8">'
       . '<meta name="viewport" content="width=device-width, initial-scale=1">'
       . '<title>Fehler</title><style>'
       . 'body{font-family:system-ui,sans-serif;background:#f4f7f6;color:#2c3e50;'
       . 'display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0}'
       . '.k{background:#fff;border-radius:16px;box-shadow:0 8px 40px rgba(0,0,0,.10);'
       . 'padding:2.5rem 2rem;max-width:32rem;text-align:center}'
       . 'code{background:#f0f2f5;padding:.2rem .5rem;border-radius:6px;font-size:1.1rem}'
       . 'a{color:#149ddd}</style></head><body><div class="k">'
       . '<h1 style="font-size:1.3rem;margin:0 0 .75rem">Da ist etwas schiefgegangen</h1>'
       . '<p style="margin:0 0 1.25rem">Die Seite konnte nicht geladen werden. '
       . 'Der Fehler wurde protokolliert.</p>'
       . '<p style="margin:0 0 1.25rem">Vorgang: <code>' . $k . '</code></p>'
       . '<p style="margin:0"><a href="index">Zurück zur Startseite</a></p>'
       . '</div></body></html>';
}

/** Dasselbe als JSON, fuer die Endpunkte, die JSON liefern. */
function fehler_seite_json(string $kennung): string
{
    return (string) json_encode(['error' => 'server_error', 'ref' => $kennung]);
}

/**
 * Gibt die Fehlerseite aus. Ohne Einzelheiten - die stehen im Protokoll.
 */
function fehler_ausgeben(string $kennung): void
{
    // Laeuft die Ausgabe schon, ist der Kopf raus und eine Fehlerseite
    // wuerde sich nur an das halb Gesendete anhaengen. Dann lieber nichts.
    if (headers_sent()) {
        return;
    }

    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    http_response_code(500);

    if (fehler_will_json()) {
        header('Content-Type: application/json; charset=utf-8');
        echo fehler_seite_json($kennung);
        return;
    }

    header('Content-Type: text/html; charset=utf-8');
    echo fehler_seite_html($kennung);
}

/**
 * Haengt die Handler ein. Aus config.php aufgerufen, nachdem die
 * Fehlerausgabe konfiguriert ist.
 */
function fehler_handler_einrichten(): void
{
    set_exception_handler(function (Throwable $e): void {
        $kennung = fehler_kennung();
        error_log(sprintf(
            '[%s] %s: %s in %s:%d%s%s',
            $kennung,
            get_class($e),
            $e->getMessage(),
            $e->getFile(),
            $e->getLine(),
            PHP_EOL,
            $e->getTraceAsString()
        ));
        fehler_ausgeben($kennung);
    });

    // Fatale Fehler laufen an set_exception_handler vorbei - etwa ein
    // Aufruf einer Funktion, die es nicht gibt, oder eine erschoepfte
    // Speichergrenze. Nur ueber das Ende der Anfrage sind sie greifbar.
    register_shutdown_function(function (): void {
        $letzter = error_get_last();
        if ($letzter === null) {
            return;
        }
        if (!in_array($letzter['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
            return;
        }

        $kennung = fehler_kennung();
        error_log(sprintf(
            '[%s] Fataler Fehler: %s in %s:%d',
            $kennung,
            $letzter['message'],
            $letzter['file'],
            $letzter['line']
        ));
        fehler_ausgeben($kennung);
    });
}
