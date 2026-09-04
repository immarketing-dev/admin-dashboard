<?php
// 1. Zentrale Config laden
require_once 'config.php';
require_once __DIR__ . '/includes/logging.php';
require_once 'includes/auth.php';
require_once 'includes/upload_helper.php';

// ==========================================
// AKTIONEN VERARBEITEN (Inkl. AJAX Upload)
// ==========================================
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['action'])) {
    // AJAX-Upload-Aktionen ohne Reload dürfen CSRF überspringen — alle anderen nicht
    if (!isset($_POST['ajax_upload'])) {
        csrf_check();
    }
    $action = $_POST['action'];

    // ARTIKEL HINZUFÜGEN / BEARBEITEN
    if ($action === 'add_article' || $action === 'edit_article') {
        $title = trim($_POST['title']);
        $category = trim($_POST['category']);
        $tags = trim($_POST['tags']);
        $content = $_POST['content']; 
        $is_pinned = isset($_POST['is_pinned']) ? 1 : 0;
        $article_id = isset($_POST['article_id']) ? (int)$_POST['article_id'] : 0;

        if (!empty($title)) {
            if ($action === 'add_article') {
                $stmt = $pdo->prepare("INSERT INTO wiki_articles (title, content, category, tags, is_pinned) VALUES (?, ?, ?, ?, ?)");
                $stmt->execute([$title, $content, $category, $tags, $is_pinned]);
                $article_id = $pdo->lastInsertId(); // ID für Attachments abgreifen
                log_event($pdo, 'WIKI_ADDED', "Wiki-Eintrag '". $title ."' wurde angelegt.");
            } else {
                $stmt = $pdo->prepare("UPDATE wiki_articles SET title=?, content=?, category=?, tags=?, is_pinned=? WHERE id=?");
                $stmt->execute([$title, $content, $category, $tags, $is_pinned, $article_id]);
                log_event($pdo, 'WIKI_EDITED', "Wiki-Eintrag '". $title ."' wurde aktualisiert.");
            }
            
            // DATEI-UPLOAD VERARBEITEN
            if (!empty($_FILES['attachments']['name'][0])) {
                $upload_dir   = 'uploads/wiki/';
                $upload_errors = [];
                if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);

                foreach ($_FILES['attachments']['tmp_name'] as $key => $tmp_name) {
                    $name      = $_FILES['attachments']['name'][$key];
                    $file_size = $_FILES['attachments']['size'][$key];
                    if (!$tmp_name) continue;

                    $err = validate_upload($tmp_name, $name, $file_size);
                    if ($err) {
                        $upload_errors[] = $err;
                        continue;
                    }

                    $safe_name = safe_filename($name);
                    $path      = $upload_dir . $safe_name;

                    if (move_uploaded_file($tmp_name, $path)) {
                        $pdo->prepare("INSERT INTO wiki_attachments (article_id, file_name, file_path) VALUES (?, ?, ?)")
                            ->execute([$article_id, $name, $path]);
                        log_event($pdo, 'WIKI_ATTACHMENT_ADDED', "Anhang '$name' zu Wiki-Artikel #$article_id hochgeladen.");
                    }
                }
            }
        }

        // Wenn der Aufruf per AJAX (Fortschrittsbalken) kam, gib Ergebnis zurück
        if (isset($_POST['ajax_upload'])) {
            if (!empty($upload_errors)) {
                echo json_encode(['status' => 'partial', 'errors' => $upload_errors]);
            } else {
                echo "OK";
            }
            exit();
        } else {
            $redirect = "wiki";
            if (!empty($upload_errors)) {
                $redirect .= "?upload_err=" . urlencode(implode(' | ', $upload_errors));
            }
            header("Location: $redirect"); exit();
        }
    }
    
    // EINZELNES BILD/DOKUMENT LÖSCHEN (AJAX Aufruf)
    elseif ($action === 'delete_wiki_asset') {
        $stmt = $pdo->prepare("SELECT file_name, file_path FROM wiki_attachments WHERE id = ?");
        $stmt->execute([$_POST['asset_id']]);
        $att = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($att && file_exists($att['file_path'])) @unlink($att['file_path']);
        $pdo->prepare("DELETE FROM wiki_attachments WHERE id = ?")->execute([$_POST['asset_id']]);
        if ($att) log_event($pdo, 'WIKI_ATTACHMENT_DELETED', "Anhang '{$att['file_name']}' gelöscht.");
        echo "DELETED"; exit();
    }
    
    // ARTIKEL-FREIGABEN SPEICHERN
    elseif ($action === 'save_shares') {
        $article_id  = (int)$_POST['article_id'];
        $contact_ids = array_map('intval', (array)($_POST['contact_ids'] ?? []));

        // Alle bisherigen Freigaben dieses Artikels löschen, dann neu setzen
        $pdo->prepare("DELETE FROM wiki_client_shares WHERE article_id = ?")->execute([$article_id]);

        $ins = $pdo->prepare("INSERT IGNORE INTO wiki_client_shares (article_id, contact_id) VALUES (?, ?)");
        foreach ($contact_ids as $cid) {
            if ($cid > 0) $ins->execute([$article_id, $cid]);
        }

        $stmt = $pdo->prepare("SELECT title FROM wiki_articles WHERE id = ?");
        $stmt->execute([$article_id]);
        $art_title = $stmt->fetchColumn();
        log_event($pdo, 'WIKI_SHARED', "Artikel '". $art_title ."' für ". count($contact_ids) ." Kunde(n) freigegeben.");

        header("Location: wiki"); exit();
    }

    // KOMPLETTEN ARTIKEL LÖSCHEN
    elseif ($action === 'delete_article') {
        $stmt = $pdo->prepare("SELECT title FROM wiki_articles WHERE id = ?");
        $stmt->execute([$_POST['article_id']]);
        $del_title = $stmt->fetchColumn();

        // 1. Physische Dateien des Artikels löschen
        $files = $pdo->prepare("SELECT file_path FROM wiki_attachments WHERE article_id = ?");
        $files->execute([$_POST['article_id']]);
        foreach($files->fetchAll(PDO::FETCH_COLUMN) as $f) {
            if(file_exists($f)) @unlink($f);
        }
        
        // 2. Datenbankeinträge löschen
        $pdo->prepare("DELETE FROM wiki_attachments WHERE article_id = ?")->execute([$_POST['article_id']]);
        $pdo->prepare("DELETE FROM wiki_articles WHERE id = ?")->execute([$_POST['article_id']]);
        
        if($del_title) {
            log_event($pdo, 'WIKI_DELETED', "Wiki-Eintrag '". $del_title ."' wurde gelöscht.");
        }
        header("Location: wiki"); exit();
    }
}

// ==========================================
// FILTER, SORTIERUNG & DATEN LADEN
// ==========================================
$search_query = isset($_GET['q']) ? trim($_GET['q']) : '';
$filter_category = isset($_GET['category']) ? $_GET['category'] : 'all';
$sort_by = isset($_GET['sort']) ? $_GET['sort'] : 'newest';

$sql = "SELECT * FROM wiki_articles WHERE 1=1";
$params = [];
if ($search_query !== '') {
    $sql .= " AND (title LIKE ? OR content LIKE ? OR tags LIKE ?)";
    $term = "%$search_query%"; $params[] = $term; $params[] = $term; $params[] = $term;
}
if ($filter_category !== 'all') { $sql .= " AND category = ?"; $params[] = $filter_category; }

$sql .= " ORDER BY is_pinned DESC, " . ($sort_by === 'az' ? "title ASC" : "created_at DESC");

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$articles = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ANHÄNGE ZU JEDEM ARTIKEL LADEN
foreach ($articles as &$article) {
    $stmt = $pdo->prepare("SELECT * FROM wiki_attachments WHERE article_id = ? ORDER BY uploaded_at DESC");
    $stmt->execute([$article['id']]);
    $article['attachments'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
}
unset($article);

// FREIGABEN PRO ARTIKEL (Batch)
$share_map = [];
if (!empty($articles)) {
    $ids          = array_column($articles, 'id');
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $sh_stmt      = $pdo->prepare("SELECT article_id, contact_id FROM wiki_client_shares WHERE article_id IN ($placeholders)");
    $sh_stmt->execute($ids);
    foreach ($sh_stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $share_map[$row['article_id']][] = (int)$row['contact_id'];
    }
}

// Alle Kontakte mit Portal-Token (für das Freigabe-Modal)
$portal_contacts = $pdo->query("SELECT id, name, company FROM contacts WHERE deleted_at IS NULL AND portal_token IS NOT NULL AND portal_token != '' ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
// Alle Kontakte ohne Portal-Zugang (zum Anzeigen eines Hinweises)
$all_contacts_count = (int)$pdo->query("SELECT COUNT(*) FROM contacts WHERE deleted_at IS NULL")->fetchColumn();

$all_categories = $pdo->query("SELECT DISTINCT category FROM wiki_articles WHERE category != '' ORDER BY category ASC")->fetchAll(PDO::FETCH_COLUMN);

// ARTIKEL NACH KATEGORIE GRUPPIEREN FÜR DAS AKKORDEON
$grouped_articles = [];
foreach($articles as $art) {
    $cat = $art['category'] ?: 'Unkategorisiert';
    if(!isset($grouped_articles[$cat])) {
        $grouped_articles[$cat] = [];
    }
    $grouped_articles[$cat][] = $art;
}

$page_title   = 'Wiki & Snippets';
$page_heading = 'Wiki & Snippets';
$current_page = basename($_SERVER['PHP_SELF']);
$header_actions = '<button class="btn btn-primary btn-sm fw-bold px-3" data-bs-toggle="modal" data-bs-target="#wikiFormModal" onclick="prepareAdd()"><i class="bi bi-journal-plus"></i> Neuer Eintrag</button>';
// Prism-Theme (Code-Highlighting) wird nur hier gebraucht, daher hier statt in head.php.
$extra_head = '<link href="https://cdnjs.cloudflare.com/ajax/libs/prism/1.29.0/themes/prism-tomorrow.min.css" rel="stylesheet" />';

require 'includes/head.php';
require 'includes/layout_start.php';
?>

    <form method="GET" action="wiki" class="filter-bar">
        <div class="input-group input-group-sm search-box">
            <span class="input-group-text bg-surface border-end-0 text-muted"><i class="bi bi-search"></i></span>
            <input type="text" name="q" class="form-control border-start-0 ps-0" placeholder="Wiki durchsuchen..." value="<?=htmlspecialchars($search_query)?>">
        </div>

        <select name="category" class="form-select form-select-sm w-auto" onchange="this.form.submit()">
            <option value="all">Alle Kategorien</option>
            <?php foreach($all_categories as $cat): ?>
                <option value="<?=htmlspecialchars($cat)?>" <?=$filter_category==$cat?'selected':''?>><?=htmlspecialchars($cat)?></option>
            <?php endforeach; ?>
        </select>
        
        <select name="sort" class="form-select form-select-sm w-auto" onchange="this.form.submit()">
            <option value="newest" <?=$sort_by=='newest'?'selected':''?>>Neueste zuerst</option>
            <option value="az" <?=$sort_by=='az'?'selected':''?>>A-Z</option>
        </select>
        
        <button type="submit" class="btn btn-primary btn-sm ms-auto" style="display: none;">Suchen</button>
        <?php if($search_query !== '' || $filter_category !== 'all'): ?>
            <a href="wiki" class="btn btn-outline-secondary btn-sm ms-auto"><i class="bi bi-x-circle"></i> Reset</a>
        <?php endif; ?>
    </form>

    <div class="accordion" id="wikiAccordion">
        
      <?php if(empty($grouped_articles)): ?>
          <div class="text-center py-5 text-muted bg-surface rounded shadow-sm border">
              <i class="bi bi-journal-x fs-1 d-block mb-2"></i>
              <p>Keine Wiki-Einträge gefunden.</p>
          </div>
      <?php endif; ?>

      <?php 
      $is_first = true;
      foreach($grouped_articles as $category => $items): 
          $cat_id = md5($category); 
          $is_open = ($search_query !== '' || $is_first) ? 'show' : '';
          $btn_collapsed = ($is_open === 'show') ? '' : 'collapsed';
      ?>
          <div class="accordion-item">
            <h2 class="accordion-header" id="heading_<?=$cat_id?>">
              <button class="accordion-button <?=$btn_collapsed?>" type="button" data-bs-toggle="collapse" data-bs-target="#collapse_<?=$cat_id?>">
                <i class="bi bi-folder2-open me-2 text-primary"></i> <?= htmlspecialchars($category) ?> 
                <span class="badge bg-secondary ms-2 rounded-pill"><?= count($items) ?></span>
              </button>
            </h2>
            <div id="collapse_<?=$cat_id?>" class="accordion-collapse collapse <?=$is_open?>" aria-labelledby="heading_<?=$cat_id?>">
              <div class="accordion-body p-0">
                  <div class="list-group list-group-flush">
                      
                      <?php foreach($items as $article):
                          $safe_json     = htmlspecialchars(json_encode($article, JSON_HEX_TAG|JSON_HEX_APOS), ENT_QUOTES, 'UTF-8');
                          $shared_with   = $share_map[$article['id']] ?? [];
                          $share_count   = count($shared_with);
                          $shared_ids_js = json_encode($shared_with);
                      ?>
                          <div class="wiki-list-item <?= $article['is_pinned'] ? 'pinned' : ''; ?>" onclick='openViewModal(<?= $safe_json; ?>)'>
                              <div style="min-width: 0; flex-grow: 1; padding-right: 20px;">
                                  <div class="wiki-title">
                                      <?php if($article['is_pinned']): ?>
                                          <i class="bi bi-pin-angle-fill text-warning"></i>
                                      <?php endif; ?>
                                      <?= htmlspecialchars($article['title']); ?>

                                      <?php if($share_count > 0): ?>
                                          <span class="badge bg-success ms-1" style="font-size: 10px;" title="Für <?=$share_count?> Kunde(n) freigegeben">
                                              <i class="bi bi-globe2"></i> <?= $share_count ?>
                                          </span>
                                      <?php endif; ?>

                                      <?php if(count($article['attachments']) > 0): ?>
                                          <i class="bi bi-paperclip text-primary ms-1" title="Hat Anhänge"></i>
                                      <?php endif; ?>

                                      <?php if(!empty($article['tags'])):
                                          $tags = explode(',', $article['tags']);
                                          foreach($tags as $t): ?>
                                              <span class="badge bg-subtle text-muted border fw-normal ms-1" style="font-size: 10px;"><?= htmlspecialchars(trim($t)) ?></span>
                                      <?php endforeach; endif; ?>
                                  </div>
                                  <div class="wiki-preview-text">
                                      <?= mb_strimwidth(strip_tags($article['content']), 0, 150, "..."); ?>
                                  </div>
                              </div>

                              <div class="card-actions">
                                  <button type="button" class="btn-icon text-success" onclick='event.stopPropagation(); openShareModal(<?= $article["id"] ?>, <?= $shared_ids_js ?>)' title="Im Kundenportal freigeben">
                                      <i class="bi bi-share" style="font-size: 1.2rem;"></i>
                                  </button>

                                  <button type="button" class="btn-icon" onclick='event.stopPropagation(); prepareEdit(<?= $safe_json; ?>)' title="Bearbeiten">
                                      <i class="bi bi-pencil-square" style="font-size: 1.2rem;"></i>
                                  </button>

                                  <form action="wiki" method="POST" class="d-inline" id="del_form_<?= $article['id'] ?>">
                                      <?= csrf_field() ?>
                                      <input type="hidden" name="action" value="delete_article">
                                      <input type="hidden" name="article_id" value="<?= $article['id'] ?>">
                                      <button type="button" class="btn-icon text-danger" data-confirmed="0" onclick="event.stopPropagation(); confirmDeleteArticle(this, <?= $article['id'] ?>)" title="Löschen">
                                          <i class="bi bi-trash3-fill" style="font-size: 1.2rem;"></i>
                                      </button>
                                  </form>
                              </div>
                          </div>
                      <?php endforeach; ?>

                  </div>
              </div>
            </div>
          </div>
      <?php 
      $is_first = false;
      endforeach; 
      ?>
      
    </div>

  <div class="modal fade" id="wikiFormModal" tabindex="-1" data-bs-backdrop="static" data-bs-keyboard="false">
    <div class="modal-dialog modal-xl">
      <div class="modal-content border-0 shadow">
        <div class="modal-header bg-dark text-white">
            <h5 class="modal-title fw-bold m-0" id="form_title_label"><i class="bi bi-journal-plus me-2"></i> Neuer Eintrag</h5>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body p-4 bg-subtle">
          <form action="wiki" method="POST" enctype="multipart/form-data" id="wikiMainForm">
            <?= csrf_field() ?>
            <input type="hidden" name="action" id="form_action" value="add_article">
            <input type="hidden" name="article_id" id="form_article_id">
            
            <div class="row g-3 bg-surface p-3 rounded shadow-sm border mb-3">
              <div class="col-md-8"><label class="form-label fw-bold small">Titel *</label><input type="text" name="title" id="form_title" class="form-control" required></div>
              <div class="col-md-4"><label class="form-label fw-bold small">Kategorie *</label><input class="form-control" list="catList" name="category" id="form_category" required><datalist id="catList"><?php foreach($all_categories as $cat): ?><option value="<?=htmlspecialchars($cat)?>"><?php endforeach; ?></datalist></div>
            </div>
            
            <div class="mb-3 bg-surface p-3 rounded shadow-sm border">
                <label class="form-label fw-bold small">Inhalt</label>
                <!-- form-control und rows als Rueckfallebene: startet CKEditor nicht -->
                <!-- (CDN nicht erreichbar, JS-Fehler davor), bleibt sonst ein -->
                <!-- nacktes Textfeld in Browser-Standardbreite stehen. -->
                <textarea name="content" id="editor" class="form-control" rows="14"></textarea>
            </div>
            
            <div class="row bg-surface p-3 rounded shadow-sm border mx-0 align-items-end">
              <div class="col-md-5">
                  <label class="form-label fw-bold small">Dateien / Bilder anhängen</label>
                  <input type="file" name="attachments[]" id="fileInput" class="form-control form-control-sm" multiple>
              </div>
              <div class="col-md-5 mt-3 mt-md-0">
                  <label class="form-label fw-bold small">Tags (Kommagetrennt)</label>
                  <input type="text" name="tags" id="form_tags" class="form-control form-control-sm" placeholder="z.B. CSS, Login, API">
              </div>
              <div class="col-md-2 mt-3 mt-md-0 d-flex align-items-center">
                  <div class="form-check mb-1">
                      <input class="form-check-input" type="checkbox" name="is_pinned" id="form_pinCheck" value="1">
                      <label class="form-check-label text-warning fw-bold small">Oben anpinnen</label>
                  </div>
              </div>
              
              <div class="col-12 mt-3" id="uploadProgressContainer" style="display:none;">
                  <div class="progress" style="height: 15px;">
                      <div id="uploadProgressBar" class="progress-bar progress-bar-striped progress-bar-animated bg-primary" style="width: 0%; font-size: 10px;">0%</div>
                  </div>
              </div>
              
              <div class="col-12 mt-3" id="edit_attachments_container" style="display:none;">
                  <label class="form-label fw-bold small text-muted">Vorhandene Anhänge (Klick auf Mülleimer zum Löschen)</label>
                  <div id="e_wiki_assets" class="d-flex flex-column gap-1"></div>
              </div>
            </div>
          </form>
        </div>
        <div class="modal-footer p-3 bg-subtle d-flex justify-content-between">
            <button type="button" class="btn btn-outline-danger fw-bold" id="modalDeleteBtn" style="display:none;" data-confirmed="0" onclick="confirmDeleteFromEdit(this)">
                <i class="bi bi-trash3-fill"></i> Löschen
            </button>
            <button type="submit" form="wikiMainForm" class="btn btn-primary px-5 fw-bold" id="saveWikiBtn">
                <i class="bi bi-save me-1"></i> Speichern
            </button>
        </div>
      </div>
    </div>
  </div>
  
  <div class="modal fade" id="viewWikiModal" tabindex="-1">
    <div class="modal-dialog modal-xl">
      <div class="modal-content border-0 shadow">
        <div class="modal-header border-bottom pb-3 mt-2 mx-2 align-items-start">
            <div>
                <span class="badge bg-primary text-uppercase" id="view_category" style="letter-spacing: 0.5px;"></span>
                <span id="view_tags_container" class="ms-2"></span>
                <h2 id="view_title" class="fw-bold mt-2 mb-0" style="color:var(--text-heading);"></h2>
            </div>
            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body pt-3 px-4 pb-4">
          <div id="view_content" style="font-size: 15px; color: var(--text-body); line-height: 1.7;"></div>
          
          <div id="view_attachments" class="mt-5 pt-4 border-top" style="display:none;">
              <h6 class="fw-bold mb-3 text-strong-c"><i class="bi bi-paperclip me-1"></i> Angehängte Dateien</h6>
              <div id="view_attachments_list" class="d-flex flex-wrap gap-2"></div>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- FREIGABE-MODAL -->
  <div class="modal fade" id="shareWikiModal" tabindex="-1" data-bs-backdrop="static" data-bs-keyboard="false">
    <div class="modal-dialog">
      <div class="modal-content border-0 shadow">
        <div class="modal-header bg-success text-white">
          <h5 class="modal-title fw-bold m-0"><i class="bi bi-share me-2"></i> Artikel im Kundenportal freigeben</h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <form action="wiki" method="POST" id="shareWikiForm">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="save_shares">
          <input type="hidden" name="article_id" id="share_article_id">
          <div class="modal-body">
            <?php if (empty($portal_contacts)): ?>
              <div class="alert alert-warning mb-0">
                <i class="bi bi-exclamation-triangle me-2"></i>
                Es gibt noch keine Kontakte mit Portalzugang.<br>
                <small class="text-muted">Weise einem Kontakt zuerst ein Portal-Token zu (Kontakte-Seite).</small>
              </div>
            <?php else: ?>
              <p class="text-muted small mb-3">Wähle, welche Kunden diesen Artikel in ihrem Portal lesen können:</p>
              <div class="list-group" id="share_contact_list">
                <?php foreach($portal_contacts as $pc): ?>
                  <label class="list-group-item list-group-item-action d-flex align-items-center gap-3 cursor-pointer" style="cursor:pointer;">
                    <input type="checkbox" name="contact_ids[]" value="<?= (int)$pc['id'] ?>" class="form-check-input share-contact-check" style="flex-shrink:0;">
                    <span>
                      <span class="fw-semibold"><?= htmlspecialchars($pc['name']) ?></span>
                      <?php if($pc['company']): ?>
                        <span class="text-muted small ms-1">&mdash; <?= htmlspecialchars($pc['company']) ?></span>
                      <?php endif; ?>
                    </span>
                  </label>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Abbrechen</button>
            <?php if (!empty($portal_contacts)): ?>
              <button type="submit" class="btn btn-success fw-bold"><i class="bi bi-check-lg me-1"></i> Freigaben speichern</button>
            <?php endif; ?>
          </div>
        </form>
      </div>
    </div>
  </div>

  <script src="https://cdnjs.cloudflare.com/ajax/libs/prism/1.29.0/prism.min.js"></script>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/prism/1.29.0/components/prism-php.min.js"></script>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/prism/1.29.0/components/prism-javascript.min.js"></script>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/prism/1.29.0/components/prism-css.min.js"></script>
  <script src="https://cdn.ckeditor.com/ckeditor5/39.0.1/classic/ckeditor.js"></script>

  <script>
    let wikiEditor;
    const formModal = new bootstrap.Modal(document.getElementById('wikiFormModal'));

    // CKEditor Initialisierung
    ClassicEditor.create(document.querySelector('#editor'), {
        toolbar: [ 'heading', '|', 'bold', 'italic', 'link', 'bulletedList', 'numberedList', 'blockQuote', 'codeBlock', '|', 'insertTable', 'undo', 'redo' ]
    }).then(editor => { wikiEditor = editor; });

    // Formulardaten zurücksetzen (Hinzufügen)
    function prepareAdd() {
        document.getElementById('form_action').value = 'add_article';
        document.getElementById('form_title_label').innerHTML = '<i class="bi bi-journal-plus me-2"></i> Neuer Eintrag';
        document.getElementById('form_title').value = '';
        document.getElementById('form_category').value = '';
        document.getElementById('form_tags').value = '';
        document.getElementById('form_article_id').value = '';
        document.getElementById('form_pinCheck').checked = false;
        
        let delBtn = document.getElementById('modalDeleteBtn');
        delBtn.style.display = 'none';
        delBtn.dataset.confirmed = "0";
        delBtn.innerHTML = '<i class="bi bi-trash3-fill"></i> Löschen';
        delBtn.classList.replace('btn-danger', 'btn-outline-danger');
        
        document.getElementById('edit_attachments_container').style.display = 'none';
        document.getElementById('uploadProgressContainer').style.display = 'none';
        document.getElementById('fileInput').value = '';
        
        if(wikiEditor) wikiEditor.setData('');
    }

    // Formulardaten laden (Bearbeiten)
    function prepareEdit(art) {
        document.getElementById('form_action').value = 'edit_article';
        document.getElementById('form_article_id').value = art.id;
        document.getElementById('form_title_label').innerHTML = '<i class="bi bi-pencil-square me-2"></i> Eintrag bearbeiten';
        document.getElementById('form_title').value = art.title;
        document.getElementById('form_category').value = art.category;
        document.getElementById('form_tags').value = art.tags;
        document.getElementById('form_pinCheck').checked = (art.is_pinned == 1);
        
        let delBtn = document.getElementById('modalDeleteBtn');
        delBtn.style.display = 'inline-block';
        delBtn.dataset.confirmed = "0";
        delBtn.innerHTML = '<i class="bi bi-trash3-fill"></i> Löschen';
        delBtn.classList.replace('btn-danger', 'btn-outline-danger');
        
        document.getElementById('uploadProgressContainer').style.display = 'none';
        document.getElementById('fileInput').value = '';
        
        // Bestehende Anhänge laden INKLUSIVE "Ansehen" Button
        let assetHtml = '';
        if(art.attachments && art.attachments.length > 0) {
            art.attachments.forEach(a => {
                // Prüfen ob die Datei im Browser darstellbar ist
                let ext = a.file_name.split('.').pop().toLowerCase();
                let isViewable = ['pdf', 'jpg', 'jpeg', 'png', 'gif', 'webp', 'svg'].includes(ext);
                
                let viewBtn = isViewable ? `<a href="${a.file_path}" target="_blank" class="btn btn-sm btn-outline-secondary py-0 px-2 me-1" title="Ansehen"><i class="bi bi-eye"></i></a>` : '';

                assetHtml += `
                <div class="d-flex align-items-center bg-surface border rounded p-1 pe-2 shadow-sm w-100" id="asset_row_${a.id}">
                    <span class="text-truncate px-2" style="font-size:12px; max-width: 300px;"><i class="bi bi-file-earmark me-1"></i> ${a.file_name}</span>
                    <div class="ms-auto d-flex">
                        ${viewBtn}
                        <button type="button" class="btn btn-sm btn-outline-danger py-0 px-2" data-confirmed="0" onclick="confirmDeleteAsset(this, ${a.id})"><i class="bi bi-trash"></i></button>
                    </div>
                </div>`;
            });
            document.getElementById('e_wiki_assets').innerHTML = assetHtml;
            document.getElementById('edit_attachments_container').style.display = 'block';
        } else {
            document.getElementById('edit_attachments_container').style.display = 'none';
        }
        
        if(wikiEditor) wikiEditor.setData(art.content);
        formModal.show();
    }

    // Lese-Ansicht Modal INKLUSIVE "Ansehen" Button
    function openViewModal(art) {
      document.getElementById('view_category').innerText = art.category;
      document.getElementById('view_title').innerText = art.title;
      document.getElementById('view_content').innerHTML = art.content; 
      
      let tagsHtml = '';
      if(art.tags && art.tags.trim() !== '') {
          let tagsArray = art.tags.split(',');
          tagsArray.forEach(t => {
              tagsHtml += `<span class="badge bg-subtle text-muted border me-1 fw-normal">${t.trim()}</span>`;
          });
      }
      document.getElementById('view_tags_container').innerHTML = tagsHtml;
      
      // Downloads anzeigen
      if(art.attachments && art.attachments.length > 0) {
          let attHtml = '';
          art.attachments.forEach(a => {
              let ext = a.file_name.split('.').pop().toLowerCase();
              let isViewable = ['pdf', 'jpg', 'jpeg', 'png', 'gif', 'webp', 'svg'].includes(ext);
              
              attHtml += `<div class="btn-group shadow-sm me-2 mb-2">`;
              if (isViewable) {
                  attHtml += `<a href="${a.file_path}" target="_blank" class="btn btn-sm btn-outline-secondary" title="Im Browser ansehen"><i class="bi bi-eye"></i></a>`;
              }
              attHtml += `<a href="${a.file_path}" download class="btn btn-sm btn-outline-primary fw-bold"><i class="bi bi-download me-1"></i> ${a.file_name}</a>`;
              attHtml += `</div>`;
          });
          document.getElementById('view_attachments_list').innerHTML = attHtml;
          document.getElementById('view_attachments').style.display = 'block';
      } else {
          document.getElementById('view_attachments').style.display = 'none';
      }
      
      new bootstrap.Modal(document.getElementById('viewWikiModal')).show();
      
      // Code Highlighting initialisieren
      setTimeout(() => { 
          Prism.highlightAllUnder(document.getElementById('view_content')); 
      }, 150);
    }

    // SICHERES LÖSCHEN EINES ARTIKELS (Aus der Liste)
    function confirmDeleteArticle(btn, id) {
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

    // SICHERES LÖSCHEN EINES ARTIKELS (Aus dem Edit-Modal)
    function confirmDeleteFromEdit(btn) {
        if(btn.dataset.confirmed === "1") {
            let id = document.getElementById('form_article_id').value;
            let csrfToken = document.querySelector('input[name="csrf_token"]').value;
            let form = document.createElement('form');
            form.method = 'POST';
            form.action = 'wiki';
            form.innerHTML = `<input type="hidden" name="csrf_token" value="${csrfToken}"><input type="hidden" name="action" value="delete_article"><input type="hidden" name="article_id" value="${id}">`;
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

    // SICHERES LÖSCHEN EINER EINZELNEN DATEI (AJAX)
    function confirmDeleteAsset(btn, id) {
        if(btn.dataset.confirmed === "1") {
            btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span>';
            const formData = new FormData();
            formData.append('action', 'delete_wiki_asset');
            formData.append('asset_id', id);
            formData.append('ajax_upload', '1');

            fetch('wiki', { method: 'POST', body: formData })
                .then(r => r.text())
                .then(() => {
                    document.getElementById('asset_row_'+id).remove();
                });
        } else {
            btn.dataset.confirmed = "1";
            btn.innerHTML = 'Sicher?';
            btn.classList.replace('btn-outline-danger', 'btn-danger');
            
            setTimeout(() => { 
                if(document.getElementById('asset_row_'+id)) { 
                    btn.dataset.confirmed = "0"; 
                    btn.innerHTML = '<i class="bi bi-trash"></i>'; 
                    btn.classList.replace('btn-danger', 'btn-outline-danger');
                }
            }, 3000);
        }
    }

    // AJAX UPLOAD MIT FORTSCHRITTSBALKEN
    document.getElementById('wikiMainForm').addEventListener('submit', function(e) {
        e.preventDefault(); 
        
        const saveBtn = document.getElementById('saveWikiBtn');
        saveBtn.disabled = true;
        saveBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Lade...';
        
        if(wikiEditor) document.getElementById('editor').value = wikiEditor.getData();
        
        const formData = new FormData(this);
        formData.append('ajax_upload', '1'); 
        
        const fileInput = document.getElementById('fileInput');
        if(fileInput.files.length > 0) {
            document.getElementById('uploadProgressContainer').style.display = 'block';
        }
        
        const xhr = new XMLHttpRequest();
        xhr.open('POST', 'wiki', true);
        
        xhr.upload.onprogress = function(e) {
            if (e.lengthComputable) {
                const percent = Math.round((e.loaded / e.total) * 100);
                const bar = document.getElementById('uploadProgressBar');
                bar.style.width = percent + '%';
                bar.innerText = percent + '%';
            }
        };
        
        xhr.onload = function() {
            if (xhr.status === 200) {
                try {
                    const resp = JSON.parse(xhr.responseText);
                    if (resp.status === 'partial' && resp.errors && resp.errors.length) {
                        alert('Gespeichert, aber einige Dateien wurden abgelehnt:\n\n' + resp.errors.join('\n'));
                    }
                } catch(e) {} // "OK" ist kein JSON → alles gut
                window.location.reload();
            } else {
                alert('Es gab einen Fehler beim Upload!');
                saveBtn.disabled = false;
                saveBtn.innerHTML = '<i class="bi bi-save me-1"></i> Speichern';
            }
        };
        
        xhr.send(formData);
    });

    // FREIGABE-MODAL ÖFFNEN
    function openShareModal(articleId, sharedIds) {
        document.getElementById('share_article_id').value = articleId;

        // Alle Checkboxen erst deaktivieren, dann die freigegebenen ankreuzen
        document.querySelectorAll('.share-contact-check').forEach(cb => {
            cb.checked = Array.isArray(sharedIds) && sharedIds.includes(parseInt(cb.value));
        });

        new bootstrap.Modal(document.getElementById('shareWikiModal')).show();
    }

  </script>
<?php require 'includes/layout_end.php'; ?>