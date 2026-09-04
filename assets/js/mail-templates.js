/**
 * Setzt Platzhalter in einer E-Mail-Vorlage ein - dieselbe Regel wie
 * mail_fill() in includes/mail_templates.php: {{name}} wird ersetzt, und
 * eine Zeile, die durch einen leeren Wert vollstaendig leer wird,
 * verschwindet mitsamt der Zeile.
 */
function mailTplFill(text, vars) {
    if (!text) return '';
    var zeilenVorher = text.split('\n');
    var ersetzt = text.replace(/\{\{\s*([a-z_]+)\s*\}\}/g, function (_, name) {
        return vars[name] != null ? String(vars[name]) : '';
    });
    return ersetzt.split('\n').filter(function (zeile, i) {
        var hattePlatzhalter = (zeilenVorher[i] || '').indexOf('{{') !== -1;
        return !(hattePlatzhalter && zeile.trim() === '');
    }).join('\n');
}
