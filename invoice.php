<?php
// 1. Zentrale Config laden
require_once 'config.php';
require_once 'includes/numbering.php';

// Session ueber den gemeinsamen Bootstrap. Frueher setzte diese Datei ein
// domainweites Cookie (abgeleitet aus MAIN_WEBSITE) und erzeugte damit
// selbst den PHPSESSID-Konflikt, den includes/session.php beschreibt.
require_once 'includes/session.php';
app_session_start();

if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    die("Zugriff verweigert.");
}

// PRÜFUNG: Ist der Composer-Autoloader vorhanden?
if (!file_exists(__DIR__ . '/vendor/autoload.php')) {
    die("<b>FEHLER:</b> Die Datei 'vendor/autoload.php' wurde nicht gefunden! Bitte 'composer install' ausführen.");
}
require_once __DIR__ . '/vendor/autoload.php';

// Hilfsfunktion für Umlaute
function safe_decode($str) {
    if(empty($str)) return '';
    return mb_convert_encoding($str, 'ISO-8859-1', 'UTF-8');
}

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    
    // 1. Absender (Nutzt Config-Fallbacks)
    $sender_name    = safe_decode($_POST['sender_name'] ?? COMPANY_NAME);
    $sender_street  = safe_decode($_POST['sender_street'] ?? '');
    $sender_city    = safe_decode($_POST['sender_city'] ?? '');
    $sender_email   = safe_decode(!empty($_POST['sender_email']) ? $_POST['sender_email'] : ADMIN_EMAIL);
    
    $clean_website  = str_replace(['http://', 'https://', 'www.'], '', MAIN_WEBSITE);
    $sender_website = safe_decode(!empty($_POST['sender_website']) ? $_POST['sender_website'] : $clean_website);
    
    $sender_line1   = safe_decode($_POST['sender_line1'] ?? ''); 
    $sender_line2   = safe_decode($_POST['sender_line2'] ?? ''); 

    // 2. Empfänger
    $client_name    = safe_decode($_POST['client_name'] ?? '');
    $client_street  = safe_decode($_POST['client_street'] ?? '');
    $client_city    = safe_decode($_POST['client_city'] ?? '');
    $client_country = safe_decode($_POST['client_country'] ?? '');
    $client_website = safe_decode($_POST['client_website'] ?? '');
    $client_line1   = safe_decode($_POST['client_line1'] ?? ''); 
    $client_line2   = safe_decode($_POST['client_line2'] ?? ''); 

    // 3. Metadaten
    $invoice_number = $_POST['invoice_number'] ?? 'RE-00000';
    
    $raw_invoice_date = !empty($_POST['invoice_date']) ? $_POST['invoice_date'] : date('Y-m-d');
    $raw_due_date = !empty($_POST['due_date']) ? $_POST['due_date'] : date('Y-m-d', strtotime($raw_invoice_date . ' + 14 days'));
    
    $invoice_date   = date('d.m.Y', strtotime($raw_invoice_date));
    $due_date       = date('d.m.Y', strtotime($raw_due_date));
    
    $tax_type       = $_POST['tax_type'] ?? 'kleinunternehmer';
    $installments   = $_POST['installments'] ?? '1';
    $subject        = safe_decode($_POST['subject'] ?? '');
    $intro_text     = safe_decode($_POST['intro_text'] ?? '');

    $iban   = safe_decode($_POST['iban'] ?? '');
    $paypal = safe_decode($_POST['paypal'] ?? '');
    $notes  = safe_decode($_POST['notes'] ?? '');
    $items_unit = $_POST['item_unit'] ?? [];

    // PDF GENERIERUNG STARTEN
    $pdf = new FPDF();
    $pdf->AddPage();
    $pdf->SetMargins(15, 20, 15);
    $pdf->SetAutoPageBreak(true, 20);

    // --- LOGO (OBEN LINKS) ---
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

    // --- ABSENDER (RECHTS) ---
    $pdf->SetFont('Arial', 'B', 16);
    
    // Hex-Farbe aus Config in RGB für FPDF umwandeln
    list($r, $g, $b) = sscanf(COLOR_PRIMARY, "#%02x%02x%02x");
    $pdf->SetTextColor($r, $g, $b);
    
    $pdf->Cell(0, 8, $sender_name, 0, 1, 'R');
    
    $pdf->SetFont('Arial', '', 9);
    $pdf->SetTextColor(100, 100, 100);
    $pdf->Cell(0, 4, $sender_street . " | " . $sender_city, 0, 1, 'R');
    $pdf->Cell(0, 4, $sender_email . " | " . $sender_website, 0, 1, 'R');
    
    if (!empty($sender_line1)) $pdf->Cell(0, 4, $sender_line1, 0, 1, 'R');
    if (!empty($sender_line2)) $pdf->Cell(0, 4, $sender_line2, 0, 1, 'R');
    $pdf->Ln(15);

    // --- EMPFÄNGER (LINKS) ---
    $pdf->SetTextColor(0, 0, 0);
    $pdf->SetFont('Arial', 'B', 11);
    $pdf->Cell(0, 6, $client_name, 0, 1);
    
    $pdf->SetFont('Arial', '', 10);
    if (!empty($client_street))  $pdf->Cell(0, 5, $client_street, 0, 1);
    if (!empty($client_city))    $pdf->Cell(0, 5, $client_city, 0, 1);
    if (!empty($client_country)) $pdf->Cell(0, 5, $client_country, 0, 1);
    if (!empty($client_website)) $pdf->Cell(0, 5, $client_website, 0, 1);
    
    if (!empty($client_line1)) $pdf->Cell(0, 5, $client_line1, 0, 1);
    if (!empty($client_line2)) $pdf->Cell(0, 5, $client_line2, 0, 1);
    $pdf->Ln(15);

    // --- RECHNUNGS-KOPF ---
    $pdf->SetFont('Arial', 'B', 18);
    $pdf->Cell(0, 10, 'Rechnung Nr. ' . $invoice_number, 0, 1);
    $pdf->SetFont('Arial', '', 10);
    $pdf->SetTextColor(100, 100, 100);
    $pdf->Cell(40, 5, 'Rechnungsdatum:', 0, 0);
    $pdf->Cell(0, 5, $invoice_date, 0, 1);
    $pdf->SetFont('Arial', 'B', 10);
    $pdf->SetTextColor(220, 53, 69);
    $pdf->Cell(40, 5, safe_decode('Zahlbar bis:'), 0, 0);
    $pdf->Cell(0, 5, $due_date, 0, 1);

    // BETREFF
    if (!empty($subject)) {
        $pdf->Ln(5);
        $pdf->SetFont('Arial', 'B', 11);
        $pdf->SetTextColor(0, 0, 0);
        $pdf->Cell(0, 6, 'Betreff: ' . $subject, 0, 1);
    }

    // EINLEITUNGSTEXT
    if (!empty($intro_text)) {
        $pdf->Ln(3);
        $pdf->SetFont('Arial', '', 10);
        $pdf->SetTextColor(50, 50, 50);
        $pdf->MultiCell(0, 5, $intro_text);
    }

    $pdf->Ln(!empty($subject) || !empty($intro_text) ? 6 : 10);

    // --- TABELLEN-HEADER (88+20+22+25+25 = 180mm) ---
    $pdf->SetTextColor(0, 0, 0);
    $pdf->SetFillColor(240, 240, 240);
    $pdf->SetFont('Arial', 'B', 10);
    $pdf->Cell(88, 10, 'Position / Beschreibung', 0, 0, 'L', true);
    $pdf->Cell(20, 10, 'Menge', 0, 0, 'C', true);
    $pdf->Cell(22, 10, 'Einheit', 0, 0, 'C', true);
    $pdf->Cell(25, 10, 'Einzel', 0, 0, 'R', true);
    $pdf->Cell(25, 10, 'Gesamt', 0, 1, 'R', true);
    $pdf->Ln(2);

    // --- POSITIONEN ---
    $netto_total = 0;
    $items_desc = $_POST['item_desc'] ?? [];
    $items_qty = $_POST['item_qty'] ?? [];
    $items_price = $_POST['item_price'] ?? [];

    $pdf->SetFont('Arial', '', 9);

    for ($i = 0; $i < count($items_desc); $i++) {
        if (empty(trim($items_desc[$i]))) continue;

        $desc  = safe_decode($items_desc[$i]);
        $unit  = safe_decode($items_unit[$i] ?? '');
        $qty   = (float)$items_qty[$i];
        $price = (float)str_replace(',', '.', $items_price[$i]);
        $total = $qty * $price;
        $netto_total += $total;

        $startX = $pdf->GetX();
        $startY = $pdf->GetY();

        $pdf->MultiCell(88, 5, $desc, 0, 'L');
        $newY = $pdf->GetY();
        $height = max(5, $newY - $startY);

        $pdf->SetXY($startX + 88, $startY);
        $pdf->Cell(20, $height, number_format($qty, 2, ',', '.'), 0, 0, 'C');
        $pdf->Cell(22, $height, $unit, 0, 0, 'C');
        $pdf->Cell(25, $height, number_format($price, 2, ',', '.') . ' ' . chr(128), 0, 0, 'R');
        $pdf->Cell(25, $height, number_format($total, 2, ',', '.') . ' ' . chr(128), 0, 1, 'R');

        $pdf->SetY($newY + 2);
        $pdf->SetDrawColor(220, 220, 220);
        $pdf->Line(15, $pdf->GetY(), 195, $pdf->GetY());
        $pdf->SetY($pdf->GetY() + 3);
    }

    $pdf->Ln(5);

    // --- SUMMEN ---
    $tax_rate = ($tax_type == 'regel') ? 0.19 : 0.00;
    $tax_amount = $netto_total * $tax_rate;
    $brutto_total = $netto_total + $tax_amount;

    if ($tax_type == 'regel') {
        $pdf->SetFont('Arial', '', 10);
        $pdf->Cell(155, 6, 'Netto:', 0, 0, 'R');
        $pdf->Cell(25, 6, number_format($netto_total, 2, ',', '.') . ' ' . chr(128), 0, 1, 'R');
        $pdf->Cell(155, 6, 'zzgl. 19% MwSt:', 0, 0, 'R');
        $pdf->Cell(25, 6, number_format($tax_amount, 2, ',', '.') . ' ' . chr(128), 0, 1, 'R');
    }

    $pdf->Ln(2);
    $pdf->SetFont('Arial', 'B', 14);
    $pdf->Cell(155, 10, 'Rechnungsbetrag:', 0, 0, 'R');
    
    $pdf->SetTextColor($r, $g, $b); // Farbe aus Config anwenden
    $pdf->Cell(25, 10, number_format($brutto_total, 2, ',', '.') . ' ' . chr(128), 0, 1, 'R');

    $pdf->Ln(10);
    $pdf->SetTextColor(0, 0, 0);

    // --- RATENZAHLUNG / ABO ---
    if ($installments !== '1') {
        $pdf->SetFont('Arial', 'B', 10);
        $pdf->SetFillColor(255, 243, 205);
        if ($installments == 'abo') {
            $pdf->Cell(0, 8, safe_decode(" Dies ist eine wiederkehrende Abo-Rechnung. "), 0, 1, 'L', true);
        } else {
            $rate_amount = $brutto_total / (int)$installments;
            $pdf->Cell(0, 8, safe_decode(" Ratenzahlung: " . $installments . " Raten zu je " . number_format($rate_amount, 2, ',', '.') . " " . chr(128)), 0, 1, 'L', true);
        }
        $pdf->Ln(5);
    }

    // --- NOTIZEN ---
    if (!empty($notes)) {
        $pdf->SetFont('Arial', 'B', 10);
        $pdf->Cell(0, 6, 'Anmerkungen:', 0, 1);
        $pdf->SetFont('Arial', '', 9);
        $pdf->MultiCell(0, 5, $notes);
        $pdf->Ln(5);
    }

    $pdf->SetFont('Arial', 'I', 9);
    $pdf->SetTextColor(100, 100, 100);
    if ($tax_type == 'kleinunternehmer') {
        $pdf->MultiCell(0, 5, safe_decode("Gemäß § 19 UStG (Kleinunternehmerregelung) wird keine Umsatzsteuer ausgewiesen."));
    }
    
    $pdf->Ln(5);
    $pdf->SetFont('Arial', 'B', 9);
    $pdf->SetTextColor(0, 0, 0);
    $pdf->Cell(0, 5, 'Bankverbindung:', 0, 1);
    $pdf->SetFont('Arial', '', 9);
    if (!empty($iban)) $pdf->Cell(0, 5, safe_decode("IBAN: " . $iban), 0, 1);
    if (!empty($paypal)) $pdf->Cell(0, 5, safe_decode("PayPal: " . $paypal), 0, 1);

    // ==========================================
    // 1. PDF AUF SERVER SPEICHERN
    // ==========================================
    $upload_dir = __DIR__ . '/uploads/invoices/';
    if (!is_dir($upload_dir)) {
        if (!mkdir($upload_dir, 0755, true)) {
            die("<b>FEHLER:</b> Konnte den Ordner '$upload_dir' nicht erstellen.");
        }
    }

    $safe_invoice_number = preg_replace("/[^a-zA-Z0-9_-]/", "", $invoice_number);
    $file_name = 'Rechnung_' . $safe_invoice_number . '.pdf';
    $file_path = $upload_dir . $file_name;
    $relative_path = 'uploads/invoices/' . $file_name;

    $pdf->Output($file_path, 'F');

    // ==========================================
    // 2. IN FINANZEN-DATENBANK EINTRAGEN ODER AKTUALISIEREN
    // ==========================================
    try {
        // Prüfen, ob die Rechnung schon existiert
        $stmt = $pdo->prepare("SELECT id FROM finances WHERE title = ?");
        $stmt->execute([$invoice_number]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $db_client_name = trim($_POST['client_name'] ?? "Unbekannter Kunde");
        $db_notes = trim($_POST['notes'] ?? "");
        $db_contact_id = !empty($_POST['contact_id']) ? (int)$_POST['contact_id'] : null;
        $db_amount = number_format($brutto_total, 2, '.', ''); // Punkt als Dezimaltrenner für MySQL

        if (!$existing) {
            // NEU ANLEGEN
            $insert = $pdo->prepare("
                INSERT INTO finances 
                (type, title, invoice_number, contact_id, custom_name, amount, status, record_date, due_date, notes, invoice_pdf_path, is_recurring)
                VALUES ('INCOME', ?, ?, ?, ?, ?, 'Offen', ?, ?, ?, ?, 0)
            ");
            // Die Nummer steht weiterhin im Titel – die Suche nach einer
            // bestehenden Rechnung weiter oben greift darauf zu – und
            // zusätzlich in ihrer eigenen Spalte mit eindeutigem Index.
            $insert->execute([$invoice_number, $invoice_number, $db_contact_id, $db_client_name, $db_amount, $raw_invoice_date, $raw_due_date, $db_notes, $relative_path]);

            $pdo->prepare("INSERT INTO logs (action_type, description) VALUES (?, ?)")
                ->execute(['INVOICE_CREATED', "Rechnung $invoice_number für $db_client_name generiert."]);
        } else {
            // AKTUALISIEREN (Wenn du die gleiche Rechnung noch mal überschreibst)
            $update = $pdo->prepare("
                UPDATE finances 
                SET contact_id = ?, custom_name = ?, amount = ?, record_date = ?, due_date = ?, notes = ?, invoice_pdf_path = ?
                WHERE id = ?
            ");
            $update->execute([$db_contact_id, $db_client_name, $db_amount, $raw_invoice_date, $raw_due_date, $db_notes, $relative_path, $existing['id']]);

            $pdo->prepare("INSERT INTO logs (action_type, description) VALUES (?, ?)")
                ->execute(['INVOICE_UPDATED', "Rechnung $invoice_number wurde neu generiert und auf $db_amount € aktualisiert."]);
        }
    } catch (PDOException $e) {
        die("<b>Datenbankfehler beim Speichern der Rechnung:</b><br>" . $e->getMessage());
    }

    // ==========================================
    // 3. PDF IM BROWSER ANZEIGEN
    // ==========================================
    $pdf->Output($file_name, 'I');
}
?>