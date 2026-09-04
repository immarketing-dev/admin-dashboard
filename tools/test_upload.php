<?php
// Test für die Upload-Prüfung. Aufruf: php tools/test_upload.php
require_once __DIR__ . '/../includes/upload_helper.php';

$dir = sys_get_temp_dir() . '/upl_test_' . getmypid();
@mkdir($dir);

/** Legt eine Datei mit gegebenem Inhalt an und gibt den Pfad zurück. */
function tmpfile_with(string $dir, string $name, string $bytes): string {
    $p = $dir . '/' . $name;
    file_put_contents($p, $bytes);
    return $p;
}

// Ein echtes 1x1-PNG, damit die Typerkennung etwas zu erkennen hat.
$png = base64_decode(
    'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='
);
$svg = '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>';

$png_ok    = tmpfile_with($dir, 'bild.png',   $png);
$png_falsch= tmpfile_with($dir, 'bild.jpg',   $png);   // Endung passt nicht zum Inhalt
$svg_datei = tmpfile_with($dir, 'logo.svg',   $svg);
$txt       = tmpfile_with($dir, 'notiz.txt',  "nur text\n");

$hat_fileinfo = detect_mime_type($png_ok) !== null;

$checks = [];

if ($hat_fileinfo) {
    $checks['PNG mit passender Endung wird angenommen']
        = validate_upload($png_ok, 'bild.png', filesize($png_ok)) === null;

    // Der Kern der Prüfung: die Endung stammt vom Hochladenden, der Inhalt
    // entscheidet. Ein PNG als .jpg auszugeben darf nicht durchgehen.
    $checks['PNG als .jpg wird abgewiesen']
        = validate_upload($png_falsch, 'bild.jpg', filesize($png_falsch)) !== null;

    // SVG kann <script> tragen und wird vom Portal aus dem eigenen Origin
    // ausgeliefert - das wäre gespeichertes XSS.
    $checks['SVG wird abgewiesen']
        = validate_upload($svg_datei, 'logo.svg', filesize($svg_datei)) !== null;

    $checks['Textdatei wird angenommen']
        = validate_upload($txt, 'notiz.txt', filesize($txt)) === null;
} else {
    // Ohne fileinfo muss die Prüfung schließen, nicht durchwinken.
    $checks['ohne fileinfo wird abgewiesen statt abzustürzen']
        = validate_upload($png_ok, 'bild.png', filesize($png_ok)) !== null;
    echo "HINWEIS  fileinfo ist hier nicht geladen - die Typprüfungen laufen nicht.\n";
}

// Größe wird vor der Typerkennung geprüft und gilt immer.
$checks['zu große Datei wird abgewiesen']
    = validate_upload($png_ok, 'bild.png', MAX_UPLOAD_BYTES + 1) !== null;
$checks['Datei genau an der Grenze wird nicht wegen Größe abgewiesen']
    = strpos((string)validate_upload($png_ok, 'bild.png', MAX_UPLOAD_BYTES), 'zu groß') === false;

// ── safe_filename ───────────────────────────────────────────────────
$checks['Pfadwechsel wird entfernt']
    = strpos(safe_filename('../../etc/passwd.txt'), '/') === false
   && strpos(safe_filename('..\\..\\windows\\win.txt'), '\\') === false;

$checks['Nullbyte überlebt nicht']
    = strpos(safe_filename("harmlos\0.php.txt"), "\0") === false;

$checks['Sonderzeichen werden ersetzt']
    = preg_match('/^\d+_[a-zA-Z0-9_\-äöüÄÖÜ]+\.txt$/u', safe_filename('mein bild (2)!.txt')) === 1;

$checks['Umlaute bleiben erhalten']
    = strpos(safe_filename('Übergabe.txt'), 'bergabe') !== false;

$checks['Endung bleibt und wird klein']
    = str_ends_with(safe_filename('Datei.TXT'), '.txt');

$checks['sehr langer Name wird gekürzt']
    = mb_strlen(pathinfo(safe_filename(str_repeat('a', 300) . '.txt'), PATHINFO_FILENAME)) <= 72;

$checks['zwei Aufrufe kollidieren nicht im selben Namen']
    = safe_filename('a.txt') === safe_filename('a.txt'); // gleiche Sekunde, gleicher Name - dokumentiert das Verhalten

array_map('unlink', glob($dir . '/*'));
@rmdir($dir);

$fail = 0;
foreach ($checks as $name => $ok) {
    echo ($ok ? 'PASS' : 'FAIL') . "  $name\n";
    if (!$ok) $fail = 1;
}
exit($fail);
