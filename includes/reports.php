<?php
/**
 * Auswertungen.
 *
 * Seit Schemaversion 9 liegt alles beisammen, was eine
 * Rentabilitätsrechnung braucht: ein Stundensatz an Kunde und Projekt,
 * erfasste Minuten in time_entries, abgerechnete Beträge in finances.
 * Ausgewertet wurde davon nichts - finances.php zeichnet Einnahmen gegen
 * Ausgaben über die Zeit, mehr nicht. Die Frage "welcher Kunde trägt
 * sich eigentlich" ließ sich mit dem Panel nicht beantworten, obwohl es
 * die Antwort hatte.
 *
 * Keine neue Tabelle: alles hier sind Abfragen auf vorhandene Spalten.
 *
 * Was hier bewusst FEHLT: der tatsächlich erzielte Stundensatz je
 * Projekt. Dafür müsste sich ein Rechnungsbetrag einem Projekt zuordnen
 * lassen, und das geht nicht - finances kennt nur einen Kontakt, keine
 * task_id. Die Verbindung besteht allein über time_entries.invoice_id,
 * und eine Rechnung enthält oft mehr als die Zeit eines Projekts. Eine
 * Zahl, die so täte, als wüsste sie es, wäre schlimmer als keine.
 *
 * Die Aufteilung ist dieselbe wie in includes/reminders.php: was rechnet
 * und einteilt, kommt ohne Datenbank aus und ist deshalb prüfbar.
 */

require_once __DIR__ . '/dates.php';
require_once __DIR__ . '/time_billing.php';

/**
 * Die Altersstufen für offene Posten, in Tagen nach Fälligkeit.
 *
 * Die üblichen Stufen einer offenen-Posten-Liste. Was älter ist als die
 * letzte Stufe, landet im Rest.
 */
const OP_STUFEN = [30, 60, 90];

// ---------------------------------------------------------------------
// Rechnen und einteilen - ohne Datenbank
// ---------------------------------------------------------------------

/**
 * Ordnet eine offene Rechnung ihrer Altersstufe zu.
 *
 * Rückgabe ist der Index in OP_STUFEN, oder die Anzahl der Stufen für
 * "älter als die letzte". Noch nicht fällige Rechnungen bekommen -1:
 * sie sind offen, aber nicht überfällig, und gehören in keine Mahnstufe.
 */
function op_stufe(int $tage_ueberfaellig): int
{
    if ($tage_ueberfaellig <= 0) {
        return -1;
    }
    foreach (OP_STUFEN as $i => $grenze) {
        if ($tage_ueberfaellig <= $grenze) {
            return $i;
        }
    }
    return count(OP_STUFEN);
}

/** Die Beschriftungen der Altersstufen, in derselben Reihenfolge. */
function op_stufen_namen(): array
{
    $namen = ['nicht fällig'];
    $vorher = 0;
    foreach (OP_STUFEN as $grenze) {
        $namen[] = ($vorher + 1) . '–' . $grenze . ' Tage';
        $vorher = $grenze;
    }
    // end() will eine Variable, keine Konstante - sie nimmt ihr
    // Argument per Referenz.
    $stufen = OP_STUFEN;
    $namen[] = 'über ' . end($stufen) . ' Tage';
    return $namen;
}

/**
 * Verteilt offene Rechnungen auf die Altersstufen.
 *
 * Erwartet Zeilen mit due_date und amount. Gibt je Stufe Betrag und
 * Anzahl zurück, in der Reihenfolge von op_stufen_namen() - der Eimer
 * für "nicht fällig" steht vorn.
 *
 * @return array<int, array{name: string, betrag: float, anzahl: int}>
 */
function offene_posten_verteilen(array $zeilen, string $heute): array
{
    $namen  = op_stufen_namen();
    $eimer  = [];
    foreach ($namen as $name) {
        $eimer[] = ['name' => $name, 'betrag' => 0.0, 'anzahl' => 0];
    }

    foreach ($zeilen as $z) {
        $tage  = tage_ueberfaellig($z['due_date'] ?? null, $heute);
        // -1 (nicht fällig) landet im ersten Eimer, die Stufen dahinter.
        $index = op_stufe($tage) + 1;

        $eimer[$index]['betrag'] += (float) ($z['amount'] ?? 0);
        $eimer[$index]['anzahl']++;
    }

    return $eimer;
}

/**
 * Balkenbreite in Prozent, gegen den größten Wert der Reihe.
 *
 * Die Balken der Auswertungsseite sind CSS und nicht Chart.js: eine
 * Leinwand kann var() nicht auflösen, und für einen Größenvergleich in
 * einer Tabellenzeile wäre eine Diagrammbibliothek zu viel Apparat.
 */
function balken(float $wert, float $max): string
{
    if ($max <= 0) {
        return '0';
    }
    return (string) round(max(0, $wert) / $max * 100, 1);
}

/**
 * Minuten als Stundenangabe, wie sie auf einer Rechnung stünde.
 *
 * Zwei Nachkommastellen, damit Stundenzahl mal Satz den ausgewiesenen
 * Betrag ergibt - dieselbe Rundung wie in zeiten_als_position().
 */
function stunden(int $minuten): float
{
    return round($minuten / 60, 2);
}

/**
 * Minuten als "7:45" - für die Anzeige, nicht zum Rechnen.
 *
 * Auf einem Stundenzettel liest sich 7:45 besser als 7,75, und die
 * Summe darunter muss nicht auf zwei Stellen aufgehen.
 */
function stunden_lesbar(int $minuten): string
{
    $vorzeichen = $minuten < 0 ? '-' : '';
    $minuten = abs($minuten);
    return $vorzeichen . intdiv($minuten, 60) . ':' . str_pad((string) ($minuten % 60), 2, '0', STR_PAD_LEFT);
}

/**
 * Der Zeitraum eines Stundenzettels.
 *
 * 'week' rechnet von Montag bis Sonntag - der Wochenanfang ist hier
 * Montag und nicht Sonntag, weil das Panel deutsch denkt.
 *
 * @return array{von: string, bis: string, titel: string}
 */
function zeitraum_grenzen(string $modus, string $anker): array
{
    $zeit = strtotime(substr($anker, 0, 10)) ?: time();

    if ($modus === 'week') {
        $montag  = strtotime('monday this week', $zeit);
        $sonntag = strtotime('sunday this week', $zeit);
        return [
            'von'   => date('Y-m-d', $montag),
            'bis'   => date('Y-m-d', $sonntag),
            'titel' => date('d.m.', $montag) . ' – ' . date('d.m.Y', $sonntag)
                     . ' (' . date('\K\W W', $montag) . ')',
        ];
    }

    if ($modus === 'year') {
        return [
            'von'   => date('Y-01-01', $zeit),
            'bis'   => date('Y-12-31', $zeit),
            'titel' => date('Y', $zeit),
        ];
    }

    // Monat als Vorgabe.
    return [
        'von'   => date('Y-m-01', $zeit),
        'bis'   => date('Y-m-t', $zeit),
        'titel' => date('m/Y', $zeit),
    ];
}

/**
 * Verschiebt den Anker um einen Zeitraum vor oder zurück.
 *
 * Für die Blätterknöpfe. Bei Monat und Jahr wird auf den Monatsersten
 * gegangen, bevor gerechnet wird: sonst überspringt "+1 month" vom 31.
 * aus den Februar (derselbe Fallstrick wie bei den wiederkehrenden
 * Einträgen, siehe includes/recurring.php).
 */
function zeitraum_verschieben(string $modus, string $anker, int $richtung): string
{
    $zeit = strtotime(substr($anker, 0, 10)) ?: time();

    if ($modus === 'week') {
        return date('Y-m-d', strtotime(($richtung >= 0 ? '+' : '-') . '1 week', $zeit));
    }
    if ($modus === 'year') {
        return date('Y-01-01', strtotime(($richtung >= 0 ? '+' : '-') . '1 year', strtotime(date('Y-01-01', $zeit))));
    }
    return date('Y-m-01', strtotime(($richtung >= 0 ? '+' : '-') . '1 month', strtotime(date('Y-m-01', $zeit))));
}

// ---------------------------------------------------------------------
// Datenbank
// ---------------------------------------------------------------------

/**
 * Umsatz je Kunde in einem Jahr.
 *
 * Bezahlt und offen getrennt: eine Summe, die beides vermengt, sagt
 * nichts darüber, ob das Geld auch angekommen ist. Rechnungen ohne
 * Kontakt laufen über custom_name mit - sie sind Umsatz wie jeder
 * andere.
 *
 * @return array<int, array{kunde: string, bezahlt: float, offen: float, anzahl: int}>
 */
function umsatz_je_kunde(PDO $pdo, int $jahr): array
{
    // GROUP BY und ORDER BY wiederholen den Ausdruck, statt den Alias
    // zu nennen. Einen Alias dort zu verwenden ist eine Erweiterung
    // von MySQL und nicht Standard-SQL; die Prüfumgebung dieses
    // Projekts läuft gegen SQLite und winkt beides durch, ein echter
    // Server muss das nicht. Ausgeschrieben läuft die Abfrage überall.
    $stmt = $pdo->prepare(
        "SELECT COALESCE(NULLIF(c.name, ''), NULLIF(f.custom_name, ''), '—') AS kunde,
                SUM(CASE WHEN f.status = 'Bezahlt' THEN f.amount ELSE 0 END) AS bezahlt,
                SUM(CASE WHEN f.status IN ('Offen', 'Überfällig') THEN f.amount ELSE 0 END) AS offen,
                COUNT(*) AS anzahl
           FROM finances f
           LEFT JOIN contacts c ON c.id = f.contact_id AND c.deleted_at IS NULL
          WHERE f.deleted_at IS NULL
            AND f.type = 'INCOME'
            AND f.record_date >= ? AND f.record_date <= ?
          GROUP BY COALESCE(NULLIF(c.name, ''), NULLIF(f.custom_name, ''), '—')
          ORDER BY SUM(CASE WHEN f.status = 'Bezahlt' THEN f.amount ELSE 0 END) DESC,
                   SUM(CASE WHEN f.status IN ('Offen', 'Überfällig') THEN f.amount ELSE 0 END) DESC"
    );
    $stmt->execute([$jahr . '-01-01', $jahr . '-12-31']);

    $aus = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $z) {
        $aus[] = [
            'kunde'   => (string) $z['kunde'],
            'bezahlt' => (float) $z['bezahlt'],
            'offen'   => (float) $z['offen'],
            'anzahl'  => (int) $z['anzahl'],
        ];
    }
    return $aus;
}

/** Die Jahre, für die es überhaupt Einnahmen gibt - für die Auswahl. */
function umsatz_jahre(PDO $pdo): array
{
    $jahre = $pdo->query(
        "SELECT DISTINCT YEAR(record_date) AS jahr
           FROM finances
          WHERE deleted_at IS NULL AND type = 'INCOME' AND record_date IS NOT NULL
          ORDER BY jahr DESC"
    )->fetchAll(PDO::FETCH_COLUMN);

    $jahre = array_map('intval', $jahre ?: []);
    // Das laufende Jahr ist immer dabei, auch wenn noch nichts drinsteht -
    // sonst zeigt eine frische Installation eine leere Auswahl.
    if (!in_array((int) date('Y'), $jahre, true)) {
        array_unshift($jahre, (int) date('Y'));
    }
    return $jahre;
}

/**
 * Alle offenen Ausgangsrechnungen - Grundlage der offenen-Posten-Liste.
 */
function offene_posten(PDO $pdo): array
{
    return $pdo->query(
        "SELECT f.id, f.invoice_number, f.title, f.amount, f.due_date, f.status,
                f.reminder_count,
                COALESCE(NULLIF(c.name, ''), NULLIF(f.custom_name, ''), '—') AS kunde
           FROM finances f
           LEFT JOIN contacts c ON c.id = f.contact_id AND c.deleted_at IS NULL
          WHERE f.deleted_at IS NULL
            AND f.type = 'INCOME'
            AND f.status IN ('Offen', 'Überfällig')
          ORDER BY f.due_date ASC, f.id ASC"
    )->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/**
 * Erfasste, abgerechnete und offene Zeit je Projekt.
 *
 * Der Stundensatz kommt in derselben Reihenfolge zustande wie beim
 * Abrechnen - Projekt schlägt Kunde schlägt Voreinstellung -, aber in
 * einer Abfrage statt in einer je Projekt: bei dreißig Projekten wären
 * das sonst dreißig zusätzliche Abfragen für eine einzige Tabelle.
 *
 * @return array<int, array<string, mixed>>
 */
function zeit_je_projekt(PDO $pdo, float $standardsatz): array
{
    $stmt = $pdo->query(
        "SELECT t.id, t.title, t.status,
                COALESCE(NULLIF(c.name, ''), '—') AS kunde,
                t.hourly_rate AS satz_projekt,
                c.hourly_rate AS satz_kunde,
                COALESCE(SUM(te.duration_minutes), 0) AS minuten,
                COALESCE(SUM(CASE WHEN te.billed_at IS NOT NULL THEN te.duration_minutes ELSE 0 END), 0) AS minuten_berechnet
           FROM tasks t
           LEFT JOIN contacts c ON c.id = t.contact_id AND c.deleted_at IS NULL
           LEFT JOIN time_entries te ON te.task_id = t.id
          WHERE t.deleted_at IS NULL
          GROUP BY t.id, t.title, t.status,
                   COALESCE(NULLIF(c.name, ''), '—'),
                   t.hourly_rate, c.hourly_rate
          HAVING SUM(te.duration_minutes) > 0
          ORDER BY (COALESCE(SUM(te.duration_minutes), 0)
                    - COALESCE(SUM(CASE WHEN te.billed_at IS NOT NULL
                                        THEN te.duration_minutes ELSE 0 END), 0)) DESC,
                   COALESCE(SUM(te.duration_minutes), 0) DESC"
    );

    $aus = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $z) {
        // Auf null prüfen, nicht auf leer: ein Satz von 0,00 ist eine
        // Aussage ("wird nicht berechnet") und keine fehlende Angabe -
        // dieselbe Regel wie in stundensatz().
        $satz = $z['satz_projekt'] !== null
            ? (float) $z['satz_projekt']
            : ($z['satz_kunde'] !== null ? (float) $z['satz_kunde'] : $standardsatz);

        $minuten    = (int) $z['minuten'];
        $berechnet  = (int) $z['minuten_berechnet'];
        $offen      = $minuten - $berechnet;

        $aus[] = [
            'id'          => (int) $z['id'],
            'title'       => (string) $z['title'],
            'status'      => (string) $z['status'],
            'kunde'       => (string) $z['kunde'],
            'satz'        => $satz,
            'minuten'     => $minuten,
            'berechnet'   => $berechnet,
            'offen'       => $offen,
            'offen_wert'  => round(stunden($offen) * $satz, 2),
        ];
    }
    return $aus;
}

/**
 * Die einzelnen Zeiteinträge eines Zeitraums.
 *
 * Die Tabelle time_entries hatte bis hierher keine eigene Ansicht: sie
 * wurde ausschließlich als Summe je Projekt gelesen und beim
 * Rechnungslauf. Wer wissen wollte, was am Dienstag passiert ist, fand
 * es nirgends - die Einzelnachweise waren da, aber unsichtbar.
 */
function zeiteintraege(PDO $pdo, string $von, string $bis): array
{
    $stmt = $pdo->prepare(
        "SELECT te.id, te.task_id, te.duration_minutes, te.note, te.created_at,
                te.billed_at, te.invoice_id,
                t.title AS projekt,
                COALESCE(NULLIF(c.name, ''), '—') AS kunde
           FROM time_entries te
           JOIN tasks t ON t.id = te.task_id AND t.deleted_at IS NULL
           LEFT JOIN contacts c ON c.id = t.contact_id AND c.deleted_at IS NULL
          WHERE te.created_at >= ? AND te.created_at < ?
          ORDER BY te.created_at ASC, te.id ASC"
    );
    // Bis einschliesslich des letzten Tages: created_at ist ein
    // Zeitstempel, ein "<= bis" verlöre alles nach Mitternacht.
    $stmt->execute([$von . ' 00:00:00', date('Y-m-d', strtotime($bis . ' +1 day')) . ' 00:00:00']);

    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/**
 * Fasst Zeiteinträge nach Tag zusammen.
 *
 * @return array<string, array{minuten: int, eintraege: array<int, array<string, mixed>>}>
 */
function zeiten_nach_tag(array $eintraege): array
{
    $tage = [];
    foreach ($eintraege as $e) {
        $tag = substr((string) $e['created_at'], 0, 10);
        if (!isset($tage[$tag])) {
            $tage[$tag] = ['minuten' => 0, 'eintraege' => []];
        }
        $tage[$tag]['minuten'] += (int) $e['duration_minutes'];
        $tage[$tag]['eintraege'][] = $e;
    }
    return $tage;
}

/**
 * Fasst Zeiteinträge nach Projekt zusammen, absteigend nach Dauer.
 *
 * @return array<int, array{projekt: string, kunde: string, minuten: int, offen: int}>
 */
function zeiten_nach_projekt(array $eintraege): array
{
    $projekte = [];
    foreach ($eintraege as $e) {
        $id = (int) $e['task_id'];
        if (!isset($projekte[$id])) {
            $projekte[$id] = [
                'projekt' => (string) $e['projekt'],
                'kunde'   => (string) $e['kunde'],
                'minuten' => 0,
                'offen'   => 0,
            ];
        }
        $projekte[$id]['minuten'] += (int) $e['duration_minutes'];
        if (empty($e['billed_at'])) {
            $projekte[$id]['offen'] += (int) $e['duration_minutes'];
        }
    }
    uasort($projekte, fn($a, $b) => $b['minuten'] <=> $a['minuten']);

    return array_values($projekte);
}
