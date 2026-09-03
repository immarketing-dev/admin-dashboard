<?php
/**
 * Erzeugt aus dem Repository einen sauberen Upload-Ordner und sagt, welche
 * Dateien sich seit dem letzten Durchlauf geaendert haben.
 *
 * Aufruf aus dem Repo-Wurzelverzeichnis:
 *   php tools/deploy.php ../admin-dashboard
 *   php tools/deploy.php ../admin-dashboard --dry-run
 *
 * Warum es das gibt: Repository und Server unterscheiden sich in genau drei
 * Punkten - das Repo hat keine .env, seine uploads/ sind leer, und mehrere
 * Verzeichnisse gehoeren nicht ins Webroot. Diese drei Punkte von Hand
 * richtig zu behandeln geht so lange gut, bis es einmal nicht gut geht.
 * Beim manuellen Abgleich wanderte einmal tools/leakscan-local.txt mit auf
 * den Server - ausgerechnet die Datei, die auflistet, was niemals
 * veroeffentlicht werden darf.
 *
 * Was der Ordner NICHT bekommt: .git, .github, docs, tools und die
 * Entwickler-Dotfiles. Was er behaelt: eine vorhandene .env und alles unter
 * uploads/ - dort liegen Kundendaten, die es nirgendwo sonst gibt.
 */

$root   = dirname(__DIR__);
$target = $argv[1] ?? null;
$dryRun = in_array('--dry-run', $argv, true);

if ($target === null) {
    fwrite(STDERR, "Aufruf: php tools/deploy.php <zielordner> [--dry-run]\n");
    exit(1);
}
$target = rtrim(str_replace('\\', '/', $target), '/');
if (!is_dir($target)) {
    fwrite(STDERR, "Zielordner existiert nicht: $target\n");
    exit(1);
}

// ── 1. Nicht ohne gruene Pruefungen ausliefern ──────────────────────
// Ein Deployment ist der teuerste Zeitpunkt, um einen Fehler zu entdecken.
echo "=== Pruefungen ===\n";
$checks = [
    'Harness'    => 'bash tools/check.sh',
    'Schema'     => 'php tools/check_schema.php',
    'PHP-Tags'   => 'php tools/check_php_tags.php',
    'Session'    => 'php tools/test_session.php',
    '.env-Parser'=> 'php tools/test_env.php',
];
$blocked = false;
foreach ($checks as $name => $cmd) {
    exec("cd " . escapeshellarg($root) . " && $cmd 2>&1", $out, $code);
    printf("  %-12s %s\n", $name, $code === 0 ? 'OK' : 'FEHLGESCHLAGEN');
    if ($code !== 0) {
        $blocked = true;
        foreach (array_slice($out, -6) as $l) echo "      $l\n";
    }
    $out = [];
}
if ($blocked) {
    fwrite(STDERR, "\nAbbruch: Es wird nichts ausgeliefert, solange eine Pruefung rot ist.\n");
    exit(1);
}

// ── 2. Was gehoert nicht auf den Server ─────────────────────────────
$excludeDirs = ['.git', '.github', 'docs', 'tools', '.superpowers'];
$excludeFiles = ['.gitignore', '.gitattributes', '.editorconfig'];

$files = [];
$it = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::SELF_FIRST
);
foreach ($it as $path) {
    $rel = str_replace('\\', '/', substr($path->getPathname(), strlen($root) + 1));
    $top = explode('/', $rel)[0];
    if (in_array($top, $excludeDirs, true)) continue;
    if ($path->isDir()) continue;
    if (in_array($rel, $excludeFiles, true)) continue;
    // uploads/ nur als Struktur - Inhalte gehoeren dem Server
    if (str_starts_with($rel, 'uploads/') && !str_ends_with($rel, '.htaccess') && !str_ends_with($rel, '.gitkeep')) continue;
    $files[$rel] = md5_file($path->getPathname());
}
ksort($files);

// ── 3. Vergleich mit dem letzten Durchlauf ──────────────────────────
$manifestPath = $target . '/.deploy-manifest.json';
$previous = is_readable($manifestPath)
    ? (json_decode(file_get_contents($manifestPath), true) ?: [])
    : [];

$new = $changed = $gone = [];
foreach ($files as $rel => $hash) {
    if (!isset($previous[$rel]))            $new[] = $rel;
    elseif ($previous[$rel] !== $hash)      $changed[] = $rel;
}
foreach ($previous as $rel => $hash) {
    if (!isset($files[$rel])) $gone[] = $rel;
}

// ── 4. Kopieren ─────────────────────────────────────────────────────
if (!$dryRun) {
    foreach ($files as $rel => $hash) {
        $dst = $target . '/' . $rel;
        $dir = dirname($dst);
        if (!is_dir($dir)) mkdir($dir, 0755, true);
        copy($root . '/' . $rel, $dst);
    }
    file_put_contents($manifestPath, json_encode($files, JSON_PRETTY_PRINT));
}

// ── 5. Bericht ──────────────────────────────────────────────────────
echo "\n=== " . ($dryRun ? 'Vorschau (nichts geschrieben)' : 'Ziel aktualisiert') . ": $target ===\n";
echo count($files) . " Dateien im Auslieferungsstand\n";

if (!$previous) {
    echo "\nKein frueherer Durchlauf gefunden - beim ersten Mal alles hochladen.\n";
} elseif (!$new && !$changed && !$gone) {
    echo "\nKeine Aenderungen seit dem letzten Durchlauf. Nichts hochzuladen.\n";
} else {
    if ($new) {
        echo "\nNEU hochladen (" . count($new) . "):\n";
        foreach ($new as $f) echo "  + $f\n";
    }
    if ($changed) {
        echo "\nGEAENDERT, hochladen (" . count($changed) . "):\n";
        foreach ($changed as $f) echo "  ~ $f\n";
    }
    if ($gone) {
        echo "\nAuf dem Server LOESCHEN (" . count($gone) . "):\n";
        foreach ($gone as $f) echo "  - $f\n";
        echo "\n  Hinweis: Ein Upload entfernt nichts. Diese Dateien muessen von\n";
        echo "  Hand geloescht werden, sonst bleiben sie erreichbar.\n";
    }
}

// ── 6. Was der Zielordner selbst mitbringt ──────────────────────────
echo "\n=== Unberuehrt im Zielordner ===\n";
printf("  %-24s %s\n", '.env',
    is_file($target . '/.env') ? 'vorhanden' : 'FEHLT - ohne sie laeuft nichts');
$kunden = 0;
foreach (glob($target . '/uploads/*/*') as $p) {
    if (is_file($p) && !str_ends_with($p, '.htaccess') && !str_ends_with($p, '.gitkeep')) $kunden++;
}
printf("  %-24s %d Datei(en)\n", 'uploads/ (Kundendaten)', $kunden);

echo "\nNICHT im Auslieferungsstand: " . implode(', ', $excludeDirs) . "\n";
exit(0);
