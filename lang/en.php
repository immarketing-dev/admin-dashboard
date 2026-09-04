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
];
