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
 * Startet die Session mit den Parametern der Anwendung.
 * Mehrfachaufruf ist unschaedlich - laeuft bereits eine Session, passiert
 * nichts (Cookie-Parameter liessen sich danach ohnehin nicht mehr aendern).
 */
function app_session_start(): void
{
    if (session_status() !== PHP_SESSION_NONE) {
        return;
    }

    session_name(APP_SESSION_NAME);

    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
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
