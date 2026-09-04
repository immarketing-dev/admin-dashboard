<?php
/**
 * E-Mail-Vorlagen.
 *
 * Bis hierher standen die sieben Mails als HTML-Zeichenketten mitten in
 * tasks.php, contacts.php, tickets.php, calendar.php, quotes.php und
 * finances.php. Wer den Wortlaut ändern wollte, musste den Code ändern.
 *
 * Aufteilung:
 *   - Der Text jeder Vorlage (Betreff und Nachricht) ist bearbeitbar und
 *     liegt in der settings-Tabelle unter mailtpl_<schlüssel>_subject
 *     bzw. _body. Fehlt der Eintrag, gilt der Standard aus dieser Datei.
 *   - Der Rahmen — Kopfbereich, Farbe, Schaltfläche, Fußzeile, Signatur —
 *     ist einmal zentral gestaltet und gilt für alle Mails. So lässt sich
 *     der Wortlaut ändern, ohne das Tabellen-Layout zu zerlegen, das
 *     E-Mails brauchen, um in Outlook nicht auseinanderzufallen.
 *
 * Platzhalter werden als {{name}} geschrieben und beim Rendern ersetzt.
 * Im HTML-Teil werden die Werte maskiert, im Textteil nicht.
 */

/**
 * Alle bearbeitbaren Vorlagen mit ihren Standardtexten.
 *
 * 'vars' ist die Liste der Platzhalter, die der Editor als Hilfe anzeigt.
 * 'button' beschreibt die optionale Schaltfläche im Rahmen.
 */
function mail_templates(): array
{
    return [
        'milestone' => [
            'label'   => 'Meilenstein abgeschlossen',
            'hint'    => 'Geht an den Projektkontakt, sobald ein Meilenstein abgehakt wird.',
            'vars'    => ['kunde', 'projekt', 'meilenstein', 'firma'],
            'subject' => 'Projektfortschritt: {{meilenstein}} | {{firma}}',
            'body'    => "Hallo {{kunde}},\n\n"
                       . "im Projekt „{{projekt}}“ ist ein weiterer Schritt geschafft:\n"
                       . "{{meilenstein}}\n\n"
                       . "Den aktuellen Stand können Sie jederzeit im Portal einsehen.",
            'button'  => 'Zum Projektportal',
        ],

        'portal_access' => [
            'label'   => 'Portal-Zugang',
            'hint'    => 'Die Einladung mit Zugangslink und QR-Code, versendet aus den Kontakten.',
            'vars'    => ['kunde', 'nachricht', 'firma'],
            'subject' => 'Ihr Zugang zum Projekt-Portal | {{firma}}',
            'body'    => "Hallo {{kunde}},\n\n{{nachricht}}",
            'button'  => 'Portal öffnen',
        ],

        'ticket_reply' => [
            'label'   => 'Antwort auf eine Support-Anfrage',
            'hint'    => 'Geht an den Kunden, wenn Sie eine Anfrage öffentlich beantworten.',
            'vars'    => ['kunde', 'betreff', 'antwort', 'firma'],
            'subject' => '{{firma}}: Neue Antwort auf Ihre Support-Anfrage',
            'body'    => "Hallo {{kunde}},\n\n"
                       . "zu Ihrer Anfrage „{{betreff}}“ gibt es eine Antwort:\n\n"
                       . "{{antwort}}",
            'button'  => 'Anfrage im Portal ansehen',
        ],

        'event_invite' => [
            'label'   => 'Termineinladung',
            'hint'    => 'Die Einladung aus dem Kalender, mit Kalenderdatei im Anhang.',
            'vars'    => ['kunde', 'titel', 'datum', 'ort', 'beschreibung', 'firma'],
            'subject' => 'Einladung: {{titel}} am {{datum}}',
            'body'    => "Hallo {{kunde}},\n\n"
                       . "Sie sind zu folgendem Termin eingeladen:\n\n"
                       . "{{titel}}\n{{datum}}\n{{ort}}\n\n"
                       . "{{beschreibung}}",
            'button'  => 'Termin im Kalender speichern',
        ],

        'password_reset' => [
            'label'   => 'Passwort zurücksetzen',
            'hint'    => 'Geht an Sie selbst, wenn Sie im Anmeldebild "Passwort vergessen" benutzen.',
            'vars'    => ['link', 'minuten', 'firma'],
            'subject' => 'Passwort zurücksetzen | {{firma}}',
            'body'    => "Sie haben angefordert, das Passwort für Ihr Admin-Panel zurückzusetzen.\n\n"
                       . "Der Link gilt {{minuten}} Minuten und lässt sich nur einmal verwenden:\n"
                       . "{{link}}\n\n"
                       . "Haben Sie das nicht angefordert, können Sie diese Nachricht ignorieren. "
                       . "Ihr bisheriges Passwort bleibt gültig, solange der Link nicht benutzt wird.",
            'button'  => 'Neues Passwort festlegen',
        ],

        // Die folgenden drei füllen ein Formular vor, das Sie vor dem
        // Absenden noch bearbeiten. Der Rahmen gilt hier nicht — diese
        // Mails gehen als reiner Text hinaus.
        'quote_send' => [
            'label'     => 'Angebot versenden (Vorbelegung)',
            'hint'      => 'Füllt Betreff und Text im Versandfenster vor. Reiner Text, kein Rahmen.',
            'vars'      => ['kunde', 'nummer', 'betrag', 'anmerkungen', 'firma'],
            'subject'   => 'Angebot {{nummer}} für {{kunde}}',
            'body'      => "Sehr geehrte Damen und Herren,\n\n"
                         . "anbei erhalten Sie unser Angebot {{nummer}} über {{betrag}} €.\n\n"
                         . "{{anmerkungen}}\n\n"
                         . "Bei Fragen stehe ich Ihnen gerne zur Verfügung.\n\n"
                         . "Mit freundlichen Grüßen\n{{firma}}",
            'plaintext' => true,
        ],

        'invoice_send' => [
            'label'     => 'Rechnung versenden (Vorbelegung)',
            'hint'      => 'Füllt Betreff und Text im Versandfenster vor. Reiner Text, kein Rahmen.',
            'vars'      => ['kunde', 'nummer', 'betrag', 'faellig', 'firma'],
            'subject'   => 'Rechnung {{nummer}}',
            'body'      => "Sehr geehrte Damen und Herren,\n\n"
                         . "anbei erhalten Sie unsere Rechnung {{nummer}} über {{betrag}} €, "
                         . "zahlbar bis {{faellig}}.\n\n"
                         . "Mit freundlichen Grüßen\n{{firma}}",
            'plaintext' => true,
        ],

        'payment_reminder' => [
            'label'     => 'Zahlungserinnerung (Vorbelegung)',
            'hint'      => 'Füllt Betreff und Text im Versandfenster vor. Reiner Text, kein Rahmen.',
            'vars'      => ['kunde', 'nummer', 'betrag', 'faellig', 'firma'],
            'subject'   => 'Zahlungserinnerung zu Rechnung {{nummer}}',
            'body'      => "Sehr geehrte Damen und Herren,\n\n"
                         . "unsere Rechnung {{nummer}} über {{betrag}} € war am {{faellig}} fällig "
                         . "und ist bislang nicht ausgeglichen.\n\n"
                         . "Sollten Sie die Zahlung bereits veranlasst haben, betrachten Sie "
                         . "diese Nachricht bitte als gegenstandslos.\n\n"
                         . "Mit freundlichen Grüßen\n{{firma}}",
            'plaintext' => true,
        ],
    ];
}

/** Betreff einer Vorlage: gespeicherte Fassung, sonst Standard. */
function mail_template_subject(string $key): string
{
    $tpl = mail_templates()[$key] ?? null;
    if ($tpl === null) return '';
    return setting('mailtpl_' . $key . '_subject', $tpl['subject']);
}

/** Nachrichtentext einer Vorlage: gespeicherte Fassung, sonst Standard. */
function mail_template_body(string $key): string
{
    $tpl = mail_templates()[$key] ?? null;
    if ($tpl === null) return '';
    return setting('mailtpl_' . $key . '_body', $tpl['body']);
}

/**
 * Ersetzt {{platzhalter}} durch Werte.
 *
 * $escape steuert die Maskierung: im HTML-Teil müssen die Werte maskiert
 * werden, im Textteil nicht. Nicht belegte Platzhalter verschwinden mitsamt
 * ihrer Zeile, damit keine leere „Ort:“-Zeile stehen bleibt.
 */
function mail_fill(string $text, array $vars, bool $escape): string
{
    $ersetzt = preg_replace_callback('/\{\{\s*([a-z_]+)\s*\}\}/', function ($m) use ($vars, $escape) {
        $wert = (string) ($vars[$m[1]] ?? '');
        return $escape ? htmlspecialchars($wert, ENT_QUOTES, 'UTF-8') : $wert;
    }, $text);

    // Zeilen, die durch einen leeren Platzhalter komplett leer geworden
    // sind, fallen weg - aber nur, wenn sie vorher einen Platzhalter
    // enthielten. Eine bewusst gesetzte Leerzeile bleibt.
    $zeilenVorher = explode("\n", $text);
    $zeilenNachher = explode("\n", $ersetzt);
    $behalten = [];
    foreach ($zeilenNachher as $i => $zeile) {
        $vorher = $zeilenVorher[$i] ?? '';
        $hatPlatzhalter = strpos($vorher, '{{') !== false;
        if ($hatPlatzhalter && trim($zeile) === '') continue;
        $behalten[] = $zeile;
    }
    return implode("\n", $behalten);
}

/** Wandelt den Textteil in Absätze für den HTML-Rahmen. */
function mail_text_to_html(string $text): string
{
    $absaetze = preg_split("/\n{2,}/", trim($text));
    $out = '';
    foreach ($absaetze as $a) {
        $out .= '<p style="margin:0 0 16px;font-size:15px;line-height:1.7;color:#343a40;">'
              . nl2br(htmlspecialchars($a, ENT_QUOTES, 'UTF-8'))
              . "</p>\n";
    }
    return $out;
}

/**
 * Der gemeinsame Rahmen um jede HTML-Mail.
 *
 * Bewusst Tabellen und Inline-Styles: Outlook rendert mit der Word-Engine
 * und beherrscht weder Flexbox noch externe Stylesheets. Farben stehen als
 * feste Hex-Werte - Mailclients lösen CSS-Variablen nicht auf.
 */
function mail_frame(string $innerHtml, string $buttonLabel = '', string $buttonUrl = ''): string
{
    $primary = setting('color_primary', COLOR_PRIMARY);
    $dunkel  = setting('color_sidebar', COLOR_SIDEBAR);
    $firma   = setting('company_name', COMPANY_NAME);
    $website = setting('main_website', MAIN_WEBSITE);
    $fuss    = setting('mail_footer', $firma . ' · ' . $website);
    $signatur = setting('mail_signature', '');

    $e = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');

    $logo = '';
    $logoRel = setting('company_logo', '');
    if ($logoRel !== '' && $website !== '') {
        // Absolute URL: relative Pfade zeigen im Postfach ins Leere.
        $logo = '<img src="' . $e(rtrim($website, '/') . '/' . ltrim($logoRel, '/'))
              . '" alt="' . $e($firma) . '" height="34" style="display:block;margin:0 auto;border:0;">';
    }
    if ($logo === '') {
        $logo = '<div style="font-size:20px;font-weight:700;color:#ffffff;">' . $e($firma) . '</div>';
    }

    $button = '';
    if ($buttonLabel !== '' && $buttonUrl !== '') {
        $button = '<table role="presentation" cellpadding="0" cellspacing="0" style="margin:8px auto 24px;">'
                . '<tr><td style="border-radius:8px;background:' . $e($primary) . ';">'
                . '<a href="' . $e($buttonUrl) . '" style="display:inline-block;padding:13px 32px;'
                . 'font-size:15px;font-weight:700;color:#ffffff;text-decoration:none;">'
                . $e($buttonLabel) . '</a></td></tr></table>';
    }

    $sigBlock = '';
    if (trim($signatur) !== '') {
        $sigBlock = '<p style="margin:24px 0 0;font-size:14px;line-height:1.7;color:#6c757d;">'
                  . nl2br($e($signatur)) . '</p>';
    }

    return '<!DOCTYPE html><html lang="de"><body style="margin:0;padding:0;background:#f4f6f9;'
         . 'font-family:Arial,Helvetica,sans-serif;">'
         . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" '
         . 'style="background:#f4f6f9;padding:32px 16px;"><tr><td align="center">'
         . '<table role="presentation" width="600" cellpadding="0" cellspacing="0" '
         . 'style="max-width:600px;width:100%;background:#ffffff;border-radius:12px;overflow:hidden;">'
         . '<tr><td style="background:' . $e($dunkel) . ';padding:26px 32px;text-align:center;">' . $logo . '</td></tr>'
         . '<tr><td style="padding:32px;">' . $innerHtml . $button . $sigBlock . '</td></tr>'
         . '<tr><td style="background:#f8f9fa;padding:18px 32px;text-align:center;border-top:1px solid #e9ecef;">'
         . '<p style="margin:0;font-size:12px;color:#adb5bd;">' . nl2br($e($fuss)) . '</p>'
         . '</td></tr></table></td></tr></table></body></html>';
}

/**
 * Rendert eine Vorlage.
 *
 * $extraHtml wird innerhalb des Rahmens hinter den Nachrichtentext
 * gesetzt – für Beiwerk, das keine Vorlage sein soll, etwa den QR-Code in
 * der Portal-Einladung. Es wird nicht maskiert und darf deshalb nur aus
 * dem Code stammen, nie aus einer Eingabe.
 *
 * @return array{subject:string, html:string, text:string}
 */
function mail_render(string $key, array $vars = [], string $buttonUrl = '', string $extraHtml = ''): array
{
    $tpl     = mail_templates()[$key] ?? null;
    $subject = mail_fill(mail_template_subject($key), $vars, false);
    $text    = mail_fill(mail_template_body($key), $vars, false);

    if ($tpl !== null && !empty($tpl['plaintext'])) {
        return ['subject' => $subject, 'html' => '', 'text' => $text];
    }

    $html = mail_frame(
        mail_text_to_html($text) . $extraHtml,
        $tpl['button'] ?? '',
        $buttonUrl
    );

    return ['subject' => $subject, 'html' => $html, 'text' => $text];
}

/** Beispielwerte für die Vorschau im Einstellungsbereich. */
function mail_preview_vars(): array
{
    return [
        'kunde'        => 'Max Mustermann',
        'projekt'      => 'Relaunch Landingpage',
        'meilenstein'  => 'Entwurf Startseite',
        'firma'        => setting('company_short', COMPANY_SHORT),
        'nachricht'    => 'anbei Ihr persönlicher Zugang zum Projektportal.',
        'betreff'      => 'Kontaktformular sendet nicht',
        'antwort'      => 'Die Ursache lag am SMTP-Zertifikat. Es ist erneuert, das Formular läuft wieder.',
        'titel'        => 'Abstimmung Startseite',
        'datum'        => '12.09.2026, 10:00 Uhr',
        'ort'          => 'Online (Videokonferenz)',
        'beschreibung' => 'Wir gehen den Entwurf gemeinsam durch.',
        // Fuer die Vorschau der Vorlage 'password_reset'.
        'link'         => 'https://admin.example.com/login?reset=…',
        'minuten'      => '60',
        'nummer'       => 'RE-2026-014',
        'betrag'       => '1.240,00',
        'faellig'      => '20.09.2026',
        'anmerkungen'  => 'Die Positionen sind wie besprochen aufgeteilt.',
    ];
}

/**
 * Vorlagen für die Verwendung im Browser.
 *
 * Die drei Vorbelegungen (Angebot, Rechnung, Zahlungserinnerung) füllen ein
 * Formular, das erst im Browser entsteht. Sie werden deshalb als JSON
 * mitgegeben und dort eingesetzt; die Ersetzung macht mailTplFill() in
 * assets/js/mail-templates.js.
 */
function mail_templates_json(array $keys): string
{
    $out = [];
    foreach ($keys as $k) {
        if (!isset(mail_templates()[$k])) continue;
        $out[$k] = [
            'subject' => mail_template_subject($k),
            'body'    => mail_template_body($k),
        ];
    }
    return json_encode($out, JSON_HEX_TAG | JSON_HEX_APOS | JSON_UNESCAPED_UNICODE);
}
