<?php
/**
 * MySQL-Funktionen, die die lokale Demo-Instanz zusätzlich braucht.
 *
 * tools/lib_sqlite_mirror.php deckt ab, was Seed und Tests aufrufen. Die
 * Seiten selbst rufen mehr auf — DATEDIFF für die Fristen auf der
 * Startseite, FIELD für die Reihenfolge der Ticket-Prioritäten,
 * DATE_FORMAT in den Auswertungen. Ohne sie bleiben genau die drei
 * Seiten dunkel, die man sich ansehen wollte.
 *
 * Gehört zu tools/serve_demo.php und ist nicht Teil der Auslieferung.
 */

function serve_demo_funktionen(PDO $pdo): void
{
    // sqliteCreateFunction() ist in PHP 8.5 als veraltet markiert. Einen
    // Ersatz gibt es nicht, also die Meldung nur hier stummschalten.
    $stufe = error_reporting();
    error_reporting($stufe & ~E_DEPRECATED);

    $pdo->sqliteCreateFunction('DATEDIFF', static function ($a, $b) {
        if ($a === null || $b === null) return null;
        $ta = strtotime(substr((string) $a, 0, 10));
        $tb = strtotime(substr((string) $b, 0, 10));
        if ($ta === false || $tb === false) return null;
        return (int) round(($ta - $tb) / 86400);
    }, 2);

    $pdo->sqliteCreateFunction('TIMESTAMPDIFF', static function ($einheit, $a, $b) {
        if ($a === null || $b === null) return null;
        $d = strtotime((string) $b) - strtotime((string) $a);
        return match (strtoupper((string) $einheit)) {
            'SECOND' => (int) $d,
            'MINUTE' => intdiv((int) $d, 60),
            'HOUR'   => intdiv((int) $d, 3600),
            'DAY'    => intdiv((int) $d, 86400),
            'WEEK'   => intdiv((int) $d, 604800),
            'MONTH'  => (int) floor($d / 2629746),
            'YEAR'   => (int) floor($d / 31556952),
            default  => null,
        };
    }, 3);

    $pdo->sqliteCreateFunction('DATE_FORMAT', static function ($wert, $format) {
        if ($wert === null) return null;
        $t = strtotime((string) $wert);
        if ($t === false) return null;

        $karte = [
            'Y' => 'Y', 'y' => 'y', 'm' => 'm', 'c' => 'n', 'd' => 'd',
            'e' => 'j', 'H' => 'H', 'k' => 'G', 'i' => 'i', 's' => 's',
            'b' => 'M', 'M' => 'F', 'a' => 'D', 'W' => 'l', 'p' => 'A',
        ];
        $aus    = '';
        $format = (string) $format;
        for ($i = 0; $i < strlen($format); $i++) {
            if ($format[$i] === '%' && isset($format[$i + 1])) {
                $z    = $format[$i + 1];
                $aus .= isset($karte[$z]) ? date($karte[$z], $t) : $z;
                $i++;
            } else {
                $aus .= $format[$i];
            }
        }
        return $aus;
    }, 2);

    // YEAR() schreibt die Spiegelung nur um, wenn das Argument ein
    // schlichter Spaltenname ist. YEAR(NOW()) fällt durch.
    foreach ([
        'YEAR' => 'Y', 'MONTH' => 'n', 'DAY' => 'j', 'DAYOFMONTH' => 'j',
        'HOUR' => 'G', 'MINUTE' => 'i', 'WEEK' => 'W', 'DAYOFWEEK' => 'w',
    ] as $name => $fmt) {
        $pdo->sqliteCreateFunction($name, static function ($wert) use ($fmt) {
            if ($wert === null) return null;
            $t = strtotime((string) $wert);
            return $t === false ? null : (int) date($fmt, $t);
        }, 1);
    }

    $pdo->sqliteCreateFunction('QUARTER', static function ($wert) {
        $t = strtotime((string) $wert);
        return $t === false ? null : (int) ceil((int) date('n', $t) / 3);
    }, 1);

    $pdo->sqliteCreateFunction('LAST_DAY', static function ($wert) {
        $t = strtotime((string) $wert);
        return $t === false ? null : date('Y-m-t', $t);
    }, 1);

    // FIELD(x, a, b, c): Sortierung nach einer festen Liste, in
    // tickets.php für die Reihenfolge der Prioritäten.
    $pdo->sqliteCreateFunction('FIELD', static function (...$a) {
        $wert = array_shift($a);
        $i    = array_search($wert, $a, false);
        return $i === false ? 0 : $i + 1;
    }, -1);

    $pdo->sqliteCreateFunction('LOCATE', static function ($nadel, $heu) {
        $p = strpos((string) $heu, (string) $nadel);
        return $p === false ? 0 : $p + 1;
    }, 2);

    $pdo->sqliteCreateFunction('LPAD', static fn($s, $l, $p) => str_pad((string) $s, (int) $l, (string) $p, STR_PAD_LEFT), 3);
    $pdo->sqliteCreateFunction('RPAD', static fn($s, $l, $p) => str_pad((string) $s, (int) $l, (string) $p, STR_PAD_RIGHT), 3);
    $pdo->sqliteCreateFunction('GREATEST', static fn($a, $b) => max($a, $b), 2);
    $pdo->sqliteCreateFunction('LEAST', static fn($a, $b) => min($a, $b), 2);
    $pdo->sqliteCreateFunction('MD5', static fn($s) => md5((string) $s), 1);

    error_reporting($stufe);
}
