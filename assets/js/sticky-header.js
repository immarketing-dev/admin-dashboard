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
    var kopf = document.querySelector('.top-header');
    if (!kopf) return;

    function setzen() {
        // offsetHeight rundet auf ganze Pixel ab. Bei einem Kopf mit
        // gebrochener Hoehe bliebe sonst ein Haarstrich sichtbar, durch
        // den der Inhalt beim Scrollen durchwandert.
        var hoehe = Math.ceil(kopf.getBoundingClientRect().height);
        document.documentElement.style.setProperty('--header-height', hoehe + 'px');
    }

    setzen();

    if (typeof ResizeObserver === 'function') {
        new ResizeObserver(setzen).observe(kopf);
    } else {
        // Aeltere Browser: wenigstens auf die Groessenaenderung hoeren.
        window.addEventListener('resize', setzen);
    }

    // Schriften kommen ueber das Netz und aendern die Hoehe des Kopfes,
    // nachdem er schon einmal gemessen wurde. Ohne dieses Nachmessen
    // sitzt die Filterleiste bis zum ersten Umbruch ein paar Pixel falsch.
    if (document.fonts && document.fonts.ready) {
        document.fonts.ready.then(setzen);
    }
})();
