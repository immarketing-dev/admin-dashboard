<?php
/**
 * Datensicherung der Datenbank.
 *
 * Bis hierher gab es keine. Eine misslungene Migration, ein verrutschtes
 * DELETE oder ein Hoster, der ein Paket löscht, waren ohne Rückweg — und
 * das fällt erst auf, wenn man den Rückweg braucht.
 *
 * Bewusst ohne mysqldump und ohne exec(): auf einfachen Hosting-Paketen
 * gibt es beides nicht. Geschrieben wird über PDO, mit demselben
 * Verfahren, das tools/export_demo_sql.php seit längerem benutzt.
 *
 * WAS GESICHERT WIRD: die Datenbank. Nicht die Dateien unter uploads/ —
 * Rechnungs-PDFs, Belege, Portaldateien. Dafür ist die Dateisicherung des
 * Hosters zuständig. Eine Sicherung, die nur die Hälfte umfasst und so
 * aussieht, als wäre sie vollständig, ist gefährlicher als keine; deshalb
 * steht es hier, im Kopf jeder erzeugten Datei und auf der
 * Einstellungsseite.
 */

/** Wie viele Stände aufgehoben werden. */
const SICHERUNG_BEHALTEN = 7;

/** Präfix der Dateinamen — danach wird beim Aufräumen gesucht. */
const SICHERUNG_PRAEFIX = 'sicherung_';

/**
 * Ein Wert als SQL-Literal.
 *
 * Wortgleich zu sql_wert() in tools/export_demo_sql.php — dort erprobt,
 * hier gebraucht. Zahlen bleiben in Anführungszeichen, damit eine
 * Postleitzahl ihre führende Null behält.
 */
function sicherung_wert($wert): string
{
    if ($wert === null)  return 'NULL';
    if (is_int($wert))   return (string) $wert;
    if (is_float($wert)) return rtrim(rtrim(sprintf('%.4F', $wert), '0'), '.');

    return "'" . strtr((string) $wert, [
        "\\"   => "\\\\",
        "'"    => "\\'",
        "\n"   => "\\n",
        "\r"   => "\\r",
        "\x00" => "\\0",
        "\x1a" => "\\Z",
    ]) . "'";
}

/**
 * Legt die Sperre an, wenn das Verzeichnis im Webstamm liegt.
 *
 * Dieselbe Datei wie über uploads/: ohne sie läge die Sicherung offen im
 * Netz, und darin steht jeder Datensatz und jeder Passwort-Hash.
 */
function sicherung_sperre_anlegen(string $verzeichnis): void
{
    $datei = $verzeichnis . '/.htaccess';
    if (is_file($datei)) {
        return;
    }
    @file_put_contents($datei,
        "# Datensicherungen - kein direkter Zugriff ueber den Webserver.\n"
      . "# In diesen Dateien steht der gesamte Datenbestand, einschliesslich\n"
      . "# aller Passwort-Hashes. Diese Datei nicht entfernen.\n"
      . "<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n"
      . "<IfModule !mod_authz_core.c>\n    Order deny,allow\n    Deny from all\n</IfModule>\n"
      . "\nOptions -Indexes\n"
    );
}

/**
 * Wohin gesichert wird.
 *
 * Zuerst außerhalb des Webverzeichnisses — eine .sql-Datei im
 * Dokumentenstamm wäre abrufbar, und in ihr steht alles. Lässt sich dort
 * nicht schreiben (auf manchen Paketen ist oberhalb des Stamms nichts
 * erlaubt), dann uploads/backups/ mit derselben Sperre, die schon über
 * den Kundenunterlagen liegt.
 *
 * @return array{0:string,1:bool} [Verzeichnis, außerhalb des Webstamms]
 */
function sicherung_verzeichnis(string $wurzel, string $eingestellt = ''): array
{
    $wurzel     = rtrim(str_replace('\\', '/', $wurzel), '/');
    $kandidaten = [];

    if (trim($eingestellt) !== '') {
        $kandidaten[] = [rtrim(str_replace('\\', '/', trim($eingestellt)), '/'), true];
    }
    $kandidaten[] = [dirname($wurzel) . '/backups', true];
    $kandidaten[] = [$wurzel . '/uploads/backups', false];

    foreach ($kandidaten as [$pfad, $ausserhalb]) {
        if (!is_dir($pfad) && !@mkdir($pfad, 0750, true) && !is_dir($pfad)) {
            continue;
        }
        if (!is_writable($pfad)) {
            continue;
        }
        // Liegt der eingestellte Pfad im Webstamm, braucht er die Sperre
        // genauso — die Angabe in den Einstellungen sagt nichts darüber,
        // ob der Ordner erreichbar ist.
        if (!$ausserhalb || strpos($pfad . '/', $wurzel . '/') === 0) {
            sicherung_sperre_anlegen($pfad);
            $ausserhalb = false;
        }
        return [$pfad, $ausserhalb];
    }

    return ['', false];
}

/** Die Tabellen aus install/schema.sql — dieselbe Quelle wie überall. */
function sicherung_tabellen(string $schemaDatei): array
{
    if (!is_readable($schemaDatei)) {
        return [];
    }
    preg_match_all(
        '/CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?`?([a-z_][a-z0-9_]*)`?/i',
        (string) file_get_contents($schemaDatei),
        $treffer
    );
    return array_values(array_unique(array_map('strtolower', $treffer[1])));
}

/** Dateiname eines Standes. */
function sicherung_dateiname(string $jetzt = 'now'): string
{
    $zeit = strtotime($jetzt);
    return SICHERUNG_PRAEFIX . date('Y-m-d_His', $zeit === false ? time() : $zeit) . '.sql';
}

/**
 * Schreibt den Abzug.
 *
 * Die Struktur kommt aus SHOW CREATE TABLE, wenn der Treiber das kann.
 * Kann er es nicht, steht im Kopf der Datei, dass install/schema.sql
 * zuerst einzuspielen ist — eine Datei, die schweigend nur Daten enthält,
 * wäre die schlechtere Überraschung.
 *
 * @return array{tabellen:int,zeilen:int,bytes:int,struktur:bool}
 */
function sicherung_schreiben(PDO $pdo, string $datei, array $tabellen): array
{
    $out = @fopen($datei, 'w');
    if (!$out) {
        throw new RuntimeException('Sicherungsdatei nicht beschreibbar: ' . $datei);
    }

    $struktur = true;
    try {
        $pdo->query('SHOW CREATE TABLE `settings`');
    } catch (Throwable $e) {
        $struktur = false;
    }

    fwrite($out,
        "-- ---------------------------------------------------------------------\n"
      . "-- Datensicherung des Admin-Dashboards\n"
      . '-- Erzeugt am ' . date('d.m.Y H:i') . "\n"
      . "--\n"
      . ($struktur
            ? "-- Enthaelt Struktur und Daten. Zum Zurueckspielen in eine LEERE\n"
              . "-- Datenbank importieren.\n"
            : "-- Enthaelt NUR Daten, nicht die Struktur: dieser Server lieferte\n"
              . "-- kein SHOW CREATE TABLE. Vor dem Import zuerst\n"
              . "-- install/schema.sql einspielen.\n")
      . "--\n"
      . "-- NICHT enthalten: die Dateien unter uploads/ (Rechnungs-PDFs, Belege,\n"
      . "-- Portaldateien). Dafuer ist die Dateisicherung des Hosters zustaendig.\n"
      . "-- ---------------------------------------------------------------------\n\n"
      . "SET NAMES utf8mb4;\n"
      . "SET FOREIGN_KEY_CHECKS = 0;\n\n"
    );

    $zeilen_gesamt = 0;
    $tabellen_zahl = 0;

    foreach ($tabellen as $tabelle) {
        if ($struktur) {
            try {
                $z = $pdo->query('SHOW CREATE TABLE `' . $tabelle . '`')->fetch(PDO::FETCH_NUM);
                if (isset($z[1])) {
                    fwrite($out, 'DROP TABLE IF EXISTS `' . $tabelle . "`;\n" . $z[1] . ";\n\n");
                }
            } catch (Throwable $e) {
                // Eine Tabelle, die es hier nicht gibt, ist kein Grund
                // abzubrechen — der Rest der Sicherung ist mehr wert.
                error_log('Sicherung: Struktur von ' . $tabelle . ' nicht lesbar: ' . $e->getMessage());
                continue;
            }
        }

        try {
            $daten = $pdo->query('SELECT * FROM ' . $tabelle)->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            error_log('Sicherung: ' . $tabelle . ' nicht lesbar: ' . $e->getMessage());
            continue;
        }

        $tabellen_zahl++;
        if ($daten === []) {
            continue;
        }

        // Ohne Struktur wird in eine Datenbank importiert, die aus
        // install/schema.sql entstanden ist - und die legt in settings
        // bereits schema_version an. Ein INSERT darauf scheitert am
        // Primaerschluessel, mitten im Zurueckspielen. Mit Struktur
        // steht ohnehin ein DROP TABLE davor.
        if (!$struktur) {
            fwrite($out, 'DELETE FROM `' . $tabelle . "`;\n");
        }

        $spalten      = array_keys($daten[0]);
        $spaltenliste = '`' . implode('`, `', $spalten) . '`';

        // In Blöcken: eine einzelne Riesenanweisung läuft je nach
        // Servereinstellung gegen max_allowed_packet.
        foreach (array_chunk($daten, 50) as $block) {
            $werte = [];
            foreach ($block as $zeile) {
                $einzeln = [];
                foreach ($spalten as $s) {
                    $einzeln[] = sicherung_wert($zeile[$s] ?? null);
                }
                $werte[] = '(' . implode(', ', $einzeln) . ')';
            }
            fwrite($out, 'INSERT INTO `' . $tabelle . '` (' . $spaltenliste . ") VALUES\n"
                       . implode(",\n", $werte) . ";\n");
        }
        fwrite($out, "\n");
        $zeilen_gesamt += count($daten);
    }

    fwrite($out, "SET FOREIGN_KEY_CHECKS = 1;\n");
    fclose($out);

    return [
        'tabellen' => $tabellen_zahl,
        'zeilen'   => $zeilen_gesamt,
        'bytes'    => (int) filesize($datei),
        'struktur' => $struktur,
    ];
}

/** Die vorhandenen Stände, neueste zuerst. */
function sicherungen_auflisten(string $verzeichnis): array
{
    if ($verzeichnis === '' || !is_dir($verzeichnis)) {
        return [];
    }
    $aus = [];
    foreach (glob($verzeichnis . '/' . SICHERUNG_PRAEFIX . '*.sql') ?: [] as $datei) {
        $aus[] = [
            'name'  => basename($datei),
            'pfad'  => $datei,
            'bytes' => (int) filesize($datei),
            'zeit'  => date('Y-m-d H:i:s', (int) filemtime($datei)),
        ];
    }
    // Der Name trägt den Zeitstempel, also sortiert er sich selbst — und
    // zwar unabhängig davon, ob eine Datei später angefasst wurde.
    usort($aus, fn($a, $b) => strcmp($b['name'], $a['name']));
    return $aus;
}

/**
 * Entfernt die ältesten Stände.
 *
 * @return int Anzahl entfernter Dateien
 */
function sicherungen_aufraeumen(string $verzeichnis, int $behalten): int
{
    $behalten = max(1, $behalten);
    $weg      = 0;

    foreach (array_slice(sicherungen_auflisten($verzeichnis), $behalten) as $eintrag) {
        if (@unlink($eintrag['pfad'])) {
            $weg++;
        }
    }
    return $weg;
}

/**
 * Ein vollständiger Lauf: schreiben und aufräumen.
 *
 * @return array{ok:bool,meldung:string,datei:string}
 */
function sicherung_laufen(
    PDO $pdo,
    string $wurzel,
    string $eingestellt = '',
    int $behalten = SICHERUNG_BEHALTEN,
    string $jetzt = 'now'
): array {
    [$verzeichnis, $ausserhalb] = sicherung_verzeichnis($wurzel, $eingestellt);
    if ($verzeichnis === '') {
        return [
            'ok'      => false,
            'meldung' => 'Kein beschreibbares Verzeichnis gefunden. In den Einstellungen '
                       . 'einen Pfad angeben, den der Webserver beschreiben darf.',
            'datei'   => '',
        ];
    }

    $tabellen = sicherung_tabellen($wurzel . '/install/schema.sql');
    if ($tabellen === []) {
        return ['ok' => false, 'meldung' => 'install/schema.sql nicht lesbar.', 'datei' => ''];
    }

    $datei = $verzeichnis . '/' . sicherung_dateiname($jetzt);
    try {
        $ergebnis = sicherung_schreiben($pdo, $datei, $tabellen);
    } catch (Throwable $e) {
        error_log('Sicherung fehlgeschlagen: ' . $e->getMessage());
        return ['ok' => false, 'meldung' => $e->getMessage(), 'datei' => ''];
    }

    $weg = sicherungen_aufraeumen($verzeichnis, $behalten);

    $meldung = $ergebnis['zeilen'] . ' Zeilen aus ' . $ergebnis['tabellen'] . ' Tabellen, '
             . max(1, (int) round($ergebnis['bytes'] / 1024)) . ' KB.';
    if (!$ergebnis['struktur']) {
        $meldung .= ' Ohne Struktur — vor dem Zurückspielen install/schema.sql einspielen.';
    }
    if (!$ausserhalb) {
        $meldung .= ' Liegt im Webverzeichnis, durch .htaccess gesperrt.';
    }
    if ($weg > 0) {
        $meldung .= ' ' . $weg . ' alte Stände entfernt.';
    }

    return ['ok' => true, 'meldung' => $meldung, 'datei' => $datei];
}
