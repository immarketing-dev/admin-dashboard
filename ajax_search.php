<?php
/**
 * Globale Suche über alle Bereiche.
 *
 * Bisher hatte jede Seite ihre eigene Suche. Um „Musterbau“ zu finden,
 * musste man vorher wissen, ob es ein Kontakt, ein Projekt, eine Rechnung
 * oder ein Ticket ist. Dieser Endpunkt durchsucht alles auf einmal und
 * liefert Treffer mit direktem Ziel.
 *
 * Antwortet als JSON auf GET ?q=…
 */
require_once 'config.php';
require_once 'includes/auth.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$q = trim($_GET['q'] ?? '');

// Unter zwei Zeichen liefert eine Volltextsuche fast alles - das hilft
// niemandem und belastet nur die Datenbank.
if (mb_strlen($q) < 2) {
    echo json_encode(['q' => $q, 'groups' => []], JSON_UNESCAPED_UNICODE);
    exit;
}

$like = '%' . $q . '%';

/**
 * Führt eine Suchabfrage aus und formt die Treffer einheitlich.
 *
 * @param string $sql    Muss id, titel, unterzeile liefern.
 * @param int    $anzahl Platzhalter im WHERE-Teil.
 */
function suche(PDO $pdo, string $sql, string $like, int $anzahl, callable $ziel): array
{
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute(array_fill(0, $anzahl, $like));
    } catch (PDOException $e) {
        // Eine fehlende Tabelle (etwa auf einer älteren Installation) darf
        // nicht die ganze Suche kippen.
        error_log('Globale Suche: ' . $e->getMessage());
        return [];
    }

    $out = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $out[] = [
            'titel'      => (string) $r['titel'],
            'unterzeile' => trim((string) ($r['unterzeile'] ?? '')),
            'url'        => $ziel($r),
        ];
    }
    return $out;
}

$gruppen = [];

// ── Kontakte ────────────────────────────────────────────────────────
$treffer = suche($pdo,
    "SELECT id, name AS titel,
            TRIM(CONCAT_WS(' · ', NULLIF(company,''), NULLIF(email,''), NULLIF(city,''))) AS unterzeile
     FROM contacts
     WHERE deleted_at IS NULL AND name LIKE ? OR company LIKE ? OR email LIKE ? OR phone LIKE ? OR notes LIKE ?
     ORDER BY name LIMIT 5",
    $like, 5,
    fn($r) => 'contacts?search=' . urlencode($q)
);
if ($treffer) $gruppen[] = ['label' => 'Kontakte', 'icon' => 'bi-people-fill', 'treffer' => $treffer];

// ── Projekte ────────────────────────────────────────────────────────
$treffer = suche($pdo,
    "SELECT t.id, t.title AS titel,
            TRIM(CONCAT_WS(' · ', t.status, NULLIF(c.name,''), NULLIF(t.category,''))) AS unterzeile
     FROM tasks t LEFT JOIN contacts c ON t.contact_id = c.id
     WHERE t.deleted_at IS NULL AND t.title LIKE ? OR t.description LIKE ? OR t.category LIKE ?
     ORDER BY t.deadline IS NULL, t.deadline LIMIT 5",
    $like, 3,
    fn($r) => 'tasks?q=' . urlencode($q)
);
if ($treffer) $gruppen[] = ['label' => 'Projekte', 'icon' => 'bi-check2-square', 'treffer' => $treffer];

// ── Rechnungen und Ausgaben ─────────────────────────────────────────
$treffer = suche($pdo,
    "SELECT f.id, COALESCE(NULLIF(f.invoice_number,''), f.title) AS titel,
            TRIM(CONCAT_WS(' · ',
                 CONCAT(FORMAT(f.amount, 2, 'de_DE'), ' €'),
                 f.status,
                 NULLIF(COALESCE(c.name, f.custom_name),''))) AS unterzeile
     FROM finances f LEFT JOIN contacts c ON f.contact_id = c.id
     WHERE f.deleted_at IS NULL AND f.title LIKE ? OR f.invoice_number LIKE ? OR f.notes LIKE ? OR f.custom_name LIKE ?
     ORDER BY f.record_date DESC LIMIT 5",
    $like, 4,
    fn($r) => 'finances?search=' . urlencode($q)
);
if ($treffer) $gruppen[] = ['label' => 'Finanzen', 'icon' => 'bi-currency-euro', 'treffer' => $treffer];

// ── Angebote ────────────────────────────────────────────────────────
$treffer = suche($pdo,
    "SELECT q.id, CONCAT(q.quote_number, ' · ', COALESCE(NULLIF(q.subject,''), 'Angebot')) AS titel,
            TRIM(CONCAT_WS(' · ',
                 CONCAT(FORMAT(q.total_amount, 2, 'de_DE'), ' €'),
                 q.status,
                 NULLIF(COALESCE(c.name, q.custom_name),''))) AS unterzeile
     FROM quotes q LEFT JOIN contacts c ON q.contact_id = c.id
     WHERE q.deleted_at IS NULL AND q.quote_number LIKE ? OR q.subject LIKE ? OR q.notes LIKE ? OR q.custom_name LIKE ?
     ORDER BY q.created_at DESC LIMIT 5",
    $like, 4,
    fn($r) => 'quotes?status=all'
);
if ($treffer) $gruppen[] = ['label' => 'Angebote', 'icon' => 'bi-file-earmark-text', 'treffer' => $treffer];

// ── Support-Tickets ─────────────────────────────────────────────────
$treffer = suche($pdo,
    "SELECT s.id, s.subject AS titel,
            TRIM(CONCAT_WS(' · ', s.status, s.priority, NULLIF(c.name,''))) AS unterzeile
     FROM support_tickets s LEFT JOIN contacts c ON s.contact_id = c.id
     WHERE s.subject LIKE ? OR s.message LIKE ?
     ORDER BY s.created_at DESC LIMIT 5",
    $like, 2,
    fn($r) => 'tickets?search=' . urlencode($q)
);
if ($treffer) $gruppen[] = ['label' => 'Support-Tickets', 'icon' => 'bi-life-preserver', 'treffer' => $treffer];

// ── Wiki ────────────────────────────────────────────────────────────
$treffer = suche($pdo,
    "SELECT id, title AS titel,
            TRIM(CONCAT_WS(' · ', NULLIF(category,''), NULLIF(tags,''))) AS unterzeile
     FROM wiki_articles
     WHERE title LIKE ? OR content LIKE ? OR tags LIKE ? OR category LIKE ?
     ORDER BY title LIMIT 5",
    $like, 4,
    fn($r) => 'wiki?q=' . urlencode($q)
);
if ($treffer) $gruppen[] = ['label' => 'Wiki', 'icon' => 'bi-book-half', 'treffer' => $treffer];

echo json_encode(['q' => $q, 'groups' => $gruppen], JSON_UNESCAPED_UNICODE);
