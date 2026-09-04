<?php
/**
 * Einheitlicher Session-Start fuer die gesamte Anwendung.
 *
 * Vorher gab es drei verschiedene Konfigurationen nebeneinander:
 * auth.php/login.php/logout.php/sso.php mit gehaerteten Parametern,
 * invoice.php mit einem domainweiten Cookie, und portal.php ganz ohne
 * Parameter. Das ist nicht nur unordentlich, sondern hat aktiv Schaden
 * angerichtet - siehe unten.
 *
 * ── Warum ein eigener Session-Name ──────────────────────────────────
 * Der Standardname PHPSESSID wird von jeder PHP-Anwendung benutzt. Laeuft
 * auf einer Schwesterdomain (etwa der Hauptseite unter derselben
 * Registrable Domain) eine zweite Anwendung, die ihr Cookie domainweit
 * setzt - ".example.com" statt nur "admin.example.com" -, dann liegen im
 * Browser ZWEI Cookies namens PHPSESSID. Beide werden mitgeschickt, und
 * PHP liest genau eines davon, nicht zwingend das zuletzt geschriebene.
 *
 * Die Folge sieht aus wie ein kaputter Login: Die Anmeldung schreibt in
 * Session A, der naechste Seitenaufruf liest Session B, findet keine
 * Anmeldung und schickt den Benutzer zurueck aufs Anmeldeformular. Kein
 * Fehler, keine Meldung - es "passiert einfach nichts".
 *
 * Ein eigener Name macht die Sessions dieser Anwendung von allem anderen
 * auf der Domain unabhaengig. Das ist die robuste Loesung: Cookies
 * loeschen hilft nur bis zur naechsten Anmeldung in der anderen Anwendung.
 */

const APP_SESSION_NAME = 'ADMINPANELSESS';

/**
 * Der Demo-Modus braucht einen EIGENEN Cookie-Namen.
 *
 * Sonst hebelt eine Demo im Unterverzeichnis derselben Adresse die
 * Anmeldung der echten Installation aus: die Demo setzt
 * $_SESSION['admin_logged_in'] = true, das Cookie gilt fuer die ganze
 * Host-Adresse, und der naechste Aufruf des echten Panels findet eine
 * gueltige Sitzung vor. Wer den Demo-Link bekommt, haette damit vollen
 * Zugriff auf die echten Daten.
 *
 * Zwei verschiedene Namen koennen sich nicht vermischen - in keiner
 * Richtung. tools/check_demo.php haelt das nach.
 */
function app_session_name(): string
{
    require_once __DIR__ . '/demo.php';
    return demo_mode() ? APP_SESSION_NAME . 'DEMO' : APP_SESSION_NAME;
}

/**
 * Verzeichnis dieser Installation, aus Sicht des Browsers.
 *
 * Liegt die Anwendung im Wurzelverzeichnis, ist das "/" wie bisher. Liegt
 * sie in einem Unterordner, bleibt das Cookie auf diesen Ordner
 * beschraenkt und wird gar nicht erst an den Rest der Adresse geschickt.
 */
function app_session_path(): string
{
    $skript = $_SERVER['SCRIPT_NAME'] ?? '';

    // Nur ein absoluter URL-Pfad ist verwertbar. Auf der Kommandozeile
    // steht hier der Aufrufpfad des Skripts ("tools/foo.php"), und daraus
    // duerfte niemals ein Cookie-Pfad werden.
    if ($skript === '' || $skript[0] !== '/') {
        return '/';
    }

    $verzeichnis = str_replace('\\', '/', dirname($skript));
    if ($verzeichnis === '' || $verzeichnis === '.' || $verzeichnis === '/') {
        return '/';
    }
    return rtrim($verzeichnis, '/') . '/';
}

/**
 * Startet die Session mit den Parametern der Anwendung.
 * Mehrfachaufruf ist unschaedlich - laeuft bereits eine Session, passiert
 * nichts (Cookie-Parameter liessen sich danach ohnehin nicht mehr aendern).
 */
function app_session_start(): void
{
    if (session_status() !== PHP_SESSION_NONE) {
        return;
    }

    session_name(app_session_name());

    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => app_session_path(),
        // Bewusst NICHT isset($_SERVER['HTTPS']): manche Server setzen die
        // Variable auf den String "off". isset() waere dann wahr, das Cookie
        // wuerde als "secure" markiert und ueber eine reine HTTP-Verbindung
        // nie gespeichert - der Login schluege still fehl.
        'secure'   => !empty($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) !== 'off',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    session_start();
}
