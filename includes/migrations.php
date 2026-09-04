<?php
/**
 * Schemaversionierung. Ersetzt die früheren AUTO-PATCH-Blöcke, die bei
 * jedem Request ein CREATE/ALTER TABLE versucht haben.
 *
 * Neue Migration: einen Eintrag am Ende von migrations() anhängen und
 * SCHEMA_VERSION erhöhen. Migrationen laufen genau einmal, in Reihenfolge.
 */

const SCHEMA_VERSION = 3;

/**
 * MySQL-Fehlercodes, die "war schon da" bedeuten. Sie sind kein
 * Migrationsfehler, sondern der Normalfall auf einer Datenbank, die vor
 * der Schemadatei entstanden ist. Alles andere ist ein echter Fehler.
 */
const MIGRATION_BENIGN_ERRORS = [
    1050, // Tabelle existiert bereits
    1060, // Spaltenname doppelt
    1061, // Schluesselname doppelt
    1091, // Kann nicht loeschen: Spalte/Schluessel existiert nicht
];

function run_migrations(PDO $pdo): void
{
    // settings ist die einzige Tabelle, die vor allem anderen da sein muss –
    // sie trägt die Versionsnummer.
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS settings ('
        . 'k VARCHAR(100) NOT NULL PRIMARY KEY, v TEXT NOT NULL'
        . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    $stmt = $pdo->prepare("SELECT v FROM settings WHERE k = 'schema_version'");
    $stmt->execute();
    $current = (int) ($stmt->fetchColumn() ?: 0);

    if ($current >= SCHEMA_VERSION) {
        return;
    }

    $failed = false;

    foreach (migrations() as $version => $steps) {
        if ($version <= $current) {
            continue;
        }
        foreach ($steps as $sql) {
            try {
                if (is_callable($sql)) {
                    $sql($pdo);
                } else {
                    $pdo->exec($sql);
                }
            } catch (PDOException $e) {
                $code = isset($e->errorInfo[1]) ? (int) $e->errorInfo[1] : 0;

                if (in_array($code, MIGRATION_BENIGN_ERRORS, true)) {
                    // Spalte, Tabelle oder Index existiert bereits – bei einer
                    // aus AUTO-PATCH-Zeiten gewachsenen Datenbank der Normalfall.
                    // Nur zur Information protokollieren und weitermachen.
                    error_log(
                        'Migration ' . $version . ': Schritt bereits angewendet'
                        . ' (Code ' . $code . '): ' . $e->getMessage()
                    );
                    continue;
                }

                // Echter Fehler (Rechte, Lock, Datenträger voll). Früher wurde
                // auch dieser Fall verschluckt und die Version trotzdem
                // gestempelt – die Migration lief dann nie wieder, obwohl das
                // Schema unvollständig blieb (z. B. logs.ip, von dem der
                // Login-Pfad abhängt), und der Administrator war ausgesperrt.
                // Deshalb: laut protokollieren und die Version nicht stempeln,
                // damit der nächste Request es erneut versucht.
                $failed = true;
                error_log(
                    'Migration ' . $version . ' FEHLGESCHLAGEN (Code ' . $code
                    . '): ' . $e->getMessage()
                    . ' – Schritt: ' . (is_callable($sql) ? 'Rückruf' : $sql)
                );
            }
        }
    }

    if ($failed) {
        // Version bewusst nicht stempeln: beim nächsten Request wird erneut
        // migriert. Die bereits erfolgreichen Schritte laufen dann in einen
        // der gutartigen Codes oben und stören nicht.
        return;
    }

    $pdo->prepare(
        "INSERT INTO settings (k, v) VALUES ('schema_version', ?) "
        . 'ON DUPLICATE KEY UPDATE v = VALUES(v)'
    )->execute([(string) SCHEMA_VERSION]);
}

/**
 * Ein Schritt ist entweder eine SQL-Anweisung oder ein Rückruf, der das
 * PDO-Objekt bekommt. Rückrufe sind für Schritte gedacht, die sich nicht
 * als eine Anweisung schreiben lassen – etwa eine Rückfüllung, die je Zeile
 * einen Wert berechnet und auf MySQL 5.7 wie auf 8 laufen soll.
 *
 * @return array<int, array<int, string|callable>> Version => Schritte
 */
function migrations(): array
{
    return [
        // Version 1 bringt eine Datenbank, die vor der Schemadatei
        // entstanden ist, auf den Stand von install/schema.sql.
        1 => [
            "ALTER TABLE tasks MODIFY COLUMN status "
            . "ENUM('Offen','In Bearbeitung','Erledigt','Storniert') "
            . "NOT NULL DEFAULT 'Offen'",

            "ALTER TABLE client_assets "
            . "ADD COLUMN uploaded_by VARCHAR(50) DEFAULT 'client'",

            "ALTER TABLE contacts "
            . 'ADD COLUMN portal_pin VARCHAR(255) DEFAULT NULL, '
            . 'ADD COLUMN portal_pin_attempts TINYINT UNSIGNED DEFAULT 0, '
            . 'ADD COLUMN portal_pin_locked_until DATETIME DEFAULT NULL',

            "ALTER TABLE milestone_comments "
            . 'ADD COLUMN admin_seen TINYINT(1) NOT NULL DEFAULT 0',

            "ALTER TABLE ticket_notes "
            . "ADD COLUMN author VARCHAR(20) NOT NULL DEFAULT 'admin', "
            . 'ADD COLUMN is_public TINYINT(1) NOT NULL DEFAULT 0',

            "ALTER TABLE support_tickets "
            . "ADD COLUMN priority ENUM('Niedrig','Mittel','Hoch','Kritisch') "
            . "NOT NULL DEFAULT 'Mittel'",

            "ALTER TABLE quotes "
            . "ADD COLUMN subject VARCHAR(255) NOT NULL DEFAULT '' AFTER quote_number, "
            . 'ADD COLUMN intro_text TEXT AFTER subject',
        ],

        // Version 2: der Login-Sperrzaehler bekommt eine eigene Spalte
        // statt LIKE auf die Log-Beschreibung zu matchen - die Beschreibung
        // enthaelt die vom Angreifer frei waehlbare E-Mail-Adresse und war
        // damit ueber das Login-Formular vergiftbar (siehe auth_login.php).
        2 => [
            'ALTER TABLE logs ADD COLUMN ip VARCHAR(45) DEFAULT NULL',
            'ALTER TABLE logs ADD INDEX idx_logs_lockout (action_type, ip, created_at)',
        ],

        // Version 3: Rechnungen bekommen eine gespeicherte Nummer.
        //
        // Bisher wurde 'RE-JJJJ-NNN' bei jeder PDF- oder Mail-Erzeugung aus
        // SELECT COUNT(*) neu berechnet und nirgends abgelegt. Damit trug
        // dieselbe Rechnung nach dem Loeschen einer anderen eine andere
        // Nummer als im bereits versendeten PDF, und zwei Rechnungen konnten
        // dieselbe tragen. Fuer eine Ausgangsrechnung verlangt Paragraf 14
        // UStG eine fortlaufende, einmalig vergebene Nummer.
        3 => [
            'ALTER TABLE finances ADD COLUMN invoice_number VARCHAR(50) DEFAULT NULL',

            // Rueckfuellung in zwei Schritten. Bewusst in PHP: eine
            // Fensterfunktion braeuchte MySQL 8, und die Variante mit
            // Sitzungsvariablen verhaelt sich je nach Server anders.
            static function (PDO $pdo): void {
                // Schritt 1: Rechnungen, deren Nummer bisher in 'title'
                // stand (so legt invoice.php sie an), behalten sie
                // unveraendert. Sie steht bereits auf versendeten PDFs -
                // eine Neuvergabe wuerde Belege und Datenbank entzweien.
                $pdo->exec(
                    "UPDATE finances SET invoice_number = title"
                    . " WHERE type = 'INCOME' AND invoice_number IS NULL"
                    . " AND title REGEXP '^RE-[0-9]{4}-[0-9]+$'"
                );

                // Schritt 2: alles Uebrige bekommt eine Nummer nach
                // Rechnungsdatum, bei Gleichstand nach ID - fortgesetzt
                // hinter der hoechsten im jeweiligen Jahr bereits
                // vergebenen Nummer, damit keine Kollision entsteht.
                $hoechste = [];
                foreach ($pdo->query(
                    "SELECT SUBSTRING(invoice_number, 4, 4) AS jahr,"
                    . " MAX(CAST(SUBSTRING_INDEX(invoice_number, '-', -1) AS UNSIGNED)) AS nr"
                    . " FROM finances WHERE invoice_number REGEXP '^RE-[0-9]{4}-[0-9]+$'"
                    . " GROUP BY jahr"
                )->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    $hoechste[(string) $r['jahr']] = (int) $r['nr'];
                }

                $rows = $pdo->query(
                    "SELECT id, YEAR(COALESCE(record_date, created_at)) AS jahr"
                    . " FROM finances"
                    . " WHERE type = 'INCOME' AND invoice_number IS NULL"
                    . " ORDER BY COALESCE(record_date, created_at) ASC, id ASC"
                )->fetchAll(PDO::FETCH_ASSOC);

                $upd = $pdo->prepare('UPDATE finances SET invoice_number = ? WHERE id = ?');
                foreach ($rows as $r) {
                    $jahr = (string) $r['jahr'];
                    $hoechste[$jahr] = ($hoechste[$jahr] ?? 0) + 1;
                    $upd->execute([
                        'RE-' . $jahr . '-' . str_pad((string) $hoechste[$jahr], 3, '0', STR_PAD_LEFT),
                        (int) $r['id'],
                    ]);
                }
            },

            // Erst nach der Rueckfuellung: vorher waeren alle Zeilen NULL und
            // der Index brauchte nichts zu leisten, danach haelt er die
            // Einmaligkeit auch gegen zwei gleichzeitige Anlagen durch.
            'ALTER TABLE finances ADD UNIQUE KEY uq_fin_invoice_number (invoice_number)',
        ],
    ];
}
