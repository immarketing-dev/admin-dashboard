<?php
/**
 * Helfer für die Demodaten.
 *
 * Getrennt von tools/seed_demo.php, damit tests/test_seed_demo.php den
 * Seed gegen eine SQLite-Datenbank laufen lassen kann, ohne die
 * CLI-Hülle mit ihrer MySQL-Verbindung mitzuziehen.
 */

// ── Helfer ──────────────────────────────────────────────────────────

/** Datum relativ zu heute, Format Y-m-d. */
function tag(int $tage): string
{
    return date('Y-m-d', strtotime($tage . ' days'));
}

/** Zeitpunkt relativ zu heute, Format Y-m-d H:i:s. */
function zeit(int $tage, string $uhr = '09:30'): string
{
    return date('Y-m-d', strtotime($tage . ' days')) . ' ' . $uhr . ':00';
}

/** Fügt eine Zeile ein und gibt den vergebenen Schlüssel zurück. */
function ins(string $tabelle, array $daten): int
{
    global $pdo;
    $spalten = array_keys($daten);
    $sql = 'INSERT INTO ' . $tabelle . ' (' . implode(', ', $spalten) . ') VALUES ('
         . implode(', ', array_fill(0, count($spalten), '?')) . ')';
    $pdo->prepare($sql)->execute(array_values($daten));
    return (int) $pdo->lastInsertId();
}

/**
 * Portal-Zugang, aus dem Schlüssel abgeleitet.
 *
 * Bewusst nicht zufällig: der Demo-Link soll ein erneutes Befüllen
 * überleben, sonst ist jeder verschickte Link danach tot. In einer echten
 * Installation wäre das ein Fehler - dort ist der Token ein Geheimnis.
 * In einer Demo, deren Inhalt ohnehin öffentlich ist, ist es der Zweck.
 */
function demo_token(string $schluessel): string
{
    return hash('sha256', 'admin-dashboard-demo::' . $schluessel);
}
