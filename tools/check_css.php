<?php
// Prueft das neue Stylesheet (assets/css/tokens.css + assets/css/app.css).
//
// Zwei Arten von Pruefungen, mit unterschiedlicher Lebensdauer:
//
//   Parity-Pruefungen (1, 2, 5) vergleichen app.css/tokens.css gegen die
//   urspruengliche, vor dem Token-Umbau handgeschriebene design.css. Das
//   war ein EINMALIGES Migrationshilfsmittel fuer den Umbau (Teil B) und
//   braucht eine Kopie von design.css - die ist NICHT Teil dieses
//   oeffentlichen Repos (sie wird nach der Migration nicht mehr
//   gebraucht und war ohnehin nur in der privaten Installation
//   vorhanden). Ohne diese Kopie werden die drei Pruefungen mit einem
//   klaren Hinweis uebersprungen statt falsch-gruen durchzulaufen.
//
//   Struktur-Pruefungen (3, 4, 6) brauchen keine design.css und laufen
//   IMMER: keine Rohfarben ausserhalb von tokens.css, keine undefinierten
//   Tokens, [data-theme] nur noch im klar markierten Bootstrap-Override-
//   Abschnitt. Das sind die Pruefungen, die fuer die laufende Pflege des
//   Projekts relevant bleiben, lange nachdem die Migration abgeschlossen
//   ist - sie laufen deshalb unabhaengig davon, ob eine Baseline
//   angegeben wurde.
//
// Warum ueberhaupt so ausfuehrlich: In dieser Umgebung gibt es keinen
// Browser und keinen Web-Server, das Ergebnis kann also nicht per
// Sichtpruefung kontrolliert werden. Dieses Skript ersetzt die
// Sichtpruefung fuer alles, was sich textuell aus dem CSS ableiten laesst:
// verlorene Selektoren, verlorene Eigenschaften, liegen gebliebene
// Rohfarben, undefinierte Tokens und Dark-Mode-Overrides, die beim Umbau
// auf Tokens vergessen wurden. Es ist bewusst ein simpler, regelbasierter
// Parser (kein vollstaendiger CSS-Parser) - das genuegt fuer den hier
// vorliegenden, handgeschriebenen CSS-Stil.
//
// Pruefung 2 und 5 vergleichen nicht nur, OB eine Eigenschaft ein Token
// verwendet, sondern loesen var(--x) rekursiv gegen die Tokens in
// tokens.css auf (fuer Pruefung 5 zusaetzlich gegen die Ueberschreibungen
// im [data-theme="dark"]-Block) und vergleichen den AUFGELOESTEN,
// normalisierten Wert mit dem Original. --color-primary und
// --color-sidebar bleiben dabei bewusst UNAUFGELOEST (als "var(--name)"
// stehend): ihr tatsaechlicher Wert wird erst zur Laufzeit von
// includes/theme.php gesetzt, tokens.css enthaelt dafuer nur einen
// Platzhalter-Default. Direkte Verwendung (z.B. "color:
// var(--color-primary)") bleibt trotzdem mechanisch vergleichbar, weil
// design.css an derselben Stelle woertlich dieselbe Variable
// referenziert. Wird die Markenfarbe aber rechnerisch gebraucht
// (color-mix(), s.u.), laesst sich kein konkreter Wert ermitteln - dieser
// Fall wird explizit als "nicht maschinell vergleichbar" gemeldet statt
// stillschweigend als OK durchzuwinken.
//
// Teil B, Runde 1: nicht jeder [data-theme="dark"]-Override im Original
// laesst sich durch eine Token-Redefinition ersetzen - fuer Bootstrap-
// Komponenten (.table, .modal-content, .alert-*, ...) gibt es in
// design.css keine eigene Hell-Regel, also auch kein Token zum
// Umdefinieren. Diese Faelle bleiben bewusst als [data-theme]-Regeln
// bestehen, aber ausschliesslich in einem eigenen, klar markierten
// Abschnitt am Ende von app.css (Bannermarke s.u.) und ausschliesslich
// mit Tokens statt Rohfarben. Pruefung 5 akzeptiert deshalb zwei Wege der
// Abdeckung (Token-Redefinition ODER portierte Regel nach der Bannermarke)
// und meldet je Override, welcher Weg gegriffen hat. Pruefung 6 verbietet
// [data-theme] nur noch VOR der Bannermarke.
//
// Aufruf: php tools/check_css.php [pfad/zur/design.css]
//   Ohne Argument laufen nur die Struktur-Pruefungen (3, 4, 6).
//   Mit Argument (Pfad zu einer Kopie der alten design.css) laufen
//   zusaetzlich die Parity-Pruefungen (1, 2, 5).

$root          = dirname(__DIR__);
$appCssPath    = $root . '/assets/css/app.css';
$tokensCssPath = $root . '/assets/css/tokens.css';

// app.css und tokens.css sind das eigentliche Pruefobjekt - ohne sie kann
// keine einzige Pruefung sinnvoll etwas aussagen. Ein fehlendes
// Pruefobjekt ist ein Fehlerzustand, kein "nichts zu tun": sonst wuerde
// z.B. ein versehentlich umbenanntes app.css das ganze Skript
// stillschweigend gruen durchlaufen lassen.
if (!is_readable($appCssPath)) {
    fwrite(STDERR, "FEHLER: assets/css/app.css nicht gefunden oder nicht lesbar ($appCssPath).\n");
    exit(1);
}
if (!is_readable($tokensCssPath)) {
    fwrite(STDERR, "FEHLER: assets/css/tokens.css nicht gefunden oder nicht lesbar ($tokensCssPath).\n");
    exit(1);
}

// Baseline (design.css vor dem Token-Umbau) ist optional und kommt
// ausschliesslich per Kommandozeilenargument - kein fest verdrahteter
// Pfad mehr. Ein fest verdrahteter Pfad waere ein privater Maschinenpfad
// in einem oeffentlichen Repo und in jedem Klon ohnehin ungueltig, da
// design.css nicht mitveroeffentlicht wird.
$baselinePath = $argv[1] ?? null;
$hasBaseline  = false;

if ($baselinePath !== null) {
    if (!is_readable($baselinePath)) {
        fwrite(STDERR, "FEHLER: Baseline-Datei nicht gefunden oder nicht lesbar: $baselinePath\n");
        exit(1);
    }
    $hasBaseline = true;
}

$fail = 0;

// ---------------------------------------------------------------------
// Hilfsfunktionen: minimaler, regelbasierter CSS-Parser
// ---------------------------------------------------------------------

// Ersetzt /* ... */-Kommentare durch Leerzeichen (Zeilenumbrueche bleiben
// erhalten), damit Zeilennummern fuer Pruefung 3 stabil bleiben und
// Kommentartext nie versehentlich als Regel/Farbe/Token gelesen wird.
function strip_css_comments(string $css): string
{
    return (string) preg_replace_callback(
        '/\/\*.*?\*\//s',
        static function (array $m): string {
            return (string) preg_replace('/[^\n]/', ' ', $m[0]);
        },
        $css
    );
}

// Zerlegt einen String am angegebenen Trennzeichen, aber nur auf oberster
// Klammerebene (Klammerinhalte wie rgba(0,0,0,.04) oder :not(.a, .b)
// werden nicht aufgebrochen). Wird sowohl fuer Selektorlisten (Trenner
// ',') als auch fuer Deklarationslisten (Trenner ';') verwendet.
function split_top_level(string $s, string $delim): array
{
    $parts = [];
    $depth = 0;
    $cur   = '';
    $len   = strlen($s);
    for ($i = 0; $i < $len; $i++) {
        $ch = $s[$i];
        if ($ch === '(') {
            $depth++;
        } elseif ($ch === ')') {
            $depth--;
        }
        if ($ch === $delim && $depth === 0) {
            $parts[] = $cur;
            $cur     = '';
            continue;
        }
        $cur .= $ch;
    }
    $parts[] = $cur;
    return array_map('trim', $parts);
}

// Findet die zur oeffnenden Klammer an $openPos passende schliessende
// Klammer per Tiefenzaehlung (fuer verschachtelte @media/@keyframes-
// Bloecke).
function find_matching_brace(string $css, int $openPos): int
{
    $depth = 0;
    $len   = strlen($css);
    for ($i = $openPos; $i < $len; $i++) {
        if ($css[$i] === '{') {
            $depth++;
        } elseif ($css[$i] === '}') {
            $depth--;
            if ($depth === 0) {
                return $i;
            }
        }
    }
    return -1;
}

// Normalisiert einen Selektor fuer den Vergleich: mehrfachen Whitespace
// auf ein Leerzeichen reduzieren, Leerzeichen um Kombinatoren (> + ~)
// vereinheitlichen, trimmen.
function normalize_selector(string $sel): string
{
    $sel = trim($sel);
    $sel = (string) preg_replace('/\s+/', ' ', $sel);
    $sel = (string) preg_replace('/\s*([>+~])\s*/', ' $1 ', $sel);
    $sel = (string) preg_replace('/\s+/', ' ', $sel);
    return trim($sel);
}

// Zerlegt kommentarbereinigtes CSS in eine flache Liste von Regeln
// ['selector' => normalisierter Selektor, 'props' => [name => wert]].
//
// - @media-Bloecke werden rekursiv aufgeloest: ihre Selektoren landen wie
//   normale Top-Level-Selektoren in der Liste. Die Media-Query-Grenze
//   selbst wird NICHT geprueft - eine Regel, die im Original in einem
//   @media-Block stand, gilt hier schon als "erhalten", wenn sie
//   irgendwo in app.css denselben Selektor/dieselben Eigenschaften hat.
//   Das ist eine bewusste Vereinfachung, siehe Bericht.
// - Andere At-Rules (@keyframes, @font-face, ...) werden als EIN
//   Selektor-Eintrag behandelt (Praeludium als "Selektor", alle darin
//   gefundenen Deklarationen flach eingesammelt) statt ihre inneren
//   Prozent-/Stop-Selektoren einzeln abzubilden - die sind kein normales
//   CSS-Selektor-Vokabular und wuerden Kollisionen erzeugen.
// - Mehrfache, kommagetrennte Selektoren einer Regel werden aufgeteilt.
// - Kommt derselbe Selektor mehrfach vor, erscheint er mehrfach in der
//   Liste; der Aufrufer fasst das zu einer Vereinigungsmenge zusammen.
function parse_css_rules(string $css): array
{
    $rules = [];
    $len   = strlen($css);
    $pos   = 0;

    while ($pos < $len) {
        $bracePos = strpos($css, '{', $pos);
        if ($bracePos === false) {
            break;
        }

        $preludeTrim = trim(substr($css, $pos, $bracePos - $pos));
        if ($preludeTrim === '') {
            // Sollte bei gueltigem CSS nicht vorkommen - Sicherheitsnetz
            // gegen eine Endlosschleife bei kaputter Eingabe.
            $pos = $bracePos + 1;
            continue;
        }

        $closePos = find_matching_brace($css, $bracePos);
        if ($closePos === -1) {
            // Unausgeglichene Klammer - Rest der Datei ist nicht mehr
            // zuverlaessig auswertbar.
            break;
        }
        $body = substr($css, $bracePos + 1, $closePos - $bracePos - 1);

        if ($preludeTrim[0] === '@') {
            if (preg_match('/^@media\b/i', $preludeTrim)) {
                foreach (parse_css_rules($body) as $r) {
                    $rules[] = $r;
                }
            } else {
                $props = [];
                if (preg_match_all('/([a-zA-Z-]+)\s*:\s*([^;{}]+)\s*;/', $body, $mm, PREG_SET_ORDER)) {
                    foreach ($mm as $m) {
                        $props[trim($m[1])] = trim($m[2]);
                    }
                }
                $rules[] = ['selector' => normalize_selector($preludeTrim), 'props' => $props];
            }
        } else {
            $props = [];
            foreach (split_top_level($body, ';') as $decl) {
                if ($decl === '') {
                    continue;
                }
                $colonPos = strpos($decl, ':');
                if ($colonPos === false) {
                    continue;
                }
                $name = trim(substr($decl, 0, $colonPos));
                if ($name === '') {
                    continue;
                }
                $props[$name] = trim(substr($decl, $colonPos + 1));
            }
            foreach (split_top_level($preludeTrim, ',') as $selPart) {
                $selPart = normalize_selector($selPart);
                if ($selPart !== '') {
                    $rules[] = ['selector' => $selPart, 'props' => $props];
                }
            }
        }

        $pos = $closePos + 1;
    }

    return $rules;
}

// Fasst eine flache Regelliste zu Selektor => Eigenschaften zusammen.
// $propMode 'union' sammelt nur die Menge der je Selektor je gesehenen
// Eigenschaftsnamen (fuer Verlust-Pruefungen reicht Existenz).
// $propMode 'last' merkt sich je Eigenschaft den zuletzt gesehenen Wert
// (Annaeherung an CSS-Kaskade, fuer den Wertabgleich in Pruefung 2/5).
function collect_by_selector(array $rules, string $propMode = 'union'): array
{
    $bySelector = [];
    foreach ($rules as $r) {
        $sel = $r['selector'];
        if (!isset($bySelector[$sel])) {
            $bySelector[$sel] = [];
        }
        foreach ($r['props'] as $name => $value) {
            if ($propMode === 'last') {
                $bySelector[$sel][$name] = $value;
            } else {
                $bySelector[$sel][$name] = true;
            }
        }
    }
    return $bySelector;
}

// ---------------------------------------------------------------------
// Wertaufloesung fuer Pruefung 2 und 5 (Auftrag Punkt 4): var(--x)
// rekursiv gegen eine Token-Map aufloesen und normalisieren, damit
// AUFGELOESTE Werte verglichen werden koennen statt nur "irgendein Token
// wird benutzt".
// ---------------------------------------------------------------------

// Tokens, deren "wahrer" Wert erst zur Laufzeit von includes/theme.php
// gesetzt wird (siehe tokens.css-Kommentar "Markenfarben"). tokens.css
// enthaelt dafuer nur einen Platzhalter-Default - den als Vergleichswert
// zu verwenden waere fahrlaessig, weil er in einer echten Installation
// so gut wie nie der tatsaechliche Wert ist. Deshalb bleiben diese beiden
// Tokens beim Aufloesen bewusst als "var(--name)" stehen.
const BRAND_TOKENS = ['--color-primary', '--color-sidebar'];

// Loest var(--name) rekursiv gegen $tokenMap auf. Markenfarben-Tokens
// (s.o.) werden NICHT ersetzt und bleiben als "var(--name)" im Ergebnis
// stehen - direkte Verwendung bleibt dadurch trotzdem vergleichbar (beide
// Seiten haben dann woertlich denselben Text), nur eine rechnerische
// Weiterverarbeitung (color-mix, s.u.) wird dadurch bewusst verhindert.
function expand_var_chain(string $value, array $tokenMap, int $depth = 0): string
{
    if ($depth > 12) {
        return $value; // Sicherheitsnetz gegen Zirkelbezuege
    }
    return (string) preg_replace_callback(
        '/var\(\s*(--[A-Za-z0-9_-]+)\s*(?:,[^()]*(?:\([^()]*\)[^()]*)*)?\)/',
        static function (array $m) use ($tokenMap, $depth): string {
            $name = $m[1];
            if (in_array($name, BRAND_TOKENS, true)) {
                return 'var(' . $name . ')';
            }
            if (!isset($tokenMap[$name])) {
                return $m[0]; // undefiniert - Pruefung 4 meldet das separat
            }
            return expand_var_chain($tokenMap[$name], $tokenMap, $depth + 1);
        },
        $value
    );
}

// Wandelt #hex in [r, g, b] um (3-, 4-, 6- oder 8-stellig; ein
// Alpha-Nibble/-Byte wird fuer den RGB-Anteil ignoriert). null bei
// ungueltigem Hex.
function hex_to_rgb(string $hex): ?array
{
    $core = ltrim($hex, '#');
    $len  = strlen($core);
    if ($len === 3 || $len === 4) {
        $expanded = '';
        for ($i = 0; $i < 3; $i++) {
            $expanded .= str_repeat($core[$i], 2);
        }
        $core = $expanded;
    } elseif ($len === 8) {
        $core = substr($core, 0, 6);
    }
    if (strlen($core) !== 6 || !ctype_xdigit($core)) {
        return null;
    }
    return [hexdec(substr($core, 0, 2)), hexdec(substr($core, 2, 2)), hexdec(substr($core, 4, 2))];
}

// Ersetzt color-mix(in srgb, C P%, transparent) durch die dazu identische,
// praemultiplizierte Form rgba(C, P/100) (Auftrag Punkt 4). Kann C nicht
// in konkrete RGB-Kanaele aufgeloest werden (insbesondere: C ist noch
// "var(--color-primary)" oder "var(--color-sidebar)", weil diese Tokens
// absichtlich nicht aufgeloest werden, s.o.), wird $comparable auf false
// gesetzt statt irgendeinen Wert zu raten.
function resolve_color_mix(string $value, bool &$comparable): string
{
    return (string) preg_replace_callback(
        '/color-mix\(\s*in\s+srgb\s*,\s*([^,]+?)\s+([0-9.]+)%\s*,\s*transparent\s*\)/i',
        static function (array $m) use (&$comparable): string {
            $colorExpr = trim($m[1]);
            $pct       = (float) $m[2];
            if (str_contains($colorExpr, 'var(')) {
                $comparable = false;
                return $m[0];
            }
            $rgb = hex_to_rgb($colorExpr);
            if ($rgb === null) {
                $comparable = false;
                return $m[0];
            }
            $alpha    = round($pct / 100, 4);
            $alphaStr = rtrim(rtrim(number_format($alpha, 4, '.', ''), '0'), '.');
            if ($alphaStr === '') {
                $alphaStr = '0';
            }
            return "rgba({$rgb[0]}, {$rgb[1]}, {$rgb[2]}, {$alphaStr})";
        },
        $value
    );
}

// Normalisiert einen (bereits aufgeloesten) CSS-Wert fuer den Vergleich
// (Auftrag Punkt 4): !important entfernen, Whitespace vereinheitlichen,
// Whitespace um Kommata vereinheitlichen, fuehrende Null bei
// Dezimalzahlen ergaenzen (.25 -> 0.25), ein paar im Projekt vorkommende
// benannte Farben auf Hex abbilden, Hex-Kurzform expandieren +
// Kleinschreibung.
function normalize_css_value(string $value): string
{
    $value = (string) preg_replace('/\s*!\s*important/i', '', $value);
    $value = trim($value);
    $value = (string) preg_replace('/\s+/', ' ', $value);
    $value = (string) preg_replace('/\s*,\s*/', ', ', $value);
    $value = (string) preg_replace('/(?<![0-9])\.(\d)/', '0.$1', $value);

    static $namedColors = ['white' => '#ffffff', 'black' => '#000000'];
    $value = (string) preg_replace_callback('/\b([a-zA-Z]+)\b/', static function (array $m) use ($namedColors): string {
        return $namedColors[strtolower($m[1])] ?? $m[0];
    }, $value);

    $value = (string) preg_replace_callback('/#[0-9a-fA-F]{3,8}\b/', static function (array $m): string {
        $rgb = hex_to_rgb($m[0]);
        if ($rgb === null) {
            return strtolower($m[0]);
        }
        return sprintf('#%02x%02x%02x', $rgb[0], $rgb[1], $rgb[2]);
    }, $value);

    return trim($value);
}

// Aufloesungsergebnis fuer Pruefung 2/5: loest var(...) gegen $tokenMap
// auf, wendet die color-mix()-Identitaet an und normalisiert das
// Ergebnis. Gibt ['value' => normalisierter String, 'comparable' => bool]
// zurueck; comparable=false heisst "haengt an der Markenfarbe, kein
// konkreter Wert ermittelbar".
function resolve_and_normalize(string $rawValue, array $tokenMap): array
{
    $expanded   = expand_var_chain($rawValue, $tokenMap);
    $comparable = true;
    $expanded   = resolve_color_mix($expanded, $comparable);

    if (preg_match_all('/var\(\s*(--[A-Za-z0-9_-]+)/', $expanded, $vm)) {
        foreach ($vm[1] as $residual) {
            if (!in_array($residual, BRAND_TOKENS, true)) {
                $comparable = false; // undefiniert oder Zirkelbezug - kein geratener Wert
            }
        }
    }

    return ['value' => normalize_css_value($expanded), 'comparable' => $comparable];
}

// ---------------------------------------------------------------------
// Dateien einlesen und vorbereiten
// ---------------------------------------------------------------------

$appRawOriginal = file_get_contents($appCssPath);
$appCss         = strip_css_comments($appRawOriginal);

$tokensRawOriginal = file_get_contents($tokensCssPath);
$tokensCss          = strip_css_comments($tokensRawOriginal);

$appRules   = parse_css_rules($appCss);
$tokenRules = parse_css_rules($tokensCss);

$originalRules = [];
if ($hasBaseline) {
    $originalRaw = file_get_contents($baselinePath);
    // BOM entfernen (design.css beginnt mit \xEF\xBB\xBF).
    $originalRaw = (string) preg_replace('/^\xEF\xBB\xBF/', '', $originalRaw);
    $originalCss = strip_css_comments($originalRaw);
    $originalRules = parse_css_rules($originalCss);
}

// Selektoren, die den Dark-Mode-Block markieren, werden aus den
// "muss erhalten bleiben"-Mengen (Pruefung 1 + 2) ausgenommen - dieser
// Block wird laut Auftrag absichtlich entfernt.
$isDarkSelector = static fn(string $sel): bool => str_contains($sel, '[data-theme');

// ---------------------------------------------------------------------
// Bannermarke fuer den Bootstrap-Override-Abschnitt (Teil B, Runde 1).
// strip_css_comments() ersetzt Kommentarzeichen 1:1 durch Leerzeichen
// (Laenge und Byte-Positionen bleiben erhalten, nur der Zeileninhalt
// nicht) - deshalb kann die Position der Bannermarke im ROHEN Quelltext
// gesucht und direkt als Schnittstelle in $appCss (kommentarbereinigt)
// verwendet werden, ohne die Positionen neu zu berechnen.
// ---------------------------------------------------------------------
$bootstrapBannerNeedle = 'DARK MODE: Overrides fuer Bootstrap-Komponenten';
$bootstrapBannerPos    = strpos($appRawOriginal, $bootstrapBannerNeedle);

if ($bootstrapBannerPos === false) {
    // Keine Bootstrap-Override-Sektion vorhanden: dann ist ueberall in
    // app.css [data-theme] verboten (alter, strenger Zustand).
    $appCssBeforeBanner = $appCss;
    $appCssAfterBanner  = '';
} else {
    $appCssBeforeBanner = substr($appCss, 0, $bootstrapBannerPos);
    $appCssAfterBanner  = substr($appCss, $bootstrapBannerPos);
}

$appRulesBeforeBanner = parse_css_rules($appCssBeforeBanner);
$appRulesAfterBanner  = parse_css_rules($appCssAfterBanner);

// Original-Regeln (nur befuellt, wenn eine Baseline angegeben wurde -
// sonst bleiben das leere Mengen und die Pruefungen 1/2/5 unten werden
// uebersprungen).
$originalLightRules = array_values(array_filter(
    $originalRules,
    static fn(array $r): bool => !$isDarkSelector($r['selector'])
));
$originalDarkRules = array_values(array_filter(
    $originalRules,
    static fn(array $r): bool => $isDarkSelector($r['selector'])
));

$originalBySelectorUnion = collect_by_selector($originalLightRules, 'union');
$originalBySelectorLast  = collect_by_selector($originalLightRules, 'last');
$appBySelectorUnion      = collect_by_selector($appRules, 'union');
$appBySelectorLast       = collect_by_selector($appRules, 'last');

// Definierte Tokens: Vereinigung aller in tokens.css deklarierten
// benutzerdefinierten Eigenschaften (:root UND [data-theme="dark"]).
// Unabhaengig von der Baseline - wird von Pruefung 4 immer gebraucht.
$definedTokens = [];
foreach ($tokenRules as $r) {
    foreach (array_keys($r['props']) as $name) {
        $definedTokens[$name] = true;
    }
}

// Token-Werte-Maps fuer die Aufloesung in Pruefung 2 (hell, gegen :root)
// und Pruefung 5 (dunkel, :root mit [data-theme="dark"]-Ueberschreibungen
// zusammengefuehrt - alles, was dort nicht neu gesetzt wird, behaelt den
// :root-Wert, genau wie im Browser).
$rootTokenValues    = [];
$darkTokenOverrides = [];
foreach ($tokenRules as $r) {
    if ($r['selector'] === ':root') {
        foreach ($r['props'] as $k => $v) {
            $rootTokenValues[$k] = $v;
        }
    } elseif ($isDarkSelector($r['selector'])) {
        foreach ($r['props'] as $k => $v) {
            $darkTokenOverrides[$k] = $v;
        }
    }
}
$darkTokenValues = array_merge($rootTokenValues, $darkTokenOverrides);

$skipMsg = "UEBERSPRUNGEN: braucht eine Baseline (design.css vor dem Token-Umbau). Aufruf mit: php tools/check_css.php pfad/zur/design.css\n\n";

// ---------------------------------------------------------------------
// Pruefung 1: Regelverlust (Selektoren) - braucht die Baseline.
// ---------------------------------------------------------------------
echo "=== Pruefung 1: Regelverlust (Selektoren) ===\n";
if (!$hasBaseline) {
    echo $skipMsg;
} else {
    $check1_fail = 0;
    $missingSelectors = [];
    foreach (array_keys($originalBySelectorUnion) as $sel) {
        if (!array_key_exists($sel, $appBySelectorUnion)) {
            $missingSelectors[] = $sel;
            $check1_fail = 1;
        }
    }
    if ($check1_fail) {
        foreach ($missingSelectors as $sel) {
            echo "FEHLT in app.css: $sel\n";
        }
    } else {
        echo 'OK: alle ' . count($originalBySelectorUnion) . " Selektoren aus dem Original (ohne [data-theme]-Block) existieren noch in app.css.\n";
    }
    if ($check1_fail) {
        $fail = 1;
    }
    echo "\n";
}

// ---------------------------------------------------------------------
// Pruefung 2: Eigenschaftsverlust & Wertabweichung - braucht die
// Baseline. Prueft fuer jede Eigenschaft aus dem Original nicht nur, ob
// sie noch deklariert ist, sondern ob ihr in app.css AUFGELOESTER Wert
// (var(--x) rekursiv gegen :root in tokens.css aufgeloest) dem
// Original-Wert entspricht (Auftrag Punkt 4).
// ---------------------------------------------------------------------
echo "=== Pruefung 2: Eigenschaftsverlust & Wertabweichung ===\n";
if (!$hasBaseline) {
    echo $skipMsg;
} else {
    $check2_fail   = 0;
    $propsChecked  = 0;
    $notComparable = 0;
    foreach ($originalBySelectorLast as $sel => $props) {
        if (!array_key_exists($sel, $appBySelectorUnion)) {
            continue; // schon in Pruefung 1 gemeldet
        }
        foreach ($props as $propName => $origValue) {
            $propsChecked++;
            $appValue = $appBySelectorLast[$sel][$propName] ?? null;
            if ($appValue === null) {
                echo "FEHLT: Selektor '$sel' - Eigenschaft '$propName' nicht mehr deklariert.\n";
                $check2_fail = 1;
                continue;
            }

            $resolved = resolve_and_normalize($appValue, $rootTokenValues);
            if (!$resolved['comparable']) {
                echo "NICHT VERGLEICHBAR: Selektor '$sel' - '$propName' -> $appValue (haengt von der zur Laufzeit gesetzten Markenfarbe ab).\n";
                $notComparable++;
                continue;
            }

            $origNormalized = normalize_css_value($origValue);
            if ($resolved['value'] !== $origNormalized) {
                echo "FEHLER: Selektor '$sel' - '$propName': Original '$origValue' (-> $origNormalized) != app.css '$appValue' (-> {$resolved['value']}).\n";
                $check2_fail = 1;
            }
        }
    }
    if (!$check2_fail) {
        echo "OK: $propsChecked Eigenschafts-Deklarationen geprueft, keine verloren, keine Wertabweichung ($notComparable davon nicht maschinell vergleichbar wegen Markenfarbe).\n";
    }
    if ($check2_fail) {
        $fail = 1;
    }
    echo "\n";
}

// ---------------------------------------------------------------------
// Pruefung 3: Rohfarben in app.css
// Hex-Literale sowie rgb()/rgba()/hsl()-Aufrufe gehoeren ausschliesslich
// nach tokens.css. Kommentare sind hier schon entfernt (siehe oben), es
// werden also keine Farbwerte aus Kommentartext faelschlich gemeldet.
// Braucht keine Baseline - laeuft immer.
// ---------------------------------------------------------------------
echo "=== Pruefung 3: Rohfarben in app.css ===\n";
$check3_fail = 0;
$colorPattern = '/#[0-9a-fA-F]{3,8}\b|\brgba?\(|\bhsla?\(/';
$appLines = explode("\n", $appCss);
foreach ($appLines as $i => $line) {
    if (preg_match($colorPattern, $line)) {
        $lineNo = $i + 1;
        echo "Zeile $lineNo: " . trim($line) . "\n";
        $check3_fail = 1;
    }
}
if (!$check3_fail) {
    echo "OK: keine Rohfarben (#hex, rgb(), rgba(), hsl()) in app.css gefunden.\n";
}
if ($check3_fail) {
    $fail = 1;
}
echo "\n";

// ---------------------------------------------------------------------
// Pruefung 4: Undefinierte Tokens
// Jedes in app.css per var(--name) verwendete Token muss in tokens.css
// definiert sein (egal ob nur in :root oder auch in [data-theme="dark"]).
// Braucht keine Baseline - laeuft immer.
// ---------------------------------------------------------------------
echo "=== Pruefung 4: Undefinierte Tokens ===\n";
$check4_fail = 0;
preg_match_all('/var\(\s*(--[A-Za-z0-9_-]+)/', $appCss, $vm);
$usedTokens = array_unique($vm[1]);
sort($usedTokens);
$undefinedTokens = [];
foreach ($usedTokens as $t) {
    if (!isset($definedTokens[$t])) {
        $undefinedTokens[] = $t;
        $check4_fail = 1;
    }
}
if ($check4_fail) {
    foreach ($undefinedTokens as $t) {
        echo "FEHLER: var($t) verwendet, aber $t ist nicht in tokens.css definiert.\n";
    }
} else {
    echo 'OK: alle ' . count($usedTokens) . " in app.css verwendeten Tokens sind in tokens.css definiert.\n";
}
if ($check4_fail) {
    $fail = 1;
}
echo "\n";

// ---------------------------------------------------------------------
// Pruefung 5: Dark-Mode-Abdeckung - braucht die Baseline.
// Fuer jede [data-theme="dark"]-Regel im Original: Basisselektor (ohne
// das Praefix) und die dort ueberschriebenen Eigenschaften ermitteln.
// Zwei Wege gelten in app.css als Abdeckung (Teil B, Runde 1):
//   (a) Token-Redefinition: dieselbe Regel (ohne [data-theme]-Praefix,
//       VOR der Bootstrap-Bannermarke) deklariert dieselbe Eigenschaft
//       ueber einen var(...)-Wert - der Dark Mode wirkt automatisch,
//       weil das Token in tokens.css unter [data-theme="dark"] einen
//       anderen Wert bekommt.
//   (b) Portierte Bootstrap-Override-Regel: dieselbe [data-theme="dark"]-
//       Regel wurde NACH der Bannermarke unveraendert (nur mit Tokens
//       statt Rohfarben) uebernommen, weil es dafuer keine eigene
//       Hell-Regel gibt (Bootstrap-Komponente).
// Fuer beide Wege wird jetzt (Auftrag Punkt 4) der AUFGELOESTE Wert
// gegen die Original-Dark-Deklaration verglichen (var(--x) rekursiv
// gegen :root MIT [data-theme="dark"]-Ueberschreibungen aufgeloest),
// nicht mehr nur "irgendein Token wird benutzt".
// ---------------------------------------------------------------------

// Bekannte, eng gefasste Ausnahme: .wiki-list-item loest den im Original
// fuer ALLE Seiten geltenden "border-color"-Override bewusst NICHT ueber
// ein gleichnamiges "border-color"-Token, sondern ueber zwei eigene
// Tokens auf den betroffenen Langformen (border-left ueber
// --border-wiki-accent, border-bottom ueber --border-wiki-divider). Der
// Grund: das Original hatte im Hell-Modus zwei UNTERSCHIEDLICHE
// Randfarben (transparent links, #f1f3f5 unten). Ein einzelnes
// "border-color"-Token wuerde im Dark Mode zwar denselben Zielwert auf
// beiden Seiten erzeugen (das tut es hier auch), aber im Hell-Modus die
// zwei unterschiedlichen Werte auf einen einzigen zusammenziehen - eine
// echte Regression. Deshalb hier eine gezielte Ausnahme statt genereller
// Shorthand/Longhand-Kaskadenlogik im Parser (siehe Bericht Teil B).
// Bleibt bewusst eine reine Existenz-Pruefung (kein Wertabgleich): sie
// bestaetigt nur, dass beide Langformen ueber ein Token gesetzt sind -
// der eigentliche Wertabgleich der beiden Tokens passiert schon fuer
// jedes Token einzeln an anderer Stelle in dieser Pruefung.
function check5_wiki_list_item_exception(string $sel, string $propName, array $appBySelectorLastOwn): bool
{
    if ($sel !== '.wiki-list-item' || $propName !== 'border-color') {
        return false;
    }
    $own = $appBySelectorLastOwn[$sel] ?? [];
    $leftOk = (isset($own['border-left']) && str_contains($own['border-left'], 'var('))
           || (isset($own['border-left-color']) && str_contains($own['border-left-color'], 'var('));
    $bottomOk = (isset($own['border-bottom']) && str_contains($own['border-bottom'], 'var('))
             || (isset($own['border-bottom-color']) && str_contains($own['border-bottom-color'], 'var('));
    return $leftOk && $bottomOk;
}

// Diese beiden Mengen braucht auch Pruefung 6 (Rohfarben-Scan der
// portierten Regeln) - deshalb unabhaengig von der Baseline berechnen.
$appBySelectorLastOwn = collect_by_selector($appRulesBeforeBanner, 'last');
$portedDarkRules = array_values(array_filter(
    $appRulesAfterBanner,
    static fn(array $r): bool => $isDarkSelector($r['selector'])
));
$portedDarkBySelector = [];
foreach ($portedDarkRules as $r) {
    $base = normalize_selector((string) preg_replace('/^\[data-theme="dark"\]\s*/', '', $r['selector']));
    if (!isset($portedDarkBySelector[$base])) {
        $portedDarkBySelector[$base] = [];
    }
    foreach ($r['props'] as $name => $value) {
        $portedDarkBySelector[$base][$name] = $value;
    }
}

echo "=== Pruefung 5: Dark-Mode-Abdeckung ===\n";
if (!$hasBaseline) {
    echo $skipMsg;
} else {
    $check5_fail        = 0;
    $darkPairs          = 0;
    $coveredByToken      = 0;
    $coveredByOverride   = 0;
    $coveredByException  = 0;
    $notComparableCount  = 0;

    $darkBySelector = [];
    foreach ($originalDarkRules as $r) {
        $base = normalize_selector((string) preg_replace('/^\[data-theme="dark"\]\s*/', '', $r['selector']));
        if (!isset($darkBySelector[$base])) {
            $darkBySelector[$base] = [];
        }
        foreach ($r['props'] as $name => $value) {
            $darkBySelector[$base][$name] = $value;
        }
    }

    foreach ($darkBySelector as $sel => $props) {
        foreach ($props as $propName => $origValue) {
            $darkPairs++;
            $origNormalized = normalize_css_value($origValue);

            $tokenValue = $appBySelectorLastOwn[$sel][$propName] ?? null;
            if ($tokenValue !== null && str_contains($tokenValue, 'var(')) {
                $resolved = resolve_and_normalize($tokenValue, $darkTokenValues);
                if (!$resolved['comparable']) {
                    echo "NICHT VERGLEICHBAR (Token):    '$sel' - '$propName' -> $tokenValue (haengt von der zur Laufzeit gesetzten Markenfarbe ab).\n";
                    $notComparableCount++;
                    $coveredByToken++;
                    continue;
                }
                if ($resolved['value'] !== $origNormalized) {
                    echo "FEHLER: Selektor '$sel' - Dark-Mode-Override fuer '$propName': Original '$origValue' (-> $origNormalized) != Token-Wert '$tokenValue' (-> {$resolved['value']}).\n";
                    $check5_fail = 1;
                    continue;
                }
                echo "OK (Token):    '$sel' - '$propName' -> $tokenValue (-> {$resolved['value']})\n";
                $coveredByToken++;
                continue;
            }

            $overrideValue = $portedDarkBySelector[$sel][$propName] ?? null;
            if ($overrideValue !== null) {
                $resolved = resolve_and_normalize($overrideValue, $darkTokenValues);
                if (!$resolved['comparable']) {
                    echo "NICHT VERGLEICHBAR (Override): '$sel' - '$propName' -> $overrideValue (haengt von der zur Laufzeit gesetzten Markenfarbe ab).\n";
                    $notComparableCount++;
                    $coveredByOverride++;
                    continue;
                }
                if ($resolved['value'] !== $origNormalized) {
                    echo "FEHLER: Selektor '$sel' - Dark-Mode-Override fuer '$propName': Original '$origValue' (-> $origNormalized) != portierter Wert '$overrideValue' (-> {$resolved['value']}).\n";
                    $check5_fail = 1;
                    continue;
                }
                echo "OK (Override): '$sel' - '$propName' -> $overrideValue (portierte Bootstrap-Regel, -> {$resolved['value']})\n";
                $coveredByOverride++;
                continue;
            }

            if (check5_wiki_list_item_exception($sel, $propName, $appBySelectorLastOwn)) {
                echo "OK (Ausnahme): '$sel' - '$propName' -> border-left/border-bottom mit eigenen Tokens (siehe Kommentar im Skript und Bericht Teil B).\n";
                $coveredByException++;
                continue;
            }

            echo "FEHLER: Selektor '$sel' - Dark-Mode-Override fuer '$propName' (Original: '$origValue') ist weder durch eine Token-Redefinition noch durch eine portierte [data-theme=\"dark\"]-Regel abgedeckt.\n";
            $check5_fail = 1;
        }
    }
    echo "--- Zwischenstand Pruefung 5 ---\n";
    if (!$check5_fail) {
        echo "OK: alle $darkPairs Dark-Mode-Overrides aus dem Original sind abgedeckt ($coveredByToken durch Token-Redefinition, $coveredByOverride durch portierte Bootstrap-Overrides, $coveredByException durch dokumentierte Ausnahme; $notComparableCount davon nicht maschinell vergleichbar wegen Markenfarbe).\n";
    }
    if ($check5_fail) {
        $fail = 1;
    }
    echo "\n";
}

// ---------------------------------------------------------------------
// Pruefung 6: [data-theme]-Regeln nur nach der Bootstrap-Bannermarke
// Vor der Bannermarke (unsere eigenen Komponenten) ist [data-theme]
// weiterhin verboten - dort muss Token-Redefinition die Arbeit machen.
// Nach der Bannermarke sind [data-theme]-Regeln erlaubt, aber jede
// Deklaration muss ausschliesslich Tokens verwenden - keine Rohfarbe
// (Pruefung 3 deckt das global bereits ab, hier zusaetzlich lokalisiert
// fuer eine praezisere Fehlermeldung). Braucht keine Baseline - laeuft
// immer.
// ---------------------------------------------------------------------
echo "=== Pruefung 6: [data-theme]-Regeln nur nach der Bootstrap-Bannermarke ===\n";
$check6_fail = 0;

$darkSelectorsBeforeBanner = [];
foreach ($appRulesBeforeBanner as $r) {
    if ($isDarkSelector($r['selector'])) {
        $darkSelectorsBeforeBanner[$r['selector']] = true;
    }
}
foreach (array_keys($darkSelectorsBeforeBanner) as $sel) {
    echo "FEHLER: [data-theme]-Regel VOR der Bootstrap-Override-Bannermarke gefunden (muss durch Token-Redefinition geloest werden): $sel\n";
    $check6_fail = 1;
}

$colorPatternInline = '/#[0-9a-fA-F]{3,8}\b|\brgba?\(|\bhsla?\(/';
$portedRuleCount     = 0;
foreach ($portedDarkRules as $r) {
    $portedRuleCount++;
    foreach ($r['props'] as $propName => $value) {
        if (preg_match($colorPatternInline, $value)) {
            echo "FEHLER: portierte [data-theme]-Regel '{$r['selector']}' enthaelt eine Rohfarbe bei '$propName': $value\n";
            $check6_fail = 1;
        }
    }
}

if ($bootstrapBannerPos === false) {
    echo "HINWEIS: keine Bootstrap-Override-Bannermarke in app.css gefunden - es wird ueberall der strenge Zustand (kein [data-theme]) geprueft.\n";
}

if (!$check6_fail) {
    echo "OK: keine [data-theme]-Regeln vor der Bannermarke; $portedRuleCount [data-theme]-Regel(n) danach verwenden ausschliesslich Tokens.\n";
}
if ($check6_fail) {
    $fail = 1;
}
echo "\n";

// ---------------------------------------------------------------------
// Zusammenfassung
// ---------------------------------------------------------------------
echo "=== Zusammenfassung ===\n";
if (!$hasBaseline) {
    echo "HINWEIS: ohne Baseline liefen nur die Struktur-Pruefungen 3, 4, 6. Fuer die Parity-Pruefungen 1, 2, 5: php tools/check_css.php pfad/zur/design.css\n";
}
if ($fail) {
    echo "FEHLGESCHLAGEN: mindestens eine Pruefung ist fehlgeschlagen (siehe oben).\n";
} else {
    echo "OK: alle (durchgefuehrten) Pruefungen bestanden.\n";
}

exit($fail);
