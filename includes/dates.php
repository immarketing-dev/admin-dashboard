<?php
/**
 * Datumshilfen, die mehr als ein Bereich braucht.
 *
 * tage_ueberfaellig() stand zuerst in includes/reminders.php, weil die
 * Mahnstufen der erste Anlass waren. Die offene-Posten-Liste in
 * includes/reports.php rechnet dasselbe. Sie dort einzubinden hieße,
 * eine Auswertungsseite an den Mailversand und damit an PHPMailer zu
 * hängen - für eine Datumsdifferenz eine seltsame Abhängigkeit. Also
 * hierher, wo beide sie holen können.
 */

/**
 * Wie viele Tage ist dieses Fälligkeitsdatum überschritten?
 *
 * Gerechnet auf Tagesgrenzen, nicht auf Sekunden: eine Rechnung, die
 * heute fällig ist, ist nicht überfällig, und morgen ist sie es um genau
 * einen Tag - unabhängig davon, zu welcher Uhrzeit gefragt wird. Ein
 * noch nicht erreichter Termin ergibt einen negativen Wert.
 */
function tage_ueberfaellig(?string $faellig, string $heute): int
{
    if ($faellig === null || trim($faellig) === '') {
        return 0;
    }
    $f = strtotime(substr($faellig, 0, 10) . ' 00:00:00');
    $h = strtotime(substr($heute, 0, 10) . ' 00:00:00');
    if ($f === false || $h === false) {
        return 0;
    }
    return (int) floor(($h - $f) / 86400);
}
