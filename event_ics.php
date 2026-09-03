<?php
require_once __DIR__ . '/config.php';

$token = trim($_GET['token'] ?? '');
if (empty($token)) {
    http_response_code(404);
    exit('Kein Token angegeben.');
}

$stmt = $pdo->prepare("
    SELECT ce.title, ce.description, ce.location, ce.meeting_url,
           ce.event_date, ce.start_time, ce.end_time, ce.status, ce.ics_uid,
           c.name AS contact_name, c.email AS contact_email
    FROM event_contacts ec
    JOIN calendar_events ce ON ec.event_id = ce.id
    JOIN contacts c ON ec.contact_id = c.id
    WHERE ec.invite_token = ?
");
$stmt->execute([$token]);
$ev = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$ev) {
    http_response_code(404);
    exit('Einladungslink nicht gefunden oder ungültig.');
}

function ics_esc(string $s): string {
    return str_replace(['\\', ';', ',', "\r\n", "\n", "\r"], ['\\\\', '\;', '\,', '\n', '\n', '\n'], trim($s));
}

function ics_fold(string $line): string {
    $result = '';
    while (strlen($line) > 75) {
        $result .= substr($line, 0, 75) . "\r\n ";
        $line    = substr($line, 75);
    }
    return $result . $line;
}

$desc = trim($ev['description'] ?? '');
if (!empty($ev['meeting_url'])) {
    $desc .= ($desc ? "\nMeeting-Link: " : 'Meeting-Link: ') . $ev['meeting_url'];
}

$is_allday = empty($ev['start_time']);
$date_ymd  = date('Ymd', strtotime($ev['event_date']));

if ($is_allday) {
    $dtstart = "DTSTART;VALUE=DATE:{$date_ymd}";
    $dtend   = "DTEND;VALUE=DATE:" . date('Ymd', strtotime('+1 day', strtotime($ev['event_date'])));
} else {
    $st      = str_replace(':', '', substr($ev['start_time'], 0, 5));
    $dtstart = "DTSTART:{$date_ymd}T{$st}00";
    if (!empty($ev['end_time'])) {
        $et    = str_replace(':', '', substr($ev['end_time'], 0, 5));
        $dtend = "DTEND:{$date_ymd}T{$et}00";
    } else {
        $end_ts = strtotime($ev['event_date'] . ' ' . $ev['start_time']) + 3600;
        $dtend  = "DTEND:" . date('Ymd\THis', $end_ts);
    }
}

$status_map = ['Geplant' => 'TENTATIVE', 'Bestätigt' => 'CONFIRMED', 'Abgesagt' => 'CANCELLED', 'Abgeschlossen' => 'CONFIRMED'];
$ics_status = $status_map[$ev['status']] ?? 'TENTATIVE';
$organizer  = setting('admin_email', ADMIN_EMAIL);
$org_cn     = ics_esc(setting('company_name', COMPANY_NAME));

$lines = [
    "BEGIN:VCALENDAR",
    "VERSION:2.0",
    "PRODID:-//" . ics_esc(COMPANY_NAME) . "//AdminPanel//DE",
    "CALSCALE:GREGORIAN",
    "METHOD:REQUEST",
    "BEGIN:VEVENT",
    "UID:{$ev['ics_uid']}",
    $dtstart,
    $dtend,
    "SUMMARY:" . ics_esc($ev['title']),
    "DESCRIPTION:" . ics_esc($desc),
    "LOCATION:" . ics_esc($ev['location'] ?? ''),
    "STATUS:{$ics_status}",
    "ORGANIZER;CN=\"{$org_cn}\":MAILTO:{$organizer}",
    "ATTENDEE;CN=\"" . ics_esc($ev['contact_name']) . "\";RSVP=TRUE:MAILTO:{$ev['contact_email']}",
    "DTSTAMP:" . gmdate('Ymd\THis\Z'),
    "END:VEVENT",
    "END:VCALENDAR",
];

if (!empty($ev['meeting_url'])) {
    $url_idx = array_search("STATUS:{$ics_status}", $lines);
    if ($url_idx !== false) {
        array_splice($lines, $url_idx, 0, ["URL:" . $ev['meeting_url']]);
    }
}

$safe = preg_replace('/[^a-zA-Z0-9_-]/', '_', $ev['title']);
header('Content-Type: text/calendar; charset=utf-8');
header('Content-Disposition: attachment; filename="Termin_' . $safe . '.ics"');
header('Cache-Control: no-store, no-cache');
header('Pragma: no-cache');

echo implode("\r\n", array_map('ics_fold', $lines)) . "\r\n";
exit;
