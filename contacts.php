<?php
ob_start();

// 1. NAMESPACES (Ganz oben!)
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// 2. Composer-Autoloader (stellt PHPMailer\PHPMailer\PHPMailer bereit)
require_once __DIR__ . '/vendor/autoload.php';

// 3. Zentrale Config laden (ob_end_clean verhindert, dass PHPMailer-Warnings die Session brechen)
ob_end_clean();
require_once 'config.php';
require_once 'includes/mail_templates.php';
require_once 'includes/auth.php';

// ==========================================
// AKTIONEN VERARBEITEN & LOGGEN
// ==========================================
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['action'])) {
    csrf_check();
    $action = $_POST['action'];

    // 1. Portal Token generieren
    if ($action === 'generate_token') {
        $token      = bin2hex(random_bytes(16));
        $contact_id = (int)$_POST['contact_id'];
        
        $pdo->prepare("UPDATE contacts SET portal_token = ? WHERE id = ?")->execute([$token, $contact_id]);
        
        // Name für das Logbuch holen
        $stmt = $pdo->prepare("SELECT name FROM contacts WHERE deleted_at IS NULL AND id = ?");
        $stmt->execute([$contact_id]);
        $c_name = $stmt->fetchColumn();
        
        $pdo->prepare("INSERT INTO logs (action_type, description) VALUES ('PORTAL_CREATED', ?)")->execute(["Portal-Zugang für '".$c_name."' erstellt."]);
    }
    
    // 2. Portal Mail & QR-Code senden (White-Label SMTP Config)
    elseif ($action === 'send_portal_mail') {
        $stmt = $pdo->prepare("SELECT * FROM contacts WHERE deleted_at IS NULL AND id = ?");
        $stmt->execute([(int)$_POST['contact_id']]);
        $c = $stmt->fetch();

        if ($c && $c['email'] && class_exists('PHPMailer\PHPMailer\PHPMailer')) {
            // BASE_URL aus config.php nutzen
            $portal_link = BASE_URL . "/portal?token=" . $c['portal_token'];
            $mail = new PHPMailer(true);
            try {
                $mail->isSMTP();
                $mail->Host       = SMTP_HOST;
                $mail->SMTPAuth   = true;
                $mail->Username   = SMTP_USER;
                $mail->Password   = SMTP_PASS; 
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
                $mail->Port       = SMTP_PORT;
                $mail->CharSet    = 'UTF-8';

                // Absender & Namen dynamisch
                $mail->setFrom(setting('admin_email', ADMIN_EMAIL), setting('company_name', COMPANY_NAME));
                $mail->addAddress($c['email'], $c['name']);
                $mail->addReplyTo(setting('support_email', SUPPORT_EMAIL), setting('company_short', COMPANY_SHORT));

                $qr_api = "https://api.qrserver.com/v1/create-qr-code/?size=150x150&data=" . urlencode($portal_link);

                $mail->isHTML(true);
                // Der QR-Code ist der eigentliche Zweck dieser Mail. Er ist
                // kein Vorlagentext, sondern Beiwerk, und wird deshalb als
                // Zusatzblock hinter die Nachricht in den Rahmen gesetzt.
                $_qr = '<p style="margin:0 0 8px;font-size:14px;color:#6c757d;">'
                     . 'QR-Code für den mobilen Zugriff:</p>'
                     . '<img src="' . htmlspecialchars($qr_api, ENT_QUOTES) . '" alt="QR-Code" '
                     . 'width="150" height="150" style="display:block;border:0;">';

                // Wortlaut aus der Vorlage (Einstellungen > E-Mail-Vorlagen).
                $_m = mail_render('portal_access', [
                    'kunde'     => $c['name'],
                    'nachricht' => $_POST['mail_text'] ?? '',
                    'firma'     => setting('company_short', COMPANY_SHORT),
                ], $portal_link, $_qr);

                $mail->Subject = $_m['subject'];
                $mail->Body    = $_m['html'];
                $mail->AltBody = $_m['text'] . "\n\n" . $portal_link;
                $mail->send();
                
                $pdo->prepare("INSERT INTO logs (action_type, description) VALUES ('MAIL_SENT', ?)")->execute(["Portal-Link via E-Mail an ".$c['name']." gesendet."]);
            } catch (Exception $e) {
                $pdo->prepare("INSERT INTO logs (action_type, description) VALUES ('MAIL_ERROR', ?)")->execute(["SMTP Fehler beim Senden an ".$c['name'].": " . $mail->ErrorInfo]);
            }
        }
    }
    
    // 3. Kontakt Hinzufügen / Bearbeiten
    elseif ($action === 'add_contact' || $action === 'edit_contact') {
        $name = trim($_POST['name']);
        $params = [$name, trim($_POST['company']), trim($_POST['email']), trim($_POST['phone']), trim($_POST['website']), trim($_POST['street']), trim($_POST['zip']), trim($_POST['city']), trim($_POST['country']), $_POST['contact_type'], $_POST['source'], trim($_POST['notes'])];

        if ($action === 'edit_contact') {
            $params[] = (int)$_POST['contact_id'];
            $pdo->prepare("UPDATE contacts SET name=?, company=?, email=?, phone=?, website=?, street=?, zip=?, city=?, country=?, contact_type=?, source=?, notes=? WHERE id=?")->execute($params);
            $pdo->prepare("INSERT INTO logs (action_type, description) VALUES ('CONTACT_EDITED', ?)")->execute(["Kontakt '".$name."' wurde aktualisiert."]);
        } else {
            $pdo->prepare("INSERT INTO contacts (name, company, email, phone, website, street, zip, city, country, contact_type, source, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")->execute($params);
            $pdo->prepare("INSERT INTO logs (action_type, description) VALUES ('CONTACT_ADDED', ?)")->execute(["Neuer Kontakt '".$name."' wurde angelegt."]);
        }
    }
    
    // 4. Portal-PIN zurücksetzen
    elseif ($action === 'reset_portal_pin') {
        $id = (int)$_POST['contact_id'];
        $pdo->prepare("UPDATE contacts SET portal_pin=NULL, portal_pin_attempts=0, portal_pin_locked_until=NULL WHERE id=?")->execute([$id]);
        $pdo->prepare("INSERT INTO logs (action_type, description) VALUES ('PORTAL_PIN_RESET',?)")
            ->execute(["Portal-Zugangscode für Kontakt #$id zurückgesetzt."]);
        header("Location: contacts?msg=pin_reset"); exit();
    }

    // 5. Kontakt Löschen
    elseif ($action === 'delete_contact') {
        $stmt = $pdo->prepare("SELECT name FROM contacts WHERE deleted_at IS NULL AND id = ?");
        $stmt->execute([(int)$_POST['contact_id']]);
        $del_name = $stmt->fetchColumn();

        // Papierkorb statt Sofortloeschung: der Datensatz verschwindet aus
        // allen Ansichten, bleibt aber 30 Tage wiederherstellbar.
        $pdo->prepare("UPDATE contacts SET deleted_at = NOW() WHERE id = ? AND deleted_at IS NULL")->execute([(int)$_POST['contact_id']]);
        
        if($del_name) {
            $pdo->prepare("INSERT INTO logs (action_type, description) VALUES ('CONTACT_DELETED', ?)")->execute(["Kontakt '".$del_name."' wurde gelöscht."]);
        }
    }
    
    header("Location: contacts"); exit();
}

// ==========================================
// FILTER & DATEN LADEN
// ==========================================
$search_query = isset($_GET['search']) ? trim($_GET['search']) : ''; 
$filter_type = isset($_GET['type']) ? $_GET['type'] : 'all';

$sql = "SELECT * FROM contacts WHERE deleted_at IS NULL AND 1=1";
$params = [];

if ($search_query !== '') {
    $sql .= " AND (name LIKE ? OR company LIKE ? OR email LIKE ? OR notes LIKE ?)";
    $params[] = "%$search_query%";
    $params[] = "%$search_query%";
    $params[] = "%$search_query%";
    $params[] = "%$search_query%";
}

if ($filter_type !== 'all') {
    $sql .= " AND contact_type = ?";
    $params[] = $filter_type;
}

$sql .= " ORDER BY created_at DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$contacts = $stmt->fetchAll(PDO::FETCH_ASSOC);

$page_title   = 'Kontakte';
$page_heading = 'CRM & Kontakte';
$current_page = basename($_SERVER['PHP_SELF']);
$header_actions = '<button class="btn btn-primary btn-sm fw-bold" data-bs-toggle="modal" data-bs-target="#addContactModal" onclick="prepareAdd()"><i class="bi bi-person-plus-fill"></i> Neu anlegen</button>';
// QR-Code-Bibliothek wird nur hier gebraucht, daher hier statt in head.php.
$extra_head = '<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>';

require 'includes/head.php';
require 'includes/layout_start.php';
?>

    <div class="row mb-4">
        <div class="col-12">
            <form method="GET" action="contacts" class="filter-bar mb-0">
                <div class="input-group input-group-sm search-box">
                    <span class="input-group-text bg-surface border-end-0 text-muted"><i class="bi bi-search"></i></span>
                    <input type="text" name="search" class="form-control border-start-0 ps-0" placeholder="Suche nach Name, Firma, E-Mail oder Notiz..." value="<?=htmlspecialchars($search_query)?>">
                </div>
                <select name="type" class="form-select form-select-sm w-auto" onchange="this.form.submit()">
                        <option value="all">Alle Typen</option>
                        <option value="Kunde" <?= $filter_type === 'Kunde' ? 'selected' : '' ?>>Kunden</option>
                        <option value="Interessent" <?= $filter_type === 'Interessent' ? 'selected' : '' ?>>Interessenten</option>
                        <option value="Geschäftspartner" <?= $filter_type === 'Geschäftspartner' ? 'selected' : '' ?>>Geschäftspartner</option>
                </select>
                <button type="submit" class="btn btn-primary btn-sm" style="display: none;">Filtern</button>
                <?php if($search_query !== '' || $filter_type !== 'all'): ?>
                    <a href="contacts" class="btn btn-outline-secondary btn-sm" title="Filter zurücksetzen"><i class="bi bi-x-circle"></i> Reset</a>
                <?php endif; ?>
            </form>
        </div>
    </div>

    <div class="row">
      <?php if(count($contacts) === 0): ?>
          <div class="col-12 text-center text-muted py-5">
              <i class="bi bi-inbox fs-1 d-block mb-2"></i>
              <p>Keine Kontakte für diese Suchkriterien gefunden.</p>
          </div>
      <?php endif; ?>

      <?php foreach($contacts as $c): ?>
          <div class="col-lg-4 col-md-6 mb-4">
            <div class="contact-card shadow-sm">
              <div class="card-actions">
                <button type="button" class="btn-icon" onclick='openEditModal(<?=json_encode($c, JSON_HEX_TAG|JSON_HEX_APOS)?>)' title="Bearbeiten">
                    <i class="bi bi-pencil-square" style="font-size:1.2rem;"></i>
                </button>
                
                <form action="contacts" method="POST" class="d-inline" id="del_form_<?= $c['id'] ?>">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="delete_contact">
                    <input type="hidden" name="contact_id" value="<?= $c['id'] ?>">
                    <button type="button" class="btn-icon text-danger" data-confirmed="0" onclick="confirmDelete(this, <?= $c['id'] ?>)" title="Löschen">
                        <i class="bi bi-trash3-fill" style="font-size:1.2rem;"></i>
                    </button>
                </form>
              </div>
              
              <div class="mb-2">
                  <?php
                    $ct = $c['contact_type'] ?: 'Interessent';
                    if ($ct === 'Kunde') $ct_class = 'border-success text-success';
                    elseif ($ct === 'Geschäftspartner') $ct_class = 'border-warning text-warning';
                    else $ct_class = 'border-primary text-primary';
                  ?>
                  <span class="badge bg-subtle border <?= $ct_class ?>"><?= htmlspecialchars($ct) ?></span>
                  <?php if(!empty($c['source'])): ?>
                      <span class="badge bg-subtle text-muted border ms-1"><i class="bi bi-box-arrow-in-right"></i> <?=htmlspecialchars($c['source'])?></span>
                  <?php endif; ?>
              </div>

              <div class="d-flex align-items-center mb-3">
                  <div class="contact-avatar"><?=strtoupper(substr($c['name'],0,1))?></div>
                  <div>
                      <h3 style="font-size:18px; font-weight:700; margin:0; word-break: break-word;"><?=htmlspecialchars($c['name'])?></h3>
                      <?php if(!empty($c['company'])): ?>
                        <small class="text-muted fw-bold"><?=htmlspecialchars($c['company'])?></small>
                      <?php endif; ?>
                  </div>
              </div>
              
              <div class="small mb-3">
                  <?php if(!empty($c['email'])): ?>
                    <p class="mb-1 text-muted text-truncate"><i class="bi bi-envelope text-primary me-2" style="color: var(--color-primary) !important;"></i> <a href="mailto:<?=$c['email']?>" class="text-decoration-none text-muted"><?=$c['email']?></a></p>
                  <?php endif; ?>
                  
                  <?php if(!empty($c['phone'])): ?>
                      <p class="mb-1 text-muted"><i class="bi bi-telephone text-primary me-2" style="color: var(--color-primary) !important;"></i> <a href="tel:<?=$c['phone']?>" class="text-decoration-none text-muted"><?=$c['phone']?></a></p>
                  <?php endif; ?>
                  
                  <?php if(!empty($c['website'])): ?>
                      <p class="mb-1 text-muted text-truncate"><i class="bi bi-globe text-primary me-2" style="color: var(--color-primary) !important;"></i> <a href="<?=strpos($c['website'], 'http') === 0 ? $c['website'] : 'https://'.$c['website']?>" target="_blank" class="text-decoration-none"><?=$c['website']?></a></p>
                  <?php endif; ?>
                  
                  <?php if(!empty($c['street']) || !empty($c['city'])): ?>
                      <p class="mb-1 text-muted mt-2 pt-2 border-top">
                          <i class="bi bi-geo-alt text-primary me-2" style="color: var(--color-primary) !important;"></i> 
                          <?=htmlspecialchars(trim($c['street'] . ', ' . $c['zip'] . ' ' . $c['city'] . ', ' . $c['country'], ', '))?>
                      </p>
                  <?php endif; ?>
              </div>

              <?php if(!empty($c['notes'])): ?>
                  <div class="bg-subtle p-2 rounded small text-muted mb-3 scroll-box-sm border">
                      <i class="bi bi-card-text me-1"></i> <?=nl2br(htmlspecialchars($c['notes']))?>
                  </div>
              <?php endif; ?>

              <div class="mt-auto d-grid gap-2">
                <?php if(empty($c['portal_token'])): ?>
                    <form method="POST"><?= csrf_field() ?><input type="hidden" name="action" value="generate_token"><input type="hidden" name="contact_id" value="<?=$c['id']?>"><button type="submit" class="btn btn-sm btn-outline-primary w-100 fw-bold">Portal erstellen</button></form>
                <?php else: 
                    $magic_url = BASE_URL . "/portal?token=".$c['portal_token']; ?>
                    
                    <div class="d-flex gap-2">
                        <button class="btn btn-sm btn-success shadow-sm flex-fill" onclick="navigator.clipboard.writeText('<?=$magic_url?>').then(()=>alert('Kopiert!'))"><i class="bi bi-clipboard"></i> Link kopieren</button>
                        <button class="btn btn-sm btn-info text-white fw-bold shadow-sm flex-fill" onclick="openQR('<?=$magic_url?>', <?=$c['id']?>, '<?=htmlspecialchars($c['name'], ENT_QUOTES)?>')" data-bs-toggle="modal" data-bs-target="#qrModal"><i class="bi bi-qr-code-scan"></i> QR & Mail</button>
                    </div>
                    <?php if (!empty($c['portal_pin'])): ?>
                    <form method="POST" onsubmit="return confirm('Portal-Zugangscode von <?= htmlspecialchars($c['name'], ENT_QUOTES) ?> wirklich zurücksetzen?')">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="reset_portal_pin">
                        <input type="hidden" name="contact_id" value="<?= $c['id'] ?>">
                        <button type="submit" class="btn btn-sm btn-outline-warning w-100"><i class="bi bi-key me-1"></i>PIN zurücksetzen</button>
                    </form>
                    <?php endif; ?>
                <?php endif; ?>
              </div>
            </div>
          </div>
      <?php endforeach; ?>
    </div>

  <div class="modal fade" id="addContactModal" tabindex="-1" data-bs-backdrop="static" data-bs-keyboard="false">
    <div class="modal-dialog modal-lg">
      <div class="modal-content">
        <form action="contacts" method="POST">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="add_contact">
            <div class="modal-header bg-dark text-white"><h5 class="fw-bold m-0"><i class="bi bi-person-plus-fill me-2"></i>Neuen Kontakt anlegen</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div>
            <div class="modal-body p-4 bg-subtle">
              <div class="row g-3 bg-surface p-3 rounded shadow-sm border mb-3">
                  <h6 class="fw-bold text-primary border-bottom pb-2">Stammdaten</h6>
                  <div class="col-md-6"><label class="form-label small fw-bold">Name *</label><input type="text" name="name" class="form-control form-control-sm" required></div>
                  <div class="col-md-6"><label class="form-label small fw-bold">Firma</label><input type="text" name="company" class="form-control form-control-sm"></div>
                  <div class="col-md-4"><label class="form-label small fw-bold">E-Mail</label><input type="email" name="email" class="form-control form-control-sm"></div>
                  <div class="col-md-4"><label class="form-label small fw-bold">Telefon</label><input type="text" name="phone" class="form-control form-control-sm"></div>
                  <div class="col-md-4"><label class="form-label small fw-bold">Website</label><input type="text" name="website" class="form-control form-control-sm" placeholder="www.seite.de"></div>
              </div>
              <div class="row g-3 bg-surface p-3 rounded shadow-sm border mb-3">
                  <h6 class="fw-bold text-primary border-bottom pb-2">Adresse</h6>
                  <div class="col-12"><label class="form-label small fw-bold">Straße</label><input type="text" name="street" class="form-control form-control-sm"></div>
                  <div class="col-md-4"><label class="form-label small fw-bold">PLZ</label><input type="text" name="zip" class="form-control form-control-sm"></div>
                  <div class="col-md-4"><label class="form-label small fw-bold">Ort</label><input type="text" name="city" class="form-control form-control-sm"></div>
                  <div class="col-md-4"><label class="form-label small fw-bold">Land</label><input type="text" name="country" class="form-control form-control-sm" value="Deutschland"></div>
              </div>
              <div class="row g-3 bg-surface p-3 rounded shadow-sm border">
                  <h6 class="fw-bold text-primary border-bottom pb-2">Meta & CRM</h6>
                  <div class="col-md-6"><label class="form-label small fw-bold">Typ</label><select name="contact_type" class="form-select form-select-sm"><option value="Kunde">Kunde</option><option value="Interessent">Interessent</option><option value="Geschäftspartner">Geschäftspartner</option></select></div>
                  <div class="col-md-6"><label class="form-label small fw-bold">Quelle</label><input name="source" class="form-control form-control-sm" value="Direktkontakt"></div>
                  <div class="col-12"><label class="form-label small fw-bold">Interne Notizen</label><textarea name="notes" class="form-control form-control-sm" rows="3"></textarea></div>
              </div>
            </div>
            <div class="modal-footer"><button type="submit" class="btn btn-primary px-4 fw-bold">Speichern</button></div>
        </form>
      </div>
    </div>
  </div>

  <div class="modal fade" id="editContactModal" tabindex="-1" data-bs-backdrop="static" data-bs-keyboard="false">
    <div class="modal-dialog modal-lg">
      <div class="modal-content">
        <div class="modal-header bg-dark text-white"><h5 class="fw-bold m-0"><i class="bi bi-pencil-square me-2"></i>Kontakt bearbeiten</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div>
        <div class="modal-body p-4 bg-subtle">
            <form action="contacts" method="POST" id="editContactForm">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="edit_contact">
                <input type="hidden" name="contact_id" id="edit_id">
                
                <div class="row g-3 bg-surface p-3 rounded shadow-sm border mb-3">
                    <h6 class="fw-bold text-primary border-bottom pb-2">Stammdaten</h6>
                    <div class="col-md-6"><label class="form-label small fw-bold">Name *</label><input type="text" name="name" id="edit_name" class="form-control form-control-sm" required></div>
                    <div class="col-md-6"><label class="form-label small fw-bold">Firma</label><input type="text" name="company" id="edit_company" class="form-control form-control-sm"></div>
                    <div class="col-md-4"><label class="form-label small fw-bold">E-Mail</label><input type="email" name="email" id="edit_email" class="form-control form-control-sm"></div>
                    <div class="col-md-4"><label class="form-label small fw-bold">Telefon</label><input type="text" name="phone" id="edit_phone" class="form-control form-control-sm"></div>
                    <div class="col-md-4"><label class="form-label small fw-bold">Website</label><input type="text" name="website" id="edit_website" class="form-control form-control-sm"></div>
                </div>
                
                <div class="row g-3 bg-surface p-3 rounded shadow-sm border mb-3">
                    <h6 class="fw-bold text-primary border-bottom pb-2">Adresse</h6>
                    <div class="col-12"><label class="form-label small fw-bold">Straße</label><input type="text" name="street" id="edit_street" class="form-control form-control-sm"></div>
                    <div class="col-md-4"><label class="form-label small fw-bold">PLZ</label><input type="text" name="zip" id="edit_zip" class="form-control form-control-sm"></div>
                    <div class="col-md-4"><label class="form-label small fw-bold">Ort</label><input type="text" name="city" id="edit_city" class="form-control form-control-sm"></div>
                    <div class="col-md-4"><label class="form-label small fw-bold">Land</label><input type="text" name="country" id="edit_country" class="form-control form-control-sm"></div>
                </div>
                
                <div class="row g-3 bg-surface p-3 rounded shadow-sm border">
                    <h6 class="fw-bold text-primary border-bottom pb-2">Meta & CRM</h6>
                    <div class="col-md-6"><label class="form-label small fw-bold">Typ</label><select name="contact_type" id="edit_contact_type" class="form-select form-select-sm"><option value="Kunde">Kunde</option><option value="Interessent">Interessent</option><option value="Geschäftspartner">Geschäftspartner</option></select></div>
                    <div class="col-md-6"><label class="form-label small fw-bold">Quelle</label><input type="text" name="source" id="edit_source" class="form-control form-control-sm"></div>
                    <div class="col-12"><label class="form-label small fw-bold">Interne Notizen</label><textarea name="notes" id="edit_notes" class="form-control form-control-sm" rows="3"></textarea></div>
                </div>
            </form>
        </div>
        <div class="modal-footer d-flex justify-content-between bg-subtle">
            <button type="button" class="btn btn-outline-danger" id="modalDeleteBtn" data-confirmed="0" onclick="confirmDeleteFromEdit(this)"><i class="bi bi-trash3-fill"></i> Löschen</button>
            <button type="submit" form="editContactForm" class="btn btn-primary px-4 fw-bold">Speichern</button>
        </div>
      </div>
    </div>
  </div>

  <div class="modal fade" id="qrModal" tabindex="-1">
    <div class="modal-dialog"><div class="modal-content border-0 shadow">
        <div class="modal-header bg-dark text-white"><h6 class="modal-title fw-bold">Portal-Zugang: <span id="qr_name" class="text-info"></span></h6><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div>
        <div class="modal-body p-4 text-center bg-subtle">
            <div id="qrcode" class="mb-3 d-flex justify-content-center p-3 bg-surface rounded shadow-sm d-inline-block border"></div>
            <br>
            <button class="btn btn-outline-secondary btn-sm mb-4 fw-bold shadow-sm" onclick="downloadQR()"><i class="bi bi-download"></i> QR-Code speichern (.png)</button>
            
            <div class="card border-0 shadow-sm text-start">
                <div class="card-body">
                    <form method="POST" class="m-0">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="send_portal_mail">
                        <input type="hidden" name="contact_id" id="qr_id">
                        <label class="form-label small fw-bold text-primary" style="color: var(--color-primary) !important;">Begleittext der E-Mail:</label>
                        <textarea name="mail_text" class="form-control form-control-sm mb-3" rows="4">Hier ist Ihr persönlicher Zugang zum Projekt-Portal. Dort können Sie jederzeit den Fortschritt Ihres Projekts oder Dienstleistung einsehen, mitwirken oder Feedback dalassen.</textarea>
                        <button type="submit" class="btn btn-primary w-100 fw-bold"><i class="bi bi-send me-1"></i> E-Mail mit Link & QR-Code senden</button>
                    </form>
                </div>
            </div>
        </div>
    </div></div>
  </div>

  <script>
    // QR Code Logik
    function openQR(url, id, name) {
        document.getElementById('qr_name').innerText = name;
        document.getElementById('qr_id').value = id;
        document.getElementById('qrcode').innerHTML = "";
        new QRCode(document.getElementById('qrcode'), { text: url, width: 160, height: 160, colorDark : "#000000", colorLight : "#ffffff" });
    }
    
    function downloadQR() {
        const img = document.querySelector('#qrcode img');
        if(img) { const l = document.createElement('a'); l.href = img.src; l.download='Portal_QR.png'; l.click(); }
    }
    
    const editModal = new bootstrap.Modal(document.getElementById('editContactModal'));

    function prepareAdd() {
        document.querySelectorAll('#addContactModal input:not([type="hidden"]), #addContactModal textarea').forEach(el => el.value = '');
        document.querySelector('#addContactModal select[name="contact_type"]').value = 'Kunde';
        document.querySelector('#addContactModal input[name="country"]').value = 'Deutschland';
        document.querySelector('#addContactModal input[name="source"]').value = 'Direktkontakt';
    }

    function openEditModal(c) {
        document.getElementById('edit_id').value = c.id;
        document.getElementById('edit_name').value = c.name || '';
        document.getElementById('edit_company').value = c.company || '';
        document.getElementById('edit_email').value = c.email || '';
        document.getElementById('edit_phone').value = c.phone || '';
        document.getElementById('edit_website').value = c.website || '';
        document.getElementById('edit_street').value = c.street || '';
        document.getElementById('edit_zip').value = c.zip || '';
        document.getElementById('edit_city').value = c.city || '';
        document.getElementById('edit_country').value = c.country || '';
        document.getElementById('edit_contact_type').value = c.contact_type || 'Kunde';
        document.getElementById('edit_source').value = c.source || '';
        document.getElementById('edit_notes').value = c.notes || '';
        
        let delBtn = document.getElementById('modalDeleteBtn');
        delBtn.dataset.confirmed = "0";
        delBtn.innerHTML = '<i class="bi bi-trash3-fill"></i> Löschen';
        delBtn.classList.replace('btn-danger', 'btn-outline-danger');
        
        editModal.show();
    }

    function confirmDelete(btn, id) {
        if(btn.dataset.confirmed === "1") {
            document.getElementById('del_form_' + id).submit();
        } else {
            btn.dataset.confirmed = "1";
            btn.classList.replace('text-danger', 'btn-danger');
            btn.classList.remove('btn-icon');
            btn.classList.add('btn', 'btn-sm', 'text-white', 'fw-bold', 'px-2');
            btn.innerHTML = 'Sicher?';
            
            setTimeout(() => { 
                if(btn.parentNode) {
                    btn.dataset.confirmed = "0"; 
                    btn.innerHTML = '<i class="bi bi-trash3-fill" style="font-size:1.2rem;"></i>'; 
                    btn.classList.remove('btn', 'btn-sm', 'btn-danger', 'text-white', 'fw-bold', 'px-2');
                    btn.classList.add('btn-icon', 'text-danger');
                }
            }, 3000);
        }
    }

    function confirmDeleteFromEdit(btn) {
        if(btn.dataset.confirmed === "1") {
            let id = document.getElementById('edit_id').value;
            let form = document.createElement('form');
            form.method = 'POST';
            form.action = 'contacts';
            form.innerHTML = `<input type="hidden" name="action" value="delete_contact"><input type="hidden" name="contact_id" value="${id}">`;
            document.body.appendChild(form);
            form.submit();
        } else {
            btn.dataset.confirmed = "1";
            btn.classList.replace('btn-outline-danger', 'btn-danger');
            btn.innerHTML = 'Sicher? Klick noch mal!';
            
            setTimeout(() => { 
                btn.dataset.confirmed = "0"; 
                btn.classList.replace('btn-danger', 'btn-outline-danger');
                btn.innerHTML = '<i class="bi bi-trash3-fill"></i> Löschen';
            }, 3000);
        }
    }

  </script>
<?php require 'includes/layout_end.php'; ?>