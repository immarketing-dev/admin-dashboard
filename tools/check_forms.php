<?php
/**
 * Prueft Formulare auf zwei Fehler, die still bleiben:
 * ein csrf_field() im Tag statt darin, und ein POST-Formular ganz ohne Token.
 *
 * Findet Formular-Tags, in denen csrf_field() im Tag selbst gelandet ist.
 *
 * Mein erster Ausdruck suchte "<form ... csrf_field ... >" ohne ein > dazwischen
 * und übersah damit genau den Fall: das ?> einer PHP-Ausgabe im id-Attribut
 * zählt für einen naiven Ausdruck als Tag-Ende. Hier wird deshalb bis zum
 * ersten > gesucht, das NICHT zu einem PHP-Tag gehört.
 */
chdir(dirname(__DIR__));

function tagEnde(string $s, int $von): int
{
    $i = $von;
    $len = strlen($s);
    while ($i < $len) {
        if ($s[$i] === '<' && substr($s, $i, 2) === '<?') {
            $zu = strpos($s, '?>', $i);
            $i = $zu === false ? $len : $zu + 2;
            continue;
        }
        if ($s[$i] === '>') return $i;
        $i++;
    }
    return -1;
}

$kaputt = [];
foreach (array_merge(glob('*.php'), glob('includes/*.php')) as $datei) {
    $s = file_get_contents($datei);
    $pos = 0;
    while (($pos = stripos($s, '<form', $pos)) !== false) {
        $ende = tagEnde($s, $pos);
        if ($ende === -1) break;
        $tag = substr($s, $pos, $ende - $pos + 1);
        if (stripos($tag, 'csrf_field') !== false) {
            $zeile = substr_count(substr($s, 0, $pos), "\n") + 1;
            $kaputt[] = [$datei, $zeile, preg_replace('/\s+/', ' ', $tag)];
        }
        $pos = $ende + 1;
    }
}

if (!$kaputt) { echo "OK: kein Formular-Tag enthaelt csrf_field().\n"; exit(0); }

echo "Formular-Tags mit csrf_field() im Tag:\n\n";
foreach ($kaputt as [$d, $z, $t]) {
    echo "  $d Zeile $z\n    " . substr($t, 0, 130) . "\n";
}
exit(1);
