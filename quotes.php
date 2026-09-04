<?php
require_once 'config.php';
require_once __DIR__ . '/includes/logging.php';
require_once 'includes/mail_templates.php';
require_once 'includes/numbering.php';
require_once 'includes/auth.php';
require_once 'includes/filter_state.php';

// PHPMailer laden
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as PHPMailerException;
require_once __DIR__ . '/vendor/autoload.php';
$_pm_available = class_exists(PHPMailer::class);

// Hilfsfunktion FPDF-sichere Umlaute
function q_safe($str) {
    return mb_convert_encoding((string)$str, 'ISO-8859-1', 'UTF-8');
}

// =============================================
// POST-AKTIONEN
// =============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    csrf_check();
    $action = $_POST['action'];

    // Angebotsnummer generieren
    function next_quote_number(PDO $pdo): string {
        $y = date('Y');
        // Zaehlt nicht mehr Zeilen, sondern nimmt die hoechste vergebene
        // Nummer - siehe includes/numbering.php.
        return next_quote_number($pdo, $y);
    }

    // Items-Array aus POST sauber zusammenbauen
    function build_items(): array {
        $descs  = $_POST['item_desc']  ?? [];
        $qtys   = $_POST['item_qty']   ?? [];
        $prices = $_POST['item_price'] ?? [];
        $units  = $_POST['item_unit']  ?? [];
        $items  = [];
        for ($i = 0; $i < count($descs); $i++) {
            if (empty(trim($descs[$i]))) continue;
            $items[] = [
                'desc'  => trim($descs[$i]),
                'qty'   => (float)($qtys[$i] ?? 1),
                'price' => (float)str_replace(',', '.', $prices[$i] ?? 0),
                'unit'  => trim($units[$i] ?? ''),
            ];
        }
        return $items;
    }

    function calc_total(array $items, string $tax_type): float {
        $netto = array_sum(array_map(fn($it) => $it['qty'] * $it['price'], $items));
        return $netto * ($tax_type === 'regel' ? 1.19 : 1.0);
    }

    // ANGEBOT ERSTELLEN
    if ($action === 'create_quote') {
        $quote_number = next_quote_number($pdo);
        $contact_id   = !empty($_POST['contact_id']) ? (int)$_POST['contact_id'] : null;
        $custom_name  = trim($_POST['custom_name'] ?? '');
        $tax_type     = in_array($_POST['tax_type'] ?? '', ['kleinunternehmer','regel']) ? $_POST['tax_type'] : 'kleinunternehmer';
        $valid_until  = !empty($_POST['valid_until']) ? $_POST['valid_until'] : null;
        $notes        = trim($_POST['notes'] ?? '');
        $subject      = trim($_POST['subject'] ?? '');
        $intro_text   = trim($_POST['intro_text'] ?? '');
        $items        = build_items();
        $total        = calc_total($items, $tax_type);

        $pdo->prepare("INSERT INTO quotes (quote_number, subject, intro_text, contact_id, custom_name, status, tax_type, items, notes, total_amount, valid_until) VALUES (?,?,?,?,?,'Entwurf',?,?,?,?,?)")
            ->execute([$quote_number, $subject, $intro_text, $contact_id, $custom_name, $tax_type, json_encode($items), $notes, $total, $valid_until]);

        log_event($pdo, 'QUOTE_CREATED', "Angebot $quote_number erstellt.");
        filter_redirect('quotes');
    }

    // ANGEBOT BEARBEITEN
    if ($action === 'edit_quote') {
        $id          = (int)$_POST['quote_id'];
        $contact_id  = !empty($_POST['contact_id']) ? (int)$_POST['contact_id'] : null;
        $custom_name = trim($_POST['custom_name'] ?? '');
        $status      = trim($_POST['status'] ?? 'Entwurf');
        $tax_type    = in_array($_POST['tax_type'] ?? '', ['kleinunternehmer','regel']) ? $_POST['tax_type'] : 'kleinunternehmer';
        $valid_until = !empty($_POST['valid_until']) ? $_POST['valid_until'] : null;
        $notes       = trim($_POST['notes'] ?? '');
        $subject     = trim($_POST['subject'] ?? '');
        $intro_text  = trim($_POST['intro_text'] ?? '');
        $items       = build_items();
        $total       = calc_total($items, $tax_type);

        $pdo->prepare("UPDATE quotes SET subject=?, intro_text=?, contact_id=?, custom_name=?, status=?, tax_type=?, items=?, notes=?, total_amount=?, valid_until=? WHERE id=?")
            ->execute([$subject, $intro_text, $contact_id, $custom_name, $status, $tax_type, json_encode($items), $notes, $total, $valid_until, $id]);

        log_event($pdo, 'QUOTE_EDITED', "Angebot #$id aktualisiert.");
        filter_redirect('quotes');
    }

    // ANGEBOT LÖSCHEN
    if ($action === 'delete_quote') {
        $id = (int)$_POST['quote_id'];
        $row = $pdo->prepare("SELECT quote_number, quote_pdf_path FROM quotes WHERE deleted_at IS NULL AND id=?");
        $row->execute([$id]);
        $q = $row->fetch(PDO::FETCH_ASSOC);
        if ($q && $q['quote_pdf_path'] && file_exists($q['quote_pdf_path'])) @unlink($q['quote_pdf_path']);
        // Papierkorb statt Sofortloeschung: der Datensatz verschwindet aus
        // allen Ansichten, bleibt aber 30 Tage wiederherstellbar.
        $pdo->prepare("UPDATE quotes SET deleted_at = NOW() WHERE id = ? AND deleted_at IS NULL")->execute([$id]);
        log_event($pdo, 'QUOTE_DELETED', "Angebot {$q['quote_number']} gelöscht.");
        filter_redirect('quotes');
    }

    // ── PDF HELPER ──────────────────────────────────────────────
    function build_quote_pdf(PDO $pdo, array $q): string {
        require_once __DIR__ . '/vendor/autoload.php';
        $items       = json_decode($q['items'], true) ?: [];
        $tax_type    = $q['tax_type'];
        $client_name = $q['custom_name'] ?: ($q['c_name'] ?? 'Unbekannt');

        list($r, $g, $b) = sscanf(COLOR_PRIMARY, "#%02x%02x%02x");
        $pdf = new FPDF();
        $pdf->AddPage();
        $pdf->SetMargins(15, 20, 15);
        $pdf->SetAutoPageBreak(true, 20);

        // LOGO (oben links)
        $_logo_rel = setting('company_logo', '');
        if ($_logo_rel && file_exists(__DIR__ . '/' . $_logo_rel)) {
            $_logo_abs = __DIR__ . '/' . $_logo_rel;
            $_linfo = @getimagesize($_logo_abs);
            if ($_linfo) {
                $ratio = $_linfo[0] / $_linfo[1];
                $max_w = 55; $max_h = 22;
                if ($ratio > $max_w / $max_h) { $dw = $max_w; $dh = round($max_w / $ratio, 1); }
                else { $dh = $max_h; $dw = round($max_h * $ratio, 1); }
                $ext = strtoupper(pathinfo($_logo_rel, PATHINFO_EXTENSION));
                if ($ext === 'JPG') $ext = 'JPEG';
                $pdf->Image($_logo_abs, 15, 13, $dw, $dh, $ext);
                $pdf->SetY(20);
            }
        }

        // ABSENDER (rechts)
        $pdf->SetFont('Arial', 'B', 16);
        $pdf->SetTextColor($r, $g, $b);
        $pdf->Cell(0, 8, q_safe(COMPANY_NAME), 0, 1, 'R');
        $pdf->SetFont('Arial', '', 9);
        $pdf->SetTextColor(100, 100, 100);
        $s_street = q_safe(setting('company_street', ''));
        $s_city   = q_safe(setting('company_city', ''));
        if ($s_street || $s_city) {
            $pdf->Cell(0, 4, trim(($s_street ? $s_street . ' | ' : '') . $s_city, ' | '), 0, 1, 'R');
        }
        $pdf->Cell(0, 4, ADMIN_EMAIL . ' | ' . str_replace(['http://','https://','www.'], '', MAIN_WEBSITE), 0, 1, 'R');
        $pdf->Ln(15);

        // EMPFÄNGER (links)
        $pdf->SetTextColor(0, 0, 0);
        $pdf->SetFont('Arial', 'B', 11);
        $pdf->Cell(0, 6, q_safe($client_name), 0, 1);
        $pdf->SetFont('Arial', '', 10);
        if (!empty($q['c_company'])) $pdf->Cell(0, 5, q_safe($q['c_company']), 0, 1);
        if (!empty($q['c_street']))  $pdf->Cell(0, 5, q_safe($q['c_street']), 0, 1);
        if (!empty($q['c_zip']) || !empty($q['c_city'])) $pdf->Cell(0, 5, q_safe(trim($q['c_zip'] . ' ' . $q['c_city'])), 0, 1);
        if (!empty($q['c_country'])) $pdf->Cell(0, 5, q_safe($q['c_country']), 0, 1);
        $pdf->Ln(15);

        // ANGEBOTS-KOPF
        $pdf->SetFont('Arial', 'B', 18);
        $pdf->SetTextColor($r, $g, $b);
        $pdf->Cell(0, 10, q_safe('Angebot Nr. ' . $q['quote_number']), 0, 1);
        $pdf->SetFont('Arial', '', 10);
        $pdf->SetTextColor(100, 100, 100);
        $pdf->Cell(50, 5, q_safe('Datum:'), 0, 0);
        $pdf->Cell(0, 5, date('d.m.Y', strtotime($q['created_at'])), 0, 1);
        if ($q['valid_until']) {
            $pdf->SetFont('Arial', 'B', 10);
            $pdf->SetTextColor(220, 53, 69);
            $pdf->Cell(50, 5, q_safe('Gültig bis:'), 0, 0);
            $pdf->Cell(0, 5, date('d.m.Y', strtotime($q['valid_until'])), 0, 1);
        }

        // BETREFF
        if (!empty($q['subject'])) {
            $pdf->Ln(5);
            $pdf->SetFont('Arial', 'B', 11);
            $pdf->SetTextColor(0, 0, 0);
            $pdf->Cell(0, 6, q_safe('Betreff: ' . $q['subject']), 0, 1);
        }

        // EINLEITUNGSTEXT
        if (!empty($q['intro_text'])) {
            $pdf->Ln(3);
            $pdf->SetFont('Arial', '', 10);
            $pdf->SetTextColor(50, 50, 50);
            $pdf->MultiCell(0, 5, q_safe($q['intro_text']));
        }

        $pdf->Ln(8);

        // TABELLEN-HEADER (88+20+22+25+25 = 180mm)
        $pdf->SetTextColor(0, 0, 0);
        $pdf->SetFillColor(240, 240, 240);
        $pdf->SetFont('Arial', 'B', 10);
        $pdf->Cell(88, 10, q_safe('Position / Beschreibung'), 0, 0, 'L', true);
        $pdf->Cell(20, 10, 'Menge', 0, 0, 'C', true);
        $pdf->Cell(22, 10, 'Einheit', 0, 0, 'C', true);
        $pdf->Cell(25, 10, 'Einzelpreis', 0, 0, 'R', true);
        $pdf->Cell(25, 10, 'Gesamt', 0, 1, 'R', true);
        $pdf->Ln(2);

        // POSITIONEN
        $netto = 0;
        $pdf->SetFont('Arial', '', 9);
        foreach ($items as $item) {
            $desc  = q_safe($item['desc']);
            $qty   = (float)($item['qty'] ?? 1);
            $price = (float)($item['price'] ?? 0);
            $unit  = q_safe($item['unit'] ?? '');
            $pos   = $qty * $price;
            $netto += $pos;
            $sx = $pdf->GetX(); $sy = $pdf->GetY();
            $pdf->MultiCell(88, 5, $desc, 0, 'L');
            $ny = $pdf->GetY();
            $h  = max(5, $ny - $sy);
            $pdf->SetXY($sx + 88, $sy);
            $pdf->Cell(20, $h, number_format($qty, 2, ',', '.'), 0, 0, 'C');
            $pdf->Cell(22, $h, $unit, 0, 0, 'C');
            $pdf->Cell(25, $h, number_format($price, 2, ',', '.') . ' ' . chr(128), 0, 0, 'R');
            $pdf->Cell(25, $h, number_format($pos, 2, ',', '.') . ' ' . chr(128), 0, 1, 'R');
            $pdf->SetY($ny + 2);
            $pdf->SetDrawColor(220, 220, 220);
            $pdf->Line(15, $pdf->GetY(), 195, $pdf->GetY());
            $pdf->SetY($pdf->GetY() + 3);
        }
        $pdf->Ln(5);

        // SUMMEN
        $tax_amount = $tax_type === 'regel' ? $netto * 0.19 : 0;
        $brutto     = $netto + $tax_amount;
        if ($tax_type === 'regel') {
            $pdf->SetFont('Arial', '', 10);
            $pdf->Cell(155, 6, q_safe('Netto:'), 0, 0, 'R');
            $pdf->Cell(25, 6, number_format($netto, 2, ',', '.') . ' ' . chr(128), 0, 1, 'R');
            $pdf->Cell(155, 6, q_safe('zzgl. 19% MwSt:'), 0, 0, 'R');
            $pdf->Cell(25, 6, number_format($tax_amount, 2, ',', '.') . ' ' . chr(128), 0, 1, 'R');
        }
        $pdf->Ln(2);
        $pdf->SetFont('Arial', 'B', 14);
        $pdf->Cell(155, 10, q_safe('Angebotssumme:'), 0, 0, 'R');
        $pdf->SetTextColor($r, $g, $b);
        $pdf->Cell(25, 10, number_format($brutto, 2, ',', '.') . ' ' . chr(128), 0, 1, 'R');
        $pdf->SetTextColor(0, 0, 0);
        $pdf->Ln(10);

        if (!empty($q['notes'])) {
            $pdf->SetFont('Arial', 'B', 10);
            $pdf->Cell(0, 6, q_safe('Anmerkungen:'), 0, 1);
            $pdf->SetFont('Arial', '', 9);
            $pdf->MultiCell(0, 5, q_safe($q['notes']));
            $pdf->Ln(5);
        }
        $pdf->SetFont('Arial', 'I', 9);
        $pdf->SetTextColor(100, 100, 100);
        if ($tax_type === 'kleinunternehmer') {
            $pdf->MultiCell(0, 5, q_safe('Gemäß § 19 UStG (Kleinunternehmerregelung) wird keine Umsatzsteuer ausgewiesen.'));
        }

        $upload_dir = __DIR__ . '/uploads/quotes/';
        if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);
        $safe_num  = preg_replace('/[^a-zA-Z0-9_-]/', '', $q['quote_number']);
        $file_name = 'Angebot_' . $safe_num . '.pdf';
        $file_path = $upload_dir . $file_name;
        $rel_path  = 'uploads/quotes/' . $file_name;
        $pdf->Output($file_path, 'F');

        $pdo->prepare("UPDATE quotes SET quote_pdf_path=? WHERE id=?")->execute([$rel_path, $q['id']]);
        return $rel_path;
    }

    // PDF GENERIEREN & IM BROWSER ANZEIGEN
    if ($action === 'generate_pdf') {
        $id   = (int)$_POST['quote_id'];
        $stmt = $pdo->prepare("SELECT q.*, c.name AS c_name, c.company AS c_company, c.street AS c_street, c.zip AS c_zip, c.city AS c_city, c.country AS c_country FROM quotes q LEFT JOIN contacts c ON q.contact_id = c.id WHERE q.deleted_at IS NULL AND q.id=?");
        $stmt->execute([$id]);
        $q = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$q) { filter_redirect('quotes'); }

        $rel_path = build_quote_pdf($pdo, $q);
        $pdo->prepare("UPDATE quotes SET status='Gesendet' WHERE id=? AND status='Entwurf'")->execute([$id]);
        log_event($pdo, 'QUOTE_PDF', "PDF für Angebot {$q['quote_number']} generiert.");

        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="' . basename($rel_path) . '"');
        readfile(__DIR__ . '/' . $rel_path);
        exit();
    }

    // ANGEBOT PER E-MAIL SENDEN
    if ($action === 'send_quote_email') {
        global $_pm_available;
        $id       = (int)$_POST['quote_id'];
        $to_email = trim($_POST['to_email'] ?? '');
        $subject  = trim($_POST['email_subject'] ?? '');
        $body     = trim($_POST['email_body'] ?? '');

        if (!filter_var($to_email, FILTER_VALIDATE_EMAIL)) {
            filter_redirect('quotes', ['error' => 'invalid_email']);
        }

        $stmt = $pdo->prepare("SELECT q.*, c.name AS c_name, c.company AS c_company, c.street AS c_street, c.zip AS c_zip, c.city AS c_city, c.country AS c_country, c.email AS c_email FROM quotes q LEFT JOIN contacts c ON q.contact_id = c.id WHERE q.deleted_at IS NULL AND q.id=?");
        $stmt->execute([$id]);
        $q = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$q) { filter_redirect('quotes'); }

        // PDF generieren falls noch nicht vorhanden
        if (empty($q['quote_pdf_path']) || !file_exists(__DIR__ . '/' . $q['quote_pdf_path'])) {
            $rel_path = build_quote_pdf($pdo, $q);
            $q['quote_pdf_path'] = $rel_path;
        }

        if (!$_pm_available) {
            filter_redirect('quotes', ['error' => 'no_phpmailer']);
        }

        try {
            $mail = new PHPMailer(true);
            $mail->isSMTP();
            $mail->Host       = SMTP_HOST;
            $mail->SMTPAuth   = true;
            $mail->Username   = SMTP_USER;
            $mail->Password   = SMTP_PASS;
            $mail->SMTPSecure = (SMTP_PORT == 587) ? 'tls' : 'ssl';
            $mail->Port       = SMTP_PORT;
            $mail->CharSet    = 'UTF-8';
            $mail->setFrom(ADMIN_EMAIL, COMPANY_NAME);
            $mail->addAddress($to_email);
            $mail->Subject = $subject;
            $mail->isHTML(false);
            $mail->Body    = $body;
            if (!empty($q['quote_pdf_path']) && file_exists(__DIR__ . '/' . $q['quote_pdf_path'])) {
                $mail->addAttachment(__DIR__ . '/' . $q['quote_pdf_path'], 'Angebot_' . $q['quote_number'] . '.pdf');
            }
            $mail->send();

            if ($q['status'] === 'Entwurf') {
                $pdo->prepare("UPDATE quotes SET status='Gesendet' WHERE id=?")->execute([$id]);
            }
            log_event($pdo, 'QUOTE_EMAIL_SENT', "Angebot {$q['quote_number']} per E-Mail an $to_email gesendet.");

            filter_redirect('quotes', ['msg' => 'email_sent']);
        } catch (PHPMailerException $e) {
            filter_redirect('quotes', ['error' => 'email_failed', 'detail' => $e->getMessage()]);
        }
    }

    // ANGEBOT ZU RECHNUNG KONVERTIEREN
    function build_invoice_pdf_from_quote(array $q, string $inv_num): string {
        require_once __DIR__ . '/vendor/autoload.php';
        $items    = json_decode($q['items'], true) ?: [];
        $tax_type = $q['tax_type'];
        $client_name = $q['custom_name'] ?: ($q['c_name'] ?? 'Unbekannt');
        list($r, $g, $b) = sscanf(COLOR_PRIMARY, "#%02x%02x%02x");

        $pdf = new FPDF();
        $pdf->AddPage();
        $pdf->SetMargins(15, 20, 15);
        $pdf->SetAutoPageBreak(true, 20);

        // Logo
        $_logo_rel = setting('company_logo', '');
        if ($_logo_rel && file_exists(__DIR__ . '/' . $_logo_rel)) {
            $_logo_abs = __DIR__ . '/' . $_logo_rel;
            $_linfo = @getimagesize($_logo_abs);
            if ($_linfo) {
                $ratio = $_linfo[0] / $_linfo[1]; $max_w = 55; $max_h = 22;
                if ($ratio > $max_w / $max_h) { $dw = $max_w; $dh = round($max_w / $ratio, 1); }
                else { $dh = $max_h; $dw = round($max_h * $ratio, 1); }
                $ext = strtoupper(pathinfo($_logo_rel, PATHINFO_EXTENSION));
                if ($ext === 'JPG') $ext = 'JPEG';
                $pdf->Image($_logo_abs, 15, 13, $dw, $dh, $ext);
                $pdf->SetY(20);
            }
        }

        // Absender
        $pdf->SetFont('Arial', 'B', 16); $pdf->SetTextColor($r, $g, $b);
        $pdf->Cell(0, 8, q_safe(COMPANY_NAME), 0, 1, 'R');
        $pdf->SetFont('Arial', '', 9); $pdf->SetTextColor(100, 100, 100);
        $s_street = q_safe(setting('company_street', '')); $s_city = q_safe(setting('company_city', ''));
        if ($s_street || $s_city) $pdf->Cell(0, 4, trim(($s_street ? $s_street . ' | ' : '') . $s_city, ' | '), 0, 1, 'R');
        $pdf->Cell(0, 4, ADMIN_EMAIL . ' | ' . str_replace(['http://','https://','www.'], '', MAIN_WEBSITE), 0, 1, 'R');
        $pdf->Ln(15);

        // Empfänger
        $pdf->SetTextColor(0, 0, 0); $pdf->SetFont('Arial', 'B', 11);
        $pdf->Cell(0, 6, q_safe($client_name), 0, 1);
        $pdf->SetFont('Arial', '', 10);
        if (!empty($q['c_company'])) $pdf->Cell(0, 5, q_safe($q['c_company']), 0, 1);
        if (!empty($q['c_street']))  $pdf->Cell(0, 5, q_safe($q['c_street']), 0, 1);
        if (!empty($q['c_zip']) || !empty($q['c_city'])) $pdf->Cell(0, 5, q_safe(trim($q['c_zip'] . ' ' . $q['c_city'])), 0, 1);
        if (!empty($q['c_country'])) $pdf->Cell(0, 5, q_safe($q['c_country']), 0, 1);
        $pdf->Ln(15);

        // Rechnungs-Kopf
        $pdf->SetFont('Arial', 'B', 18); $pdf->SetTextColor($r, $g, $b);
        $pdf->Cell(0, 10, q_safe('Rechnung Nr. ' . $inv_num), 0, 1);
        $pdf->SetFont('Arial', '', 10); $pdf->SetTextColor(100, 100, 100);
        $pdf->Cell(40, 5, q_safe('Rechnungsdatum:'), 0, 0); $pdf->Cell(0, 5, date('d.m.Y'), 0, 1);
        $pdf->SetFont('Arial', 'B', 10); $pdf->SetTextColor(220, 53, 69);
        $pdf->Cell(40, 5, q_safe('Zahlbar bis:'), 0, 0); $pdf->Cell(0, 5, date('d.m.Y', strtotime('+14 days')), 0, 1);
        if (!empty($q['subject'])) {
            $pdf->Ln(5); $pdf->SetFont('Arial', 'B', 11); $pdf->SetTextColor(0, 0, 0);
            $pdf->Cell(0, 6, q_safe('Betreff: ' . $q['subject']), 0, 1);
        }
        if (!empty($q['intro_text'])) {
            $pdf->Ln(3); $pdf->SetFont('Arial', '', 10); $pdf->SetTextColor(50, 50, 50);
            $pdf->MultiCell(0, 5, q_safe($q['intro_text']));
        }
        $pdf->Ln(!empty($q['subject']) || !empty($q['intro_text']) ? 6 : 10);

        // Tabelle
        $pdf->SetTextColor(0, 0, 0); $pdf->SetFillColor(240, 240, 240); $pdf->SetFont('Arial', 'B', 10);
        $pdf->Cell(88, 10, q_safe('Position / Beschreibung'), 0, 0, 'L', true);
        $pdf->Cell(20, 10, 'Menge', 0, 0, 'C', true);
        $pdf->Cell(22, 10, 'Einheit', 0, 0, 'C', true);
        $pdf->Cell(25, 10, q_safe('Einzelpreis'), 0, 0, 'R', true);
        $pdf->Cell(25, 10, 'Gesamt', 0, 1, 'R', true);
        $pdf->Ln(2);

        $netto = 0;
        $pdf->SetFont('Arial', '', 9);
        foreach ($items as $item) {
            $desc = q_safe($item['desc']); $qty = (float)($item['qty'] ?? 1); $price = (float)($item['price'] ?? 0);
            $unit = q_safe($item['unit'] ?? ''); $pos = $qty * $price; $netto += $pos;
            $sx = $pdf->GetX(); $sy = $pdf->GetY();
            $pdf->MultiCell(88, 5, $desc, 0, 'L');
            $ny = $pdf->GetY(); $h = max(5, $ny - $sy);
            $pdf->SetXY($sx + 88, $sy);
            $pdf->Cell(20, $h, number_format($qty, 2, ',', '.'), 0, 0, 'C');
            $pdf->Cell(22, $h, $unit, 0, 0, 'C');
            $pdf->Cell(25, $h, number_format($price, 2, ',', '.') . ' ' . chr(128), 0, 0, 'R');
            $pdf->Cell(25, $h, number_format($pos, 2, ',', '.') . ' ' . chr(128), 0, 1, 'R');
            $pdf->SetY($ny + 2); $pdf->SetDrawColor(220, 220, 220);
            $pdf->Line(15, $pdf->GetY(), 195, $pdf->GetY()); $pdf->SetY($pdf->GetY() + 3);
        }
        $pdf->Ln(5);

        // Summen
        $tax_amount = $tax_type === 'regel' ? $netto * 0.19 : 0; $brutto = $netto + $tax_amount;
        if ($tax_type === 'regel') {
            $pdf->SetFont('Arial', '', 10);
            $pdf->Cell(155, 6, q_safe('Netto:'), 0, 0, 'R'); $pdf->Cell(25, 6, number_format($netto, 2, ',', '.') . ' ' . chr(128), 0, 1, 'R');
            $pdf->Cell(155, 6, q_safe('zzgl. 19% MwSt:'), 0, 0, 'R'); $pdf->Cell(25, 6, number_format($tax_amount, 2, ',', '.') . ' ' . chr(128), 0, 1, 'R');
        }
        $pdf->Ln(2); $pdf->SetFont('Arial', 'B', 14);
        $pdf->Cell(155, 10, q_safe('Rechnungsbetrag:'), 0, 0, 'R');
        $pdf->SetTextColor($r, $g, $b);
        $pdf->Cell(25, 10, number_format($brutto, 2, ',', '.') . ' ' . chr(128), 0, 1, 'R');
        $pdf->SetTextColor(0, 0, 0); $pdf->Ln(10);
        if (!empty($q['notes'])) {
            $pdf->SetFont('Arial', 'B', 10); $pdf->Cell(0, 6, q_safe('Anmerkungen:'), 0, 1);
            $pdf->SetFont('Arial', '', 9); $pdf->MultiCell(0, 5, q_safe($q['notes'])); $pdf->Ln(5);
        }
        $pdf->SetFont('Arial', 'I', 9); $pdf->SetTextColor(100, 100, 100);
        if ($tax_type === 'kleinunternehmer')
            $pdf->MultiCell(0, 5, q_safe('Gemäß § 19 UStG (Kleinunternehmerregelung) wird keine Umsatzsteuer ausgewiesen.'));

        $upload_dir = __DIR__ . '/uploads/invoices/';
        if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);
        $safe_num  = preg_replace('/[^a-zA-Z0-9_-]/', '', $inv_num);
        $rel_path  = 'uploads/invoices/Rechnung_' . $safe_num . '.pdf';
        $pdf->Output(__DIR__ . '/' . $rel_path, 'F');
        return $rel_path;
    }

    if ($action === 'convert_to_invoice') {
        $id   = (int)$_POST['quote_id'];
        $stmt = $pdo->prepare("SELECT q.*, c.name AS c_name, c.company AS c_company, c.street AS c_street, c.zip AS c_zip, c.city AS c_city, c.country AS c_country FROM quotes q LEFT JOIN contacts c ON q.contact_id = c.id WHERE q.deleted_at IS NULL AND q.id=?");
        $stmt->execute([$id]);
        $q = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$q) { filter_redirect('quotes'); }

        $y = date('Y');
        $inv_num = next_invoice_number($pdo, $y);

        $inv_pdf_path = build_invoice_pdf_from_quote($q, $inv_num);

        $client_name = $q['custom_name'] ?: ($q['c_name'] ?? 'Unbekannt');
        $pdo->prepare("INSERT INTO finances (type, title, invoice_number, contact_id, custom_name, amount, status, record_date, due_date, notes, invoice_pdf_path, is_recurring) VALUES ('INCOME',?,?,?,?,?,'Offen',CURDATE(),DATE_ADD(CURDATE(), INTERVAL 14 DAY),?,?,0)")
            ->execute([$inv_num, $inv_num, $q['contact_id'], $client_name, $q['total_amount'], $q['notes'], $inv_pdf_path]);

        $pdo->prepare("UPDATE quotes SET status='Angenommen' WHERE id=?")->execute([$id]);
        log_event($pdo, 'QUOTE_CONVERTED', "Angebot {$q['quote_number']} zu Rechnung $inv_num konvertiert.");

        // Seitenwechsel, kein Ruecksprung: die Filter der Angebotsliste
        // gelten fuer die Rechnungsliste nicht.
        header("Location: finances?msg=invoice_created"); exit();
    }
}

// DATEN LADEN
$contacts = $pdo->query("SELECT id, name, company FROM contacts WHERE deleted_at IS NULL ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);

$filter_status = isset($_GET['status']) ? trim($_GET['status']) : 'all';
$sql    = "SELECT q.*, c.name AS contact_name, c.email AS contact_email FROM quotes q LEFT JOIN contacts c ON q.contact_id = c.id WHERE q.deleted_at IS NULL";
$params = [];
if ($filter_status !== 'all') { $sql .= " WHERE q.status = ?"; $params[] = $filter_status; }
$sql .= " ORDER BY q.created_at DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$quotes = $stmt->fetchAll(PDO::FETCH_ASSOC);

// KPIs
$kpi = $pdo->query("SELECT
    COUNT(*) AS total,
    SUM(CASE WHEN status='Entwurf' THEN 1 ELSE 0 END) AS draft,
    SUM(CASE WHEN status='Gesendet' THEN 1 ELSE 0 END) AS sent,
    SUM(CASE WHEN status='Angenommen' THEN 1 ELSE 0 END) AS accepted,
    SUM(CASE WHEN status='Abgelehnt' THEN 1 ELSE 0 END) AS rejected,
    SUM(CASE WHEN status='Angenommen' THEN total_amount ELSE 0 END) AS revenue
FROM quotes WHERE deleted_at IS NULL")->fetch(PDO::FETCH_ASSOC);
$page_title   = 'Angebote';
$page_heading = 'Angebote';
$current_page = basename($_SERVER['PHP_SELF']);
$header_actions = '
    <button class="btn btn-primary btn-sm fw-bold px-3" data-bs-toggle="modal" data-bs-target="#quoteModal" onclick="prepareNewQuote()">
      <i class="bi bi-plus-lg me-1"></i> Neues Angebot
    </button>';

require 'includes/head.php';
require 'includes/layout_start.php';
?>

<!-- TOAST -->
<div class="position-fixed top-0 end-0 p-3" style="z-index:1090;">
  <?php if(isset($_GET['msg']) && $_GET['msg'] === 'email_sent'): ?>
  <div class="toast show align-items-center text-bg-success border-0 shadow-lg" role="alert" aria-atomic="true">
    <div class="d-flex"><div class="toast-body fw-bold"><i class="bi bi-envelope-check-fill me-2"></i><?= te('Angebot erfolgreich per E-Mail gesendet!') ?></div>
    <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button></div>
  </div>
  <?php endif; ?>
  <?php if(isset($_GET['error'])): ?>
  <div class="toast show align-items-center text-bg-danger border-0 shadow-lg" role="alert" aria-atomic="true">
    <div class="d-flex"><div class="toast-body fw-bold"><i class="bi bi-exclamation-triangle-fill me-2"></i>
      <?php
        $errs = ['email_failed'=>'E-Mail konnte nicht gesendet werden.','no_phpmailer'=>'PHPMailer nicht gefunden.','invalid_email'=>'Ungültige E-Mail-Adresse.'];
        echo $errs[$_GET['error']] ?? 'Fehler aufgetreten.';
        if(isset($_GET['detail'])) echo ' ' . htmlspecialchars($_GET['detail']);
      ?>
    </div><button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button></div>
  </div>
  <?php endif; ?>
</div>

  <!-- KPI-Karten -->
  <div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
      <div class="card border-0 shadow-sm rounded-3 p-3 text-center">
        <div class="fw-bold fs-3 text-primary"><?= $kpi['total'] ?></div>
        <div class="text-muted small"><?= te('Gesamt') ?></div>
      </div>
    </div>
    <div class="col-6 col-md-3">
      <div class="card border-0 shadow-sm rounded-3 p-3 text-center">
        <div class="fw-bold fs-3 text-success"><?= $kpi['accepted'] ?></div>
        <div class="text-muted small"><?= te('Angenommen') ?></div>
      </div>
    </div>
    <div class="col-6 col-md-3">
      <div class="card border-0 shadow-sm rounded-3 p-3 text-center">
        <div class="fw-bold fs-3 text-warning"><?= $kpi['sent'] ?></div>
        <div class="text-muted small"><?= te('Gesendet / Offen') ?></div>
      </div>
    </div>
    <div class="col-6 col-md-3">
      <div class="card border-0 shadow-sm rounded-3 p-3 text-center">
        <div class="fw-bold fs-3" style="color:var(--color-primary);"><?= number_format((float)$kpi['revenue'], 2, ',', '.') ?> €</div>
        <div class="text-muted small"><?= te('Angenommenes Volumen') ?></div>
      </div>
    </div>
  </div>

  <!-- Filter -->
  <div class="filter-bar">
    <?php $statuses = ['all'=>'Alle','Entwurf'=>'Entwurf','Gesendet'=>'Gesendet','Angenommen'=>'Angenommen','Abgelehnt'=>'Abgelehnt']; ?>
    <?php foreach($statuses as $val => $label): ?>
      <a href="quotes?status=<?= $val ?>" class="btn btn-sm <?= $filter_status === $val ? 'btn-primary' : 'btn-outline-secondary' ?>"><?= $label ?></a>
    <?php endforeach; ?>
  </div>

  <!-- Tabelle -->
  <div class="bg-surface rounded-3 shadow-sm border">
    <?php if(empty($quotes)): ?>
      <div class="text-center py-5 text-muted">
        <i class="bi bi-file-earmark-text" style="font-size:3rem;"></i>
        <h5 class="mt-3 fw-bold"><?= te('Noch keine Angebote vorhanden.') ?></h5>
      </div>
    <?php else: ?>
    <div class="table-responsive">
      <table class="table table-hover align-middle mb-0">
        <thead class="bg-subtle small text-uppercase text-muted fw-bold">
          <tr>
            <th class="py-3 ps-3"><?= te('Angebots-Nr.') ?></th>
            <th class="py-3"><?= te('Kunde') ?></th>
            <th class="py-3"><?= te('Datum') ?></th>
            <th class="py-3"><?= te('Gültig bis') ?></th>
            <th class="py-3"><?= te('Betrag') ?></th>
            <th class="py-3">Status</th>
            <th class="py-3 text-end pe-3"><?= te('Aktionen') ?></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach($quotes as $q):
            $items = json_decode($q['items'], true) ?: [];
            $status_map = [
              'Entwurf'    => 'bg-secondary',
              'Gesendet'   => 'bg-warning text-dark',
              'Angenommen' => 'bg-success',
              'Abgelehnt'  => 'bg-danger',
            ];
            $badge = $status_map[$q['status']] ?? 'bg-secondary';
            $client = $q['contact_name'] ?: $q['custom_name'] ?: '—';
            $expired = $q['valid_until'] && strtotime($q['valid_until']) < strtotime('today') && $q['status'] !== 'Angenommen';
          ?>
          <tr>
            <td class="ps-3 fw-bold text-strong-c"><?= htmlspecialchars($q['quote_number']) ?></td>
            <td><?= htmlspecialchars($client) ?></td>
            <td class="text-muted small"><?= date('d.m.Y', strtotime($q['created_at'])) ?></td>
            <td class="small <?= $expired ? 'text-danger fw-bold' : 'text-muted' ?>">
              <?= $q['valid_until'] ? date('d.m.Y', strtotime($q['valid_until'])) : '—' ?>
              <?= $expired ? ' <i class="bi bi-exclamation-triangle-fill"></i>' : '' ?>
            </td>
            <td class="fw-bold"><?= number_format((float)$q['total_amount'], 2, ',', '.') ?> €</td>
            <td><span class="badge <?= $badge ?>"><?= htmlspecialchars($q['status']) ?></span></td>
            <td class="text-end pe-3">
              <div class="d-flex gap-1 justify-content-end">

                <!-- PDF generieren -->
                <form method="POST" class="d-inline">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="generate_pdf">
                  <input type="hidden" name="quote_id" value="<?= $q['id'] ?>">
                  <button type="submit" class="btn btn-sm btn-outline-primary px-2" title="<?= te('PDF generieren & anzeigen') ?>"><i class="bi bi-file-earmark-pdf"></i></button>
                </form>

                <!-- Per E-Mail senden -->
                <button type="button" class="btn btn-sm btn-outline-info px-2" title="<?= te('Per E-Mail senden') ?>"
                        onclick='openEmailModal(<?= htmlspecialchars(json_encode([
                            "id"=>$q["id"],"quote_number"=>$q["quote_number"],"total_amount"=>$q["total_amount"],
                            "client"=>($q["contact_name"]?:$q["custom_name"]?:""),"email"=>($q["contact_email"]?:""),
                            "notes"=>$q["notes"]
                        ], JSON_HEX_TAG|JSON_HEX_APOS), ENT_QUOTES) ?>)'>
                  <i class="bi bi-envelope-arrow-up"></i>
                </button>

                <!-- Bearbeiten -->
                <button type="button" class="btn btn-sm btn-outline-secondary px-2" title="<?= te('Bearbeiten') ?>"
                        onclick='prepareEditQuote(<?= htmlspecialchars(json_encode($q, JSON_HEX_TAG|JSON_HEX_APOS), ENT_QUOTES) ?>)'><i class="bi bi-pencil-square"></i></button>

                <!-- Zu Rechnung konvertieren -->
                <?php if($q['status'] !== 'Angenommen' && $q['status'] !== 'Abgelehnt'): ?>
                <form method="POST" class="d-inline"
                      onsubmit="return confirm('Angebot <?= htmlspecialchars($q['quote_number']) ?> in eine Rechnung umwandeln?')">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="convert_to_invoice">
                  <input type="hidden" name="quote_id" value="<?= $q['id'] ?>">
                  <button type="submit" class="btn btn-sm btn-outline-success px-2" title="<?= te('Zu Rechnung konvertieren') ?>"><i class="bi bi-arrow-right-circle"></i></button>
                </form>
                <?php endif; ?>

                <!-- Löschen -->
                <form method="POST" class="d-inline" id="del_q_<?= $q['id'] ?>">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="delete_quote">
                  <input type="hidden" name="quote_id" value="<?= $q['id'] ?>">
                  <button type="button" class="btn btn-sm btn-outline-danger px-2" title="<?= te('Löschen') ?>"
                          data-confirmed="0" onclick="confirmDeleteQuote(this, <?= $q['id'] ?>)"><i class="bi bi-trash3"></i></button>
                </form>
              </div>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </div>


<!-- ANGEBOT-MODAL -->
<div class="modal fade" id="quoteModal" tabindex="-1" data-bs-backdrop="static" data-bs-keyboard="false">
  <div class="modal-dialog modal-xl">
    <div class="modal-content border-0 shadow">
      <div class="modal-header bg-dark text-white">
        <h5 class="modal-title fw-bold m-0" id="quoteModalTitle"><i class="bi bi-file-earmark-text me-2"></i> <?= te('Neues Angebot') ?></h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body p-4 bg-subtle">
        <form method="POST" id="quoteForm" onsubmit="return validateQuoteForm()">
          <?= csrf_field() ?>
          <input type="hidden" name="action" id="q_action" value="create_quote">
          <input type="hidden" name="quote_id" id="q_id">

          <div class="row g-3 bg-surface p-3 rounded shadow-sm border mb-3">
            <div class="col-md-5">
              <label class="form-label small fw-bold"><?= te('Kunde (aus Kontakten)') ?></label>
              <select name="contact_id" id="q_contact" class="form-select" onchange="fillClientName(this)">
                <option value=""><?= te('— Kein Kontakt ausgewählt —') ?></option>
                <?php foreach($contacts as $c): ?>
                  <option value="<?= $c['id'] ?>" data-name="<?= htmlspecialchars($c['name']) ?>">
                    <?= htmlspecialchars($c['name']) ?><?= $c['company'] ? ' (' . htmlspecialchars($c['company']) . ')' : '' ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-4">
              <label class="form-label small fw-bold"><?= te('Oder: Freitext-Name') ?></label>
              <input type="text" name="custom_name" id="q_custom_name" class="form-control" placeholder="<?= te('z.B. Mustermann GmbH') ?>">
            </div>
            <div class="col-md-3">
              <label class="form-label small fw-bold"><?= te('Gültig bis') ?></label>
              <input type="date" name="valid_until" id="q_valid_until" class="form-control">
            </div>
            <div class="col-md-4">
              <label class="form-label small fw-bold"><?= te('Steuer') ?></label>
              <select name="tax_type" id="q_tax_type" class="form-select">
                <option value="kleinunternehmer"><?= te('Kleinunternehmer (§19 UStG, keine MwSt.)') ?></option>
                <option value="regel"><?= te('Regelbesteuerung (19% MwSt.)') ?></option>
              </select>
            </div>
            <?php $status_opts = ['Entwurf','Gesendet','Angenommen','Abgelehnt']; ?>
            <div class="col-md-4" id="q_status_container" style="display:none;">
              <label class="form-label small fw-bold">Status</label>
              <select name="status" id="q_status" class="form-select">
                <?php foreach($status_opts as $s): ?>
                  <option value="<?= $s ?>"><?= $s ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-12">
              <label class="form-label small fw-bold"><?= te('Betreff') ?> <span class="text-muted fw-normal"><?= te('(erscheint im PDF)') ?></span></label>
              <input type="text" name="subject" id="q_subject" class="form-control" placeholder="<?= te('z.B. Webseitenentwicklung – Angebot für Ihr Projekt') ?>">
            </div>
            <div class="col-12">
              <label class="form-label small fw-bold"><?= te('Einleitungstext') ?> <span class="text-muted fw-normal"><?= te('(optional)') ?></span></label>
              <textarea name="intro_text" id="q_intro_text" class="form-control form-control-sm" rows="2" placeholder="<?= te('z.B. Hiermit unterbreiten wir Ihnen folgendes Angebot für das besprochene Projekt...') ?>"></textarea>
            </div>
          </div>

          <!-- Positionen -->
          <div class="bg-surface p-3 rounded shadow-sm border mb-3">
            <label class="form-label small fw-bold d-flex justify-content-between">
              <span><?= te('Positionen') ?></span>
              <button type="button" class="btn btn-sm btn-outline-primary px-2 py-0" onclick="addItem()"><i class="bi bi-plus-lg"></i> <?= te('Position') ?></button>
            </label>
            <div id="items-container">
              <!-- Positionen werden per JS eingefügt -->
            </div>
            <div class="d-flex justify-content-end mt-2">
              <div class="text-end fw-bold fs-5 text-strong-c" id="total-display"><?= te('Gesamt: 0,00 €') ?></div>
            </div>
          </div>

          <!-- Notizen -->
          <div class="bg-surface p-3 rounded shadow-sm border mb-3">
            <label class="form-label small fw-bold"><?= te('Notizen / Anmerkungen') ?></label>
            <textarea name="notes" id="q_notes" class="form-control" rows="3" placeholder="<?= te('z.B. Zahlungsbedingungen, Hinweise...') ?>"></textarea>
          </div>

          <div class="d-flex justify-content-end gap-2">
            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal"><?= te('Abbrechen') ?></button>
            <button type="submit" class="btn btn-primary fw-bold px-4"><i class="bi bi-save me-1"></i> <?= te('Speichern') ?></button>
          </div>
        </form>
      </div>
    </div>
  </div>
</div>

<!-- E-MAIL MODAL -->
<div class="modal fade" id="emailQuoteModal" tabindex="-1" data-bs-backdrop="static" data-bs-keyboard="false">
  <div class="modal-dialog modal-lg">
    <div class="modal-content border-0 shadow">
      <div class="modal-header" style="background:var(--color-sidebar);">
        <h5 class="modal-title fw-bold text-white m-0"><i class="bi bi-envelope-arrow-up me-2"></i><?= te('Angebot per E-Mail senden') ?></h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="send_quote_email">
        <input type="hidden" name="quote_id" id="eq_id">
        <div class="modal-body p-4 bg-subtle">
          <div class="bg-surface rounded-3 border p-3 mb-3 shadow-sm">
            <div class="row g-3">
              <div class="col-md-6">
                <label class="form-label small fw-bold"><?= te('An (E-Mail) *') ?></label>
                <input type="email" name="to_email" id="eq_to" class="form-control" required placeholder="<?= te('kunde@example.com') ?>" style="border-radius:8px;">
              </div>
              <div class="col-md-6">
                <label class="form-label small fw-bold"><?= te('Betreff *') ?></label>
                <input type="text" name="email_subject" id="eq_subject" class="form-control" required style="border-radius:8px;">
              </div>
            </div>
          </div>
          <div class="bg-surface rounded-3 border p-3 shadow-sm">
            <label class="form-label small fw-bold"><?= te('Nachricht') ?></label>
            <textarea name="email_body" id="eq_body" class="form-control" rows="9" style="font-size:13px;resize:none;border-radius:8px;font-family:inherit;"></textarea>
          </div>
          <div class="mt-2 small text-muted"><i class="bi bi-paperclip me-1"></i><?= te('Das Angebot-PDF wird automatisch angehängt.') ?></div>
        </div>
        <div class="modal-footer bg-subtle">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?= te('Abbrechen') ?></button>
          <button type="submit" class="btn btn-primary fw-bold px-4"><i class="bi bi-send me-1"></i><?= te('Senden') ?></button>
        </div>
      </form>
    </div>
  </div>
</div>

<datalist id="q_unit_options">
  <option value="Stk."><option value="Std."><option value="Pauschal"><option value="Monat"><option value="Jahr"><option value="m²"><option value="km">
</datalist>

<script>
document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('.toast').forEach(el => new bootstrap.Toast(el, {delay:5000}).show());
});
const quoteModal = new bootstrap.Modal(document.getElementById('quoteModal'));

function prepareNewQuote() {
    document.getElementById('quoteModalTitle').innerHTML = '<i class="bi bi-file-earmark-plus me-2"></i> <?= te('Neues Angebot') ?>';
    document.getElementById('q_action').value = 'create_quote';
    document.getElementById('q_id').value = '';
    document.getElementById('q_contact').value = '';
    document.getElementById('q_custom_name').value = '';
    document.getElementById('q_tax_type').value = 'kleinunternehmer';
    document.getElementById('q_notes').value = '';
    document.getElementById('q_valid_until').value = '';
    document.getElementById('q_subject').value = '';
    document.getElementById('q_intro_text').value = '';
    document.getElementById('q_status_container').style.display = 'none';
    document.getElementById('items-container').innerHTML = '';
    addItem();
    calcTotal();
}

function prepareEditQuote(q) {
    document.getElementById('quoteModalTitle').innerHTML = '<i class="bi bi-pencil-square me-2"></i> Angebot bearbeiten';
    document.getElementById('q_action').value = 'edit_quote';
    document.getElementById('q_id').value = q.id;
    document.getElementById('q_contact').value = q.contact_id || '';
    document.getElementById('q_custom_name').value = q.custom_name || '';
    document.getElementById('q_tax_type').value = q.tax_type || 'kleinunternehmer';
    document.getElementById('q_notes').value = q.notes || '';
    document.getElementById('q_valid_until').value = q.valid_until || '';
    document.getElementById('q_subject').value    = q.subject     || '';
    document.getElementById('q_intro_text').value = q.intro_text  || '';
    document.getElementById('q_status').value = q.status || 'Entwurf';
    document.getElementById('q_status_container').style.display = 'block';

    const items = typeof q.items === 'string' ? JSON.parse(q.items) : q.items;
    document.getElementById('items-container').innerHTML = '';
    (items || []).forEach(it => addItem(it.desc, it.qty, it.price, it.unit || ''));
    if ((items || []).length === 0) addItem();
    calcTotal();
    quoteModal.show();
}

function fillClientName(sel) {
    if (sel.value) {
        const opt = sel.options[sel.selectedIndex];
        document.getElementById('q_custom_name').value = '';
    }
}

let itemIndex = 0;
function addItem(desc = '', qty = 1, price = 0, unit = '') {
    const row = document.createElement('div');
    row.className = 'row g-2 mb-2 align-items-center item-row';
    row.innerHTML = `
        <div class="col-md-5">
            <input type="text" name="item_desc[]" class="form-control form-control-sm" placeholder=<?= tjs('Beschreibung...') ?> value="${escHtml(desc)}" required>
        </div>
        <div class="col-md-2">
            <input type="text" name="item_unit[]" class="form-control form-control-sm" placeholder="Einheit" value="${escHtml(unit)}" list="q_unit_options">
        </div>
        <div class="col-md-2">
            <input type="number" name="item_qty[]" class="form-control form-control-sm item-qty" placeholder="Menge" value="${qty}" min="0.01" step="0.01" oninput="calcTotal()">
        </div>
        <div class="col-md-2">
            <div class="input-group input-group-sm">
                <input type="text" name="item_price[]" class="form-control item-price" placeholder="Preis" value="${price > 0 ? price.toFixed(2).replace('.',',') : ''}" oninput="calcTotal()">
                <span class="input-group-text">€</span>
            </div>
        </div>
        <div class="col-md-1 text-end">
            <button type="button" class="btn btn-sm btn-outline-danger px-2 py-1" onclick='this.closest(\'.item-row\').remove(); calcTotal()'><i class="bi bi-trash3"></i></button>
        </div>`;
    document.getElementById('items-container').appendChild(row);
}

function escHtml(str) {
    return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

function calcTotal() {
    const taxType = document.getElementById('q_tax_type').value;
    let netto = 0;
    document.querySelectorAll('.item-row').forEach(row => {
        const qty   = parseFloat(row.querySelector('.item-qty')?.value || 0);
        const price = parseFloat((row.querySelector('.item-price')?.value || '0').replace(',', '.'));
        if (!isNaN(qty) && !isNaN(price)) netto += qty * price;
    });
    const brutto = taxType === 'regel' ? netto * 1.19 : netto;
    document.getElementById('total-display').textContent = <?= tjs('Gesamt: ') ?> + brutto.toLocaleString('de-DE', {minimumFractionDigits:2, maximumFractionDigits:2}) + ' €';
}

document.getElementById('q_tax_type').addEventListener('change', calcTotal);

const emailQuoteModal = new bootstrap.Modal(document.getElementById('emailQuoteModal'));
// Vorlagentexte aus den Einstellungen; ersetzt wird mit mailTplFill().
const MAIL_TPL = <?= mail_templates_json(['quote_send']) ?>;
const MAIL_FIRMA = '<?= htmlspecialchars(setting('company_short', COMPANY_SHORT), ENT_QUOTES) ?>';

function openEmailModal(q) {
    document.getElementById('eq_id').value      = q.id;
    document.getElementById('eq_to').value      = q.email || '';
    const _v = {
        kunde:       q.client || 'Sie',
        nummer:      q.quote_number,
        betrag:      parseFloat(q.total_amount).toLocaleString('de-DE', {minimumFractionDigits:2, maximumFractionDigits:2}),
        anmerkungen: (q.notes || '').trim(),
        firma:       MAIL_FIRMA
    };
    document.getElementById('eq_subject').value = mailTplFill(MAIL_TPL.quote_send.subject, _v);
    document.getElementById('eq_body').value = mailTplFill(MAIL_TPL.quote_send.body, _v);
    emailQuoteModal.show();
}

function validateQuoteForm() {
    const items = document.querySelectorAll('#items-container .item-row');
    if (items.length === 0) {
        const btn = document.querySelector('#quoteForm button[type=submit]');
        const orig = btn.innerHTML;
        btn.classList.add('btn-danger'); btn.classList.remove('btn-primary');
        btn.innerHTML = '<i class="bi bi-exclamation-triangle me-1"></i> Mind. 1 Position!';
        setTimeout(() => { btn.classList.remove('btn-danger'); btn.classList.add('btn-primary'); btn.innerHTML = orig; }, 3000);
        return false;
    }
    const contact = document.getElementById('q_contact').value;
    const custom  = document.getElementById('q_custom_name').value.trim();
    if (!contact && !custom) {
        document.getElementById('q_custom_name').focus();
        document.getElementById('q_custom_name').classList.add('is-invalid');
        setTimeout(() => document.getElementById('q_custom_name').classList.remove('is-invalid'), 3000);
        return false;
    }
    return true;
}

function confirmDeleteQuote(btn, id) {
    if (btn.dataset.confirmed === '1') {
        document.getElementById('del_q_' + id).submit();
    } else {
        btn.dataset.confirmed = '1';
        btn.innerHTML = <?= tjs('Sicher?') ?>;
        btn.classList.replace('btn-outline-danger', 'btn-danger');
        btn.classList.add('text-white');
        setTimeout(() => {
            if (btn.parentNode) {
                btn.dataset.confirmed = '0';
                btn.innerHTML = '<i class="bi bi-trash3"></i>';
                btn.classList.replace('btn-danger', 'btn-outline-danger');
                btn.classList.remove('text-white');
            }
        }, 3000);
    }
}
</script>
<?php ?>
<script src="<?= asset('assets/js/mail-templates.js') ?>"></script>
<?php
require 'includes/layout_end.php'; ?>
