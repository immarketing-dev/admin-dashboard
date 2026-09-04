<?php
/**
 * Belege zu Ausgaben, und die Jahresübergabe an die Buchhaltung.
 *
 * finances kannte genau ein Dateifeld: invoice_pdf_path, und das ist die
 * selbst erzeugte Ausgangsrechnung. An einer Ausgabe hing nichts. Der
 * Serverrechnung, der Softwarelizenz, dem Bahnticket fehlte das Dokument
 * - es lag woanders, und zur Steuer wurde es wieder zusammengesucht.
 *
 * Schemaversion 11 gibt der Ausgabe ihr eigenes Feld. Diese Datei
 * sammelt, was ein Jahr davon zusammenhält.
 */

require_once __DIR__ . '/upload_helper.php';
require_once __DIR__ . '/file_access.php';

/** Wohin Belege gespeichert werden, relativ zur Wurzel. */
const BELEG_VERZEICHNIS = 'uploads/receipts/';

/**
 * Kann diese Installation ein ZIP erzeugen?
 *
 * Die Erweiterung zip ist auf verbreiteten Hostern vorhanden, aber nicht
 * garantiert - anders als pdo_mysql oder mbstring, ohne die das Panel gar
 * nicht liefe. Deshalb wird sie hier abgefragt statt vorausgesetzt, und
 * die Oberfläche blendet den Knopf aus, wenn sie fehlt. Ein Knopf, der
 * beim Drücken erklärt, warum er nicht geht, ist keiner.
 */
function beleg_zip_moeglich(): bool
{
    return class_exists('ZipArchive');
}

/**
 * Nimmt eine hochgeladene Belegdatei entgegen.
 *
 * Läuft über dieselbe Prüfung wie jeder andere Upload im Panel
 * (validate_upload: MIME-Typ gegen Whitelist, Endung passend zum Typ,
 * höchstens 20 MB) - eine zweite, eigene Regel wäre genau die Sorte
 * Unterschied, die später niemand mehr bemerkt.
 *
 * @param array  $datei  Ein Eintrag aus $_FILES
 * @param string $wurzel Verzeichnis des Panels
 * @return array{pfad: ?string, fehler: ?string}
 */
function beleg_speichern(array $datei, string $wurzel): array
{
    // Kein Upload ist kein Fehler - das Formular wird auch ohne Beleg
    // abgeschickt.
    if (($datei['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return ['pfad' => null, 'fehler' => null];
    }
    if (($datei['error'] ?? 1) !== UPLOAD_ERR_OK) {
        return ['pfad' => null, 'fehler' => 'Der Upload ist fehlgeschlagen.'];
    }

    $fehler = validate_upload(
        (string) $datei['tmp_name'],
        (string) $datei['name'],
        (int) $datei['size']
    );
    if ($fehler !== null) {
        return ['pfad' => null, 'fehler' => $fehler];
    }

    $ziel_ordner = rtrim($wurzel, '/\\') . '/' . BELEG_VERZEICHNIS;
    if (!is_dir($ziel_ordner) && !@mkdir($ziel_ordner, 0755, true) && !is_dir($ziel_ordner)) {
        return ['pfad' => null, 'fehler' => 'Das Belegverzeichnis lässt sich nicht anlegen.'];
    }

    $name = safe_filename((string) $datei['name']);
    $ziel = $ziel_ordner . $name;

    // move_uploaded_file und nicht rename: es prüft mit, dass die Datei
    // wirklich aus einem Upload stammt.
    if (!@move_uploaded_file((string) $datei['tmp_name'], $ziel)) {
        return ['pfad' => null, 'fehler' => 'Die Datei ließ sich nicht speichern.'];
    }

    return ['pfad' => BELEG_VERZEICHNIS . $name, 'fehler' => null];
}

/**
 * Entfernt eine Belegdatei von der Platte.
 *
 * Prüft den Pfad, bevor sie löscht: der Wert kommt aus der Datenbank,
 * aber ein Pfad, der aus uploads/ herausführt, wäre auch dann falsch,
 * wenn ihn niemand angegriffen hat - config.php und .env liegen ein
 * Verzeichnis darüber (dieselbe Schranke wie in file_access.php).
 */
function beleg_loeschen(?string $pfad, string $wurzel): bool
{
    if ($pfad === null || $pfad === '' || !datei_pfad_erlaubt($pfad)) {
        return false;
    }
    $voll = rtrim($wurzel, '/\\') . '/' . $pfad;

    return is_file($voll) && @unlink($voll);
}

/**
 * Alle Ausgaben eines Jahres, für die Übergabe an die Buchhaltung.
 *
 * Bewusst nur Ausgaben: Ausgangsrechnungen liegen bereits als PDF im
 * Panel und tragen ihre Nummer im Dateinamen. Hier geht es um die
 * fremden Belege, die sonst über fünf Postfächer verteilt sind.
 */
function belege_des_jahres(PDO $pdo, int $jahr): array
{
    $stmt = $pdo->prepare(
        "SELECT f.id, f.title, f.amount, f.record_date, f.status, f.notes,
                f.receipt_path,
                COALESCE(NULLIF(c.name, ''), NULLIF(f.custom_name, ''), '') AS empfaenger
           FROM finances f
           LEFT JOIN contacts c ON c.id = f.contact_id AND c.deleted_at IS NULL
          WHERE f.deleted_at IS NULL
            AND f.type = 'EXPENSE'
            AND f.record_date >= ? AND f.record_date <= ?
          ORDER BY f.record_date ASC, f.id ASC"
    );
    $stmt->execute([$jahr . '-01-01', $jahr . '-12-31']);

    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/**
 * Der Name, unter dem ein Beleg im Archiv liegt.
 *
 * Datum voran, damit die Dateien im Archiv in der Reihenfolge stehen, in
 * der sie auch in der Liste stehen; die Kennung dahinter, damit zwei
 * Ausgaben mit gleichem Datum und gleichem Titel sich nicht überschreiben.
 */
function beleg_archivname(array $ausgabe): string
{
    $datum = substr((string) ($ausgabe['record_date'] ?? ''), 0, 10) ?: '0000-00-00';
    $titel = preg_replace('/[^A-Za-z0-9_\-]/', '_', (string) ($ausgabe['title'] ?? 'Beleg'));
    $titel = trim(mb_substr($titel, 0, 40), '_');
    $endung = strtolower(pathinfo((string) $ausgabe['receipt_path'], PATHINFO_EXTENSION));

    return $datum . '_' . (int) $ausgabe['id'] . '_' . ($titel !== '' ? $titel : 'Beleg')
         . ($endung !== '' ? '.' . $endung : '');
}

/**
 * Die Übersicht, die dem Archiv beiliegt.
 *
 * Semikolon und Byte-Order-Mark wie beim vorhandenen Finanz-Export in
 * finances.php - beides, damit Excel die Datei ohne Rückfragen richtig
 * öffnet.
 *
 * Der leere $escape ist kein Beiwerk: PHPs Standard ist ein Backslash,
 * den weder RFC 4180 noch ein Tabellenprogramm kennt - ein Feld, das auf
 * einen Backslash endet, würde damit anders geschrieben als es gelesen
 * wird. Seit PHP 8.4 meldet die Funktion das Weglassen ohnehin als
 * veraltet.
 */
function belege_csv(array $ausgaben): string
{
    $puffer = fopen('php://temp', 'r+');
    fwrite($puffer, chr(0xEF) . chr(0xBB) . chr(0xBF));
    fputcsv($puffer, ['Datum', 'Bezeichnung', 'Empfaenger', 'Betrag (EUR)', 'Status', 'Notiz', 'Beleg'], ';', '"', '');

    foreach ($ausgaben as $a) {
        fputcsv($puffer, [
            $a['record_date'],
            $a['title'],
            $a['empfaenger'],
            str_replace('.', ',', (string) $a['amount']),
            $a['status'],
            $a['notes'],
            $a['receipt_path'] ? beleg_archivname($a) : '',
        ], ';', '"', '');
    }

    rewind($puffer);
    $inhalt = stream_get_contents($puffer);
    fclose($puffer);

    return (string) $inhalt;
}

/**
 * Packt ein Jahr in ein ZIP: die Übersicht plus jeden vorhandenen Beleg.
 *
 * Gibt den Pfad der erzeugten Datei zurück, die der Aufrufer nach dem
 * Ausliefern wieder löscht. In den Arbeitsspeicher lässt sich ein Archiv
 * nicht bauen - ZipArchive schreibt auf die Platte.
 *
 * Fehlende Dateien überspringt der Lauf und meldet sie in $vermisst,
 * statt abzubrechen: ein Beleg, der von Hand aus dem Verzeichnis
 * entfernt wurde, darf nicht die Übergabe des ganzen Jahres verhindern.
 *
 * @param array<int, string> $vermisst wird gefüllt
 * @return string|null Pfad zur ZIP-Datei, null wenn zip fehlt
 */
function belege_archiv(array $ausgaben, string $wurzel, int $jahr, array &$vermisst = []): ?string
{
    if (!beleg_zip_moeglich()) {
        return null;
    }

    $ziel = tempnam(sys_get_temp_dir(), 'belege');
    if ($ziel === false) {
        return null;
    }

    $zip = new ZipArchive();
    if ($zip->open($ziel, ZipArchive::OVERWRITE) !== true) {
        @unlink($ziel);
        return null;
    }

    $zip->addFromString('Ausgaben_' . $jahr . '.csv', belege_csv($ausgaben));

    foreach ($ausgaben as $a) {
        $pfad = (string) ($a['receipt_path'] ?? '');
        if ($pfad === '' || !datei_pfad_erlaubt($pfad)) {
            continue;
        }
        $voll = rtrim($wurzel, '/\\') . '/' . $pfad;
        if (!is_file($voll)) {
            $vermisst[] = $pfad;
            continue;
        }
        $zip->addFile($voll, 'Belege/' . beleg_archivname($a));
    }

    $zip->close();

    return $ziel;
}
