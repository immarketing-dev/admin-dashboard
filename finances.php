<?php
// 1. Zentrale Config laden
require_once 'config.php';
require_once __DIR__ . '/includes/logging.php';
require_once 'includes/mail_templates.php';
require_once 'includes/numbering.php';
require_once 'includes/auth.php';
require_once 'includes/filter_state.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as PHPMailerException;
require_once __DIR__ . '/vendor/autoload.php';
$_pm_available = class_exists(PHPMailer::class);

// ==========================================
// QUOTES: HILFSFUNKTIONEN
// ==========================================
function q_safe(string $str): string {
    return mb_convert_encoding($str, 'ISO-8859-1', 'UTF-8');
}
// next_quote_number() und next_invoice_number() stehen in
// includes/numbering.php - vorher stand dieselbe COUNT(*)-Zeile hier
// und in quotes.php.
function build_items(): array {
    $descs = $_POST['item_desc'] ?? []; $qtys = $_POST['item_qty'] ?? [];
    $prices = $_POST['item_price'] ?? []; $units = $_POST['item_unit'] ?? [];
    $items = [];
    for ($i = 0; $i < count($descs); $i++) {
        if (empty(trim($descs[$i]))) continue;
        $items[] = ['desc'=>trim($descs[$i]),'qty'=>(float)($qtys[$i]??1),'price'=>(float)str_replace(',','.',$prices[$i]??0),'unit'=>trim($units[$i]??'')];
    }
    return $items;
}
function calc_total(array $items, string $tax_type): float {
    $netto = array_sum(array_map(fn($it) => $it['qty'] * $it['price'], $items));
    return $netto * ($tax_type === 'regel' ? 1.19 : 1.0);
}
function build_quote_pdf(PDO $pdo, array $q): string {
    require_once __DIR__ . '/vendor/autoload.php';
    $items = json_decode($q['items'], true) ?: [];
    $tax_type = $q['tax_type'];
    $client_name = $q['custom_name'] ?: ($q['c_name'] ?? 'Unbekannt');
    list($r, $g, $b) = sscanf(COLOR_PRIMARY, "#%02x%02x%02x");
    $pdf = new FPDF(); $pdf->AddPage(); $pdf->SetMargins(15, 20, 15); $pdf->SetAutoPageBreak(true, 20);
    $_logo_rel = setting('company_logo', '');
    if ($_logo_rel && file_exists(__DIR__ . '/' . $_logo_rel)) {
        $_logo_abs = __DIR__ . '/' . $_logo_rel; $_linfo = @getimagesize($_logo_abs);
        if ($_linfo) {
            $ratio = $_linfo[0] / $_linfo[1]; $max_w = 55; $max_h = 22;
            if ($ratio > $max_w / $max_h) { $dw = $max_w; $dh = round($max_w / $ratio, 1); } else { $dh = $max_h; $dw = round($max_h * $ratio, 1); }
            $ext = strtoupper(pathinfo($_logo_rel, PATHINFO_EXTENSION)); if ($ext === 'JPG') $ext = 'JPEG';
            $pdf->Image($_logo_abs, 15, 13, $dw, $dh, $ext); $pdf->SetY(20);
        }
    }
    $pdf->SetFont('Arial','B',16); $pdf->SetTextColor($r,$g,$b); $pdf->Cell(0,8,q_safe(COMPANY_NAME),0,1,'R');
    $pdf->SetFont('Arial','',9); $pdf->SetTextColor(100,100,100);
    $s_street = q_safe(setting('company_street','')); $s_city = q_safe(setting('company_city',''));
    if ($s_street || $s_city) $pdf->Cell(0,4,trim(($s_street?$s_street.' | ':'').$s_city,' | '),0,1,'R');
    $pdf->Cell(0,4,ADMIN_EMAIL.' | '.str_replace(['http://','https://','www.'],'',MAIN_WEBSITE),0,1,'R'); $pdf->Ln(15);
    $pdf->SetTextColor(0,0,0); $pdf->SetFont('Arial','B',11); $pdf->Cell(0,6,q_safe($client_name),0,1);
    $pdf->SetFont('Arial','',10);
    if (!empty($q['c_company'])) $pdf->Cell(0,5,q_safe($q['c_company']),0,1);
    if (!empty($q['c_street']))  $pdf->Cell(0,5,q_safe($q['c_street']),0,1);
    if (!empty($q['c_zip'])||!empty($q['c_city'])) $pdf->Cell(0,5,q_safe(trim($q['c_zip'].' '.$q['c_city'])),0,1);
    if (!empty($q['c_country'])) $pdf->Cell(0,5,q_safe($q['c_country']),0,1);
    $pdf->Ln(15);
    $pdf->SetFont('Arial','B',18); $pdf->SetTextColor($r,$g,$b); $pdf->Cell(0,10,q_safe('Angebot Nr. '.$q['quote_number']),0,1);
    $pdf->SetFont('Arial','',10); $pdf->SetTextColor(100,100,100);
    $pdf->Cell(50,5,q_safe('Datum:'),0,0); $pdf->Cell(0,5,date('d.m.Y',strtotime($q['created_at'])),0,1);
    if ($q['valid_until']) { $pdf->SetFont('Arial','B',10); $pdf->SetTextColor(220,53,69); $pdf->Cell(50,5,q_safe('Gültig bis:'),0,0); $pdf->Cell(0,5,date('d.m.Y',strtotime($q['valid_until'])),0,1); }
    if (!empty($q['subject'])) { $pdf->Ln(5); $pdf->SetFont('Arial','B',11); $pdf->SetTextColor(0,0,0); $pdf->Cell(0,6,q_safe('Betreff: '.$q['subject']),0,1); }
    if (!empty($q['intro_text'])) { $pdf->Ln(3); $pdf->SetFont('Arial','',10); $pdf->SetTextColor(50,50,50); $pdf->MultiCell(0,5,q_safe($q['intro_text'])); }
    $pdf->Ln(8);
    $pdf->SetTextColor(0,0,0); $pdf->SetFillColor(240,240,240); $pdf->SetFont('Arial','B',10);
    $pdf->Cell(88,10,q_safe('Position / Beschreibung'),0,0,'L',true); $pdf->Cell(20,10,'Menge',0,0,'C',true);
    $pdf->Cell(22,10,'Einheit',0,0,'C',true); $pdf->Cell(25,10,'Einzelpreis',0,0,'R',true); $pdf->Cell(25,10,'Gesamt',0,1,'R',true); $pdf->Ln(2);
    $netto = 0; $pdf->SetFont('Arial','',9);
    foreach ($items as $item) {
        $desc=q_safe($item['desc']); $qty=(float)($item['qty']??1); $price=(float)($item['price']??0); $unit=q_safe($item['unit']??''); $pos=$qty*$price; $netto+=$pos;
        $sx=$pdf->GetX(); $sy=$pdf->GetY(); $pdf->MultiCell(88,5,$desc,0,'L'); $ny=$pdf->GetY(); $h=max(5,$ny-$sy);
        $pdf->SetXY($sx+88,$sy); $pdf->Cell(20,$h,number_format($qty,2,',','.'),0,0,'C');
        $pdf->Cell(22,$h,$unit,0,0,'C'); $pdf->Cell(25,$h,number_format($price,2,',','.').chr(128).' ',0,0,'R');
        $pdf->Cell(25,$h,number_format($pos,2,',','.').chr(128).' ',0,1,'R');
        $pdf->SetY($ny+2); $pdf->SetDrawColor(220,220,220); $pdf->Line(15,$pdf->GetY(),195,$pdf->GetY()); $pdf->SetY($pdf->GetY()+3);
    }
    $pdf->Ln(5);
    $tax_amount = $tax_type==='regel'?$netto*0.19:0; $brutto=$netto+$tax_amount;
    if ($tax_type==='regel') {
        $pdf->SetFont('Arial','',10); $pdf->Cell(155,6,q_safe('Netto:'),0,0,'R'); $pdf->Cell(25,6,number_format($netto,2,',','.').chr(128).' ',0,1,'R');
        $pdf->Cell(155,6,q_safe('zzgl. 19% MwSt:'),0,0,'R'); $pdf->Cell(25,6,number_format($tax_amount,2,',','.').chr(128).' ',0,1,'R');
    }
    $pdf->Ln(2); $pdf->SetFont('Arial','B',14); $pdf->Cell(155,10,q_safe('Angebotssumme:'),0,0,'R');
    $pdf->SetTextColor($r,$g,$b); $pdf->Cell(25,10,number_format($brutto,2,',','.').chr(128).' ',0,1,'R');
    $pdf->SetTextColor(0,0,0); $pdf->Ln(10);
    if (!empty($q['notes'])) { $pdf->SetFont('Arial','B',10); $pdf->Cell(0,6,q_safe('Anmerkungen:'),0,1); $pdf->SetFont('Arial','',9); $pdf->MultiCell(0,5,q_safe($q['notes'])); $pdf->Ln(5); }
    $pdf->SetFont('Arial','I',9); $pdf->SetTextColor(100,100,100);
    if ($tax_type==='kleinunternehmer') $pdf->MultiCell(0,5,q_safe('Gemäß § 19 UStG (Kleinunternehmerregelung) wird keine Umsatzsteuer ausgewiesen.'));
    $upload_dir = __DIR__.'/uploads/quotes/'; if (!is_dir($upload_dir)) mkdir($upload_dir,0755,true);
    $safe_num = preg_replace('/[^a-zA-Z0-9_-]/','', $q['quote_number']);
    $file_path = $upload_dir.'Angebot_'.$safe_num.'.pdf'; $rel_path = 'uploads/quotes/Angebot_'.$safe_num.'.pdf';
    $pdf->Output($file_path,'F'); $pdo->prepare("UPDATE quotes SET quote_pdf_path=? WHERE id=?")->execute([$rel_path,$q['id']]);
    return $rel_path;
}
function build_invoice_pdf_from_quote(array $q, string $inv_num): string {
    require_once __DIR__ . '/vendor/autoload.php';
    $items = json_decode($q['items'], true) ?: []; $tax_type = $q['tax_type'];
    $client_name = $q['custom_name'] ?: ($q['c_name'] ?? 'Unbekannt');
    list($r,$g,$b) = sscanf(COLOR_PRIMARY,"#%02x%02x%02x");
    $pdf = new FPDF(); $pdf->AddPage(); $pdf->SetMargins(15,20,15); $pdf->SetAutoPageBreak(true,20);
    $_logo_rel = setting('company_logo','');
    if ($_logo_rel && file_exists(__DIR__.'/'.$_logo_rel)) {
        $_logo_abs=__DIR__.'/'.$_logo_rel; $_linfo=@getimagesize($_logo_abs);
        if ($_linfo) {
            $ratio=$_linfo[0]/$_linfo[1]; $max_w=55; $max_h=22;
            if ($ratio>$max_w/$max_h){$dw=$max_w;$dh=round($max_w/$ratio,1);}else{$dh=$max_h;$dw=round($max_h*$ratio,1);}
            $ext=strtoupper(pathinfo($_logo_rel,PATHINFO_EXTENSION)); if($ext==='JPG')$ext='JPEG';
            $pdf->Image($_logo_abs,15,13,$dw,$dh,$ext); $pdf->SetY(20);
        }
    }
    $pdf->SetFont('Arial','B',16); $pdf->SetTextColor($r,$g,$b); $pdf->Cell(0,8,q_safe(COMPANY_NAME),0,1,'R');
    $pdf->SetFont('Arial','',9); $pdf->SetTextColor(100,100,100);
    $s_street=q_safe(setting('company_street','')); $s_city=q_safe(setting('company_city',''));
    if($s_street||$s_city) $pdf->Cell(0,4,trim(($s_street?$s_street.' | ':'').$s_city,' | '),0,1,'R');
    $pdf->Cell(0,4,ADMIN_EMAIL.' | '.str_replace(['http://','https://','www.'],'',MAIN_WEBSITE),0,1,'R'); $pdf->Ln(15);
    $pdf->SetTextColor(0,0,0); $pdf->SetFont('Arial','B',11); $pdf->Cell(0,6,q_safe($client_name),0,1);
    $pdf->SetFont('Arial','',10);
    if(!empty($q['c_company']))$pdf->Cell(0,5,q_safe($q['c_company']),0,1);
    if(!empty($q['c_street'])) $pdf->Cell(0,5,q_safe($q['c_street']),0,1);
    if(!empty($q['c_zip'])||!empty($q['c_city']))$pdf->Cell(0,5,q_safe(trim($q['c_zip'].' '.$q['c_city'])),0,1);
    if(!empty($q['c_country']))$pdf->Cell(0,5,q_safe($q['c_country']),0,1); $pdf->Ln(15);
    $pdf->SetFont('Arial','B',18); $pdf->SetTextColor($r,$g,$b); $pdf->Cell(0,10,q_safe('Rechnung Nr. '.$inv_num),0,1);
    $pdf->SetFont('Arial','',10); $pdf->SetTextColor(100,100,100);
    $pdf->Cell(40,5,q_safe('Rechnungsdatum:'),0,0); $pdf->Cell(0,5,date('d.m.Y'),0,1);
    $pdf->SetFont('Arial','B',10); $pdf->SetTextColor(220,53,69);
    $pdf->Cell(40,5,q_safe('Zahlbar bis:'),0,0); $pdf->Cell(0,5,date('d.m.Y',strtotime('+14 days')),0,1);
    if(!empty($q['subject'])){$pdf->Ln(5);$pdf->SetFont('Arial','B',11);$pdf->SetTextColor(0,0,0);$pdf->Cell(0,6,q_safe('Betreff: '.$q['subject']),0,1);}
    if(!empty($q['intro_text'])){$pdf->Ln(3);$pdf->SetFont('Arial','',10);$pdf->SetTextColor(50,50,50);$pdf->MultiCell(0,5,q_safe($q['intro_text']));}
    $pdf->Ln(!empty($q['subject'])||!empty($q['intro_text'])?6:10);
    $pdf->SetTextColor(0,0,0); $pdf->SetFillColor(240,240,240); $pdf->SetFont('Arial','B',10);
    $pdf->Cell(88,10,q_safe('Position / Beschreibung'),0,0,'L',true); $pdf->Cell(20,10,'Menge',0,0,'C',true);
    $pdf->Cell(22,10,'Einheit',0,0,'C',true); $pdf->Cell(25,10,q_safe('Einzelpreis'),0,0,'R',true); $pdf->Cell(25,10,'Gesamt',0,1,'R',true); $pdf->Ln(2);
    $netto=0; $pdf->SetFont('Arial','',9);
    foreach($items as $item){
        $desc=q_safe($item['desc']); $qty=(float)($item['qty']??1); $price=(float)($item['price']??0);
        $unit=q_safe($item['unit']??''); $pos=$qty*$price; $netto+=$pos;
        $sx=$pdf->GetX(); $sy=$pdf->GetY(); $pdf->MultiCell(88,5,$desc,0,'L');
        $ny=$pdf->GetY(); $h=max(5,$ny-$sy); $pdf->SetXY($sx+88,$sy);
        $pdf->Cell(20,$h,number_format($qty,2,',','.'),0,0,'C'); $pdf->Cell(22,$h,$unit,0,0,'C');
        $pdf->Cell(25,$h,number_format($price,2,',','.').chr(128).' ',0,0,'R');
        $pdf->Cell(25,$h,number_format($pos,2,',','.').chr(128).' ',0,1,'R');
        $pdf->SetY($ny+2); $pdf->SetDrawColor(220,220,220); $pdf->Line(15,$pdf->GetY(),195,$pdf->GetY()); $pdf->SetY($pdf->GetY()+3);
    }
    $pdf->Ln(5);
    $tax_amount=$tax_type==='regel'?$netto*0.19:0; $brutto=$netto+$tax_amount;
    if($tax_type==='regel'){
        $pdf->SetFont('Arial','',10); $pdf->Cell(155,6,q_safe('Netto:'),0,0,'R'); $pdf->Cell(25,6,number_format($netto,2,',','.').chr(128).' ',0,1,'R');
        $pdf->Cell(155,6,q_safe('zzgl. 19% MwSt:'),0,0,'R'); $pdf->Cell(25,6,number_format($tax_amount,2,',','.').chr(128).' ',0,1,'R');
    }
    $pdf->Ln(2); $pdf->SetFont('Arial','B',14); $pdf->Cell(155,10,q_safe('Rechnungsbetrag:'),0,0,'R');
    $pdf->SetTextColor($r,$g,$b); $pdf->Cell(25,10,number_format($brutto,2,',','.').chr(128).' ',0,1,'R');
    $pdf->SetTextColor(0,0,0); $pdf->Ln(10);
    if(!empty($q['notes'])){$pdf->SetFont('Arial','B',10);$pdf->Cell(0,6,q_safe('Anmerkungen:'),0,1);$pdf->SetFont('Arial','',9);$pdf->MultiCell(0,5,q_safe($q['notes']));$pdf->Ln(5);}
    $pdf->SetFont('Arial','I',9); $pdf->SetTextColor(100,100,100);
    if($tax_type==='kleinunternehmer') $pdf->MultiCell(0,5,q_safe('Gemäß § 19 UStG (Kleinunternehmerregelung) wird keine Umsatzsteuer ausgewiesen.'));
    $upload_dir=__DIR__.'/uploads/invoices/'; if(!is_dir($upload_dir))mkdir($upload_dir,0755,true);
    $safe_num=preg_replace('/[^a-zA-Z0-9_-]/','', $inv_num);
    $rel_path='uploads/invoices/Rechnung_'.$safe_num.'.pdf';
    $pdf->Output(__DIR__.'/'.$rel_path,'F');
    return $rel_path;
}

// ==========================================
// CSV EXPORT LOGIK
// ==========================================
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=Finanzen_Export_'.date('Y-m-d').'.csv');
    $output = fopen('php://output', 'w');
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF)); // UTF-8 BOM für Excel Kompatibilität
    fputcsv($output, ['Datum', 'Typ', 'Titel', 'Betrag (EUR)', 'Status', 'Kunde/Empfaenger', 'Notiz', 'Fixkosten'], ';');
    
    $stmt = $pdo->query("SELECT f.*, c.name as contact_name FROM finances f LEFT JOIN contacts c ON f.contact_id = c.id WHERE f.deleted_at IS NULL ORDER BY record_date DESC");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        fputcsv($output, [
            $row['record_date'], 
            $row['type'], 
            $row['title'], 
            str_replace('.', ',', $row['amount']), 
            $row['status'], 
            $row['contact_name'] ?: $row['custom_name'], 
            $row['notes'],
            $row['is_recurring'] ? t('Ja') : t('Nein')
        ], ';');
    }
    fclose($output); exit();
}

// ==========================================
// AKTIONEN (MIT LOGGING)
// ==========================================
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['action'])) {
    csrf_check();
    $action = $_POST['action'];

    if ($action === 'save_record') {
        $id = !empty($_POST['record_id']) ? (int)$_POST['record_id'] : null;
        $type = $_POST['type']; 
        $title = trim($_POST['title']);
        $amount = str_replace(',', '.', $_POST['amount']); 
        $status = $_POST['status'];
        $record_date = $_POST['record_date'];
        $due_date = !empty($_POST['due_date']) ? $_POST['due_date'] : null;
        $contact_id = !empty($_POST['contact_id']) ? $_POST['contact_id'] : null;
        $custom_name = trim($_POST['custom_name']);
        $notes = trim($_POST['notes']);
        $is_recurring = isset($_POST['is_recurring']) ? 1 : 0;

        if ($id) {
            $pdo->prepare("UPDATE finances SET type=?, title=?, contact_id=?, custom_name=?, amount=?, status=?, record_date=?, due_date=?, notes=?, is_recurring=? WHERE id=?")
                ->execute([$type, $title, $contact_id, $custom_name, $amount, $status, $record_date, $due_date, $notes, $is_recurring, $id]);
            
            log_event($pdo, 'FINANCE_UPDATED', "Finanzeintrag '$title' (ID: $id) wurde bearbeitet.");
        } else {
            $pdo->prepare("INSERT INTO finances (type, title, contact_id, custom_name, amount, status, record_date, due_date, notes, is_recurring) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")
                ->execute([$type, $title, $contact_id, $custom_name, $amount, $status, $record_date, $due_date, $notes, $is_recurring]);
            
            $typ_name = ($type === 'INCOME') ? 'Einnahme' : 'Ausgabe';
            log_event($pdo, 'FINANCE_ADDED', "Neue $typ_name angelegt: '$title' über $amount €.");
        }
        filter_redirect('finances');
    }
    
    if ($action === 'update_status') {
        $id = (int)$_POST['record_id'];
        $new_status = $_POST['status'];
        
        $stmt = $pdo->prepare("SELECT title FROM finances WHERE deleted_at IS NULL AND id = ?");
        $stmt->execute([$id]);
        $fin_title = $stmt->fetchColumn();

        $pdo->prepare("UPDATE finances SET status = ? WHERE id = ?")->execute([$new_status, $id]);
        
        log_event($pdo, 'FINANCE_UPDATED', "Status von '$fin_title' auf '$new_status' geändert.");
            
        echo "OK"; exit();
    }

    if ($action === 'delete_record') {
        $id = (int)$_POST['record_id'];

        $stmt = $pdo->prepare("SELECT title, invoice_pdf_path FROM finances WHERE deleted_at IS NULL AND id = ?");
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row['invoice_pdf_path'] && file_exists(__DIR__ . '/' . $row['invoice_pdf_path'])) {
            @unlink(__DIR__ . '/' . $row['invoice_pdf_path']);
        }
        // Papierkorb statt Sofortloeschung: der Datensatz verschwindet aus
        // allen Ansichten, bleibt aber 30 Tage wiederherstellbar.
        $pdo->prepare("UPDATE finances SET deleted_at = NOW() WHERE id = ? AND deleted_at IS NULL")->execute([$id]);

        log_event($pdo, 'FINANCE_DELETED', "Finanzeintrag '{$row['title']}' (ID: $id) wurde dauerhaft gelöscht.");

        filter_redirect('finances');
    }

    // ── QUOTES ──────────────────────────────────────────────────────
    if ($action === 'create_quote') {
        $quote_number = next_quote_number($pdo);
        $contact_id  = !empty($_POST['contact_id']) ? (int)$_POST['contact_id'] : null;
        $custom_name = trim($_POST['custom_name'] ?? '');
        $tax_type    = in_array($_POST['tax_type']??'', ['kleinunternehmer','regel'])?$_POST['tax_type']:'kleinunternehmer';
        $valid_until = !empty($_POST['valid_until']) ? $_POST['valid_until'] : null;
        $notes       = trim($_POST['notes'] ?? '');
        $subject     = trim($_POST['subject'] ?? '');
        $intro_text  = trim($_POST['intro_text'] ?? '');
        $items       = build_items(); $total = calc_total($items, $tax_type);
        $pdo->prepare("INSERT INTO quotes (quote_number,subject,intro_text,contact_id,custom_name,status,tax_type,items,notes,total_amount,valid_until) VALUES (?,?,?,?,?,'Entwurf',?,?,?,?,?)")
            ->execute([$quote_number,$subject,$intro_text,$contact_id,$custom_name,$tax_type,json_encode($items),$notes,$total,$valid_until]);
        log_event($pdo, 'QUOTE_CREATED', "Angebot $quote_number erstellt.");
        filter_redirect('finances', ['tab' => 'quotes']);
    }
    if ($action === 'edit_quote') {
        $id          = (int)$_POST['quote_id'];
        $contact_id  = !empty($_POST['contact_id']) ? (int)$_POST['contact_id'] : null;
        $custom_name = trim($_POST['custom_name'] ?? '');
        $status      = trim($_POST['status'] ?? 'Entwurf');
        $tax_type    = in_array($_POST['tax_type']??'', ['kleinunternehmer','regel'])?$_POST['tax_type']:'kleinunternehmer';
        $valid_until = !empty($_POST['valid_until']) ? $_POST['valid_until'] : null;
        $notes       = trim($_POST['notes'] ?? '');
        $subject     = trim($_POST['subject'] ?? '');
        $intro_text  = trim($_POST['intro_text'] ?? '');
        $items       = build_items(); $total = calc_total($items, $tax_type);
        $pdo->prepare("UPDATE quotes SET subject=?,intro_text=?,contact_id=?,custom_name=?,status=?,tax_type=?,items=?,notes=?,total_amount=?,valid_until=? WHERE id=?")
            ->execute([$subject,$intro_text,$contact_id,$custom_name,$status,$tax_type,json_encode($items),$notes,$total,$valid_until,$id]);
        log_event($pdo, 'QUOTE_EDITED', "Angebot #$id aktualisiert.");
        filter_redirect('finances', ['tab' => 'quotes']);
    }
    if ($action === 'delete_quote') {
        $id = (int)$_POST['quote_id'];
        $row2 = $pdo->prepare("SELECT quote_number,quote_pdf_path FROM quotes WHERE deleted_at IS NULL AND id=?"); $row2->execute([$id]); $q2 = $row2->fetch(PDO::FETCH_ASSOC);
        if ($q2 && $q2['quote_pdf_path'] && file_exists($q2['quote_pdf_path'])) @unlink($q2['quote_pdf_path']);
        // Papierkorb statt Sofortloeschung: der Datensatz verschwindet aus
        // allen Ansichten, bleibt aber 30 Tage wiederherstellbar.
        $pdo->prepare("UPDATE quotes SET deleted_at = NOW() WHERE id = ? AND deleted_at IS NULL")->execute([$id]);
        log_event($pdo, 'QUOTE_DELETED', "Angebot {$q2['quote_number']} gelöscht.");
        filter_redirect('finances', ['tab' => 'quotes']);
    }
    if ($action === 'generate_pdf') {
        $id   = (int)$_POST['quote_id'];
        $stmt2 = $pdo->prepare("SELECT q.*,c.name AS c_name,c.company AS c_company,c.street AS c_street,c.zip AS c_zip,c.city AS c_city,c.country AS c_country FROM quotes q LEFT JOIN contacts c ON q.contact_id=c.id WHERE q.deleted_at IS NULL AND q.id=?");
        $stmt2->execute([$id]); $q2 = $stmt2->fetch(PDO::FETCH_ASSOC);
        if (!$q2) { filter_redirect('finances', ['tab' => 'quotes']); }
        $rel_path = build_quote_pdf($pdo, $q2);
        $pdo->prepare("UPDATE quotes SET status='Gesendet' WHERE id=? AND status='Entwurf'")->execute([$id]);
        log_event($pdo, 'QUOTE_PDF', "PDF für Angebot {$q2['quote_number']} generiert.");
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="'.basename($rel_path).'"');
        readfile(__DIR__.'/'.$rel_path); exit();
    }
    if ($action === 'send_quote_email') {
        global $_pm_available;
        $id       = (int)$_POST['quote_id'];
        $to_email = trim($_POST['to_email'] ?? '');
        $subj2    = trim($_POST['email_subject'] ?? '');
        $body2    = trim($_POST['email_body'] ?? '');
        if (!filter_var($to_email, FILTER_VALIDATE_EMAIL)) { filter_redirect('finances', ['tab' => 'quotes', 'error' => 'invalid_email']); }
        $stmt2 = $pdo->prepare("SELECT q.*,c.name AS c_name,c.company AS c_company,c.street AS c_street,c.zip AS c_zip,c.city AS c_city,c.country AS c_country,c.email AS c_email FROM quotes q LEFT JOIN contacts c ON q.contact_id=c.id WHERE q.deleted_at IS NULL AND q.id=?");
        $stmt2->execute([$id]); $q2 = $stmt2->fetch(PDO::FETCH_ASSOC);
        if (!$q2) { filter_redirect('finances', ['tab' => 'quotes']); }
        if (empty($q2['quote_pdf_path']) || !file_exists(__DIR__.'/'.$q2['quote_pdf_path'])) { $q2['quote_pdf_path'] = build_quote_pdf($pdo, $q2); }
        if (!$_pm_available) { filter_redirect('finances', ['tab' => 'quotes', 'error' => 'no_phpmailer']); }
        try {
            $mail = new PHPMailer(true); $mail->isSMTP(); $mail->Host = SMTP_HOST; $mail->SMTPAuth = true;
            $mail->Username = SMTP_USER; $mail->Password = SMTP_PASS;
            $mail->SMTPSecure = (SMTP_PORT==587)?'tls':'ssl'; $mail->Port = SMTP_PORT;
            $mail->CharSet = 'UTF-8'; $mail->setFrom(ADMIN_EMAIL, COMPANY_NAME); $mail->addAddress($to_email);
            $mail->Subject = $subj2; $mail->isHTML(false); $mail->Body = $body2;
            if (!empty($q2['quote_pdf_path']) && file_exists(__DIR__.'/'.$q2['quote_pdf_path']))
                $mail->addAttachment(__DIR__.'/'.$q2['quote_pdf_path'], 'Angebot_'.$q2['quote_number'].'.pdf');
            $mail->send();
            if ($q2['status']==='Entwurf') $pdo->prepare("UPDATE quotes SET status='Gesendet' WHERE id=?")->execute([$id]);
            log_event($pdo, 'QUOTE_EMAIL_SENT', "Angebot {$q2['quote_number']} per E-Mail an $to_email gesendet.");
            filter_redirect('finances', ['tab' => 'quotes', 'msg' => 'quote_email_sent']);
        } catch (PHPMailerException $e) { filter_redirect('finances', ['tab' => 'quotes', 'error' => 'email_failed', 'detail' => $e->getMessage()]); }
    }
    if ($action === 'convert_to_invoice') {
        $id   = (int)$_POST['quote_id'];
        $stmt2 = $pdo->prepare("SELECT q.*,c.name AS c_name,c.company AS c_company,c.street AS c_street,c.zip AS c_zip,c.city AS c_city,c.country AS c_country FROM quotes q LEFT JOIN contacts c ON q.contact_id=c.id WHERE q.deleted_at IS NULL AND q.id=?");
        $stmt2->execute([$id]); $q2 = $stmt2->fetch(PDO::FETCH_ASSOC);
        if (!$q2) { filter_redirect('finances', ['tab' => 'quotes']); }
        $inv_num2 = next_invoice_number($pdo);
        $inv_pdf2 = build_invoice_pdf_from_quote($q2, $inv_num2);
        $client_name2 = $q2['custom_name'] ?: ($q2['c_name'] ?? 'Unbekannt');
        $pdo->prepare("INSERT INTO finances (type,title,invoice_number,contact_id,custom_name,amount,status,record_date,due_date,notes,invoice_pdf_path,is_recurring) VALUES ('INCOME',?,?,?,?,?,'Offen',CURDATE(),DATE_ADD(CURDATE(),INTERVAL 14 DAY),?,?,0)")
            ->execute([$inv_num2,$inv_num2,$q2['contact_id'],$client_name2,$q2['total_amount'],$q2['notes'],$inv_pdf2]);
        $pdo->prepare("UPDATE quotes SET status='Angenommen' WHERE id=?")->execute([$id]);
        log_event($pdo, 'QUOTE_CONVERTED', "Angebot {$q2['quote_number']} zu Rechnung $inv_num2 konvertiert.");
        filter_redirect('finances', ['msg' => 'invoice_created']);
    }
    // ── END QUOTES ──────────────────────────────────────────────────

    if ($action === 'send_invoice_email') {
        $id       = (int)$_POST['record_id'];
        $to_email = trim($_POST['to_email'] ?? '');
        $subject  = trim($_POST['email_subject'] ?? '');
        $body     = trim($_POST['email_body'] ?? '');

        if (!filter_var($to_email, FILTER_VALIDATE_EMAIL)) {
            filter_redirect('finances', ['error' => 'invalid_email']);
        }
        if (!$_pm_available) {
            filter_redirect('finances', ['error' => 'no_phpmailer']);
        }

        $stmt = $pdo->prepare("SELECT f.*, c.email AS c_email FROM finances f LEFT JOIN contacts c ON f.contact_id = c.id WHERE f.deleted_at IS NULL AND f.id=?");
        $stmt->execute([$id]);
        $rec = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$rec || empty($rec['invoice_pdf_path'])) {
            filter_redirect('finances', ['error' => 'no_pdf']);
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
            $pdf_path = __DIR__ . '/' . $rec['invoice_pdf_path'];
            if (file_exists($pdf_path)) {
                $mail->addAttachment($pdf_path, basename($pdf_path));
            }
            $mail->send();
            log_event($pdo, 'INVOICE_EMAIL_SENT', "Rechnung {$rec['title']} per E-Mail an $to_email gesendet.");
            filter_redirect('finances', ['msg' => 'email_sent']);
        } catch (PHPMailerException $e) {
            filter_redirect('finances', ['error' => 'email_failed', 'detail' => $e->getMessage()]);
        }
    }
}

// Überfällige Rechnungen automatisch markieren
$pdo->query("UPDATE finances SET status = 'Überfällig' WHERE type = 'INCOME' AND status = 'Offen' AND due_date < CURDATE()");

// ==========================================
// KPI & DIAGRAMM DATEN BERECHNEN
// ==========================================
$german_months = ['01'=>'Januar','02'=>'Februar','03'=>'März','04'=>'April','05'=>'Mai','06'=>'Juni','07'=>'Juli','08'=>'August','09'=>'September','10'=>'Oktober','11'=>'November','12'=>'Dezember'];

$period = $_GET['period'] ?? 'month'; 
// Die Bedingung des Papierkorbs steht hier, nicht in der Abfrage: dort
// wuerde sie vor diesem Ausdruck landen und ein zweites WHERE erzeugen.
$kpi_where = " WHERE deleted_at IS NULL AND 1=1";
if ($period === 'year') $kpi_where .= " AND YEAR(record_date) = YEAR(CURDATE())";
if ($period === 'month') $kpi_where .= " AND MONTH(record_date) = MONTH(CURDATE()) AND YEAR(record_date) = YEAR(CURDATE())";

$kpi_data = $pdo->query("SELECT type, status, amount FROM finances$kpi_where")->fetchAll(PDO::FETCH_ASSOC);
$sum_income = 0; $sum_expense = 0; $sum_open = 0;
foreach ($kpi_data as $k) {
    if ($k['type'] === 'INCOME') {
        if ($k['status'] === 'Bezahlt') $sum_income += $k['amount'];
        if ($k['status'] === 'Offen' || $k['status'] === 'Überfällig') $sum_open += $k['amount'];
    } else { $sum_expense += $k['amount']; }
}
$profit = $sum_income - $sum_expense;

$chart_labels = []; $chart_inc = []; $chart_exp = []; $chart_title = '';

if ($period === 'month') {
    // Daily view: current month
    $cur_year  = (int)date('Y');
    $cur_month = (int)date('m');
    $days_in_month = (int)date('t');
    $ym = date('Y-m');
    $chart_title = $german_months[date('m')] . ' ' . $cur_year . ' (täglich)';

    $inc_rows = $pdo->prepare("SELECT DAY(record_date) AS d, SUM(amount) AS total FROM finances WHERE deleted_at IS NULL AND type='INCOME' AND status='Bezahlt' AND DATE_FORMAT(record_date,'%Y-%m')=? GROUP BY DAY(record_date)");
    $inc_rows->execute([$ym]); $inc_by_day = array_column($inc_rows->fetchAll(PDO::FETCH_ASSOC), 'total', 'd');
    $exp_rows = $pdo->prepare("SELECT DAY(record_date) AS d, SUM(amount) AS total FROM finances WHERE deleted_at IS NULL AND type='EXPENSE' AND DATE_FORMAT(record_date,'%Y-%m')=? GROUP BY DAY(record_date)");
    $exp_rows->execute([$ym]); $exp_by_day = array_column($exp_rows->fetchAll(PDO::FETCH_ASSOC), 'total', 'd');

    for ($d = 1; $d <= $days_in_month; $d++) {
        $chart_labels[] = $d . '.';
        $chart_inc[]    = (float)($inc_by_day[$d] ?? 0);
        $chart_exp[]    = (float)($exp_by_day[$d] ?? 0);
    }

} elseif ($period === 'year') {
    // Monthly view: current year
    $cur_year = (int)date('Y');
    $chart_title = $cur_year . ' (monatlich)';

    $inc_rows = $pdo->prepare("SELECT MONTH(record_date) AS m, SUM(amount) AS total FROM finances WHERE deleted_at IS NULL AND type='INCOME' AND status='Bezahlt' AND YEAR(record_date)=? GROUP BY MONTH(record_date)");
    $inc_rows->execute([$cur_year]); $inc_by_month = array_column($inc_rows->fetchAll(PDO::FETCH_ASSOC), 'total', 'm');
    $exp_rows = $pdo->prepare("SELECT MONTH(record_date) AS m, SUM(amount) AS total FROM finances WHERE deleted_at IS NULL AND type='EXPENSE' AND YEAR(record_date)=? GROUP BY MONTH(record_date)");
    $exp_rows->execute([$cur_year]); $exp_by_month = array_column($exp_rows->fetchAll(PDO::FETCH_ASSOC), 'total', 'm');

    for ($mo = 1; $mo <= 12; $mo++) {
        $chart_labels[] = $german_months[sprintf('%02d', $mo)];
        $chart_inc[]    = (float)($inc_by_month[$mo] ?? 0);
        $chart_exp[]    = (float)($exp_by_month[$mo] ?? 0);
    }

} else {
    // Yearly view: all years with data
    $chart_title = 'Gesamtübersicht (jährlich)';

    $inc_rows = $pdo->query("SELECT YEAR(record_date) AS y, SUM(amount) AS total FROM finances WHERE deleted_at IS NULL AND type='INCOME' AND status='Bezahlt' GROUP BY YEAR(record_date)");
    $inc_by_year = array_column($inc_rows->fetchAll(PDO::FETCH_ASSOC), 'total', 'y');
    $exp_rows = $pdo->query("SELECT YEAR(record_date) AS y, SUM(amount) AS total FROM finances WHERE deleted_at IS NULL AND type='EXPENSE' GROUP BY YEAR(record_date)");
    $exp_by_year = array_column($exp_rows->fetchAll(PDO::FETCH_ASSOC), 'total', 'y');

    $years = array_unique(array_merge(array_keys($inc_by_year), array_keys($exp_by_year)));
    if (empty($years)) $years = [(int)date('Y')];
    sort($years);

    foreach ($years as $yr) {
        $chart_labels[] = (string)$yr;
        $chart_inc[]    = (float)($inc_by_year[$yr] ?? 0);
        $chart_exp[]    = (float)($exp_by_year[$yr] ?? 0);
    }
}

// Nächste Rechnungsnummer serverseitig generieren (RE-YYYY-NNN)
$current_year    = date('Y');
$next_inv_number = next_invoice_number($pdo);

// ==========================================
// FILTER & LISTEN DATEN LADEN
// ==========================================
$filter_type = $_GET['type'] ?? 'all';
$filter_status = $_GET['status'] ?? 'all';
$filter_month = $_GET['month'] ?? date('Y-m'); 
$filter_recurring = isset($_GET['only_recurring']) ? 1 : 0;
$search_query = trim($_GET['search'] ?? ''); 

$sql = "SELECT f.*, c.name AS contact_name, c.email AS contact_email FROM finances f LEFT JOIN contacts c ON f.contact_id = c.id WHERE f.deleted_at IS NULL AND 1=1";
$params = [];

if ($filter_type !== 'all') { $sql .= " AND f.type = ?"; $params[] = $filter_type; }
if ($filter_status !== 'all') { $sql .= " AND f.status = ?"; $params[] = $filter_status; }
if ($filter_recurring) { $sql .= " AND f.is_recurring = 1"; }
if ($filter_month !== 'all' && $filter_month !== 'year' && $filter_month !== 'all_time') { 
    $sql .= " AND DATE_FORMAT(f.record_date, '%Y-%m') = ?"; $params[] = $filter_month; 
} elseif ($filter_month === 'year') { $sql .= " AND YEAR(f.record_date) = YEAR(CURDATE())"; }

if (!empty($search_query)) {
    $sql .= " AND (f.title LIKE ? OR c.name LIKE ? OR f.custom_name LIKE ? OR f.notes LIKE ?)";
    $term = "%$search_query%";
    array_push($params, $term, $term, $term, $term);
}

$sql .= " ORDER BY f.record_date DESC, f.id DESC";
$stmt = $pdo->prepare($sql); $stmt->execute($params); $records = $stmt->fetchAll(PDO::FETCH_ASSOC);
$all_contacts = $pdo->query("SELECT * FROM contacts WHERE deleted_at IS NULL ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
$available_months = $pdo->query("SELECT DISTINCT DATE_FORMAT(record_date, '%Y-%m') as ym FROM finances WHERE deleted_at IS NULL ORDER BY ym DESC")->fetchAll(PDO::FETCH_COLUMN);

$filtered_income = 0; $filtered_expense = 0;
foreach ($records as $r) {
    if ($r['type'] === 'INCOME') $filtered_income += $r['amount'];
    else $filtered_expense += $r['amount'];
}

// ==========================================
// AKTIVER TAB & QUOTES DATEN
// ==========================================
$active_tab = $_GET['tab'] ?? 'finances';

$contacts_q     = $pdo->query("SELECT id, name, company FROM contacts WHERE deleted_at IS NULL ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
$filter_status_q = $_GET['qstatus'] ?? 'all';
$sql_q = "SELECT q.*, c.name AS contact_name, c.email AS contact_email FROM quotes q LEFT JOIN contacts c ON q.contact_id = c.id WHERE q.deleted_at IS NULL";
$params_q = [];
if ($filter_status_q !== 'all') { $sql_q .= " WHERE q.status = ?"; $params_q[] = $filter_status_q; }
$sql_q .= " ORDER BY q.created_at DESC";
$stmt_q = $pdo->prepare($sql_q); $stmt_q->execute($params_q);
$quotes = $stmt_q->fetchAll(PDO::FETCH_ASSOC);
$kpi_q = $pdo->query("SELECT COUNT(*) AS total, SUM(CASE WHEN status='Entwurf' THEN 1 ELSE 0 END) AS draft, SUM(CASE WHEN status='Gesendet' THEN 1 ELSE 0 END) AS sent, SUM(CASE WHEN status='Angenommen' THEN 1 ELSE 0 END) AS accepted, SUM(CASE WHEN status='Abgelehnt' THEN 1 ELSE 0 END) AS rejected, SUM(CASE WHEN status='Angenommen' THEN total_amount ELSE 0 END) AS revenue FROM quotes WHERE deleted_at IS NULL")->fetch(PDO::FETCH_ASSOC);

$page_title   = $active_tab === 'quotes' ? 'Angebote' : 'Finanzen';
$page_heading = $active_tab === 'quotes' ? 'Angebote' : 'Finanz-Zentrale';
$current_page = basename($_SERVER['PHP_SELF']);
// Vier Buttons im Header (CSV/Rechnung/Ausgabe/Einnahme) brauchen Zeilenumbruch
// auf schmalen Screens - im Original war das die Klasse "top-header flex-wrap".
if ($active_tab === 'quotes') {
    $header_actions = '
      <button class="btn btn-primary btn-sm fw-bold px-3 shadow-sm" data-bs-toggle="modal" data-bs-target="#quoteModal" onclick="prepareNewQuote()">
        <i class="bi bi-plus-lg me-1"></i> Neues Angebot
      </button>';
} else {
    $header_actions = '
      <div class="d-flex gap-2">
          <a href="?export=csv" class="btn btn-outline-secondary btn-sm fw-bold px-3"><i class="bi bi-filetype-csv"></i> <span class="btn-label">CSV</span></a>
          <button class="btn btn-primary btn-sm fw-bold px-3" data-bs-toggle="modal" data-bs-target="#invoiceModal"><i class="bi bi-file-earmark-plus"></i> Rechnung <span class="btn-label-xs">erstellen</span></button>
          <button class="btn btn-outline-danger btn-sm" onclick="openFinanceModal(\'EXPENSE\')"><i class="bi bi-dash-circle"></i> <span class="btn-label">Ausgabe</span></button>
          <button class="btn btn-outline-success btn-sm" onclick="openFinanceModal(\'INCOME\')"><i class="bi bi-plus-circle"></i> <span class="btn-label">Einnahme</span></button>
      </div>';
}
// Chart.js wird nur hier gebraucht, daher hier statt in head.php.
$extra_head = '<script src="' . asset('assets/vendor/chartjs/chart.umd.min.js') . '"></script>';

require 'includes/head.php';
require 'includes/layout_start.php';
?>

  <!-- TOAST NOTIFICATIONS -->
  <div class="position-fixed top-0 end-0 p-3" style="z-index:1090;">
    <?php if(isset($_GET['msg']) && $_GET['msg'] === 'quote_email_sent'): ?>
    <div class="toast show align-items-center text-bg-success border-0 shadow-lg" role="alert" aria-atomic="true">
      <div class="d-flex"><div class="toast-body fw-bold"><i class="bi bi-envelope-check-fill me-2"></i><?= te('Angebot erfolgreich per E-Mail gesendet!') ?></div>
      <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button></div>
    </div>
    <?php endif; ?>
    <?php if(isset($_GET['msg']) && $_GET['msg'] === 'invoice_created'): ?>
    <div class="toast show align-items-center text-bg-success border-0 shadow-lg" role="alert" aria-atomic="true">
      <div class="d-flex"><div class="toast-body fw-bold"><i class="bi bi-check-circle-fill me-2"></i><?= te('Angebot erfolgreich als Rechnung verbucht!') ?></div>
      <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button></div>
    </div>
    <?php endif; ?>
    <?php if(isset($_GET['msg']) && $_GET['msg'] === 'email_sent'): ?>
    <div class="toast show align-items-center text-bg-success border-0 shadow-lg" role="alert" aria-atomic="true">
      <div class="d-flex"><div class="toast-body fw-bold"><i class="bi bi-envelope-check-fill me-2"></i><?= te('Rechnung erfolgreich per E-Mail gesendet!') ?></div>
      <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button></div>
    </div>
    <?php endif; ?>
    <?php if(isset($_GET['error'])): ?>
    <div class="toast show align-items-center text-bg-danger border-0 shadow-lg" role="alert" aria-atomic="true">
      <div class="d-flex"><div class="toast-body fw-bold"><i class="bi bi-exclamation-triangle-fill me-2"></i>
        <?php
          $errs = ['email_failed'=>'E-Mail konnte nicht gesendet werden.','no_phpmailer'=>'PHPMailer nicht installiert.','invalid_email'=>'Ungültige E-Mail-Adresse.','no_pdf'=>'Kein PDF vorhanden – bitte zuerst Rechnung generieren.'];
          $err_key = htmlspecialchars($_GET['error'], ENT_QUOTES);
          echo $errs[$err_key] ?? 'Fehler aufgetreten.';
          if(isset($_GET['detail'])) echo ' '.htmlspecialchars($_GET['detail']);
        ?>
      </div><button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button></div>
    </div>
    <?php endif; ?>
  </div>

    <!-- Tab-Navigation -->
    <ul class="nav nav-tabs mb-4">
      <li class="nav-item">
        <a class="nav-link <?= $active_tab === 'finances' ? 'active fw-bold' : '' ?>" href="finances">
          <i class="bi bi-currency-euro me-1"></i> <?= te('Finanzen') ?>
          <?php if($_sb_open_inv > 0 && $active_tab !== 'finances'): ?><span class="badge bg-warning text-dark ms-1 rounded-pill"><?= $_sb_open_inv ?></span><?php endif; ?>
        </a>
      </li>
      <li class="nav-item">
        <a class="nav-link <?= $active_tab === 'quotes' ? 'active fw-bold' : '' ?>" href="finances?tab=quotes">
          <i class="bi bi-file-earmark-text me-1"></i> <?= te('Angebote') ?>
        </a>
      </li>
    </ul>

    <?php if ($active_tab === 'quotes'): ?>
    <!-- ======================= ANGEBOTE TAB ======================= -->

    <!-- KPI-Karten -->
    <div class="row g-3 mb-4">
      <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm rounded-3 p-3 text-center">
          <div class="fw-bold fs-3 text-primary"><?= $kpi_q['total'] ?></div>
          <div class="text-muted small"><?= te('Gesamt') ?></div>
        </div>
      </div>
      <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm rounded-3 p-3 text-center">
          <div class="fw-bold fs-3 text-success"><?= $kpi_q['accepted'] ?></div>
          <div class="text-muted small"><?= te('Angenommen') ?></div>
        </div>
      </div>
      <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm rounded-3 p-3 text-center">
          <div class="fw-bold fs-3 text-warning"><?= $kpi_q['sent'] ?></div>
          <div class="text-muted small"><?= te('Gesendet / Offen') ?></div>
        </div>
      </div>
      <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm rounded-3 p-3 text-center">
          <div class="fw-bold fs-3" style="color:var(--color-primary);"><?= number_format((float)$kpi_q['revenue'],2,',','.') ?> €</div>
          <div class="text-muted small"><?= te('Angenommenes Volumen') ?></div>
        </div>
      </div>
    </div>

    <!-- Filter -->
    <div class="filter-bar">
      <?php $q_statuses = ['all'=>'Alle','Entwurf'=>'Entwurf','Gesendet'=>'Gesendet','Angenommen'=>'Angenommen','Abgelehnt'=>'Abgelehnt']; ?>
      <?php foreach($q_statuses as $val => $label): ?>
        <a href="finances?tab=quotes&qstatus=<?= $val ?>" class="btn btn-sm <?= $filter_status_q === $val ? 'btn-primary' : 'btn-outline-secondary' ?>"><?= $label ?></a>
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
            <?php foreach($quotes as $q_row):
              $q_items    = json_decode($q_row['items'], true) ?: [];
              $q_badge_map = ['Entwurf'=>'bg-secondary','Gesendet'=>'bg-warning text-dark','Angenommen'=>'bg-success','Abgelehnt'=>'bg-danger'];
              $q_badge    = $q_badge_map[$q_row['status']] ?? 'bg-secondary';
              $q_client   = $q_row['contact_name'] ?: $q_row['custom_name'] ?: '—';
              $q_expired  = $q_row['valid_until'] && strtotime($q_row['valid_until']) < strtotime('today') && $q_row['status'] !== 'Angenommen';
            ?>
            <tr>
              <td class="ps-3 fw-bold text-strong-c"><?= htmlspecialchars($q_row['quote_number']) ?></td>
              <td><?= htmlspecialchars($q_client) ?></td>
              <td class="text-muted small"><?= date('d.m.Y', strtotime($q_row['created_at'])) ?></td>
              <td class="small <?= $q_expired ? 'text-danger fw-bold' : 'text-muted' ?>">
                <?= $q_row['valid_until'] ? date('d.m.Y', strtotime($q_row['valid_until'])) : '—' ?>
                <?= $q_expired ? ' <i class="bi bi-exclamation-triangle-fill"></i>' : '' ?>
              </td>
              <td class="fw-bold"><?= number_format((float)$q_row['total_amount'],2,',','.') ?> €</td>
              <td><span class="badge <?= $q_badge ?>"><?= htmlspecialchars($q_row['status']) ?></span></td>
              <td class="text-end pe-3">
                <div class="d-flex gap-1 justify-content-end">
                  <form method="POST" class="d-inline">
                    <?= csrf_field() ?><input type="hidden" name="action" value="generate_pdf">
                    <input type="hidden" name="quote_id" value="<?= $q_row['id'] ?>">
                    <button type="submit" class="btn btn-sm btn-outline-primary px-2" title="<?= te('PDF generieren') ?>"><i class="bi bi-file-earmark-pdf"></i></button>
                  </form>
                  <button type="button" class="btn btn-sm btn-outline-info px-2" title="<?= te('Per E-Mail senden') ?>"
                          onclick='openQuoteEmailModal(<?= htmlspecialchars(json_encode(["id"=>$q_row["id"],"quote_number"=>$q_row["quote_number"],"total_amount"=>$q_row["total_amount"],"client"=>($q_row["contact_name"]?:$q_row["custom_name"]?:""),"email"=>($q_row["contact_email"]?:""),"notes"=>$q_row["notes"]], JSON_HEX_TAG|JSON_HEX_APOS), ENT_QUOTES) ?>)'>
                    <i class="bi bi-envelope-arrow-up"></i>
                  </button>
                  <button type="button" class="btn btn-sm btn-outline-secondary px-2" title="<?= te('Bearbeiten') ?>"
                          onclick='prepareEditQuote(<?= htmlspecialchars(json_encode($q_row, JSON_HEX_TAG|JSON_HEX_APOS), ENT_QUOTES) ?>)'><i class="bi bi-pencil-square"></i></button>
                  <?php if($q_row['status'] !== 'Angenommen' && $q_row['status'] !== 'Abgelehnt'): ?>
                  <form method="POST" class="d-inline" onsubmit="return confirm('Angebot <?= htmlspecialchars($q_row['quote_number']) ?> in eine Rechnung umwandeln?')">
                    <?= csrf_field() ?><input type="hidden" name="action" value="convert_to_invoice">
                    <input type="hidden" name="quote_id" value="<?= $q_row['id'] ?>">
                    <button type="submit" class="btn btn-sm btn-outline-success px-2" title="<?= te('Zu Rechnung konvertieren') ?>"><i class="bi bi-arrow-right-circle"></i></button>
                  </form>
                  <?php endif; ?>
                  <form method="POST" class="d-inline" id="del_q_<?= $q_row['id'] ?>">
                    <?= csrf_field() ?><input type="hidden" name="action" value="delete_quote">
                    <input type="hidden" name="quote_id" value="<?= $q_row['id'] ?>">
                    <button type="button" class="btn btn-sm btn-outline-danger px-2" title="<?= te('Löschen') ?>"
                            data-confirmed="0" onclick="confirmDeleteQuote(this, <?= $q_row['id'] ?>)"><i class="bi bi-trash3"></i></button>
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

    <?php else: ?>
    <!-- ======================= FINANZEN TAB ======================= -->

    <div class="d-flex justify-content-between align-items-center mb-3">
        <h6 class="fw-bold m-0 text-muted small text-uppercase"><?= te('Statistiken') ?></h6>
        <div class="btn-group shadow-sm">
            <a href="?period=month&month=<?= $filter_month ?>&type=<?= $filter_type ?>&status=<?= $filter_status ?>" class="btn btn-sm btn-white border <?= $period=='month'?'active bg-primary text-white':'' ?>"><?= te('Diesen Monat') ?></a>
            <a href="?period=year&month=<?= $filter_month ?>&type=<?= $filter_type ?>&status=<?= $filter_status ?>" class="btn btn-sm btn-white border <?= $period=='year'?'active bg-primary text-white':'' ?>"><?= te('Dieses Jahr') ?></a>
            <a href="?period=all&month=<?= $filter_month ?>&type=<?= $filter_type ?>&status=<?= $filter_status ?>" class="btn btn-sm btn-white border <?= $period=='all'?'active bg-primary text-white':'' ?>"><?= te('Gesamt') ?></a>
        </div>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-md-3">
            <div class="kpi-card kpi-income">
                <div class="kpi-icon bg-success bg-opacity-10 text-success"><i class="bi bi-arrow-down-left"></i></div>
                <div><h4 class="small text-muted mb-1 text-uppercase" style="font-size:10px;"><?= te('Einnahmen') ?></h4><h3 class="fw-bold mb-0 fs-5"><?= number_format($sum_income, 2, ',', '.') ?> €</h3></div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="kpi-card kpi-expense">
                <div class="kpi-icon bg-danger bg-opacity-10 text-danger"><i class="bi bi-arrow-up-right"></i></div>
                <div><h4 class="small text-muted mb-1 text-uppercase" style="font-size:10px;"><?= te('Ausgaben') ?></h4><h3 class="fw-bold mb-0 fs-5"><?= number_format($sum_expense, 2, ',', '.') ?> €</h3></div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="kpi-card kpi-profit">
                <div class="kpi-icon bg-primary bg-opacity-10 text-primary" style="background-color: var(--color-primary) !important; color: white !important;"><i class="bi bi-piggy-bank"></i></div>
                <div><h4 class="small text-muted mb-1 text-uppercase" style="font-size:10px;"><?= te('Saldo') ?></h4><h3 class="fw-bold mb-0 fs-5 <?= $profit >= 0 ? 'text-success' : 'text-danger' ?>"><?= number_format($profit, 2, ',', '.') ?> €</h3></div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="kpi-card kpi-open">
                <div class="kpi-icon bg-warning bg-opacity-10 text-warning"><i class="bi bi-hourglass-split"></i></div>
                <div><h4 class="small text-muted mb-1 text-uppercase" style="font-size:10px;"><?= te('Offene Forderung') ?></h4><h3 class="fw-bold mb-0 fs-5"><?= number_format($sum_open, 2, ',', '.') ?> €</h3></div>
            </div>
        </div>
    </div>

    <div class="bg-surface rounded shadow-sm p-4 mb-4">
        <h6 class="fw-bold mb-3 text-muted small text-uppercase"><?= te('Einnahmen vs. Ausgaben —') ?> <?= htmlspecialchars($chart_title) ?></h6>
        <canvas id="financeChart" style="max-height: 250px;"></canvas>
    </div>

    <form class="filter-bar">
        <input type="hidden" name="period" value="<?= $period ?>">
        
        <div class="input-group input-group-sm search-box">
            <span class="input-group-text bg-surface border-end-0 text-muted"><i class="bi bi-search"></i></span>
            <input type="text" name="search" class="form-control border-start-0 ps-0" placeholder="<?= te('Suchen...') ?>" value="<?=htmlspecialchars($search_query)?>">
        </div>

        <select name="month" class="form-select form-select-sm w-auto" onchange="this.form.submit()">
            <option value="all_time" <?= $filter_month === 'all_time' ? 'selected' : '' ?>><?= te('Zeitraum: Gesamt') ?></option>
            <option value="year" <?= $filter_month === 'year' ? 'selected' : '' ?>><?= te('Zeitraum: Aktuelles Jahr') ?></option>
            <option disabled>──────────</option>
            <?php foreach($available_months as $ym): ?>
                <option value="<?=$ym?>" <?=$filter_month==$ym?'selected':''?>><?=$german_months[substr($ym,5,2)]?> <?=substr($ym,0,4)?></option>
            <?php endforeach; ?>
        </select>
        <select name="type" class="form-select form-select-sm w-auto" onchange="this.form.submit()">
            <option value="all"><?= te('Alle Typen') ?></option>
            <option value="INCOME" <?=$filter_type=='INCOME'?'selected':''?>><?= te('Einnahmen') ?></option>
            <option value="EXPENSE" <?=$filter_type=='EXPENSE'?'selected':''?>><?= te('Ausgaben') ?></option>
        </select>
        <select name="status" class="form-select form-select-sm w-auto" onchange="this.form.submit()">
            <option value="all"><?= te('Alle Status') ?></option>
            <option value="Offen" <?=$filter_status=='Offen'?'selected':''?>><?= te('Offen') ?></option>
            <option value="Bezahlt" <?=$filter_status=='Bezahlt'?'selected':''?>><?= te('Bezahlt') ?></option>
            <option value="Überfällig" <?=$filter_status=='Überfällig'?'selected':''?>><?= te('Überfällig') ?></option>
        </select>
        <div class="form-check ms-2 me-2">
            <input type="checkbox" name="only_recurring" class="form-check-input" id="checkFix" onchange="this.form.submit()" <?=$filter_recurring?'checked':''?>>
            <label class="form-check-label small fw-bold" for="checkFix" style="padding-top:2px;"><?= te('Fixkosten') ?></label>
        </div>
        <button type="submit" class="btn btn-primary btn-sm ms-auto" style="display: none;"><?= te('Suchen') ?></button> <a href="finances" class="btn btn-outline-secondary btn-sm ms-auto"><i class="bi bi-x-circle"></i> Reset</a>
    </form>

    <div class="bg-surface rounded shadow-sm overflow-hidden p-0 mb-4">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="bg-subtle text-uppercase small fw-bold">
                    <tr><th style="width: 50px;"></th><th><?= te('Datum') ?></th><th><?= te('Bezeichnung') ?></th><th><?= te('Kunde') ?></th><th>Status</th><th class="text-end"><?= te('Betrag') ?></th><th class="text-center"><?= te('Aktionen') ?></th></tr>
                </thead>
                <tbody>
                    <?php if(empty($records)): ?>
                        <tr><td colspan="7" class="text-center py-5 text-muted"><i class="bi bi-inbox fs-1 d-block mb-2"></i><?= te('Keine Einträge für diese Filter gefunden.') ?></td></tr>
                    <?php endif; ?>
                    <?php foreach($records as $row): 
                        $is_income = $row['type'] === 'INCOME';
                        $status_class = 'status-' . strtolower(str_replace('ü','ue',$row['status']));
                        $display_name = $row['contact_name'] ?: ($row['custom_name'] ?: '-');
                        $safe_json = htmlspecialchars(json_encode($row, JSON_HEX_TAG|JSON_HEX_APOS), ENT_QUOTES, 'UTF-8');
                    ?>
                        <tr>
                            <td class="text-center"><?= $is_income ? '<i class="bi bi-arrow-down-left-circle-fill text-success fs-5"></i>' : '<i class="bi bi-arrow-up-right-circle-fill text-danger fs-5"></i>' ?></td>
                            <td><span class="small"><?=date('d.m.Y', strtotime($row['record_date']))?></span></td>
                            <td>
                                <span class="fw-bold text-strong-c"><?=htmlspecialchars($row['title'])?></span>
                                <?php if($row['is_recurring']): ?> <span class="badge bg-info ms-1" style="font-size:9px;"><i class="bi bi-arrow-repeat"></i> <?= te('Fix') ?></span> <?php endif; ?>
                                <?php if($row['notes']): ?> <i class="bi bi-chat-text text-muted ms-1" title="<?=htmlspecialchars($row['notes'])?>"></i> <?php endif; ?>
                            </td>
                            <td><span class="small"><?=$display_name?></span></td>
                            <td>
                                <div class="dropdown">
                                  <span class="status-badge <?= $status_class ?> dropdown-toggle" role="button" data-bs-toggle="dropdown"><?= $row['status'] ?></span>
                                  <ul class="dropdown-menu shadow-sm">
                                    <li><a class="dropdown-item py-1 small" href="#" onclick="quickStatus(<?=$row['id']?>, 'Offen'); return false;"><?= te('Offen') ?></a></li>
                                    <li><a class="dropdown-item py-1 small text-success" href="#" onclick="quickStatus(<?=$row['id']?>, 'Bezahlt'); return false;"><?= te('Bezahlt') ?></a></li>
                                    <li><a class="dropdown-item py-1 small text-muted" href="#" onclick="quickStatus(<?=$row['id']?>, 'Storniert'); return false;"><?= te('Storniert') ?></a></li>
                                  </ul>
                                </div>
                            </td>
                            <td class="text-end fw-bold <?=$is_income?'text-success':'text-danger'?>"><?=$is_income?'+':'-'?> <?=number_format($row['amount'],2,',','.')?> €</td>
                            <td class="text-center">
                                <div class="d-flex justify-content-center gap-1">
                                    <?php if(!empty($row['invoice_pdf_path'])): ?>
                                    <a href="file?type=invoice&amp;id=<?=(int)$row['id']?>" target="_blank" class="btn-icon text-primary" title="<?= te('Rechnung ansehen') ?>"><i class="bi bi-file-earmark-pdf-fill"></i></a>
                                    <a href="file?type=invoice&amp;id=<?=(int)$row['id']?>&amp;dl=1" download class="btn-icon text-muted" title="<?= te('Herunterladen') ?>"><i class="bi bi-download"></i></a>
                                    <button class="btn-icon text-info" title="<?= te('Per E-Mail senden') ?>" onclick='openInvEmailModal(<?= $row['id'] ?>, <?= json_encode($row['title'], JSON_HEX_TAG|JSON_HEX_APOS) ?>, <?= json_encode($row['contact_name'] ?: ($row['custom_name'] ?: ''), JSON_HEX_TAG|JSON_HEX_APOS) ?>, <?= json_encode($row['contact_email'] ?? '', JSON_HEX_TAG|JSON_HEX_APOS) ?>, <?= $row['amount'] ?>)'><i class="bi bi-envelope-fill"></i></button>
                                <?php endif; ?>
                                    <button class="btn-icon" onclick='openFinanceModal(<?=$safe_json?>)'><i class="bi bi-pencil-square"></i></button>
                                    <button class="btn-icon text-danger" onclick="triggerDelete(<?=$row['id']?>)"><i class="bi bi-trash3-fill"></i></button>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
                <?php if(!empty($records)): ?>
                <tfoot class="bg-subtle">
                    <tr>
                        <td colspan="5" class="text-end fw-bold small text-muted text-uppercase"><?= te('Summe der gefilterten Liste:') ?></td>
                        <td class="text-end fw-bold">
                            <?php if($filtered_income > 0): ?><span class="text-success d-block">+ <?=number_format($filtered_income,2,',','.')?> €</span><?php endif; ?>
                            <?php if($filtered_expense > 0): ?><span class="text-danger d-block">- <?=number_format($filtered_expense,2,',','.')?> €</span><?php endif; ?>
                        </td>
                        <td></td>
                    </tr>
                </tfoot>
                <?php endif; ?>
            </table>
        </div>
    </div>
    <?php endif; // end tab ?>

  <div class="modal fade" id="invoiceModal" tabindex="-1" data-bs-backdrop="static" data-bs-keyboard="false">
    <div class="modal-dialog modal-xl">
      <div class="modal-content border-0 shadow">
        <form action="invoice" method="POST" target="_blank" onsubmit="setTimeout(()=>location.reload(),2500)">
          <?= csrf_field() ?>
          <input type="hidden" name="contact_id" id="inv_contact_id">
          
          <div class="modal-header bg-dark text-white"><h5><i class="bi bi-file-earmark-pdf me-2"></i> <?= te('Rechnung konfigurieren') ?></h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div>
          <div class="modal-body p-4 bg-subtle">
            <div class="row mb-4">
              <div class="col-md-6">
                <div class="card border-0 shadow-sm p-3 h-100">
                  <h6 class="fw-bold text-primary mb-2"><?= te('Absender (Meine Daten)') ?></h6>
                  <input type="text" name="sender_name" class="form-control form-control-sm mb-1" value="<?= COMPANY_NAME ?>">
                  <input type="text" name="sender_street" class="form-control form-control-sm mb-1" placeholder="<?= te('Straße & Hausnr. (optional)') ?>">
                  <input type="text" name="sender_city" class="form-control form-control-sm mb-1" placeholder="<?= te('PLZ & Ort (optional)') ?>">
                  <input type="text" name="sender_email" class="form-control form-control-sm mb-1" value="<?= ADMIN_EMAIL ?>">
                  <input type="text" name="sender_website" class="form-control form-control-sm mb-1" value="<?= str_replace(['http://', 'https://', 'www.'], '', MAIN_WEBSITE) ?>">
                  <input type="text" name="sender_line1" class="form-control form-control-sm mb-1 border-info-subtle" placeholder="<?= te('Zusatzzeile 1 (z.B. Steuernummer)') ?>">
                  <input type="text" name="sender_line2" class="form-control form-control-sm mb-1 border-info-subtle" placeholder="<?= te('Zusatzzeile 2 (Optional)') ?>">
                </div>
              </div>
              <div class="col-md-6">
                <div class="card border-0 shadow-sm p-3 h-100">
                  <h6 class="fw-bold text-primary mb-2"><?= te('Empfänger (Kunde)') ?></h6>
                  
                  <select class="form-select form-select-sm mb-2" onchange="autoFillInv(this)">
                      <option value=""><?= te('-- Kunde aus CRM laden --') ?></option>
                      <?php foreach($all_contacts as $c): ?>
                          <option value="<?=$c['id']?>" data-name="<?=$c['company']?:$c['name']?>" data-street="<?=$c['street']?>" data-city="<?=$c['zip'].' '.$c['city']?>"><?=$c['name']?></option>
                      <?php endforeach; ?>
                  </select>

                  <input type="text" name="client_name" id="inv_client_name" class="form-control form-control-sm mb-1" placeholder="<?= te('Name/Firma') ?>" required>
                  <input type="text" name="client_street" id="inv_client_street" class="form-control form-control-sm mb-1" placeholder="<?= te('Straße') ?>">
                  <input type="text" name="client_city" id="inv_client_city" class="form-control form-control-sm mb-1" placeholder="<?= te('PLZ & Ort') ?>">
                  <input type="text" name="client_country" value="Deutschland" class="form-control form-control-sm mb-1">
                  <input type="text" name="client_line1" class="form-control form-control-sm mb-1 border-info-subtle" placeholder="<?= te('Zusatzzeile 1 (z.B. Abteilung)') ?>">
                  <input type="text" name="client_line2" class="form-control form-control-sm mb-1 border-info-subtle" placeholder="<?= te('Zusatzzeile 2 (Optional)') ?>">
                </div>
              </div>
            </div>
            <div class="row mb-3">
              <div class="col-md-3"><label class="fw-bold small"><?= te('Rechnungsnummer') ?></label><input type="text" name="invoice_number" id="inv_number" class="form-control fw-bold text-primary" value="<?= htmlspecialchars($next_inv_number) ?>"></div>
              <div class="col-md-3"><label class="fw-bold small"><?= te('Datum') ?></label><input type="date" name="invoice_date" id="inv_date" class="form-control" value="<?=date('Y-m-d')?>" oninput="updateInvDueDate()"></div>
              <div class="col-md-3"><label class="fw-bold small"><?= te('Zahlbar bis') ?></label><input type="date" name="due_date" id="inv_due_date" class="form-control"></div>
              <div class="col-md-3"><label class="fw-bold small"><?= te('MwSt-Regel') ?></label><select name="tax_type" id="inv_tax" class="form-select" onchange="calcInv()"><option value="kleinunternehmer" selected><?= te('Kleinunternehmer (0%)') ?></option><option value="regel"><?= te('Regelbesteuerung (19%)') ?></option></select></div>
            </div>
            <div class="row mb-3">
              <div class="col-md-8"><label class="fw-bold small"><?= te('Betreff') ?> <span class="text-muted fw-normal"><?= te('(erscheint im PDF)') ?></span></label><input type="text" name="subject" class="form-control" placeholder="<?= te('z.B. Webdesign – Projektabschluss') ?>"></div>
              <div class="col-md-4"><label class="fw-bold small"><?= te('Modalität') ?></label><select name="installments" class="form-select"><option value="1"><?= te('Einmalzahlung') ?></option><option value="2"><?= te('2 Raten') ?></option><option value="3"><?= te('3 Raten') ?></option><option value="abo"><?= te('Monatliches Abo') ?></option></select></div>
            </div>
            <div class="row mb-4">
              <div class="col-12"><label class="fw-bold small"><?= te('Einleitungstext') ?> <span class="text-muted fw-normal"><?= te('(optional)') ?></span></label><textarea name="intro_text" class="form-control form-control-sm" rows="2" placeholder="<?= te('z.B. Vielen Dank für Ihr Vertrauen. Bitte überweisen Sie den Betrag bis zum oben genannten Datum.') ?>"></textarea></div>
            </div>
            
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-body">
                    <h6><?= te('Positionen') ?></h6>
                    <div id="invoice-items-container"></div>
                    <button type="button" class="btn btn-sm btn-outline-primary mt-2" onclick="addInvoiceRow('', 1, 60)"><?= te('+ Hinzufügen') ?></button>
                    
                    <div class="mt-4 pt-3 border-top">
                        <div class="row justify-content-end text-end g-2">
                            <div class="col-md-4 col-6 fw-bold"><?= te('Netto:') ?></div><div class="col-md-3 col-6" id="inv_netto">0,00 €</div>
                        </div>
                        <div class="row justify-content-end text-end g-2" id="inv_tax_row" style="display:none;">
                            <div class="col-md-4 col-6 fw-bold"><?= te('MwSt (19%):') ?></div><div class="col-md-3 col-6" id="inv_tax_val">0,00 €</div>
                        </div>
                        <div class="row justify-content-end text-end g-2 mt-1">
                            <div class="col-md-4 col-6 fw-bold text-primary fs-5"><?= te('Brutto:') ?></div><div class="col-md-3 col-6 fw-bold text-primary fs-5"><span id="inv_total">0,00</span> €</div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="row">
                <div class="col-md-6 mb-3"><label class="fw-bold small"><?= te('Bankverbindung (IBAN)') ?></label><input type="text" name="iban" class="form-control form-control-sm" placeholder="<?= te('DE12 3456...') ?>"></div>
                <div class="col-md-6 mb-3"><label class="fw-bold small"><?= te('PayPal / Notiz') ?></label><input type="text" name="paypal" class="form-control form-control-sm mb-2" placeholder="<?= te('PayPal Adresse (Optional)') ?>"><textarea name="notes" class="form-control form-control-sm" placeholder="<?= te('Z.B. Vielen Dank für das Vertrauen!') ?>" rows="2"></textarea></div>
            </div>
          </div>
          <div class="modal-footer"><button type="submit" class="btn btn-primary px-4 fw-bold"><?= te('PDF erstellen & verbuchen') ?></button></div>
        </form>
      </div>
    </div>
  </div>

  <div class="modal fade" id="financeModal" tabindex="-1" data-bs-backdrop="static" data-bs-keyboard="false">
    <div class="modal-dialog modal-lg">
      <div class="modal-content border-0 shadow">
        <div class="modal-header bg-subtle"><h5 class="fw-bold" id="fm_modal_title"><?= te('Eintrag') ?></h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body p-4">
          <form method="POST" id="fm_form">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="save_record"><input type="hidden" name="record_id" id="fm_id"><input type="hidden" name="type" id="fm_type">
            <div class="row g-3">
                <div class="col-md-8"><label class="form-label small fw-bold" id="label_title"><?= te('Bezeichnung *') ?></label><input type="text" name="title" id="fm_label" class="form-control" required></div>
                <div class="col-md-4"><label class="form-label small fw-bold"><?= te('Betrag (€) *') ?></label><input type="text" name="amount" id="fm_amt" class="form-control fw-bold" required></div>
                <div class="col-md-6"><label class="form-label small fw-bold" id="label_contact_man"><?= te('Kunde (CRM)') ?></label><select name="contact_id" id="fm_contact" class="form-select"><option value=""><?= te('-- Ohne Zuordnung --') ?></option><?php foreach($all_contacts as $c): ?><option value="<?=$c['id']?>"><?=$c['name']?></option><?php endforeach; ?></select></div>
                <div class="col-md-6"><label class="form-label small fw-bold"><?= te('Manueller Name') ?></label><input type="text" name="custom_name" id="fm_custom" class="form-control"></div>
                <div class="col-md-4"><label class="form-label small fw-bold"><?= te('Datum *') ?></label><input type="date" name="record_date" id="fm_date" class="form-control" required></div>
                <div class="col-md-4" id="div_due_man"><label class="form-label small fw-bold"><?= te('Fällig am') ?></label><input type="date" name="due_date" id="fm_due" class="form-control"></div>
                <div class="col-md-4"><label class="form-label small fw-bold"><?= te('Status *') ?></label><select name="status" id="fm_stat" class="form-select"><option value="Offen"><?= te('Offen') ?></option><option value="Bezahlt"><?= te('Bezahlt') ?></option><option value="Überfällig"><?= te('Überfällig') ?></option><option value="Storniert"><?= te('Storniert') ?></option></select></div>
                <div class="col-12"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="is_recurring" id="fm_rec"><label class="form-check-label fw-bold"><?= te('Monatliche Fixkosten') ?></label></div></div>
                <div class="col-12"><label class="form-label small fw-bold"><?= te('Notiz') ?></label><textarea name="notes" id="fm_notes" class="form-control" rows="2"></textarea></div>
            </div>
          </form>
        </div>
        <div class="modal-footer bg-subtle"><button type="submit" form="fm_form" class="btn btn-primary px-4 fw-bold"><?= te('Speichern') ?></button></div>
      </div>
    </div>
  </div>

  <div class="modal fade" id="deleteFinanceModal" tabindex="-1">
      <div class="modal-dialog modal-sm modal-dialog-centered">
          <div class="modal-content border-0 shadow">
              <form method="POST">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="delete_record"><input type="hidden" name="record_id" id="del_id">
                  <div class="modal-header bg-danger text-white"><h6 class="modal-title"><?= te('Eintrag löschen?') ?></h6><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div>
                  <div class="modal-body text-center py-4"><p class="mb-0 fw-bold"><?= te('Diesen Eintrag wirklich löschen?') ?></p></div>
                  <div class="modal-footer p-2 d-flex justify-content-between bg-subtle"><button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal"><?= te('Abbrechen') ?></button><button type="submit" class="btn btn-danger btn-sm px-3 fw-bold"><?= te('Ja, löschen') ?></button></div>
              </form>
          </div>
      </div>
  </div>

  <!-- E-Mail Modal -->
  <div class="modal fade" id="invoiceEmailModal" tabindex="-1" data-bs-backdrop="static" data-bs-keyboard="false">
    <div class="modal-dialog modal-lg">
      <div class="modal-content border-0 shadow">
        <form method="POST">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="send_invoice_email">
          <input type="hidden" name="record_id" id="inv_email_record_id">
          <div class="modal-header bg-info text-white"><h5><i class="bi bi-envelope me-2"></i><?= te('Rechnung per E-Mail senden') ?></h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div>
          <div class="modal-body p-4">
            <div class="mb-3"><label class="fw-bold small"><?= te('Empfänger-E-Mail *') ?></label><input type="email" name="to_email" id="inv_email_to" class="form-control" required></div>
            <div class="mb-3"><label class="fw-bold small"><?= te('Betreff *') ?></label><input type="text" name="email_subject" id="inv_email_subject" class="form-control" required></div>
            <div class="mb-3"><label class="fw-bold small"><?= te('Nachricht') ?></label><textarea name="email_body" id="inv_email_body" class="form-control" rows="6"></textarea></div>
          </div>
          <div class="modal-footer bg-subtle"><button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal"><?= te('Abbrechen') ?></button><button type="submit" class="btn btn-info btn-sm px-4 fw-bold text-white"><i class="bi bi-send me-1"></i><?= te('Senden') ?></button></div>
        </form>
      </div>
    </div>
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
                <select name="contact_id" id="q_contact" class="form-select">
                  <option value=""><?= te('— Kein Kontakt ausgewählt —') ?></option>
                  <?php foreach($contacts_q as $c): ?>
                    <option value="<?= $c['id'] ?>" data-name="<?= htmlspecialchars($c['name']) ?>">
                      <?= htmlspecialchars($c['name']) ?><?= $c['company'] ? ' ('.htmlspecialchars($c['company']).')' : '' ?>
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
              <div class="col-md-4" id="q_status_container" style="display:none;">
                <label class="form-label small fw-bold">Status</label>
                <select name="status" id="q_status" class="form-select">
                  <option value="Entwurf"><?= te('Entwurf') ?></option><option value="Gesendet"><?= te('Gesendet') ?></option>
                  <option value="Angenommen"><?= te('Angenommen') ?></option><option value="Abgelehnt"><?= te('Abgelehnt') ?></option>
                </select>
              </div>
              <div class="col-12">
                <label class="form-label small fw-bold"><?= te('Betreff') ?> <span class="text-muted fw-normal"><?= te('(erscheint im PDF)') ?></span></label>
                <input type="text" name="subject" id="q_subject" class="form-control" placeholder="<?= te('z.B. Webseitenentwicklung – Angebot für Ihr Projekt') ?>">
              </div>
              <div class="col-12">
                <label class="form-label small fw-bold"><?= te('Einleitungstext') ?> <span class="text-muted fw-normal"><?= te('(optional)') ?></span></label>
                <textarea name="intro_text" id="q_intro_text" class="form-control form-control-sm" rows="2"></textarea>
              </div>
            </div>
            <div class="bg-surface p-3 rounded shadow-sm border mb-3">
              <label class="form-label small fw-bold d-flex justify-content-between">
                <span><?= te('Positionen') ?></span>
                <button type="button" class="btn btn-sm btn-outline-primary px-2 py-0" onclick="addQuoteItem()"><i class="bi bi-plus-lg"></i> <?= te('Position') ?></button>
              </label>
              <div id="q-items-container"></div>
              <div class="d-flex justify-content-end mt-2">
                <div class="text-end fw-bold fs-5 text-strong-c" id="q-total-display"><?= te('Gesamt: 0,00 €') ?></div>
              </div>
            </div>
            <div class="bg-surface p-3 rounded shadow-sm border mb-3">
              <label class="form-label small fw-bold"><?= te('Notizen / Anmerkungen') ?></label>
              <textarea name="notes" id="q_notes" class="form-control" rows="3"></textarea>
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

  <!-- ANGEBOT E-MAIL MODAL -->
  <div class="modal fade" id="quoteEmailModal" tabindex="-1" data-bs-backdrop="static" data-bs-keyboard="false">
    <div class="modal-dialog modal-lg">
      <div class="modal-content border-0 shadow">
        <div class="modal-header" style="background:var(--color-sidebar);">
          <h5 class="modal-title fw-bold text-white m-0"><i class="bi bi-envelope-arrow-up me-2"></i><?= te('Angebot per E-Mail senden') ?></h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <form method="POST">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="send_quote_email">
          <input type="hidden" name="quote_id" id="qeq_id">
          <div class="modal-body p-4 bg-subtle">
            <div class="bg-surface rounded-3 border p-3 mb-3 shadow-sm">
              <div class="row g-3">
                <div class="col-md-6">
                  <label class="form-label small fw-bold"><?= te('An (E-Mail) *') ?></label>
                  <input type="email" name="to_email" id="qeq_to" class="form-control" required placeholder="<?= te('kunde@example.com') ?>">
                </div>
                <div class="col-md-6">
                  <label class="form-label small fw-bold"><?= te('Betreff *') ?></label>
                  <input type="text" name="email_subject" id="qeq_subject" class="form-control" required>
                </div>
              </div>
            </div>
            <div class="bg-surface rounded-3 border p-3 shadow-sm">
              <label class="form-label small fw-bold"><?= te('Nachricht') ?></label>
              <textarea name="email_body" id="qeq_body" class="form-control" rows="9" style="font-size:13px;resize:none;font-family:inherit;"></textarea>
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

  <datalist id="inv_unit_options">
    <option value="Stk."><option value="Std."><option value="Pauschal"><option value="Monat"><option value="Jahr"><option value="m²"><option value="km">
  </datalist>

  <script>
    // CHART.JS INITIALISIERUNG
    // Das Diagramm zeichnet auf ein <canvas> und kann CSS-Variablen
    // nicht selbst aufloesen. Die Tokenwerte werden deshalb einmal
    // ausgelesen - so stimmen Balken, Gitter und Achsen auch im Dark Mode.
    const cssVar = n => getComputedStyle(document.documentElement).getPropertyValue(n).trim();
    const ctx = document.getElementById('financeChart').getContext('2d');
    new Chart(ctx, {
        type: 'bar',
        data: {
            labels: <?= json_encode($chart_labels) ?>,
            datasets: [
                { label: 'Einnahmen', data: <?= json_encode($chart_inc) ?>, backgroundColor: cssVar('--accent-success'), borderRadius: 4 },
                { label: 'Ausgaben', data: <?= json_encode($chart_exp) ?>, backgroundColor: cssVar('--accent-danger'), borderRadius: 4 }
            ]
        },
        options: { 
            responsive: true, maintainAspectRatio: false, 
            plugins: { legend: { position: 'bottom', labels: { color: cssVar('--text-body') } } },
            scales: {
                y: { beginAtZero: true, grid: { color: cssVar('--border-subtle') }, ticks: { color: cssVar('--text-muted') } },
                x: { grid: { display: false }, ticks: { color: cssVar('--text-muted') } }
            }
        }
    });

    // INVOICE MODAL LOGIK
    function autoFillInv(s) {
        let o = s.options[s.selectedIndex]; 
        if(!o.value) return;
        
        // Füllt die versteckte ID für die Datenbank
        document.getElementById('inv_contact_id').value = o.value;
        
        // Füllt die sichtbaren Felder für das PDF
        document.getElementById('inv_client_name').value = o.dataset.name || '';
        document.getElementById('inv_client_street').value = o.dataset.street || '';
        document.getElementById('inv_client_city').value = o.dataset.city || '';
    }

    function addInvoiceRow(desc = '', qty = 1, price = 60, unit = '') {
      const container = document.getElementById('invoice-items-container');
      const row = document.createElement('div');
      row.className = 'row g-2 mb-2 pb-2 border-bottom inv-item-row';
      row.innerHTML = `<div class="col-md-5"><input type="text" name="item_desc[]" class="form-control form-control-sm" value="${desc}" placeholder=<?= tjs('Leistungsbeschreibung...') ?> required></div><div class="col-md-2"><input type="text" name="item_unit[]" class="form-control form-control-sm" value="${unit}" placeholder="Einheit" list="inv_unit_options"></div><div class="col-md-2"><input type="number" step="0.01" name="item_qty[]" class="form-control form-control-sm inv-qty" value="${qty}" oninput="calcInv()"></div><div class="col-md-2"><input type="number" step="0.01" name="item_price[]" class="form-control form-control-sm inv-price" value="${price}" oninput="calcInv()"></div><div class="col-md-1"><button type="button" class="btn btn-sm btn-outline-danger w-100" onclick='this.parentElement.parentElement.remove(); calcInv();'><i class="bi bi-x-lg"></i></button></div>`;
      container.appendChild(row);
      calcInv();
    }

    function calcInv() {
        let netto = 0; 
        document.querySelectorAll('.inv-item-row').forEach(r => {
            netto += (parseFloat(r.querySelector('.inv-qty').value)||0) * (parseFloat(r.querySelector('.inv-price').value)||0);
        });
        let tax = (document.getElementById('inv_tax').value === 'regel') ? netto * 0.19 : 0;
        document.getElementById('inv_tax_row').style.display = tax > 0 ? 'flex' : 'none';
        document.getElementById('inv_netto').innerText = netto.toLocaleString('de-DE', {minimumFractionDigits:2}) + ' €';
        document.getElementById('inv_tax_val').innerText = tax.toLocaleString('de-DE', {minimumFractionDigits:2}) + ' €';
        document.getElementById('inv_total').innerText = (netto + tax).toLocaleString('de-DE', {minimumFractionDigits:2});
    }

    function updateInvDueDate() {
        const d = document.getElementById('inv_date').value;
        if (!d) return;
        const due = new Date(d);
        due.setDate(due.getDate() + 14);
        document.getElementById('inv_due_date').value = due.toISOString().split('T')[0];
    }

// Vorlagentexte aus den Einstellungen; ersetzt wird mit mailTplFill().
    const MAIL_TPL = <?= mail_templates_json(['invoice_send', 'quote_send']) ?>;
    const MAIL_FIRMA = '<?= htmlspecialchars(setting('company_short', COMPANY_SHORT), ENT_QUOTES) ?>';

    function openInvEmailModal(id, title, client, email, amount) {
        document.getElementById('inv_email_record_id').value = id;
        document.getElementById('inv_email_to').value = email || '';
        const _v = {
            kunde:   client || 'Sie',
            nummer:  title,
            betrag:  parseFloat(amount).toLocaleString('de-DE', {minimumFractionDigits: 2}),
            faellig: document.getElementById('inv_due_date') ? '' : '',
            firma:   MAIL_FIRMA
        };
        document.getElementById('inv_email_subject').value = mailTplFill(MAIL_TPL.invoice_send.subject, _v);
        document.getElementById('inv_email_body').value    = mailTplFill(MAIL_TPL.invoice_send.body, _v);
        new bootstrap.Modal(document.getElementById('invoiceEmailModal')).show();
    }

    // Beim Laden der Seite automatisch eine leere Zeile im Rechnungs-Modal hinzufügen
    document.addEventListener('DOMContentLoaded', () => {
        addInvoiceRow();
        updateInvDueDate();
    });

    // MANUELLES FINANCE MODAL LOGIK
    const fmModal = new bootstrap.Modal(document.getElementById('financeModal'));
    const delModal = new bootstrap.Modal(document.getElementById('deleteFinanceModal'));

    function openFinanceModal(dataOrType) {
        document.getElementById('fm_form').reset();
        document.getElementById('fm_id').value = '';
        if(typeof dataOrType === 'string') {
            document.getElementById('fm_type').value = dataOrType;
            document.getElementById('fm_modal_title').innerHTML = dataOrType=='INCOME' ? '<i class="bi bi-plus-circle text-success me-2"></i> Einnahme' : '<i class="bi bi-dash-circle text-danger me-2"></i> Ausgabe';
            document.getElementById('div_due_man').style.display = (dataOrType=='INCOME' ? 'block' : 'none');
            document.getElementById('fm_date').value = new Date().toISOString().split('T')[0];
        } else {
            document.getElementById('fm_id').value = dataOrType.id;
            document.getElementById('fm_type').value = dataOrType.type;
            document.getElementById('fm_label').value = dataOrType.title;
            document.getElementById('fm_amt').value = dataOrType.amount;
            document.getElementById('fm_contact').value = dataOrType.contact_id || '';
            document.getElementById('fm_custom').value = dataOrType.custom_name || '';
            document.getElementById('fm_date').value = dataOrType.record_date;
            document.getElementById('fm_due').value = dataOrType.due_date || '';
            document.getElementById('fm_stat').value = dataOrType.status;
            document.getElementById('fm_rec').checked = dataOrType.is_recurring == 1;
            document.getElementById('fm_notes').value = dataOrType.notes || '';
            document.getElementById('fm_modal_title').innerHTML = '<i class="bi bi-pencil-square text-primary me-2"></i> Bearbeiten';
            document.getElementById('div_due_man').style.display = (dataOrType.type=='INCOME' ? 'block' : 'none');
        }
        fmModal.show();
    }

    function quickStatus(id, newStatus) {
        const csrf = document.querySelector('input[name="csrf_token"]').value;
        const xhr = new XMLHttpRequest();
        xhr.open('POST', 'finances', true);
        xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
        xhr.onload = () => window.location.reload();
        xhr.send('action=update_status&record_id=' + id + '&status=' + encodeURIComponent(newStatus) + '&csrf_token=' + encodeURIComponent(csrf));
    }

    function triggerDelete(id) { document.getElementById('del_id').value = id; delModal.show(); }

    document.querySelectorAll('.toast').forEach(el => {
        setTimeout(() => bootstrap.Toast.getOrCreateInstance(el).hide(), 5000);
    });

    // ── ANGEBOTE JS ─────────────────────────────────────────────────
    const quoteModal = new bootstrap.Modal(document.getElementById('quoteModal'));
    const quoteEmailModal = new bootstrap.Modal(document.getElementById('quoteEmailModal'));

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
        document.getElementById('q-items-container').innerHTML = '';
        addQuoteItem(); calcQuoteTotal();
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
        document.getElementById('q_subject').value = q.subject || '';
        document.getElementById('q_intro_text').value = q.intro_text || '';
        document.getElementById('q_status').value = q.status || 'Entwurf';
        document.getElementById('q_status_container').style.display = 'block';
        const items = typeof q.items === 'string' ? JSON.parse(q.items) : q.items;
        document.getElementById('q-items-container').innerHTML = '';
        (items || []).forEach(it => addQuoteItem(it.desc, it.qty, it.price, it.unit || ''));
        if (!(items || []).length) addQuoteItem();
        calcQuoteTotal(); quoteModal.show();
    }
    function addQuoteItem(desc='', qty=1, price=0, unit='') {
        const row = document.createElement('div');
        row.className = 'row g-2 mb-2 align-items-center q-item-row';
        row.innerHTML = `<div class="col-md-5"><input type="text" name="item_desc[]" class="form-control form-control-sm" placeholder=<?= tjs('Beschreibung...') ?> value="${qEsc(desc)}" required></div>
            <div class="col-md-2"><input type="text" name="item_unit[]" class="form-control form-control-sm" placeholder="Einheit" value="${qEsc(unit)}" list="q_unit_options"></div>
            <div class="col-md-2"><input type="number" name="item_qty[]" class="form-control form-control-sm q-qty" placeholder="Menge" value="${qty}" min="0.01" step="0.01" oninput="calcQuoteTotal()"></div>
            <div class="col-md-2"><div class="input-group input-group-sm"><input type="text" name="item_price[]" class="form-control q-price" placeholder="Preis" value="${price>0?price.toFixed(2).replace('.',','):''}" oninput="calcQuoteTotal()"><span class="input-group-text">€</span></div></div>
            <div class="col-md-1 text-end"><button type="button" class="btn btn-sm btn-outline-danger px-2 py-1" onclick='this.closest(\'.q-item-row\').remove(); calcQuoteTotal()'><i class="bi bi-trash3"></i></button></div>`;
        document.getElementById('q-items-container').appendChild(row);
    }
    function qEsc(s) { return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }
    function calcQuoteTotal() {
        const tax = document.getElementById('q_tax_type').value;
        let netto = 0;
        document.querySelectorAll('.q-item-row').forEach(r => {
            const qty = parseFloat(r.querySelector('.q-qty')?.value||0);
            const price = parseFloat((r.querySelector('.q-price')?.value||'0').replace(',','.'));
            if (!isNaN(qty)&&!isNaN(price)) netto += qty*price;
        });
        const brutto = tax==='regel' ? netto*1.19 : netto;
        document.getElementById('q-total-display').textContent = <?= tjs('Gesamt: ') ?>+brutto.toLocaleString('de-DE',{minimumFractionDigits:2,maximumFractionDigits:2})+' €';
    }
    document.getElementById('q_tax_type').addEventListener('change', calcQuoteTotal);

    function openQuoteEmailModal(q) {
        document.getElementById('qeq_id').value = q.id;
        document.getElementById('qeq_to').value = q.email || '';
        const _qv = {
            kunde:       q.client || 'Sie',
            nummer:      q.quote_number,
            betrag:      parseFloat(q.total_amount).toLocaleString('de-DE',{minimumFractionDigits:2,maximumFractionDigits:2}),
            anmerkungen: (q.notes || '').trim(),
            firma:       MAIL_FIRMA
        };
        document.getElementById('qeq_subject').value = mailTplFill(MAIL_TPL.quote_send.subject, _qv);
        document.getElementById('qeq_body').value = mailTplFill(MAIL_TPL.quote_send.body, _qv);
        quoteEmailModal.show();
    }
    function validateQuoteForm() {
        const items = document.querySelectorAll('#q-items-container .q-item-row');
        if (!items.length) {
            const btn = document.querySelector('#quoteForm button[type=submit]');
            const orig = btn.innerHTML;
            btn.classList.replace('btn-primary','btn-danger');
            btn.innerHTML = '<i class="bi bi-exclamation-triangle me-1"></i> Mind. 1 Position!';
            setTimeout(()=>{btn.classList.replace('btn-danger','btn-primary');btn.innerHTML=orig;},3000);
            return false;
        }
        const contact = document.getElementById('q_contact').value;
        const custom  = document.getElementById('q_custom_name').value.trim();
        if (!contact && !custom) {
            const el = document.getElementById('q_custom_name');
            el.focus(); el.classList.add('is-invalid');
            setTimeout(()=>el.classList.remove('is-invalid'),3000);
            return false;
        }
        return true;
    }
    function confirmDeleteQuote(btn, id) {
        if (btn.dataset.confirmed==='1') {
            document.getElementById('del_q_'+id).submit();
        } else {
            btn.dataset.confirmed='1'; btn.innerHTML=<?= tjs('Sicher?') ?>;
            btn.classList.replace('btn-outline-danger','btn-danger'); btn.classList.add('text-white');
            setTimeout(()=>{
                if(btn.parentNode){btn.dataset.confirmed='0';btn.innerHTML='<i class="bi bi-trash3"></i>';btn.classList.replace('btn-danger','btn-outline-danger');btn.classList.remove('text-white');}
            },3000);
        }
    }
  </script>
<?php ?>
<script src="<?= asset('assets/js/mail-templates.js') ?>"></script>
<?php
require 'includes/layout_end.php'; ?>