<?php
// Erlaubte MIME-Typen und zugehörige Endungen
const ALLOWED_MIME_TYPES = [
    'image/jpeg'      => ['jpg', 'jpeg'],
    'image/png'       => ['png'],
    'image/gif'       => ['gif'],
    'image/webp'      => ['webp'],
    // SVG bewusst NICHT erlaubt: SVGs koennen <script> und Event-Handler
    // enthalten und werden vom Portal aus dem eigenen Origin ausgeliefert -
    // ein SVG-Upload waere gespeichertes XSS. Nicht wieder hinzufuegen,
    // ohne serverseitiges Sanitizing des SVG-Inhalts.
    'application/pdf' => ['pdf'],
    'application/zip' => ['zip'],
    'application/x-zip-compressed' => ['zip'],
    'text/plain'      => ['txt', 'log', 'csv'],
    'text/csv'        => ['csv'],
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document'   => ['docx'],
    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'         => ['xlsx'],
    'application/vnd.openxmlformats-officedocument.presentationml.presentation' => ['pptx'],
    'application/msword'                    => ['doc'],
    'application/vnd.ms-excel'              => ['xls'],
    'application/vnd.ms-powerpoint'         => ['ppt'],
    'video/mp4'    => ['mp4'],
    'video/webm'   => ['webm'],
    'audio/mpeg'   => ['mp3'],
    'audio/ogg'    => ['ogg'],
];

const MAX_UPLOAD_BYTES = 20 * 1024 * 1024; // 20 MB

/**
 * Prüft eine hochgeladene Datei auf MIME-Typ, Endung und Größe.
 * Gibt null bei Erfolg zurück, sonst eine Fehlermeldung.
 */
function validate_upload(string $tmp_name, string $original_name, int $file_size): ?string {
    if ($file_size > MAX_UPLOAD_BYTES) {
        return "Datei zu groß (max. 20 MB): " . htmlspecialchars($original_name);
    }

    $ext  = strtolower(pathinfo($original_name, PATHINFO_EXTENSION));
    $mime = mime_content_type($tmp_name);

    if (!isset(ALLOWED_MIME_TYPES[$mime])) {
        return "Nicht erlaubter Dateityp ($mime): " . htmlspecialchars($original_name);
    }

    if (!in_array($ext, ALLOWED_MIME_TYPES[$mime], true)) {
        return "Dateiendung passt nicht zum Typ: " . htmlspecialchars($original_name);
    }

    return null;
}

/**
 * Erstellt einen sicheren Dateinamen (kein Path-Traversal, keine Sonderzeichen).
 */
function safe_filename(string $original_name): string {
    $name = pathinfo($original_name, PATHINFO_FILENAME);
    $ext  = strtolower(pathinfo($original_name, PATHINFO_EXTENSION));
    $name = preg_replace('/[^a-zA-Z0-9_\-äöüÄÖÜ]/', '_', $name);
    return time() . '_' . mb_substr($name, 0, 60) . '.' . $ext;
}
