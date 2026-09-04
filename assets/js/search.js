/**
 * Globale Suche: Overlay, Tastatur, Abruf.
 *
 * Der Abruf ist entprellt und bricht die vorherige Anfrage ab - sonst
 * überholen sich bei schnellem Tippen die Antworten und die Liste zeigt
 * das Ergebnis eines älteren Suchbegriffs.
 *
 * Die Treffer werden mit DOM-Methoden aufgebaut, nicht über innerHTML.
 * Titel und Unterzeilen stammen aus der Datenbank und damit letztlich aus
 * Eingaben; als Textknoten gesetzt können sie kein Markup einschleusen,
 * ganz ohne Verlass auf eine eigene Maskierfunktion.
 */
(function () {
    var overlay = document.getElementById('gsearch');
    if (!overlay) return;

    var feld    = document.getElementById('gsearch-input');
    var liste   = document.getElementById('gsearch-results');
    var timer   = null;
    var laufend = null;
    var auswahl = -1;

    function offen() { return !overlay.hidden; }

    function hinweis(text) {
        liste.textContent = '';
        var p = document.createElement('p');
        p.className = 'gsearch-hint';
        p.textContent = text;
        liste.appendChild(p);
        auswahl = -1;
    }

    function oeffnen() {
        if (offen()) return;
        overlay.hidden = false;
        document.body.style.overflow = 'hidden';
        feld.value = '';
        hinweis('Mindestens zwei Zeichen eingeben.');
        feld.focus();
    }

    function schliessen() {
        if (!offen()) return;
        overlay.hidden = true;
        document.body.style.overflow = '';
        if (laufend) { laufend.abort(); laufend = null; }
    }

    function eintraege() {
        return Array.prototype.slice.call(liste.querySelectorAll('.gsearch-item'));
    }

    function markieren(i) {
        var alle = eintraege();
        if (!alle.length) return;
        if (i < 0) i = alle.length - 1;
        if (i >= alle.length) i = 0;
        alle.forEach(function (el) {
            el.classList.remove('active');
            el.setAttribute('aria-selected', 'false');
        });
        alle[i].classList.add('active');
        alle[i].setAttribute('aria-selected', 'true');
        alle[i].scrollIntoView({ block: 'nearest' });
        auswahl = i;
    }

    /**
     * Setzt Text in ein Element und hebt den Suchbegriff mit <mark> hervor.
     * Alle Bruchstücke sind Textknoten - nur das <mark> ist ein Element.
     */
    function textMitTreffer(el, text, begriff) {
        el.textContent = '';
        text = String(text == null ? '' : text);
        if (!begriff) { el.textContent = text; return; }

        var hay = text.toLowerCase();
        var nadel = begriff.toLowerCase();
        var pos = 0;
        var i = hay.indexOf(nadel);
        while (i !== -1 && nadel.length) {
            if (i > pos) el.appendChild(document.createTextNode(text.slice(pos, i)));
            var m = document.createElement('mark');
            m.textContent = text.slice(i, i + nadel.length);
            el.appendChild(m);
            pos = i + nadel.length;
            i = hay.indexOf(nadel, pos);
        }
        if (pos < text.length) el.appendChild(document.createTextNode(text.slice(pos)));
    }

    /** Nur seiteninterne Ziele zulassen - kein javascript:, kein fremder Host. */
    function sicheresZiel(url) {
        var u = String(url == null ? '' : url);
        return /^[a-z0-9_\-]+(\?[^\s"'<>]*)?$/i.test(u) ? u : '#';
    }

    function zeichnen(daten) {
        if (!daten.groups || !daten.groups.length) {
            hinweis('Nichts gefunden zu „' + daten.q + '“.');
            return;
        }
        liste.textContent = '';

        daten.groups.forEach(function (g) {
            var kopf = document.createElement('div');
            kopf.className = 'gsearch-group';
            kopf.textContent = g.label;
            liste.appendChild(kopf);

            g.treffer.forEach(function (t) {
                var a = document.createElement('a');
                a.className = 'gsearch-item';
                a.setAttribute('role', 'option');
                a.setAttribute('aria-selected', 'false');
                a.href = sicheresZiel(t.url);

                var ikon = document.createElement('i');
                ikon.className = 'bi ' + String(g.icon || '').replace(/[^a-z0-9\- ]/gi, '');
                ikon.setAttribute('aria-hidden', 'true');
                a.appendChild(ikon);

                var wrap = document.createElement('span');
                wrap.className = 'gsearch-text';

                var titel = document.createElement('span');
                titel.className = 'gsearch-title';
                textMitTreffer(titel, t.titel, daten.q);
                wrap.appendChild(titel);

                if (t.unterzeile) {
                    var sub = document.createElement('span');
                    sub.className = 'gsearch-sub';
                    textMitTreffer(sub, t.unterzeile, daten.q);
                    wrap.appendChild(sub);
                }

                a.appendChild(wrap);
                liste.appendChild(a);
            });
        });
        markieren(0);
    }

    function suchen() {
        var q = feld.value.trim();
        if (q.length < 2) { hinweis('Mindestens zwei Zeichen eingeben.'); return; }

        if (laufend) laufend.abort();
        laufend = new AbortController();
        hinweis('Suche läuft …');

        fetch('ajax_search?q=' + encodeURIComponent(q), { signal: laufend.signal, cache: 'no-store' })
            .then(function (r) { return r.json(); })
            .then(zeichnen)
            .catch(function (e) {
                if (e.name === 'AbortError') return;
                hinweis('Die Suche ist gerade nicht erreichbar.');
            });
    }

    feld.addEventListener('input', function () {
        clearTimeout(timer);
        timer = setTimeout(suchen, 180);
    });

    feld.addEventListener('keydown', function (e) {
        if (e.key === 'ArrowDown')    { e.preventDefault(); markieren(auswahl + 1); }
        else if (e.key === 'ArrowUp') { e.preventDefault(); markieren(auswahl - 1); }
        else if (e.key === 'Enter') {
            var alle = eintraege();
            if (alle[auswahl]) { e.preventDefault(); window.location.href = alle[auswahl].href; }
        }
    });

    document.addEventListener('keydown', function (e) {
        // Strg+K bzw. ⌘+K. Firefox fokussiert damit sonst die Adressleiste.
        if ((e.ctrlKey || e.metaKey) && (e.key === 'k' || e.key === 'K')) {
            e.preventDefault();
            offen() ? schliessen() : oeffnen();
            return;
        }
        if (e.key === 'Escape' && offen()) { e.preventDefault(); schliessen(); }
    });

    document.querySelectorAll('[data-gsearch-close]').forEach(function (el) {
        el.addEventListener('click', schliessen);
    });
    document.querySelectorAll('[data-gsearch-open]').forEach(function (el) {
        el.addEventListener('click', function (e) { e.preventDefault(); oeffnen(); });
    });
})();
