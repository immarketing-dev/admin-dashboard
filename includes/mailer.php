<?php
/**
 * Ein Versandweg für Mails, die nicht aus einem Formular stammen.
 *
 * Der PHPMailer-Block - isSMTP, Host, Auth, Port, CharSet, setFrom -
 * steht in diesem Projekt an sieben Stellen wortgleich. Das ist
 * hinnehmbar, solange jede davon an einem Formular hängt und der
 * Benutzer den Fehler im Browser sieht.
 *
 * Für den Cron-Lauf trägt das nicht mehr: dort sitzt niemand davor, der
 * eine Fehlermeldung lesen könnte, und ein achter kopierter Block wäre
 * genau die Stelle, an der später ein Detail abweicht. Deshalb hier
 * einmal, mit einem Rückgabewert statt einer Weiterleitung.
 *
 * Die sieben bestehenden Stellen bleiben unangetastet - sie umzubauen
 * wäre eine eigene Änderung mit eigenem Risiko.
 */

require_once __DIR__ . '/../vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as PHPMailerException;

/**
 * Verschickt eine Mail.
 *
 * Wirft nicht, sondern meldet zurück: der Aufrufer ist entweder ein
 * Cron-Lauf, der die restlichen Mails trotzdem verschicken soll, oder
 * ein Handler, der eine Meldung anzeigen will.
 *
 * @param array{to: string, subject: string, body: string, attachment?: ?string, attachment_name?: string} $opt
 * @return array{ok: bool, error: string}
 */
function mail_versenden(array $opt): array
{
    $to = trim((string) ($opt['to'] ?? ''));

    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'error' => 'Keine gültige Empfängeradresse.'];
    }
    if (!class_exists(PHPMailer::class)) {
        return ['ok' => false, 'error' => 'PHPMailer ist nicht installiert.'];
    }
    // Ohne SMTP-Host würde PHPMailer auf die lokale Zustellung
    // zurückfallen und stillschweigend ins Leere senden. Lieber hier
    // abbrechen und es sagen.
    if (!defined('SMTP_HOST') || SMTP_HOST === '') {
        return ['ok' => false, 'error' => 'Kein SMTP-Server konfiguriert (SMTP_HOST in der .env).'];
    }

    try {
        $mail = new PHPMailer(true);
        $mail->isSMTP();
        $mail->Host       = SMTP_HOST;
        $mail->SMTPAuth   = true;
        $mail->Username   = SMTP_USER;
        $mail->Password   = SMTP_PASS;
        $mail->SMTPSecure = (SMTP_PORT == 587) ? 'tls' : 'ssl';
        $mail->Port       = SMTP_PORT;
        $mail->CharSet    = 'UTF-8';
        $mail->setFrom(ADMIN_EMAIL, COMPANY_NAME);
        $mail->addAddress($to);
        $mail->Subject = (string) ($opt['subject'] ?? '');
        $mail->isHTML(false);
        $mail->Body    = (string) ($opt['body'] ?? '');

        $anhang = $opt['attachment'] ?? null;
        if (is_string($anhang) && $anhang !== '' && is_file($anhang)) {
            $mail->addAttachment($anhang, (string) ($opt['attachment_name'] ?? basename($anhang)));
        }

        $mail->send();
        return ['ok' => true, 'error' => ''];
    } catch (PHPMailerException $e) {
        return ['ok' => false, 'error' => $e->getMessage()];
    } catch (Throwable $e) {
        // Etwa eine fehlende Konstante oder ein Problem beim Anhang.
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}
