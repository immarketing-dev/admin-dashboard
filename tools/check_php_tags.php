<?php
// Findet PHP-Code, der ausserhalb der PHP-Tags steht und daher als Text
// ausgegeben wird. "php -l" kann das prinzipiell nicht erkennen, weil
// literaler Text immer syntaktisch gueltig ist.
$files = array_merge(glob('*.php'), glob('includes/*.php'), glob('install/*.php'));
sort($files);
$bad = 0;
foreach ($files as $f) {
    $src = file_get_contents($f);
    foreach (token_get_all($src) as $t) {
        if (!is_array($t) || $t[0] !== T_INLINE_HTML) continue;
        $html = $t[1];
        if (preg_match('/^\s*\$[a-z_]+\s*=|(?<![a-z])require(_once)?\s+[\'"]/mi', $html)) {
            $snippet = trim(preg_replace('/\s+/', ' ', substr(ltrim($html), 0, 70)));
            printf("  %-16s Zeile %-5d  %s\n", $f, $t[2], $snippet);
            $bad++;
            break;
        }
    }
}
echo $bad === 0 ? "OK: kein PHP-Code ausserhalb der Tags.\n" : "\n=> $bad Datei(en) betroffen.\n";
exit($bad === 0 ? 0 : 1);
