/**
 * Haelt --header-height auf der tatsaechlichen Hoehe des Seitenkopfes.
 *
 * Der Kopf und die Filterleiste bleiben beim Scrollen stehen (app.css,
 * position:sticky). Die Filterleiste muss dabei genau unter dem Kopf
 * einrasten - und dessen Hoehe steht nicht fest: auf schmalen Geraeten
 * bricht er in zwei oder drei Zeilen um, und die Zeilenzahl aendert sich
 * beim Drehen des Geraets, beim Ein- und Ausklappen der Seitenleiste und
 * sobald eine Seite mehr Schaltflaechen mitbringt als eine andere.
 *
 * Ein fester Wert im CSS waere deshalb auf der einen Seite zu klein (die
 * Filterleiste verschwindet halb unter dem Kopf) und auf der anderen zu
 * gross (ein Streifen Seitenfarbe klafft dazwischen). Der ResizeObserver
 * meldet jede dieser Aenderungen, ohne dass etwas gepollt werden muss.
 */
(function () {
    /**
     * Haelt eine CSS-Variable auf der Hoehe eines Elements.
     *
     * Zwei Stellen brauchen das: der Seitenkopf im Panel (darunter rastet
     * die Filterleiste ein) und der Demo-Hinweis im Portal (darunter die
     * Reiterleiste). Beide aendern ihre Hoehe beim Umbruch, und keine
     * davon laesst sich im Stylesheet beziffern.
     */
    function beobachte(el, variable) {
        if (!el) return;

        function setzen() {
            // Aufrunden: getBoundingClientRect liefert gebrochene Pixel,
            // und bei einem abgerundeten Wert bliebe ein Haarstrich, durch
            // den der Inhalt beim Scrollen durchwandert.
            var hoehe = Math.ceil(el.getBoundingClientRect().height);
            document.documentElement.style.setProperty(variable, hoehe + 'px');
        }

        setzen();

        if (typeof ResizeObserver === 'function') {
            new ResizeObserver(setzen).observe(el);
        } else {
            // Aeltere Browser: wenigstens auf die Groessenaenderung hoeren.
            window.addEventListener('resize', setzen);
        }

        // Schriften kommen ueber das Netz und aendern die Hoehe, nachdem
        // schon einmal gemessen wurde. Ohne dieses Nachmessen sitzt das
        // Element darunter bis zum ersten Umbruch ein paar Pixel falsch.
        if (document.fonts && document.fonts.ready) {
            document.fonts.ready.then(setzen);
        }
    }

    beobachte(document.querySelector('.top-header'), '--header-height');
    beobachte(document.querySelector('.demo-portal-hinweis'), '--demo-strip-height');
})();
