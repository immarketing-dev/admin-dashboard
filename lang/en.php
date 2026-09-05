<?php
/**
 * English translations.
 *
 * The German source text is the key — see includes/i18n.php for why. A
 * string that is missing here falls back to the German original, so the
 * interface is never empty and never shows a raw key.
 *
 * `php tools/check_i18n.php` reports three things: strings wrapped in t()
 * that have no entry here, entries here whose German source no longer
 * exists anywhere, and pairs whose printf placeholders do not match.
 *
 * Translation is staged, page by page. Sections below follow that order.
 */

return [

    // ── Navigation (includes/sidebar.php) ────────────────────────────
    'Übersicht'                => 'Overview',
    'Kalender'                 => 'Calendar',
    'Arbeit'                   => 'Work',
    'Projekte & Aufgaben'      => 'Projects & tasks',
    'Kanban Board'             => 'Kanban board',
    'Wiki / Wissen'            => 'Wiki / knowledge',
    'Geschäft'                 => 'Business',
    'Kontakte'                 => 'Contacts',
    'Angebote'                 => 'Quotes',
    'Finanzen'                 => 'Finances',
    'Support-Tickets'          => 'Support tickets',
    'System-Logs'              => 'System logs',
    'Papierkorb'               => 'Trash',
    'Einstellungen'            => 'Settings',
    'Logout'                   => 'Log out',
    'System'                   => 'System',
    'Seitenleiste einklappen'  => 'Collapse sidebar',
    '%d Anfrage(n), %d Portal-Vorgang/-Vorgänge'
                               => '%d enquiry/enquiries, %d portal item(s)',

    // ── Seitenkopf und globale Suche ─────────────────────────────────
    'Suchen'                   => 'Search',
    'Globale Suche'            => 'Global search',
    'Globale Suche öffnen'     => 'Open global search',
    'Globale Suche (Strg+K)'   => 'Global search (Ctrl+K)',
    'Strg K'                   => 'Ctrl K',
    'Suchbegriff'              => 'Search term',
    'Suche schließen'          => 'Close search',
    'Suchergebnisse'           => 'Search results',
    'Mindestens zwei Zeichen eingeben.'
                               => 'Enter at least two characters.',
    'Kontakte, Projekte, Rechnungen, Angebote, Tickets, Wiki …'
                               => 'Contacts, projects, invoices, quotes, tickets, wiki …',

    // ── Demo-Modus ───────────────────────────────────────────────────
    'Demo-Version'             => 'Demo version',
    'alle Daten sind erfunden, Änderungen werden nicht gespeichert.'
                               => 'every record is invented, and nothing you change is saved.',
    'Dies ist eine Demo-Version. Änderungen werden nicht gespeichert.'
                               => 'This is a demo. Nothing you change is saved.',
    'Zum Ansehen und Ausprobieren bleibt aber alles offen – Filter, Suche und jede Ansicht funktionieren.'
                               => 'Everything else stays open — filters, search and every view work as usual.',
    'dies ist ein Beispielportal. Alle Namen, Projekte und Beträge sind erfunden, Änderungen werden nicht gespeichert.'
                               => 'this is an example portal. Every name, project and amount is invented, and nothing you change is saved.',

    // ── Anmeldung (login.php) ────────────────────────────────────────
    'Login'                    => 'Sign in',
    'Erster Start – bitte ein Admin-Passwort festlegen.'
                               => 'First run — please set an administrator password.',
    'E-Mail-Adresse'           => 'E-mail address',
    'Passwort'                 => 'Password',
    'Neues Passwort'           => 'New password',
    'Passwort wiederholen'     => 'Repeat password',
    'Mindestens 12 Zeichen'    => 'At least 12 characters',
    'Passwort bestätigen'      => 'Confirm password',
    'Einloggen'                => 'Sign in',
    'Passwort setzen & einloggen'
                               => 'Set password and sign in',
    'Bitte eine gültige E-Mail-Adresse angeben.'
                               => 'Please enter a valid e-mail address.',
    'Das Passwort muss mindestens 12 Zeichen lang sein.'
                               => 'The password must be at least 12 characters long.',
    'Die Passwörter stimmen nicht überein.'
                               => 'The passwords do not match.',
    'E-Mail-Adresse oder Passwort ist falsch.'
                               => 'E-mail address or password is incorrect.',
    'Zu viele Fehlversuche. Bitte in %d Minuten erneut versuchen.'
                               => 'Too many failed attempts. Please try again in %d minutes.',

    // ── Statuswerte ──────────────────────────────────────────────────
    // Diese Zeichenketten stehen so in der Datenbank. Uebersetzt wird nur
    // die Anzeige - siehe includes/i18n.php.
    'Offen'                    => 'Open',
    'In Bearbeitung'           => 'In progress',
    'Erledigt'                 => 'Done',
    'Storniert'                => 'Cancelled',
    'Bezahlt'                  => 'Paid',
    'Überfällig'               => 'Overdue',
    'Entwurf'                  => 'Draft',
    'Gesendet'                 => 'Sent',
    'Angenommen'               => 'Accepted',
    'Abgelehnt'                => 'Declined',
    'Rückfrage'                => 'Query',

    // Kontaktarten
    'Kunde'                    => 'Client',
    'Interessent'              => 'Prospect',
    'Geschäftspartner'         => 'Business partner',
    'Lieferant'                => 'Supplier',

    // Dringlichkeiten
    'Niedrig'                  => 'Low',
    'Mittel'                   => 'Medium',
    'Hoch'                     => 'High',
    'Kritisch'                 => 'Critical',

    // Terminarten und -zustaende
    'Termin'                   => 'Appointment',
    'Meeting'                  => 'Meeting',
    'Anruf'                    => 'Call',
    'Deadline'                 => 'Deadline',
    'Sonstiges'                => 'Other',
    'Geplant'                  => 'Planned',
    'Bestätigt'                => 'Confirmed',
    'Abgeschlossen'            => 'Completed',
    'Abgesagt'                 => 'Cancelled',

    // ── Spracheinstellung (settings.php) ─────────────────────────────
    'Sprache'                  => 'Language',
    'Sprache der Oberfläche'   => 'Interface language',
    'Deutsch'                  => 'German',
    'Englisch'                 => 'English',
    'Sprache speichern'        => 'Save language',
    'Gilt für das gesamte Panel. Das Kundenportal folgt der Sprache des jeweiligen Kontakts.'
                               => 'Applies to the whole panel. The client portal follows each contact\'s own language.',
    // ── Stufen 2 bis 6: Seiten ───────────────────────────────────────
    'Abbrechen'
        => 'Cancel',
    'Speichern'
        => 'Save',
    'Löschen'
        => 'Delete',
    'Bearbeiten'
        => 'Edit',
    '+ Hinzufügen'
        => '+ Add',
    'Schließen'
        => 'Close',
    'Öffnen'
        => 'Open',
    'Zurück'
        => 'Back',
    'Weiter'
        => 'Next',
    'Senden'
        => 'Send',
    'Antworten'
        => 'Reply',
    'Antwort senden'
        => 'Send reply',
    'Herunterladen'
        => 'Download',
    'Zurücksetzen'
        => 'Reset',
    'Filter zurücksetzen'
        => 'Clear filters',
    'Filtern'
        => 'Filter',
    'Alle'
        => 'All',
    'Aktion'
        => 'Action',
    'Aktionen'
        => 'Actions',
    'Details'
        => 'Details',
    'Name'
        => 'Name',
    'Name *'
        => 'Name *',
    'Titel'
        => 'Title',
    'Titel *'
        => 'Title *',
    'Typ'
        => 'Type',
    'Datum'
        => 'Date',
    'Datum *'
        => 'Date *',
    'Betrag'
        => 'Amount',
    'Betreff'
        => 'Subject',
    'Betreff *'
        => 'Subject *',
    'Nachricht'
        => 'Message',
    'Nachricht *'
        => 'Message *',
    'Beschreibung'
        => 'Description',
    'Inhalt'
        => 'Content',
    'Kategorie'
        => 'Category',
    'Kategorie *'
        => 'Category *',
    'Priorität'
        => 'Priority',
    'Notiz'
        => 'Note',
    'Notizen'
        => 'Notes',
    'Quelle'
        => 'Source',
    'Position'
        => 'Item',
    'Positionen'
        => 'Items',
    'Gesamt'
        => 'Total',
    'Zeitpunkt'
        => 'Time',
    'Zeitraum'
        => 'Period',
    'Seiten'
        => 'Pages',
    'Treffer'
        => 'results',
    'Ja'
        => 'Yes',
    'Nein'
        => 'No',
    'Später'
        => 'Later',
    'Start'
        => 'Start',
    'Stop'
        => 'Stop',
    'Läuft'
        => 'Running',
    'Manuell'
        => 'Manual',
    'Fix'
        => 'Fixed',
    '(optional)'
        => '(optional)',
    'Suchen...'
        => 'Search…',
    'Suche...'
        => 'Search…',
    'Heute'
        => 'Today',
    'Neueste'
        => 'Newest',
    'Neueste zuerst'
        => 'Newest first',
    'Fortschritt'
        => 'Progress',
    'Fortschritt ↑'
        => 'Progress ↑',
    'Fortschritt ↓'
        => 'Progress ↓',
    'Statistiken'
        => 'Statistics',
    'Steuer'
        => 'Tax',
    'Modalität'
        => 'Terms',
    'Adresse'
        => 'Address',
    'Straße'
        => 'Street',
    'Straße & Hausnr. (optional)'
        => 'Street and number (optional)',
    'PLZ'
        => 'Postcode',
    'PLZ & Ort'
        => 'Postcode and town',
    'PLZ & Ort (optional)'
        => 'Postcode and town (optional)',
    'Ort'
        => 'Town',
    'Ort / Adresse'
        => 'Location / address',
    'Land'
        => 'Country',
    'Firma'
        => 'Company',
    'Telefon'
        => 'Phone',
    'Website'
        => 'Website',
    'Name/Firma'
        => 'Name / company',
    'Stammdaten'
        => 'Master data',
    'Portal Aktivitäten'
        => 'Portal activity',
    'Uploads'
        => 'Uploads',
    'Absegnungen'
        => 'Approvals',
    'Kommentare'
        => 'Comments',
    'Kommentare anzeigen'
        => 'Show comments',
    'Keine Uploads'
        => 'No uploads',
    'Keine Absegnungen'
        => 'No approvals',
    'Kein neues Feedback'
        => 'No new feedback',
    'Keine Kommentare'
        => 'No comments',
    'DATEI'
        => 'FILE',
    'BESTÄTIGT'
        => 'APPROVED',
    'NEUES FEEDBACK'
        => 'NEW FEEDBACK',
    'KOMMENTAR'
        => 'COMMENT',
    'Ausblenden'
        => 'Dismiss',
    'Neue Website-Anfragen'
        => 'New website enquiries',
    'Keine neuen Anfragen über die Website.'
        => 'No new enquiries from the website.',
    'Anfrage Details'
        => 'Enquiry details',
    'Anfrage löschen?'
        => 'Delete this enquiry?',
    'Die Nachricht unwiderruflich löschen?'
        => 'Delete this message permanently?',
    'Als Kontakt übernehmen'
        => 'Add as contact',
    'Offene Support-Tickets'
        => 'Open support tickets',
    'Keine offenen Support-Anfragen.'
        => 'No open support requests.',
    'Alle Tickets öffnen'
        => 'Open all tickets',
    'Laufende Projekte'
        => 'Active projects',
    'Aktuell keine laufenden Projekte.'
        => 'No active projects at the moment.',
    'Kein Projekt entspricht diesem Filter.'
        => 'No project matches this filter.',
    'offene Aufgaben'
        => 'open tasks',
    'Deadlines'
        => 'Deadlines',
    'Keine Deadlines'
        => 'No deadlines',
    'Termine'
        => 'Appointments',
    'Keine Termine'
        => 'No appointments',
    'System-Monitor'
        => 'System monitor',
    'Keine URLs im Monitor.'
        => 'No URLs monitored.',
    'URL hinzufügen'
        => 'Add URL',
    'URL zum Monitor hinzufügen'
        => 'Add a URL to the monitor',
    'URL entfernen?'
        => 'Remove this URL?',
    'Soll diese Domain aus der Überwachung entfernt werden?'
        => 'Remove this domain from monitoring?',
    'Status wird geprüft …'
        => 'Checking status …',
    'Webspace'
        => 'Web space',
    'Belegter Webspace'
        => 'Web space used',
    'von 200 GB belegt'
        => 'of 200 GB used',
    'Notizen leeren?'
        => 'Clear the notepad?',
    'Möchtest du den gesamten Text im Notizblock endgültig löschen?'
        => 'Permanently delete everything in the notepad?',
    'Wird automatisch gespeichert...'
        => 'Saved automatically…',
    'neue Website-Anfrage(n)!'
        => 'new website enquiry/enquiries.',
    'offene(s) Support-Ticket(s)!'
        => 'open support ticket(s).',
    'neue Datei(en) im Portal!'
        => 'new file(s) in the portal.',
    'abgesegnete(r) Meilenstein(e)!'
        => 'approved milestone(s).',
    'neues Kunden-Feedback!'
        => 'new client feedback.',
    'neue(r) Meilenstein-Kommentar(e)!'
        => 'new milestone comment(s).',
    'Kunde XYZ'
        => 'Client name',
    'Projekte'
        => 'Projects',
    'Neues Projekt'
        => 'New project',
    'Projekt anlegen'
        => 'Create project',
    'Projekt bearbeiten'
        => 'Edit project',
    'Projekt löschen'
        => 'Delete project',
    'Projekt / Name *'
        => 'Project / name *',
    'Möchtest du dieses Projekt wirklich endgültig löschen?'
        => 'Permanently delete this project?',
    'Keine Projekte gefunden, die diesen Kriterien entsprechen.'
        => 'No projects match these criteria.',
    'Meilensteine'
        => 'Milestones',
    'Neuer Meilenstein...'
        => 'New milestone…',
    'Meilenstein löschen'
        => 'Delete milestone',
    'Möchtest du diesen Meilenstein wirklich löschen?'
        => 'Delete this milestone?',
    'Auf wen wartet dieser Schritt?'
        => 'Who is this step waiting on?',
    'wir'
        => 'us',
    'Beteiligte'
        => 'Participants',
    'Beteiligte am Projekt'
        => 'Project participants',
    'Beteiligte verwalten'
        => 'Manage participants',
    'Weitere Beteiligte'
        => 'Additional participants',
    'Projekt-Dateien'
        => 'Project files',
    'Eigene Dateien hinzufügen'
        => 'Add your own files',
    'Dateien / Bilder anhängen'
        => 'Attach files or images',
    'Datei löschen'
        => 'Delete file',
    'Möchtest du diese Datei wirklich endgültig löschen?'
        => 'Permanently delete this file?',
    'Der Upload startet sofort nach der Auswahl. Die Dateien sind für den Kunden im Portal sofort sichtbar.'
        => 'The upload starts as soon as you choose a file. Clients see it in the portal immediately.',
    'Feedback vom Portal'
        => 'Feedback from the portal',
    'Noch kein Austausch.'
        => 'No messages yet.',
    'Antworten …'
        => 'Reply …',
    'Antworten…'
        => 'Reply…',
    'Ihre Antwort…'
        => 'Your reply…',
    'Zeit nachtragen'
        => 'Add time',
    'Minuten eingeben:'
        => 'Enter minutes:',
    'Startdatum'
        => 'Start date',
    'Start egal'
        => 'Any start',
    'Startmonat egal'
        => 'Any start month',
    'Deadline egal'
        => 'Any deadline',
    'Detaillierte Beschreibung…'
        => 'Detailed description…',
    'Beschreibung / Notizen'
        => 'Description / notes',
    'Soll der Kunde per E-Mail über den abgeschlossenen Meilenstein informiert werden?'
        => 'Notify the client by e-mail that this milestone is complete?',
    'Kunde benachrichtigen?'
        => 'Notify the client?',
    'Ja, senden'
        => 'Yes, send',
    'stornierte Aufgabe(n) — in Listenansicht anzeigen'
        => 'cancelled task(s) — show in list view',
    'In Liste bearbeiten'
        => 'Edit in list',
    'Keine Aufgaben in dieser Spalte'
        => 'No tasks in this column',
    'Status *'
        => 'Status *',
    'Neuen Kontakt anlegen'
        => 'Create contact',
    'Kontakt bearbeiten'
        => 'Edit contact',
    'Kontakt suchen...'
        => 'Search contacts…',
    'Kunden'
        => 'Clients',
    'Interessenten'
        => 'Prospects',
    'Alle Typen'
        => 'All types',
    'Suche nach Name, Firma, E-Mail oder Notiz...'
        => 'Search name, company, e-mail or note…',
    'Keine Kontakte für diese Suchkriterien gefunden.'
        => 'No contacts match this search.',
    'Keine Kontakte vorhanden.'
        => 'No contacts yet.',
    'Es gibt noch keine Kontakte mit Portalzugang.'
        => 'No contact has portal access yet.',
    'Portal erstellen'
        => 'Create portal',
    'Portal-Zugang:'
        => 'Portal access:',
    'PIN zurücksetzen'
        => 'Reset PIN',
    'Link kopieren'
        => 'Copy link',
    'Kundenprofil öffnen'
        => 'Open client profile',
    'QR & Mail'
        => 'QR and e-mail',
    'QR-Code speichern (.png)'
        => 'Save QR code (.png)',
    'E-Mail mit Link & QR-Code senden'
        => 'Send link and QR code by e-mail',
    'Mailto'
        => 'Mailto',
    'Mailto öffnen'
        => 'Open mailto',
    'Hier ist Ihr persönlicher Zugang zum Projekt-Portal. Dort können Sie jederzeit den Fortschritt Ihres Projekts oder Dienstleistung einsehen, mitwirken oder Feedback dalassen.'
        => 'Here is your personal access to the project portal. You can follow your project at any time, take part, and leave feedback.',
    'Interne Notizen'
        => 'Internal notes',
    'Meta & CRM'
        => 'Meta and CRM',
    'Ja, entfernen'
        => 'Yes, remove',
    'Ja, löschen'
        => 'Yes, delete',
    'Ja, leeren'
        => 'Yes, clear',
    'Eintrag löschen?'
        => 'Delete this entry?',
    'Diesen Eintrag wirklich löschen?'
        => 'Really delete this entry?',
    'Einnahmen'
        => 'Income',
    'Ausgaben'
        => 'Expenses',
    'Saldo'
        => 'Balance',
    'Offene Forderung'
        => 'Outstanding',
    'Neuer Eintrag'
        => 'New entry',
    'Bezeichnung'
        => 'Description',
    'Bezeichnung *'
        => 'Description *',
    'Betrag (€) *'
        => 'Amount (€) *',
    'Kunde *'
        => 'Client *',
    'Kunde (CRM)'
        => 'Client (CRM)',
    'Kunde (aus Kontakten)'
        => 'Client (from contacts)',
    '-- Ohne Kunde --'
        => '— No client —',
    '-- Ohne Zuordnung --'
        => '— Unassigned —',
    '-- Kunde aus CRM laden --'
        => '— Load client from CRM —',
    'Oder: Freitext-Name'
        => 'Or: free-text name',
    'Manueller Name'
        => 'Manual name',
    'Alle Kunden'
        => 'All clients',
    'Alle Status'
        => 'All statuses',
    'Alle Kategorien'
        => 'All categories',
    'Fixkosten'
        => 'Recurring',
    'Monatliches Abo'
        => 'Monthly subscription',
    'Einmalzahlung'
        => 'One-off payment',
    '2 Raten'
        => '2 instalments',
    '3 Raten'
        => '3 instalments',
    'Diesen Monat'
        => 'This month',
    'Dieses Jahr'
        => 'This year',
    'Zeitraum: Aktuelles Jahr'
        => 'Period: current year',
    'Zeitraum: Gesamt'
        => 'Period: all time',
    'Letzte 7 Tage'
        => 'Last 7 days',
    'Letzte 30 Tage'
        => 'Last 30 days',
    'Nächste 7 Tage'
        => 'Next 7 days',
    'Nächste 14 Tage'
        => 'Next 14 days',
    'Nächste 30 Tage'
        => 'Next 30 days',
    'Monatsüberblick'
        => 'Monthly overview',
    'Summe der gefilterten Liste:'
        => 'Total of the filtered list:',
    'Keine Einträge für diese Filter gefunden.'
        => 'No entries match these filters.',
    'Rechnung'
        => 'Invoice',
    'Rechnung ansehen'
        => 'View invoice',
    'Rechnung konfigurieren'
        => 'Configure invoice',
    'Rechnungsnummer'
        => 'Invoice number',
    'Rechnungsfällig'
        => 'Invoice due',
    'Fällig am'
        => 'Due on',
    'Zahlbar bis'
        => 'Payable by',
    'Eingegangen'
        => 'Received',
    'Datum / Alter'
        => 'Date / age',
    'PDF erstellen & verbuchen'
        => 'Create PDF and post',
    'PDF generieren'
        => 'Generate PDF',
    'PDF generieren & anzeigen'
        => 'Generate and open PDF',
    'Rechnung per E-Mail senden'
        => 'Send invoice by e-mail',
    'Rechnung erfolgreich per E-Mail gesendet!'
        => 'Invoice sent by e-mail.',
    'Per E-Mail senden'
        => 'Send by e-mail',
    'Per E-Mail an Kunden senden'
        => 'Send to the client by e-mail',
    'Empfänger (Kunde)'
        => 'Recipient (client)',
    'Empfänger-E-Mail *'
        => 'Recipient e-mail *',
    'An (E-Mail) *'
        => 'To (e-mail) *',
    'An: E-Mail-Adresse'
        => 'To: e-mail address',
    'Begleittext der E-Mail:'
        => 'Message body:',
    'Absender'
        => 'Sender',
    'Absender (Meine Daten)'
        => 'Sender (your details)',
    'Bankverbindung (IBAN)'
        => 'Bank account (IBAN)',
    'DE12 3456...'
        => 'DE12 3456…',
    'PayPal / Notiz'
        => 'PayPal / note',
    'PayPal Adresse (Optional)'
        => 'PayPal address (optional)',
    'Zusatzzeile 1 (z.B. Abteilung)'
        => 'Extra line 1 (e.g. department)',
    'Zusatzzeile 1 (z.B. Steuernummer)'
        => 'Extra line 1 (e.g. tax number)',
    'Zusatzzeile 2 (Optional)'
        => 'Extra line 2 (optional)',
    '(erscheint im PDF)'
        => '(appears in the PDF)',
    'Netto:'
        => 'Net:',
    'Brutto:'
        => 'Gross:',
    'MwSt (19%):'
        => 'VAT (19%):',
    'MwSt-Regel'
        => 'VAT rule',
    'Kleinunternehmer (0%)'
        => 'Small business (0%)',
    'Kleinunternehmer (§19 UStG, keine MwSt.)'
        => 'Small business (§19 UStG, no VAT)',
    'Regelbesteuerung (19%)'
        => 'Standard rate (19%)',
    'Regelbesteuerung (19% MwSt.)'
        => 'Standard rate (19% VAT)',
    'Gesamt: 0,00 €'
        => 'Total: €0.00',
    'z.B. Vielen Dank für Ihr Vertrauen. Bitte überweisen Sie den Betrag bis zum oben genannten Datum.'
        => 'e.g. Thank you for your business. Please transfer the amount by the date shown above.',
    'z.B. Webdesign – Projektabschluss'
        => 'e.g. Web design — project completion',
    'z.B. Mustermann GmbH'
        => 'e.g. Example Ltd',
    'kunde@example.com'
        => 'client@example.com',
    'Neues Angebot'
        => 'New quote',
    'Angebots-Nr.'
        => 'Quote no.',
    'Noch keine Angebote vorhanden.'
        => 'No quotes yet.',
    'Angenommenes Volumen'
        => 'Accepted volume',
    'Gesendet / Offen'
        => 'Sent / open',
    'Gültig bis'
        => 'Valid until',
    'Einleitungstext'
        => 'Introduction',
    'Zu Rechnung konvertieren'
        => 'Convert to invoice',
    'Angebot erfolgreich als Rechnung verbucht!'
        => 'Quote posted as an invoice.',
    'Angebot per E-Mail senden'
        => 'Send quote by e-mail',
    'Angebot erfolgreich per E-Mail gesendet!'
        => 'Quote sent by e-mail.',
    'Das Angebot-PDF wird automatisch angehängt.'
        => 'The quote PDF is attached automatically.',
    'Notizen / Anmerkungen'
        => 'Notes and remarks',
    'z.B. Zahlungsbedingungen, Hinweise...'
        => 'e.g. payment terms, remarks…',
    'z.B. Hiermit unterbreiten wir Ihnen folgendes Angebot für das besprochene Projekt...'
        => 'e.g. We are pleased to offer you the following for the project we discussed…',
    'z.B. Webseitenentwicklung – Angebot für Ihr Projekt'
        => 'e.g. Website development — quote for your project',
    'Z.B. Vielen Dank für das Vertrauen!'
        => 'e.g. Thank you for your trust.',
    '– Kunde auswählen –'
        => '— Select a client —',
    'Neues Ticket anlegen'
        => 'Create ticket',
    'Ticket erstellen'
        => 'Create ticket',
    'Ticket wurde erfolgreich erstellt.'
        => 'Ticket created.',
    'Ticket löschen?'
        => 'Delete this ticket?',
    'Ticket und alle Notizen endgültig löschen?'
        => 'Permanently delete this ticket and all its notes?',
    'Keine Tickets gefunden.'
        => 'No tickets found.',
    'Alle Prioritäten'
        => 'All priorities',
    'Priorität ändern'
        => 'Change priority',
    'Kunde, Betreff oder Nachricht…'
        => 'Client, subject or message…',
    'Kurze Beschreibung des Problems'
        => 'Short description of the problem',
    'Nachricht des Kunden'
        => 'Client message',
    'Interne Notiz hinzufügen…'
        => 'Add an internal note…',
    '⚪ Niedrig'
        => '⚪ Low',
    '🟡 Mittel'
        => '🟡 Medium',
    '🟠 Hoch'
        => '🟠 High',
    '🔴 Kritisch'
        => '🔴 Critical',
    'z.B. CSS, Login, API'
        => 'e.g. CSS, login, API',
    'Wiki durchsuchen...'
        => 'Search the wiki…',
    'Keine Wiki-Einträge gefunden.'
        => 'No wiki entries found.',
    'Oben anpinnen'
        => 'Pin to top',
    'Tags (Kommagetrennt)'
        => 'Tags (comma separated)',
    'Angehängte Dateien'
        => 'Attached files',
    'Vorhandene Anhänge (Klick auf Mülleimer zum Löschen)'
        => 'Existing attachments (click the bin to delete)',
    'Hat Anhänge'
        => 'Has attachments',
    'Im Kundenportal freigeben'
        => 'Share in the client portal',
    'Artikel im Kundenportal freigeben'
        => 'Share this article in the client portal',
    'Wähle, welche Kunden diesen Artikel in ihrem Portal lesen können:'
        => 'Choose which clients can read this article in their portal:',
    'Freigaben speichern'
        => 'Save sharing',
    'Weise einem Kontakt zuerst ein Portal-Token zu (Kontakte-Seite).'
        => 'Give a contact portal access first (on the Contacts page).',
    'Neuer Termin'
        => 'New appointment',
    'Termin gespeichert!'
        => 'Appointment saved.',
    'Termin gespeichert.'
        => 'Appointment saved.',
    'Termin löschen?'
        => 'Delete this appointment?',
    'Termin und alle Einladungen löschen?'
        => 'Delete this appointment and all its invitations?',
    'Startzeit'
        => 'Start time',
    'Endzeit'
        => 'End time',
    'Farbe'
        => 'Colour',
    'Meeting-Link'
        => 'Meeting link',
    '(Zoom, Teams, Meet…)'
        => '(Zoom, Teams, Meet…)',
    'Büro, Online, Adresse...'
        => 'Office, online, address…',
    'Agenda, Infos, Themen...'
        => 'Agenda, notes, topics…',
    'Kontakte einladen'
        => 'Invite contacts',
    'Einladungen versenden?'
        => 'Send invitations?',
    'Einladung(en) wurden versendet.'
        => 'Invitation(s) sent.',
    'Einladungs-E-Mail mit ICS-Kalenderlink an'
        => 'Invitation e-mail with an ICS calendar link to',
    'Für jeden Kontakt wird ein ICS-Einladungslink generiert (öffnet direkt Outlook/Google Kalender).'
        => 'Each contact gets an ICS invitation link that opens straight in Outlook or Google Calendar.',
    'Ja, jetzt senden'
        => 'Yes, send now',
    'senden?'
        => 'send?',
    '— Kein Kontakt ausgewählt —'
        => '— No contact selected —',
    'z.B. Kundengespräch mit Firma XY'
        => 'e.g. Client meeting with Example Ltd',
    'Einträge gesamt'
        => 'Entries in total',
    'Fehlversuche (7 Tage)'
        => 'Failed attempts (7 days)',
    'Letzte Anmeldung'
        => 'Last sign-in',
    'IP-Adresse'
        => 'IP address',
    'In Beschreibung/IP suchen…'
        => 'Search description or IP…',
    'Logbuch ist leer'
        => 'The log is empty',
    'Logbuch leeren?'
        => 'Clear the log?',
    'Möchtest du alle bisherigen Einträge wirklich unwiderruflich löschen?'
        => 'Permanently delete every entry so far?',
    'Notiz speichern'
        => 'Save note',
    'Wiederherstellen'
        => 'Restore',
    'Endgültig löschen'
        => 'Delete permanently',
    'Verbleibend'
        => 'Remaining',
    'Gelöscht am'
        => 'Deleted on',
    'Eintrag'
        => 'entry',
    'Nichts gelöscht.'
        => 'Nothing deleted.',
    'URL / Domain *'
        => 'URL / domain *',
    // ── Kundenportal ─────────────────────────────────────────────────
    'Sprache wechseln'         => 'Switch language',
    'Dunkles Design umschalten' => 'Toggle dark mode',
    // ── Einstellungsseite ────────────────────────────────────────────
    'Einstellungen gespeichert.'
        => 'Settings saved.',
    'Darstellung'
        => 'Appearance',
    'Benachrichtigungen'
        => 'Notifications',
    'E-Mail-Vorlagen'
        => 'E-mail templates',
    'Dark Mode'
        => 'Dark mode',
    'Dark Mode aktivieren'
        => 'Enable dark mode',
    'Wird lokal im Browser gespeichert.'
        => 'Stored locally in your browser.',
    'Farben'
        => 'Colours',
    'Primärfarbe (Akzent)'
        => 'Primary colour (accent)',
    'Sidebar-Farbe'
        => 'Sidebar colour',
    'Farben speichern'
        => 'Save colours',
    'Unternehmensangaben'
        => 'Company details',
    'Vollständiger Name'
        => 'Full name',
    'Wird auf Rechnungen und im Footer verwendet.'
        => 'Used on invoices and in the footer.',
    'Kurzname'
        => 'Short name',
    'Wird im Seitentitel und Portal-Header verwendet.'
        => 'Used in the page title and the portal header.',
    'Admin-Panel URL'
        => 'Admin panel URL',
    'Wichtig für QR-Codes und Links.'
        => 'Important for QR codes and links.',
    'Hauptwebsite'
        => 'Main website',
    'Admin-E-Mail'
        => 'Admin e-mail',
    'Support-E-Mail'
        => 'Support e-mail',
    'Diese Werte überschreiben die Konstanten in'
        => 'These values override the constants in',
    'und werden sofort auf allen Seiten wirksam.'
        => 'and take effect immediately on every page.',
    'Bankverbindung'
        => 'Bank details',
    'Kontoinhaber'
        => 'Account holder',
    'Hinweis zur Zahlung'
        => 'Payment note',
    'z. B. Zahlbar innerhalb von 14 Tagen ohne Abzug.'
        => 'e.g. Payable within 14 days, no deductions.',
    'Firmenlogo (für Rechnungen)'
        => 'Company logo (for invoices)',
    'Firmenlogo'
        => 'Company logo',
    'Logo entfernen'
        => 'Remove logo',
    'Noch kein Logo hinterlegt. Ohne Logo erscheint nur der Firmenname auf Rechnungen.'
        => 'No logo yet. Without one, invoices show only the company name.',
    'Logo hochladen'
        => 'Upload logo',
    'PNG, JPG, GIF oder WebP · max. 2 MB · Empfehlung: transparenter Hintergrund, ca. 400×120 px'
        => 'PNG, JPG, GIF or WebP · max. 2 MB · recommended: transparent background, about 400×120 px',
    'Hochladen & speichern'
        => 'Upload and save',
    'Tab-Icon (Favicon)'
        => 'Tab icon (favicon)',
    'Favicon'
        => 'Favicon',
    'Favicon entfernen'
        => 'Remove favicon',
    'Noch kein Favicon hinterlegt. Browser zeigen dann das Standard-Icon.'
        => 'No favicon yet. Browsers will show their default icon.',
    'Favicon hochladen'
        => 'Upload favicon',
    'ICO oder PNG · max. 512 KB · Empfehlung: 32×32 px oder 64×64 px'
        => 'ICO or PNG · max. 512 KB · recommended: 32×32 px or 64×64 px',
    'E-Mail-Benachrichtigungen'
        => 'E-mail notifications',
    'Meilenstein-E-Mail-Bestätigung'
        => 'Milestone confirmation e-mail',
    'Beim Abschließen eines Meilensteins im Portal wird der Kunde per E-Mail gefragt, ob er den Meilenstein offiziell bestätigen möchte.'
        => 'When a milestone is completed in the portal, the client is asked by e-mail whether to approve it formally.',
    'Angebots-E-Mail beim Versand'
        => 'Quote e-mail on sending',
    'Beim Versand eines Angebots wird automatisch eine E-Mail an den Kunden generiert.'
        => 'Sending a quote generates an e-mail to the client automatically.',
    'Rahmen aller E-Mails'
        => 'Frame around every e-mail',
    'Kopfbereich, Farben und Logo kommen aus'
        => 'Header, colours and logo come from',
    'Signatur'
        => 'Signature',
    'Steht am Ende jeder Nachricht, vor der Fußzeile.'
        => 'Appears at the end of every message, above the footer.',
    'Fußzeile'
        => 'Footer',
    'Die kleine Zeile ganz unten. Leer lassen für Firmenname und Website.'
        => 'The small line at the very bottom. Leave empty for company name and website.',
    'Rahmen speichern'
        => 'Save frame',
    'Vorlagen'
        => 'Templates',
    'Von Ihnen angepasst'
        => 'Edited by you',
    'Auf Standard zurücksetzen'
        => 'Reset to default',
    'Platzhalter — anklicken zum Einfügen'
        => 'Placeholders — click to insert',
    'Vorlage speichern'
        => 'Save template',
    'Vorschau mit Beispieldaten'
        => 'Preview with example data',
    'Vorschau der E-Mail'
        => 'E-mail preview',
    'Betreff:'
        => 'Subject:',
    'Log-Einstellungen'
        => 'Log settings',
    'Log-Anzeigelimit'
        => 'Log display limit',
    'Maximale Anzahl Logs auf der Log-Seite (Standard: 200, max. 2000).'
        => 'Maximum number of entries on the log page (default 200, max 2000).',
    'Systeminfo'
        => 'System information',
    'PHP Version'
        => 'PHP version',
    'MySQL Version'
        => 'MySQL version',
    'Server'
        => 'Server',
    'Zeitzone'
        => 'Time zone',
    // ── Portal und JavaScript-Meldungen ──────────────────────────────
    'Ganztägig'
        => 'All day',
    'ICS-Einladungslink kopieren'
        => 'Copy ICS invitation link',
    'ICS-Link kopieren:'
        => 'Copy ICS link:',
    'Einladung per E-Mail senden'
        => 'Send invitation by e-mail',
    'Einladung per E-Mail an diesen Kontakt senden?'
        => 'Send an invitation to this contact by e-mail?',
    'Einladungen an alle Kontakte dieses Termins senden?'
        => 'Send invitations to every contact for this appointment?',
    'Nicht gesetzt'
        => 'Not set',
    'Keine Beschreibung vorhanden.'
        => 'No description.',
    'Sicher?'
        => 'Are you sure?',
    'Sicher? Klick noch mal!'
        => 'Are you sure? Click again.',
    'Leistungsbeschreibung...'
        => 'Description of work…',
    'Beschreibung...'
        => 'Description…',
    'Gesamt: '
        => 'Total: ',
    'Service: '
        => 'Service: ',
    'Fehler: '
        => 'Error: ',
    'Netzwerkfehler: '
        => 'Network error: ',
    'Unbekannter Fehler'
        => 'Unknown error',
    'Serverfehler – bitte Logs prüfen.'
        => 'Server error — please check the logs.',
    'Keine Angabe'
        => 'Not specified',
    'Keine Nachricht hinterlassen.'
        => 'No message left.',
    'Keine E-Mail hinterlegt'
        => 'No e-mail on file',
    'Kein Feedback.'
        => 'No feedback.',
    'Keine Dokumente'
        => 'No documents',
    'Bitte Antworttext eingeben.'
        => 'Please enter a reply.',
    'Bitte E-Mail-Adresse angeben.'
        => 'Please enter an e-mail address.',
    'Wird gesendet…'
        => 'Sending…',
    'Wird gespeichert…'
        => 'Saving…',
    '✓ Gesendet & gespeichert'
        => '✓ Sent and saved',
    '✓ Im Portal gespeichert'
        => '✓ Saved in the portal',
    'Im Browser ansehen'
        => 'View in the browser',
    'Upload erfolgreich!'
        => 'Upload complete.',
    'Fehler beim Upload!'
        => 'Upload failed.',
    'Es gab einen Fehler beim Upload!'
        => 'The upload failed.',
    'Gespeichert, aber einige Dateien wurden abgelehnt:'
        => 'Saved, but some files were rejected:',
    'Bitte speichern Sie das Projekt zuerst, bevor Sie Dateien hochladen!'
        => 'Please save the project before uploading files.',
    ' Tage alt'
        => ' days old',
    '--- Ursprüngliche Anfrage ---'
        => '--- Original enquiry ---',
    'Dateityp .'
        => 'File type .',
    ' ist zu groß (max. 100 MB).'
        => ' is too large (max. 100 MB).',
    'Datei zu groß (max. 100 MB).'
        => 'File too large (max. 100 MB).',
    'Dieser Dateityp ist gesperrt.'
        => 'This file type is blocked.',
    'Für dieses Projekt haben Sie keine Berechtigung.'
        => 'You do not have permission for this project.',
    '&ndash; Zugangscode'
        => '&ndash; access code',
    'Alle Daten sind erfunden, Änderungen werden nicht gespeichert.'
        => 'Every record is invented, and nothing you change is saved.',
    'Willkommen zurück'
        => 'Welcome back',
    'Portal einrichten'
        => 'Set up your portal',
    'Legen Sie einmalig einen persönlichen Zugangscode für Ihr Portal fest.'
        => 'Choose a personal access code for your portal. You only do this once.',
    'Zugang vorübergehend gesperrt'
        => 'Access temporarily locked',
    'Bitte kontaktieren Sie'
        => 'Please contact',
    'zum Zurücksetzen.'
        => 'to have it reset.',
    'Zugangscode'
        => 'Access code',
    'Zugangscode wählen'
        => 'Choose an access code',
    '(mind. 4 Zeichen)'
        => '(at least 4 characters)',
    'Neuer Zugangscode'
        => 'New access code',
    'Zugangscode bestätigen'
        => 'Confirm access code',
    'Wiederholen'
        => 'Repeat',
    'Zugangscode festlegen'
        => 'Set access code',
    'Bereitgestellt von'
        => 'Provided by',
    '✓ Übereinstimmend'
        => '✓ Match',
    '✗ Stimmt nicht überein'
        => '✗ Does not match',
    'PARTNER'
        => 'PARTNER',
    'Aktive Projekte'
        => 'Active projects',
    'Projekte gesamt'
        => 'Projects in total',
    'Offene Rechnungen'
        => 'Open invoices',
    'Mein Profil'
        => 'My profile',
    'Projekt suchen...'
        => 'Search projects…',
    'Zum hellen Design wechseln'
        => 'Switch to the light theme',
    'Zum dunklen Design wechseln'
        => 'Switch to the dark theme',
    'Schließen Sie erledigte Schritte mit "Absegnen" ab, damit wir mit dem nächsten beginnen können.'
        => 'Mark finished steps as approved so we can start the next one.',
    'Warten auf Ihre Freigabe'
        => 'Waiting for your approval',
    'Absegnen'
        => 'Approve',
    'Ihre Anmerkung zu diesem Schritt...'
        => 'Your note on this step…',
    'Noch keine Meilensteine für dieses Projekt.'
        => 'No milestones for this project yet.',
    'Ansehen'
        => 'View',
    'Dateien hochladen'
        => 'Upload files',
    'Klicken oder Dateien hierher ziehen · max. 100 MB'
        => 'Click or drop files here · max. 100 MB',
    'Beitrag'
        => 'message',
    'Etwas mitteilen oder nachfragen …'
        => 'Share something or ask a question …',
    'Zuletzt geschrieben von'
        => 'Last written by',
    'Allgemeines Feedback'
        => 'General feedback',
    'Haben Sie Fragen oder Korrekturwünsche zum aktuellen Stand?'
        => 'Any questions or corrections on where things stand?',
    'Ihre Anmerkungen...'
        => 'Your remarks…',
    'Feedback speichern'
        => 'Save feedback',
    'Aktuell liegt kein Angebot vor.'
        => 'There is no quote at the moment.',
    'Angebot als PDF'
        => 'Quote as PDF',
    'Angebot annehmen'
        => 'Accept quote',
    'Rückfrage stellen'
        => 'Ask a question',
    'Die Frist ist abgelaufen — melden Sie sich gern, wir machen Ihnen ein neues Angebot.'
        => 'This quote has expired — get in touch and we will send you a new one.',
    'Angenommen — vielen Dank!'
        => 'Accepted — thank you.',
    'Ihre Rückfrage'
        => 'Your question',
    'Was möchten Sie wissen oder anders haben?'
        => 'What would you like to know or have changed?',
    'Rückfrage senden'
        => 'Send question',
    'Eine Rückfrage ändert am Angebot nichts — sie erreicht uns als Nachricht.'
        => 'Asking a question changes nothing about the quote — it simply reaches us as a message.',
    'Noch keine Rechnungen vorhanden.'
        => 'No invoices yet.',
    'Zahlung'
        => 'Payment',
    'Empfänger'
        => 'Payee',
    'Verwendungszweck'
        => 'Reference',
    'PDF auf Anfrage'
        => 'PDF on request',
    'Worum geht es?'
        => 'What is it about?',
    'Beschreiben Sie Ihr Anliegen...'
        => 'Describe your request…',
    'Noch keine Anfragen gestellt.'
        => 'No requests yet.',
    'Antwort oder Rückfrage hinzufügen…'
        => 'Add a reply or a question…',
    'Absenden'
        => 'Send',
    'Priorität:'
        => 'Priority:',
    'Wissensdatenbank'
        => 'Knowledge base',
    'Ihre bei uns hinterlegten Stammdaten — werden u.a. für die Rechnungsstellung verwendet.'
        => 'The details we hold for you — used for invoicing, among other things.',
    'Kontaktdaten'
        => 'Contact details',
    'Vor- & Nachname *'
        => 'First and last name *',
    'Firmenname'
        => 'Company name',
    'E-Mail *'
        => 'E-mail *',
    'Rechnungsadresse'
        => 'Billing address',
    'Straße & Hausnummer'
        => 'Street and number',
    'Daten speichern'
        => 'Save details',
    // ── Von JavaScript erzeugtes HTML ────────────────────────────────
    'Noch keine Meilensteine angelegt.'
        => 'No milestones yet.',
    'Keine Einträge für diesen Tag.'
        => 'Nothing on this day.',
    'Keine Dokumente.'
        => 'No documents.',
    'Noch keine Notizen.'
        => 'No notes yet.',
    'Alle einladen'
        => 'Invite everyone',
    'und'
        => 'and',
    'Hier stellen Sie ein, was unter jeder Nachricht steht.'
        => 'Here you set what appears below every message.',
    'angepasst'
        => 'edited',
    // ── Portal, Protokoll, Papierkorb ────────────────────────────────
    'Ungültiger Zugriff. Bitte nutzen Sie den Link aus Ihrer E-Mail.'
        => 'Invalid access. Please use the link from your e-mail.',
    'Zugang abgelaufen oder ungültig.'
        => 'Access expired or invalid.',
    // ── Stundensätze und Zeitabrechnung ─────────────────────────────
    'Stundensatz'
        => 'Hourly rate',
    'Stundensatz (Voreinstellung)'
        => 'Hourly rate (default)',
    'leer = Voreinstellung'
        => 'empty = default',
    'Gilt, wenn weder das Projekt noch der Kunde einen eigenen Satz hat.'
        => 'Applies when neither the project nor the client has its own rate.',
    'Std'
        => 'hrs',
    'Kein Zugriff.'
        => 'No access.',
    'Datei nicht gefunden.'
        => 'File not found.',
    'Zugang'
        => 'Access',
    'Hallo %s, geben Sie Ihren Zugangscode ein.'
        => 'Hello %s, please enter your access code.',
    'Der Zugangscode muss mindestens 4 Zeichen haben.'
        => 'The access code must be at least 4 characters long.',
    'Die Zugangscodes stimmen nicht überein.'
        => 'The access codes do not match.',
    'Falscher Zugangscode.'
        => 'Wrong access code.',
    'Falscher Zugangscode. Noch %d Versuch(e).'
        => 'Wrong access code. %d attempt(s) left.',
    'Zu viele Fehlversuche. Bitte noch %d Minute(n) warten.'
        => 'Too many failed attempts. Please wait %d more minute(s).',
    'Zu viele Fehlversuche. Zugang für 30 Minuten gesperrt.'
        => 'Too many failed attempts. Access locked for 30 minutes.',
    'Meilenstein erfolgreich abgesegnet!'
        => 'Milestone approved.',
    'Ihr Feedback wurde gespeichert!'
        => 'Your feedback has been saved.',
    'Datei erfolgreich entfernt!'
        => 'File removed.',
    'Datei(en) erfolgreich hochgeladen!'
        => 'File(s) uploaded.',
    'Ihre Anfrage wurde gesendet!'
        => 'Your request has been sent.',
    'Ihre Antwort wurde gesendet!'
        => 'Your reply has been sent.',
    'Ticket als erledigt markiert.'
        => 'Ticket marked as done.',
    'Ticket wurde gelöscht.'
        => 'Ticket deleted.',
    'Ihre Daten wurden aktualisiert!'
        => 'Your details have been updated.',
    'Vielen Dank! Wir haben Ihre Zusage erhalten.'
        => 'Thank you. We have received your acceptance.',
    'Ihre Rückfrage ist bei uns eingegangen.'
        => 'Your question has reached us.',
    'Ihr Beitrag ist gespeichert.'
        => 'Your message has been saved.',
    'Guten Tag'
        => 'Hello',
    'Ihr persönliches Partner-Portal'
        => 'Your personal partner portal',
    'Ihr persönliches Projektportal'
        => 'Your personal project portal',
    'Offene Anfragen'
        => 'Open requests',
    'Aktuell sind keine Projekte hinterlegt.'
        => 'There are no projects at the moment.',
    'Aktuell sind keine gemeinsamen Projekte hinterlegt.'
        => 'There are no shared projects at the moment.',
    'Roadmap & Meilensteine'
        => 'Roadmap and milestones',
    'Wir sind dran'
        => 'With us',
    'Sie sind dran'
        => 'With you',
    'Abgeschlossen am'
        => 'Completed on',
    'Freigegeben am'
        => 'Approved on',
    'Kommentar hinterlassen'
        => 'Leave a comment',
    'Dokumente & Dateien'
        => 'Documents and files',
    'Dateien & Assets'
        => 'Files and assets',
    'Von uns hochgeladen'
        => 'Uploaded by us',
    'Von Ihrer Seite hochgeladen'
        => 'Uploaded by your side',
    'Von uns'
        => 'From us',
    'Von Ihnen'
        => 'From you',
    'Angebote zur gemeinsamen Zusammenarbeit.'
        => 'Quotes for our joint work.',
    'Hier können Sie ein Angebot direkt annehmen oder eine Rückfrage stellen.'
        => 'You can accept a quote here, or ask a question about it.',
    'Frist abgelaufen am'
        => 'Expired on',
    'Alle Rechnungen auf einen Blick — als PDF herunterladbar.'
        => 'All invoices at a glance — downloadable as PDF.',
    'Gemeinsame Abrechnungen auf einen Blick — als PDF herunterladbar.'
        => 'Shared invoices at a glance — downloadable as PDF.',
    'Neue Anfrage'
        => 'New request',
    'Neue Mitteilung'
        => 'New message',
    'Probleme mit der Website oder Änderungswünsche? Ich melde mich schnellstmöglich.'
        => 'Problems with the website, or changes you would like? I will get back to you as soon as I can.',
    'Fragen zur Zusammenarbeit oder sonstige Anliegen? Ich melde mich schnellstmöglich.'
        => 'Questions about our work together, or anything else? I will get back to you as soon as I can.',
    'Ticket absenden'
        => 'Send request',
    'Meine Anfragen'
        => 'My requests',
    'Bisherige Mitteilungen'
        => 'Previous messages',
    'Kein Kunde'
        => 'No client',
    'Vorbelegung, reiner Text'
        => 'Default, plain text',
    'HTML mit Rahmen'
        => 'HTML with frame',
    ' gefilterte Einträge (max. '
        => ' filtered entries (max. ',
    'Anzeige der letzten '
        => 'Showing the last ',
    ' Einträge.'
        => ' entries.',
    'Eintrag wiederhergestellt.'
        => 'Entry restored.',
    'Eintrag endgültig gelöscht.'
        => 'Entry permanently deleted.',
    '(ohne Titel)'
        => '(untitled)',
    'Sprache, Farben und Thema gelten nur für Ihren Besuch. Andere Besucher sehen weiterhin die Vorgaben, und beim nächsten Aufruf beginnt alles von vorn.'
        => 'Language, colours and theme apply to your visit only. Other visitors still see the defaults, and everything starts over on your next visit.',
    // ── Reiter, Zwischentexte, Bruchstuecke ──────────────────────────
    'weitere'
        => 'more',
    'Einnahmen vs. Ausgaben —'
        => 'Income vs. expenses —',
    'Kundenportal'
        => 'Client portal',
    'Zusammenarbeit'
        => 'Collaboration',
    'Abrechnungen'
        => 'Statements',
    'Rechnungen'
        => 'Invoices',
    'Anfragen'
        => 'Requests',
    'Support'
        => 'Support',
    'Ressourcen'
        => 'Resources',
    'Wissen'
        => 'Knowledge',
    'Schritten abgeschlossen'
        => 'steps completed',
    'Unbekannt'
        => 'Unknown',
    'Hauptansprechpartner'
        => 'Main contact',
    'Beteiligt'
        => 'Involved',
    'Austausch zum Projekt'
        => 'Project discussion',
    'wartet auf Ihre Antwort'
        => 'awaiting your reply',
    'Angebot'
        => 'Quote',
    'Rechnungsarchiv'
        => 'Invoice archive',
    'offen / überfällig'
        => 'open / overdue',
    'fällig'
        => 'due',
    'Artikel, die'
        => 'Articles that',
    'für Sie freigegeben hat.'
        => 'has shared with you.',
    'Deutschland'
        => 'Germany',
    'Filter'
        => 'Filter',
    'Austausch'
        => 'Discussion',
    'Zeiterfassung'
        => 'Time tracking',
    'Uploads ('
        => 'Uploads (',
    'd alt'
        => 'd old',
    'Eintrag/Einträge im Papierkorb'
        => 'entry/entries in the trash',
    'Gelöschtes bleibt'
        => 'Deleted items stay recoverable for',
    'Soeben aufgeräumt:'
        => 'Just cleaned up:',
    ' Tage'
        => ' days',
    // Verschiebbare Widgets auf der Startseite
    "Widgets"
        => "Widgets",
    "Widgets ein- und ausblenden"
        => "Show and hide widgets",
    "Auf der Startseite zeigen"
        => "Show on the dashboard",
    "Standard wiederherstellen"
        => "Restore default",
    "Widget ausblenden"
        => "Hide widget",
    "Projekte (Kennzahl)"
        => "Projects (metric)",
    "Kontakte (Kennzahl)"
        => "Contacts (metric)",
    // Beteiligten-Auswahl der Projekte
    "Partner"
        => "Partner",
    "kein Portal-Zugang"
        => "no portal access",
    "Person suchen …"
        => "Search for a person …",
    "Niemand gefunden."
        => "Nobody found.",
    // ── Wiederkehrende Eintraege ─────────────────────────────────────
    // Die drei Intervall-Bezeichnungen stehen als Wert in
    // wiederholung_intervalle() (includes/recurring.php) und werden mit
    // te($iv['label']) uebersetzt. check_i18n.php sieht dort kein
    // Literal und kann sie deshalb weder anmahnen noch als verwaist
    // melden - sie muessen hier von Hand gepflegt werden.
    "Wiederholung"
        => "Repeat",
    "Keine – einmaliger Eintrag"
        => "None – one-off entry",
    "Monatlich"
        => "Monthly",
    "Vierteljährlich"
        => "Quarterly",
    "Jährlich"
        => "Yearly",
    "Legt den nächsten Eintrag beim Cron-Lauf automatisch an."
        => "Creates the next entry automatically on the cron run.",
    "Nächster Termin"
        => "Next date",
    "nächster Termin"
        => "next date",
    "Leer lassen: ein Intervall nach dem Datum oben."
        => "Leave empty: one interval after the date above.",
    "Als Fixkosten markiert"
        => "Marked as a fixed cost",

    // ── Zahlungserinnerungen ─────────────────────────────────────────
    "Zahlungserinnerung"
        => "Payment reminder",
    "Zahlungserinnerungen"
        => "Payment reminders",
    "Zahlungserinnerung senden"
        => "Send a payment reminder",
    "Zahlungserinnerung gesendet."
        => "Payment reminder sent.",
    "Bisher verschickt:"
        => "Sent so far:",
    "Für diese Rechnung wurde noch nicht erinnert."
        => "No reminder has been sent for this invoice yet.",
    "Zuletzt erinnert am %s"
        => "Last reminded on %s",
    "Das Rechnungs-PDF wird angehängt, sofern eines vorliegt."
        => "The invoice PDF is attached if one exists.",
    "Mahnstufen (Tage nach Fälligkeit)"
        => "Reminder stages (days after the due date)",
    "z. B. 7, 21"
        => "e.g. 7, 21",
    "Leer lassen, um keine Erinnerungen automatisch zu versenden. Der Knopf in der Rechnungsliste funktioniert unabhängig davon."
        => "Leave empty to send no reminders automatically. The button in the invoice list works regardless.",
    "Automatische Erinnerungen werden nur verschickt, wenn cron.php regelmäßig läuft. Ohne eingerichteten Cron-Lauf passiert hier nichts."
        => "Automatic reminders only go out if cron.php runs regularly. Without a scheduled cron run, nothing happens here.",
    // ── Auswertungen (reports.php) ───────────────────────────────────
    "Auswertungen"
        => "Reports",
    "Auswertung"
        => "Report",
    "Stundenzettel"
        => "Timesheet",
    "Offene Posten nach Alter"
        => "Outstanding invoices by age",
    "Keine offenen Rechnungen."
        => "No outstanding invoices.",
    "%d Rechnung(en)"
        => "%d invoice(s)",
    "%d Tage"
        => "%d days",
    "Nummer"
        => "Number",
    "Fällig"
        => "Due",
    "Erinnert"
        => "Reminded",
    "Umsatz je Kunde"
        => "Revenue by client",
    "Für dieses Jahr gibt es keine Einnahmen."
        => "There is no income for this year.",
    "Bezahlt: %s € · Offen: %s €"
        => "Paid: %s € · Outstanding: %s €",
    "Anteil"
        => "Share",
    "Geleistet, noch nicht berechnet"
        => "Worked, not yet billed",
    "Jede erfasste Stunde ist abgerechnet."
        => "Every tracked hour has been billed.",
    "%s Stunden aus %d Projekt(en). Bewertet mit dem heute geltenden Satz (Projekt vor Kunde vor Voreinstellung)."
        => "%s hours across %d project(s), valued at the rate in force today (project before client before default).",
    "Projekt"
        => "Project",
    "Erfasst"
        => "Tracked",
    "Berechnet"
        => "Billed",
    "Satz"
        => "Rate",
    "Wert"
        => "Value",
    "In diesem Zeitraum wurde keine Zeit erfasst."
        => "No time was tracked in this period.",
    "Davon offen"
        => "Of that unbilled",
    "Einträge"
        => "Entries",
    "Nach Tag"
        => "By day",
    "Nach Projekt"
        => "By project",
    "%s offen"
        => "%s unbilled",
    "Abgerechnet"
        => "Billed",
    "Noch nicht abgerechnet"
        => "Not billed yet",
    "CSV"
        => "CSV",

    // Altersstufen der offenen Posten - ueber datenwert() nachgeschlagen,
    // weil sie an der Anzeigestelle als Variable ankommen.
    "nicht fällig"
        => "not yet due",
    "1–30 Tage"
        => "1–30 days",
    "31–60 Tage"
        => "31–60 days",
    "61–90 Tage"
        => "61–90 days",
    "über 90 Tage"
        => "over 90 days",
    // ── Belege zu Ausgaben ───────────────────────────────────────────
    "Beleg"
        => "Receipt",
    "Belege"
        => "Receipts",
    "Beleg ansehen"
        => "View receipt",
    "Hinterlegten Beleg ansehen"
        => "View the attached receipt",
    "Den hinterlegten Beleg wirklich entfernen?"
        => "Really remove the attached receipt?",
    "PDF oder Bild, höchstens 20 MB. Ein neuer Beleg ersetzt den bisherigen."
        => "PDF or image, 20 MB at most. A new receipt replaces the existing one.",
    "Ausgaben mit Belegen"
        => "Expenses with receipts",
    // ── Passwort zurücksetzen ────────────────────────────────────────
    "Passwort vergessen?"
        => "Forgot your password?",
    "Passwort zurücksetzen"
        => "Reset password",
    "Geben Sie die E-Mail-Adresse Ihres Zugangs an. Wir schicken einen Link, mit dem sich ein neues Passwort festlegen lässt."
        => "Enter the e-mail address of your account. We will send a link that lets you set a new password.",
    "Link anfordern"
        => "Request a link",
    "Zurück zur Anmeldung"
        => "Back to sign-in",
    "Falls diese Adresse hinterlegt ist, wurde eine E-Mail mit einem Link verschickt."
        => "If that address is on file, an e-mail with a link has been sent.",
    "Zu viele Anforderungen. Bitte später erneut versuchen."
        => "Too many requests. Please try again later.",
    "Legen Sie ein neues Passwort für %s fest."
        => "Set a new password for %s.",
    "Passwort speichern"
        => "Save password",
    "Das Passwort wurde geändert. Sie können sich jetzt anmelden."
        => "The password has been changed. You can sign in now.",
    "Dieser Link ist nicht mehr gültig. Fordern Sie einen neuen an."
        => "This link is no longer valid. Request a new one.",
    "Neuen Link anfordern"
        => "Request a new link",
    // ── Mailprotokoll (systemlogs.php) ───────────────────────────────
    "Ereignisse"
        => "Events",
    "Versendete E-Mails"
        => "Sent e-mails",
    "Sendungen gesamt"
        => "Messages in total",
    "Fehlgeschlagen"
        => "Failed",
    "Zugestellt"
        => "Delivered",
    "%d Einträge angezeigt"
        => "%d entries shown",
    "Noch keine Sendungen aufgezeichnet."
        => "No messages recorded yet.",
    "Vorlage"
        => "Template",
    "Bezug"
        => "Relates to",
    // ── Angebot zu Projekt ───────────────────────────────────────────
    "Zu Projekt machen"
        => "Turn into a project",
    "Zum Projekt aus diesem Angebot"
        => "Go to the project from this quote",
    "Aus Angebot %s ein Projekt anlegen? Jede Position wird ein Meilenstein."
        => "Create a project from quote %s? Every line item becomes a milestone.",
    // ── Erreichbarkeit mit Verlauf ───────────────────────────────────
    "Verfügbarkeit der letzten 24 Stunden, %d Messungen"
        => "Availability over the last 24 hours, %d checks",
];
