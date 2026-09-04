/* Kurze Rückmeldung, wenn die Demo eine Änderung abgelehnt hat.
   Wird nur im Demo-Modus eingebunden.

   Der Text wird über textContent gesetzt, nicht über innerHTML: er kann
   aus einer Serverantwort stammen, und diese Datei soll gar nicht erst
   die Möglichkeit eröffnen, dort Markup einzuschleusen. */
(function () {
    var timer = null;

    window.demoHinweis = function (text) {
        var alt = document.getElementById('demo-toast');
        if (alt) alt.remove();
        if (timer) clearTimeout(timer);

        var el = document.createElement('div');
        el.id        = 'demo-toast';
        el.className = 'demo-toast';
        el.setAttribute('role', 'status');
        el.textContent = text || 'Dies ist eine Demo-Version. Änderungen werden nicht gespeichert.';
        document.body.appendChild(el);

        requestAnimationFrame(function () { el.classList.add('sichtbar'); });

        timer = setTimeout(function () {
            el.classList.remove('sichtbar');
            setTimeout(function () { el.remove(); }, 300);
        }, 3200);
    };
})();
