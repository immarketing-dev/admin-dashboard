<?php
/**
 * Die globale Suche.
 *
 * Wird von includes/layout_start.php auf jeder Verwaltungsseite
 * eingebunden. Geöffnet mit Strg+K (bzw. ⌘+K) oder über die Lupe im
 * Seitenkopf, geschlossen mit Escape.
 */
?>
<div class="gsearch" id="gsearch" hidden>
  <div class="gsearch-backdrop" data-gsearch-close></div>
  <div class="gsearch-panel" role="dialog" aria-modal="true" aria-label="Globale Suche">
    <div class="gsearch-inputrow">
      <i class="bi bi-search" aria-hidden="true"></i>
      <input type="text" id="gsearch-input" autocomplete="off" spellcheck="false"
             placeholder="Kontakte, Projekte, Rechnungen, Angebote, Tickets, Wiki …"
             aria-label="Suchbegriff" aria-controls="gsearch-results">
      <button type="button" class="gsearch-esc" data-gsearch-close aria-label="Suche schließen">Esc</button>
    </div>
    <div class="gsearch-results" id="gsearch-results" role="listbox" aria-label="Suchergebnisse">
      <p class="gsearch-hint">Mindestens zwei Zeichen eingeben.</p>
    </div>
  </div>
</div>
