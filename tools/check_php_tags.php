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
// ---------------------------------------------------------------------
// Zweite Pruefung: ein Oeffnungs-Tag, das keines ist.
//
// "<?php" ist nur dann ein Tag, wenn Leerraum folgt. Fehlt er - etwa
// weil beim Einfuegen eines Blocks der Zeilenumbruch verlorenging und
// "<?phprequire ..." entstand -, dann sieht PHP darin keinen Tag mehr.
//
// Und jetzt kommt das Tueckische: auf einem Server mit short_open_tag=Off
// ist das Ergebnis harmloser Text. Die Datei laesst sich uebersetzen, php
// -l meldet nichts, die Seite laedt - nur der Block dahinter fehlt
// stillschweigend. Auf einem Server mit short_open_tag=On oeffnet "<?"
// den PHP-Modus, "phprequire" ist dann ein unbekannter Bezeichner, und
// die Datei laesst sich gar nicht mehr uebersetzen: HTTP 500, leere
// Seite, kein Hinweis. Dieselbe Datei, zwei Server, zwei Ergebnisse -
// und der Entwicklungsrechner zeigt das freundlichere.
//
// Genau das ist in finances.php passiert und hat einen Abend gekostet.
// Die Pruefung darueber fand es nicht: sie sucht require im Textteil,
// aber ihr Ausdruck schliesst ein require aus, dem ein Buchstabe
// vorausgeht - und in "<?phprequire" geht einer voraus.
//
// Gesucht wird im TEXTteil, nicht im Quelltext: steht "<?" in einer
// Zeichenkette, ist es ein Token und taucht hier gar nicht auf.
$tags = 0;
foreach ($files as $f) {
    $src = file_get_contents($f);
    foreach (token_get_all($src) as $t) {
        if (!is_array($t) || $t[0] !== T_INLINE_HTML) continue;
        // <?xml darf im Textteil stehen - das ist eine XML-Deklaration
        // und kein missglueckter PHP-Tag.
        if (!preg_match('/<\?(?!xml)/', $t[1], $m, PREG_OFFSET_CAPTURE)) continue;

        $stelle  = (int) $m[0][1];
        $ausschnitt = trim(preg_replace('/\s+/', ' ', substr($t[1], $stelle, 60)));
        printf("  %-16s Zeile %-5d  %s\n", $f, $t[2], $ausschnitt);
        $tags++;
        break;
    }
}

if ($tags > 0) {
    echo "\n=> $tags Datei(en) mit einem Oeffnungs-Tag, das PHP nicht als solchen liest.\n"
       . "   Meist fehlt der Leerraum nach <?php. Auf einem Server mit\n"
       . "   short_open_tag=On ist das ein Parse-Fehler und damit HTTP 500.\n";
}

echo $bad === 0 && $tags === 0
    ? "OK: kein PHP-Code ausserhalb der Tags, keine missglueckten Oeffnungs-Tags.\n"
    : '';
exit($bad === 0 && $tags === 0 ? 0 : 1);
