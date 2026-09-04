/**
 * Überweisungs-Code nach EPC069-12 („Girocode").
 *
 * Die Nutzdaten werden hier im Browser zusammengesetzt und gezeichnet.
 * Ein Bilddienst wie api.qrserver.com bekäme sonst Bankverbindung und
 * Betrag zu sehen — bei Zahlungsdaten ist das die falsche Abwägung.
 *
 * Aufbau der zwölf Zeilen laut Spezifikation (Version 002):
 *   BCD / 002 / 1 (UTF-8) / SCT / BIC / Empfänger / IBAN /
 *   EUR<Betrag> / Zweckcode / strukturierte Referenz /
 *   Verwendungszweck / Hinweis
 * Leere Felder bleiben leer, dürfen aber nicht fehlen.
 */
(function () {
    if (typeof QRCode === 'undefined') return;

    function epcNutzdaten(d) {
        // Der Betrag muss "EUR" plus Punkt-Dezimaltrennung sein.
        var betrag = parseFloat(d.amount || '0');
        if (!(betrag > 0)) return null;
        if (!d.iban || !d.holder) return null;

        return [
            'BCD',
            '002',
            '1',
            'SCT',
            (d.bic || '').slice(0, 11),
            (d.holder || '').slice(0, 70),
            (d.iban || '').replace(/\s+/g, ''),
            'EUR' + betrag.toFixed(2),
            '',                                  // Zweckcode
            '',                                  // strukturierte Referenz
            (d.ref || '').slice(0, 140),         // Verwendungszweck
            ''                                   // Hinweis an den Empfänger
        ].join('\n');
    }

    document.querySelectorAll('[data-pay]').forEach(function (box) {
        var ziel = box.querySelector('.pay-qr');
        if (!ziel) return;

        var nutzdaten = epcNutzdaten(box.dataset);
        if (!nutzdaten) { box.classList.add('pay-box-noqr'); return; }

        try {
            new QRCode(ziel, {
                text: nutzdaten,
                width: 132,
                height: 132,
                // Mittlere Fehlerkorrektur: genug Reserve fuer eine
                // Handykamera, ohne den Code unnoetig dicht zu machen.
                correctLevel: QRCode.CorrectLevel.M
            });
        } catch (e) {
            // Ohne Code bleiben die Angaben daneben trotzdem lesbar -
            // abtippen geht immer.
            box.classList.add('pay-box-noqr');
        }
    });
})();
