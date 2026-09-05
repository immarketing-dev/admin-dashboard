<?php
/**
 * Prueft, dass keine Ausgabe eine Variable ungefiltert in HTML schreibt.
 *
 * Die Suite prueft Syntax, CSRF, Soft-Delete und Uebersetzungen - aber
 * nichts prueft, ob ein Wert vor der Ausgabe gefiltert wird. Gefunden
 * wurden so unter anderem:
 *
 *   - contacts.php gab E-Mail, Telefon und Website ungefiltert aus, und
 *     zwar zusaetzlich in einem href. Ein Anfuehrungszeichen im Feld
 *     beendet dort das Attribut.
 *   - tasks.php und finances.php schrieben Kundennamen roh in <option>
 *     und in drei data-Attribute, aus denen JavaScript das
 *     Rechnungsformular fuellt.
 *
 * Das ist kein theoretischer Fall. includes/api_leads.php nimmt Anfragen
 * von aussen entgegen und prueft nur die E-Mail-Adresse auf ihr Format;
 * Name und Telefon werden lediglich gekuerzt. index.php uebernimmt eine
 * angenommene Anfrage unveraendert in contacts. Der Weg von aussen bis in
 * die Ausgabe war damit offen.
 *
 * Vorgehen: token_get_all() liefert jede echte Ausgabe-Anweisung;
 * Kommentare und Zeichenketten zaehlen nicht mit. Fuer jede wird der
 * Ausdruck eingesammelt und gefragt, ob eine Variable ohne erkennbare
 * Absicherung in die Ausgabe geht.
 *
 * Gemeldet wird nur, was auch schaden kann: eine Superglobale, oder ein
 * Feld, dessen Name auf Freitext hindeutet. Eine Zahl ist harmlos, und
 * ein Prueflauf, der 500 Zeilen meldet, wird nicht gelesen.
 *
 * Aufruf: php tools/check_output_escaping.php
 */

chdir(dirname(__DIR__));

// Funktionen, deren Ergebnis gefahrlos in HTML darf.
$sicher = [
    'htmlspecialchars', 'htmlentities', 'te', 'tjs', 'urlencode', 'rawurlencode',
    'number_format', 'intval', 'floatval', 'count', 'json_encode', 'date',
    'strtotime', 'round', 'abs', 'max', 'min', 'sprintf', 'implode', 'nl2br',
];

// Helfer, die absichtlich fertiges HTML liefern. Sie filtern ihre Werte
// selbst; eine zweite Filterung hier wuerde ihre Tags zerlegen.
$html_absicht = ['status_badge', 'csrf_field', 'asset', 'balken', 'deadline_badge'];

// Feldnamen, hinter denen Freitext steht. Bewusst unvollstaendig: die
// Liste soll melden, was erfahrungsgemaess Text enthaelt, nicht jede
// denkbare Spalte.
$textfelder = 'name|title|subject|email|company|notes|description|message|street|city|zip'
            . '|phone|website|file_name|custom_name|author_name|url_name|url_link|intro_text'
            . '|content|body|kunde|label|status|category|source|location|recipient|error'
            . '|context|invoice_number|quote_number|vat_id|buyer_reference|uploaded_by_name'
            . '|feedback_by_name|client_feedback|client_name|contact_name|ms_title|task_title';

// Begruendete Ausnahmen: Datei => [[Textstueck, Grund], ...]. Eine
// Ausnahme soll eine Entscheidung bleiben und kein Versehen - deshalb
// steht der Grund daneben.
$ausnahmen = [];

$dateien = array_merge(glob('*.php'), glob('includes/*.php'), glob('api/*.php'));
$funde   = [];

foreach ($dateien as $datei) {
    $token  = token_get_all(file_get_contents($datei));
    $anzahl = count($token);

    for ($i = 0; $i < $anzahl; $i++) {
        $t = $token[$i];
        if (!is_array($t)) continue;
        if ($t[0] !== T_OPEN_TAG_WITH_ECHO && $t[0] !== T_ECHO && $t[0] !== T_PRINT) continue;

        $zeile    = $t[2];
        $ausdruck = '';
        $tiefe    = 0;
        for ($j = $i + 1; $j < $anzahl; $j++) {
            $u = $token[$j];
            if (is_string($u)) {
                if ($u === '(') $tiefe++;
                if ($u === ')') $tiefe--;
                if ($u === ';' && $tiefe <= 0) break;
                $ausdruck .= $u;
                continue;
            }
            if ($u[0] === T_CLOSE_TAG) break;
            $ausdruck .= $u[1];
        }
        $i = $j;

        $ausdruck = trim($ausdruck);
        if ($ausdruck === '' || strpos($ausdruck, '$') === false) continue;

        foreach (array_merge($html_absicht, $sicher) as $f) {
            if (preg_match('/\b' . $f . '\s*\(/', $ausdruck)) continue 2;
        }
        if (preg_match('/\(\s*(int|integer|float|double|bool)\s*\)/', $ausdruck)) continue;

        // Ein Fragezeichen, das weder ?? noch ?: ist: was dahinter steht,
        // ist die Ausgabe. Steht dort keine Variable, kommt nur ein
        // Literal heraus - die Variable stand in der Bedingung.
        if (preg_match('/(?<!\?)\?(?![?:])/', $ausdruck, $m, PREG_OFFSET_CAPTURE)) {
            if (strpos(substr($ausdruck, $m[0][1]), '$') === false) continue;
        }

        $superglobale   = preg_match_all('/\$_(GET|POST|REQUEST|COOKIE|SERVER)\b/', $ausdruck);
        $als_schluessel = preg_match_all('/\$\w+\s*\[\s*\$_(GET|POST|REQUEST|COOKIE|SERVER)\b/', $ausdruck);

        // Fuer die Einstufung zaehlt nur, was ausgegeben wird. Ein
        // Schluessel traegt oft selbst einen Feldnamen und wuerde sonst
        // als Textfeld gelten.
        $muster_schluessel = '/[$]_(GET|POST|REQUEST|COOKIE|SERVER)\s*\[[^\]]*\]/';
        $rest = preg_replace($muster_schluessel, '', $ausdruck);

        $stufe = null;
        if ($superglobale > 0 && $superglobale > $als_schluessel) {
            $stufe = 'Superglobale';
        } elseif (preg_match('/\[\s*[\'"](' . $textfelder . ')[\'"]\s*\]/i', $rest)) {
            $stufe = 'Textfeld';
        }
        if ($stufe === null) continue;

        foreach ($ausnahmen[$datei] ?? [] as [$stueck, $grund]) {
            if (strpos($ausdruck, $stueck) !== false) continue 2;
        }

        $funde[] = [
            'datei'    => $datei,
            'zeile'    => $zeile,
            'stufe'    => $stufe,
            'ausdruck' => preg_replace('/\s+/', ' ', $ausdruck),
        ];
    }
}

echo "=== Pruefung: keine ungefilterte Ausgabe ===\n";

if ($funde === []) {
    echo 'OK: ' . count($dateien) . " Dateien geprueft, jede Ausgabe eines Textfelds ist gefiltert.\n";
    exit(0);
}

foreach ($funde as $f) {
    printf("FEHLER: %s:%d  [%s]\n        %s\n",
        $f['datei'], $f['zeile'], $f['stufe'], mb_strimwidth($f['ausdruck'], 0, 100, '...'));
}
echo "\n" . count($funde) . " ungefilterte Ausgabe(n). htmlspecialchars() davor,\n"
   . "oder - wenn der Wert aus der Datenbank kommt und uebersetzt gehoert -\n"
   . "htmlspecialchars(datenwert(...)).\n";
exit(1);
