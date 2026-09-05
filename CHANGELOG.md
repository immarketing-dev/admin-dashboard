# Changelog

All notable changes to this project are documented here. Format based on
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/). This changelog
covers the process of turning a private, single-install PHP admin panel
into this public repository — it does not track the panel's earlier
private history.

## [Unreleased]

### Added
- **Invoices as XML (XRechnung, UBL 2.1).** Invoices have been stored in
  structured form since schema version 8 — `finances.items` as JSON with
  description, quantity, price and unit, plus `tax_type`, `net_amount`,
  `tax_amount`. Exactly what an electronic invoice needs. The only output
  was a PDF drawn by FPDF: a picture of an invoice that no software can
  read.

  Schema version 17 adds `contacts.vat_id` and `finances.buyer_reference`,
  and the settings page finally gets fields for the company address and
  tax number — `company_street` and `company_city` were already *read* by
  the PDF generator, but there had never been a field to enter them in.

  Built with `DOMDocument`, not string concatenation: a client name
  containing an `&` turns glued-together XML into an unreadable file.

  **It is not a validator, and says so.** Whether a file passes a
  particular recipient's check depends on fields this panel cannot know.
  What it does check before generating is that its own mandatory fields
  are present, naming the missing ones rather than handing over a file
  that gets rejected. ZUGFeRD is deliberately absent: PDF/A-3 with
  embedded XML is beyond FPDF and would mean another dependency.

  60 checks in `tools/test_xrechnung.php` — well-formedness, the totals
  adding up across line items, `&` surviving in a client name, the §19
  exemption reason that a zero-tax invoice must carry, and the placeholder
  where a buyer reference is genuinely absent.

- **Two-factor sign-in (TOTP).** The sign-in path was carefully built —
  lockout after five attempts over the `ip` column, bcrypt at a pinned
  cost, a dummy hash against timing differences — and gained a way back
  in with schema version 12. A second factor was missing.

  Schema version 16 adds `users.totp_secret`, `users.totp_confirmed_at`
  and `totp_backup_codes`. No dependency: TOTP is HMAC-SHA1 over a
  counter, and PHP brings both; the rest is Base32 and the truncation
  from RFC 4226.

  `tools/test_totp.php` checks the arithmetic against the **test vectors
  from RFC 6238 Appendix B**. That is the difference between "my app
  accepted it" and demonstrably correct — an implementation that happens
  to agree with one app while being wrong is entirely possible.

  Three deliberate details. **Set up is not active**: the secret is stored
  unconfirmed, and only a typed code proves the app has it — otherwise a
  mistake while scanning locks the user out. **Eight backup codes**,
  single-use, hashed like passwords because that is what they are; a
  second factor that locks you out when the phone is gone trades one
  lock-out problem for another. And **wrong codes count towards the same
  lockout as wrong passwords**, since six digits are quicker to guess than
  a password.

  The state between password and second factor expires after ten minutes,
  and it carries no `admin_logged_in` — otherwise the second factor would
  be an ornament you could skip by navigating away. 79 checks.

- **An endpoint for enquiries (`api/leads.php`).** The README's instruction
  for wiring up a contact form was an `INSERT INTO leads_inbox` — write
  into the panel's database from your website. That requires both to live
  on the same machine and spreads the database credentials across a second
  project.

  `POST /api/leads` with a key in the header does the same job over HTTP,
  answering JSON with a meaningful status. The key is generated under
  Settings → System; **without one the endpoint stays closed**, the same
  principle as `CRON_TOKEN` — a fallback to "no check" would be an
  unguarded write path on every installation that never configured
  anything.

  There is deliberately **no CORS**: the key grants write access, so it
  belongs in the website's server-side handler, not in the page where it
  would be public. The settings page says so where the key is shown.

  Beyond writing the row: `name` and one way to reply are required (an
  enquiry you cannot answer is worthless), over-long fields are truncated
  rather than rejected (a long subject is no reason to discard a customer
  enquiry), a hidden `website` field acts as a honeypot answering with the
  same `201` a real request gets, and requests are rate-limited per IP
  through the same `logs` table the sign-in lockout uses.

  47 checks in `tools/test_api_leads.php`.

  The four checkers that enumerate their directories by hand now include
  `api/` — a directory nothing scans would be a blind spot exactly where a
  file is reachable from outside and writes. Adding it immediately caught
  the new endpoint as an unguarded POST path, which is what the check is
  for; it is now a documented exception with its own guard verified in
  check 1.

### Fixed
- **The credential scanner read a header name as a secret.**
  `'HTTP_X_API_KEY' => 'abc123'` matched, because the header *name* ends in
  `api_key` and the array pattern — unlike the variable pattern above it —
  had no minimum length for the value. It now requires eight characters,
  matching the existing rule for variables. Verified both ways: the test
  values no longer match, a real `'api_key' => 'sk_live_…'` still does.

- **Uptime with a history, and a notice when something goes down.** The
  monitor asked every URL on each dashboard view and threw the answer
  away. No history, no availability figure, no notice — a client's server
  could be gone for three days and nobody found out.

  Schema version 15 adds `url_checks`. Measuring moves into the cron run,
  which makes two things possible that a snapshot cannot: an availability
  figure, and a comparison with the previous measurement. The comparison
  is what makes an outage reportable at all — without it either every
  measurement mails or none does.

  A mail goes out on a **state change only**, down and up again. "Slow"
  stays silent: a page that takes four seconds once is not an incident,
  and a notice that arrives too often stops being read. The first
  measurement never sends — there is no previous state to change from,
  and otherwise setting the monitor up would mail once per address that
  happens to be quiet.

  The widget shows availability over 24 hours and one bar per
  measurement. Without a cron run it still measures on the page: a panel
  that showed nothing would be worse than the wait.

  47 checks in `tools/test_uptime.php`.

### Fixed
- **`tasks.php` checked every client website one at a time, on every page
  load.** Five-second timeout, sequentially, for a coloured dot beside the
  project title — with twenty projects that is up to a hundred seconds.
  And it had **no demo guard**, so in demo mode the server would fetch
  arbitrary addresses without anyone being logged in; check 4 of
  `tools/check_demo.php` only ever looked at `index.php`, so it never saw
  this one.

  Both are fixed: the check runs through the same parallel measurement as
  the monitor (one pass, six seconds at most, guard included), and check 4
  now walks every file that calls `curl_init()` rather than one named
  file — so a third such place would be caught too.

- **From quote to project.** There was "quote to invoice" but not "quote to
  project": whoever won the work typed the line items they had just
  written out a second time, now as milestones.

  Schema version 14 adds `quotes.converted_task_id`. Client, subject and
  line items carry over; every line item becomes a milestone, with the
  quantity in the title where it is not one — "Schulung" and "Schulung
  (3 Tage)" are different promises. The introductory text becomes the
  project description, and the client is entered in `task_contacts`,
  without which they would not see their own project in the portal.

  Two restraints. The quote's **status is left alone**: a quote can be
  accepted without a project, and work sometimes starts before the
  acceptance is in writing. And it happens **once per quote** — while
  `converted_task_id` points somewhere, the button links to that project
  instead of offering another. A second one is nearly always a
  double-click, and then the same work sits twice in the list with both
  halves half maintained. `ON DELETE SET NULL` frees the quote again if
  the project is deleted.

  The project list now takes `?highlight=<id>`, outlines that card and
  scrolls to it — a link that only lands on the list would leave you
  hunting through thirty projects for the new one.

  34 checks in `tools/test_quote_to_project.php`.

- **A record of what was sent (`mail_log`).** The panel sends nine kinds of
  mail — quotes, invoices, portal invitations, milestone notices, ticket
  replies, calendar invitations, a notice when a client reacts to a quote
  in the portal, plus the payment reminders and password links added
  earlier in this release. **Not one of them was recorded.** Faced with "I
  never got that quote", there was nothing to look up.

  Schema version 13 adds the table; a tab under System-Logs shows it, with
  the failure count on the tab itself. Recipient, subject, template,
  outcome, error text, and what the mail was about ("Angebot
  ANG-2026-003").

  Not folded into `logs`: that table holds a line of free text with no
  recipient, no subject and no outcome, and is emptied after
  `log_retention_days` — which may be a week. A proof of delivery is
  worthless in the short term, so the mail log carries its own floor of one
  year regardless of that setting.

  `mail_versenden()` records by itself. The seven older PHPMailer blocks
  call `mail_protokollieren()` explicitly rather than being rewritten —
  each carries its own quirks (attachments, HTML against text, a different
  sender), and introducing a log is not the occasion to disturb them. One
  of them, the calendar invitation, had an entirely **empty `catch` block**:
  a failed send left no trace whatsoever. It does now.

  31 checks in `tools/test_mail_log.php`. The two that matter most: an
  over-long subject is truncated rather than throwing (it comes from a
  form, and a throw here would report a delivered mail as failed), and a
  broken log never breaks a send.

- **A way back in: password reset.** There was none. No `password_reset`,
  no `forgot`, nothing on the sign-in screen but an address and a
  password. Whoever lost theirs needed database access — on someone
  else's shared hosting, the point at which the panel gets abandoned.

  Schema version 12 adds `password_resets`, a table rather than two
  columns on `users` for the same reason `sso_tokens` is one: a token is
  an event with a beginning, an end and a consumption, not an attribute of
  a person.

  Three things it does that the obvious implementation does not. It stores
  the **hash** of the token, not the token — anyone reading a backup would
  otherwise hold a working way in (SHA-256 suffices where bcrypt does not
  apply: the token is already 256 bits of randomness and cannot be
  guessed). It **expires** after an hour. And the answer is **identical**
  whether or not the address exists, because otherwise the form is a
  directory of the account names; even a failed send goes to the log
  rather than the screen, since saying so would give the same answer away.

  Redemption carries `AND used_at IS NULL`, so two concurrent openings of
  the same link cannot both go through — only the run that actually
  invalidates the row sets the password. Requests are rate-limited per IP
  through the same `logs` table the sign-in lockout uses. The cron run
  sweeps spent tokens.

  33 checks in `tools/test_auth_reset.php`. The SQLite mirror learned
  MySQL's `INTERVAL` arithmetic for it — which is what the sign-in
  lockout, the log trimming and the trash all express their deadlines in,
  so those become testable too.

### Fixed
- **`install/preflight.php` and `tools/check_schema.php` each knew only 21
  of the schema's tables.** `task_contacts` and `project_comments` arrived
  with migrations 5 and 6, and neither list was carried along. A fresh
  install missing one of them was still reported as complete by the
  pre-flight check, and check 1 of the schema test never watched them.
  Both lists are now current.

- **Receipts on expenses, and a yearly handover.** `finances` knew exactly
  one file column — `invoice_pdf_path`, the outgoing invoice the panel
  generates itself. An expense had nothing attached: the hosting bill, the
  software licence, the train ticket lived somewhere else, and at tax time
  they were gathered up again from five inboxes.

  Schema version 11 gives the expense its own column, deliberately
  separate from `invoice_pdf_path` — that file is generated and
  regenerable, a receipt is a third party's document that exists only
  once. One shared column would mean losing a receipt the next time an
  invoice PDF is produced.

  Served through `file.php` like every other upload, but **never to the
  portal** — the `receipt` case has no client branch at all, only a
  refusal. An expense receipt is a third party's invoice to you; it is no
  business of the client, not even the one the expense is assigned to.
  Five checks in `tools/test_file_access.php` pin that down, including the
  one where the assigned client asks for their own expense's receipt.

  Beside the CSV export, a year of expenses now packs into a ZIP: the
  overview plus every attached receipt, named so the archive reads in the
  order of the list. The `zip` extension is optional — without it the
  button is hidden rather than shown and then failing, and the handler
  falls back to the CSV.

### Fixed
- **The trash no longer deletes files it cannot restore, and now deletes
  the ones it should.** Two halves of the same oversight, both dating from
  migration 4, when deletion stopped being final:

  `finances.php` removed the invoice PDF from disk while moving the record
  only into the trash. Restoring it afterwards produced an entry pointing
  at a PDF that no longer existed — precisely the file one restores *for*.

  `trash.php`, meanwhile, deleted rows and never files. Permanently
  removing an invoice left `Rechnung_RE-2026-014.pdf` lying in a directory
  nobody looks at, with a client name and an amount in the filename,
  indefinitely.

  Now the file follows the record: it survives the move to the trash and
  goes with the final deletion — both the manual one and the automatic
  sweep after 30 days.

- **Reports and a timesheet (`reports.php`).** Since schema version 9
  everything needed for a profitability calculation had been in place: an
  hourly rate on client and project, tracked minutes with a "billed"
  marker, invoice amounts with due dates. None of it was evaluated —
  `finances.php` draws income against expenses over time and nothing else,
  and `time_entries` had no view of its own at all. The question "which
  client actually pays their way" could not be answered by the panel,
  although it held the answer.

  No new table and no migration: the page only reads. Four answers on two
  tabs — outstanding invoices by age, revenue per client per year (paid
  and outstanding kept apart), hours worked but not yet billed valued at
  the rate in force today, and a timesheet by week, month or year with a
  CSV export.

  Deliberately absent: the hourly rate actually achieved per project.
  Attributing an invoice amount to a project is not possible with this
  data — `finances` knows a contact, not a `task_id`, and the only link
  is `time_entries.invoice_id`, which an invoice covering several projects
  makes ambiguous. A figure that pretended to know would be worse than
  none.

  The bars are CSS, not Chart.js: a canvas cannot resolve `var()`, which
  is why `finances.php` has to feed it token values through
  `getComputedStyle`. For a size comparison inside a table row that is too
  much apparatus, and a `div` with a percentage width carries both themes
  by itself.

  67 checks in `tools/test_reports.php`, plus
  `tools/test_reports_render.php`, which renders the real page source in
  eight states — empty database, both tabs, all three periods, nonsense
  in the query string, and once in English. `php -l` sees none of those:
  a wrong array key in the markup or a `max()` over an empty list is a
  blank page in the browser and a clean bill on the command line. That
  test found two things while being written — a function defined in the
  page rather than in its include, and a query the SQLite mirror could not
  run.

- **Scheduled tasks: `cron.php`.** Nothing in this panel happened without a
  visitor. `finances.php` stamped overdue invoices while rendering the
  list, `includes/auth.php` trimmed the log on login, `index.php` queried
  every monitored URL synchronously on each dashboard view. Whoever did not
  look in for a week had none of it happen for a week.

  One entry point now collects that work, plus the two things below that
  cannot work without a schedule. Guarded twice: refused outright in demo
  mode, and over HTTP only with `CRON_TOKEN` from the `.env`, compared with
  `hash_equals`. An empty token keeps the HTTP route closed rather than
  falling back to no check — an open endpoint here triggers mail to
  customers. On the command line the token is moot: whoever can run it
  already has the `.env`.

  Every task is wrapped on its own, so one failure — an SMTP server that
  does not answer — does not swallow everything scheduled after it. The run
  prints what it did and exits non-zero when a task failed.
  `tools/check_demo.php` now watches both guards, because `cron.php` is a
  GET that writes and its SQL lives in `includes/cron_tasks.php`, where
  check 3 cannot see it.

- **Payment reminders.** The template `payment_reminder` had been sitting
  complete in `includes/mail_templates.php` — subject, body, placeholders —
  with **not one caller anywhere in the project**. Meanwhile the panel
  stamped invoices "overdue" by itself and showed the count in the sidebar.
  It knew who was not paying and told only you.

  A bell button on each open invoice sends the reminder, prefilled from
  that template and editable before it goes, with the invoice PDF attached
  when one exists. Optionally automatic: "reminder stages" under
  Settings → System takes days after the due date (`7, 21`). Empty by
  default and staying empty on upgrade — a schema step must not make an
  existing installation start mailing its customers.

  Both routes run through the same `mahnung_senden()`, so a reminder sent
  by hand advances the counter and pushes back the next automatic stage
  instead of running beside it. After the last stage the panel stops rather
  than escalating; no late fees, no interest — decisions with legal
  consequences, not features. Two locks against a double send: a 20-hour
  bar per invoice, and `UPDATE … WHERE reminder_count = ?`, so of two
  concurrent runs only one raises the counter.

- **Recurring entries that actually recur.** `is_recurring` was a label:
  the switch said "monthly fixed costs", set a `1`, and was read as a
  filter, a badge and a CSV column. It created nothing — a maintenance
  contract was retyped by hand every month.

  Schema version 10 adds `recurrence`, `next_run` and
  `recurring_parent_id`. `is_recurring` stays and now follows the choice,
  so filter, badge and CSV keep working. Existing rows are deliberately
  **not** reinterpreted: an installation must not start creating invoices
  because it applied an update.

  The end of the month is handled with an anchor day — a series on the 31st
  becomes the 28th in February and returns to the 31st in March instead of
  drifting forward for good; `mktime(0,0,0,2,31,2026)` is the 3rd of March
  in PHP, and that is exactly the bug. Income gets a fresh invoice number,
  an expense does not: it is no outgoing invoice and must not consume a
  number from that sequence, because every number without an invoice behind
  it is a gap in the run that has to be explained later. A run creates at
  most twelve entries per template, so a `next_run` left in the distant
  past cannot produce a decade of invoices at once.

  No PDF is generated — that code sits inside the POST handler of
  `invoice.php` and would need that file rebuilt to reach from here. The
  entry shows up as an open invoice; the existing button makes the PDF.

  78 checks in `tools/test_cron_billing.php` against the SQLite mirror,
  which learned `CURDATE()` and `SUBSTRING_INDEX` for the purpose — the
  latter is what `includes/numbering.php` cuts invoice numbers with, and
  therefore the place a duplicate number would appear.

### Fixed
- **`tools/check_placeholders.php` counted commas inside comments.** The
  counter walks the value list character by character and knew nothing
  about comments, so an explanatory sentence between two values — and in
  this project comments sit exactly where something needs explaining —
  added one value per comma to the tally. It reported "14 placeholders, 17
  values" for a sound query. A checker that cries wolf gets ignored, so the
  fix went into the checker rather than into a rearranged comment.

### Changed
- **The demo notice now sits inside the page header and stays there.**
  It was a separate card above the header, which meant it scrolled out of
  sight after the first screenful — on a demo whose whole job is to say
  "this is not a real system", the wrong place for it. In the panel it is
  now the first row inside `.top-header`, so it inherits that element's
  sticky behaviour and needs no rules of its own; `--header-height` is
  measured, so the filter bar keeps latching correctly below the now
  taller header.

  The client portal has no `.top-header` — it is built differently and
  already had a sticky tab bar at `top: 0`. The notice sticks above it and
  the tab bar drops to `top: var(--demo-strip-height)`, measured by the
  same script, which was generalised for the purpose. Outside demo mode
  the variable is 0px and nothing moves.

  Both are set tighter on phones — the sticky header would otherwise hold
  a quarter of the viewport permanently. Tightened rather than shortened:
  "changes are not saved" is the sentence the notice exists for.

  Verified with Playwright at 1280x800 and 375x667: after scrolling, both
  notices sit at viewport top, `elementFromPoint` hits them rather than
  content bleeding through, and the portal's tab bar sits flush below
  without overlap.

### Added
- **Tracked time can be billed.** The timer had been recording for a long
  time, but the hours never reached an invoice: `time_entries` knew neither
  a rate nor a "billed" marker. Whoever wanted to invoice added the minutes
  up by hand and typed a line item — and whether an hour had already been
  charged was known only to the person who had charged it.

  Schema version 9 adds an hourly rate to `contacts` and `tasks` and
  `billed_at` / `invoice_id` to `time_entries`. The rate resolves
  project → client → default (a setting), so a special price on one project
  does not force changing the client's rate and thereby break all their
  other projects. A rate of 0,00 is an answer, not an absence, and is not
  overridden.

  The invoice button on a project now offers only the **unbilled** hours and
  shows how many there are; `invoice.php` marks them as billed afterwards.
  Before, it handed over *every* recorded hour, so invoicing a project twice
  billed the first invoice again — an error only the customer would notice.
  The `UPDATE` carries `AND billed_at IS NULL`, so two concurrent runs cannot
  both claim the same entries.

  21 checks in `tools/test_time_billing.php`, against the SQLite mirror. The
  mirror learned to translate `NOW()`, so the production code stays as it
  is rather than being bent into a testable shape.

### Fixed
- **Invoices from the projects page had timestamp numbers.** The modal built
  its own number in the browser (`RE-20260904-193045`) and `invoice.php` took
  whatever arrived in the POST. Two number series ran side by side —
  sequential ones from `finances.php`, timestamps from the projects page —
  while `includes/numbering.php`, which exists precisely to prevent that,
  was never reached on this path. § 14 UStG requires a single sequential
  series, each number issued once. The server now supplies the number and
  rejects anything not matching `RE-JJJJ-NNN`, issuing a fresh one instead.

- **The hourly rate in that same modal was hard-coded to 60**, regardless of
  what had been agreed with the client.
- **Invoices keep their line items.** `invoice.php` took them by POST,
  printed them into the PDF and threw them away — `finances` held a single
  `amount`. The PDF was the only place the breakdown existed at all, so an
  invoice could not be corrected, its PDF not regenerated, and VAT not
  evaluated. An e-invoice (XRechnung, ZUGFeRD) was impossible outright:
  that needs the items individually and machine-readable.

  Schema version 8 adds `items` (JSON), `tax_type`, `net_amount` and
  `tax_amount` to `finances`, in exactly the format quotes already use, so
  converting a quote to an invoice now carries the breakdown across instead
  of losing it. Existing invoices keep `net_amount` NULL rather than having
  it back-filled from `amount` — an invented net sum would be worse than
  none, because an evaluation could not tell it from a real one.

  The arithmetic moved into `includes/invoice_items.php`, which the PDF, the
  totals and the database row now share; previously the PDF loop computed
  the net sum as a side effect of printing, so the number existed only while
  the document was being drawn. Rounding is applied to the tax, not to the
  gross, so the printed tax is exactly the difference between the two
  printed sums. An unknown tax type yields no tax rather than a guessed 19 %.
  26 checks in `tools/test_invoice_items.php`.

- **`tools/check_placeholders.php`** counts `?` placeholders against the
  values passed to `execute()`. `php -l` cannot see a query that lost a
  value — it is syntactically perfect and fails at runtime, mid-save. It
  found a real one immediately: the quote-to-invoice conversion in
  `quotes.php`, which this same change had just given four more columns
  without extending its parameter list. It reads the value list
  bracket-aware rather than by regex; a non-greedy `.*?\]` stops at the
  first `])`, which already occurs inside `trim($_POST['x'])` mid-list and
  produced four false reports.

### Security- **Every library is served from the panel itself now, and a
  Content-Security-Policy enforces it.** Bootstrap, Bootstrap Icons,
  Chart.js, Prism, SortableJS, Gridstack, qrcode.js and CKEditor came from
  four foreign origins on every page load, none of them with an
  `integrity` hash — a compromised CDN meant arbitrary code running inside
  an authenticated admin session. Chart.js was pulled without any version
  at all, so two deploys could get different releases.

  All of it lives under `assets/vendor/` (2.4 MB, committed), Chart.js
  pinned at 4.4.1. Google Fonts moved too: loading them from
  `fonts.googleapis.com` sent every visitor's IP address — in the client
  portal, the *customers'* IP addresses — to a third party outside the EU,
  for five files that now sit in `assets/vendor/fonts/`.

  Only with nothing left to load externally does a CSP become possible:
  `default-src 'self'`, with `connect-src`, `img-src` and `form-action`
  limited to the same origin. Its value is less in preventing XSS —
  escaping does that — than in cutting off the exit: injected code has
  nowhere to send what it reads. `'unsafe-inline'` is still required and
  remains the weak spot; roughly 2900 lines of inline script and the
  `onclick` attributes have to move to `assets/js/` before it can go.

  It is set with `Header always setifempty`, not `set`, so it cannot
  overwrite the much stricter policy `file.php` sends with customer
  documents (`default-src 'none'; sandbox`).

  Verified with Playwright against a real HTTP server under that exact
  policy: all ten library checks pass, both font families load locally,
  and there are no CSP violations.

- **HSTS is on** (`max-age=31536000`, without `preload` or
  `includeSubDomains` — both commit longer than is sensible here), and
  responses are compressed via `mod_deflate`.

### Fixed
- **PHP code was never syntax-highlighted in the wiki or the portal.**
  Prism's PHP grammar builds on `markup-templating`, which was never
  loaded — the first `highlightElement` on a PHP block threw
  "buildPlaceholders of undefined". It failed silently, because an
  uncoloured code block still looks like a code block. Found while
  verifying the libraries under CSP.

### Added
- **Static assets are cached for a year** instead of being re-fetched on
  every page load — 2.4 MB of libraries plus a 57 kB stylesheet, noticeable
  on a phone connection. That is only safe because every reference now
  carries a timestamp: `asset()` in `config.php` appends `?v=<filemtime>`,
  so a changed file has a changed address. The rules sit in a separate
  `assets/.htaccess`; in the root one they would also have hit the PHP
  responses, which carry session content.
- **The page header and the filter bar stay put while scrolling**, on every
  page and at every width. Both were part of the normal flow, so on a long
  list the page title, its buttons, the global search and the whole filter
  row scrolled out of sight — and filtering a list meant scrolling back to
  the top first.

  The header comes from `includes/layout_start.php`, which all twelve pages
  use, and the filter bar is one shared `.filter-bar` class across the seven
  pages that have one, so this is two rules in `app.css` rather than a change
  per page. Pages without a filter bar (dashboard, board, calendar, settings,
  trash) keep the sticky header; `board.php` and `calendar.php` carry their
  search and month navigation in `$header_actions`, so those ride along.

  Two details made it work. `body` had `overflow-x: hidden`, which turns the
  body into its own scroll container — every `position: sticky` inside then
  anchors to that container instead of the viewport and scrolls away anyway.
  It is `overflow-x: clip` now, which clips the same way without creating a
  container, and `tools/check.sh` rejects a relapse, because the symptom
  gives no hint of the cause. And the filter bar has to latch below a header
  whose height is not knowable in CSS — it wraps to two or three rows on a
  phone, and changes again when the sidebar collapses or the fonts finish
  loading. `assets/js/sticky-header.js` measures it with a `ResizeObserver`
  and writes `--header-height`; the token carries a single-row default for
  the moment before the script runs.

  Verified with Playwright against the real stylesheets at 1280×800 and
  375×667: after scrolling, the header sits at viewport top, the filter bar
  exactly `--header-height + 12px` below it, and `elementFromPoint` hits
  both rather than content showing through.

### Security- **Uploaded files are no longer served directly.** Everything under
  `uploads/` was delivered by the web server to anyone who knew the path.
  For invoices the path did not even have to be guessed: they were named
  `Rechnung_RE-2026-001.pdf`, so counting from 001 upwards handed out every
  invoice of every customer — name, address and amount. Project files and
  wiki attachments (contracts, powers of attorney) were reachable the same
  way, protected only by a timestamp in the filename.

  The four directories holding customer data — `client_assets`, `invoices`,
  `quotes` and `wiki` — now deny web access outright, and `file.php` is the
  only way in. It resolves a database id rather than a path and asks
  `includes/file_access.php` who may see it, applying exactly the rule the
  client portal already uses for its lists: project files go to everyone on
  the project (`task_contacts`), invoices and quotes to the contact they are
  addressed to, quote drafts to nobody, and wiki attachments only for
  articles explicitly shared with that contact. A request that fails the
  check gets the same 404 as a missing file, so the response cannot be used
  to count how many invoices exist.

  `uploads/logos` and `uploads/favicons` stay public on purpose — the login
  page shows them before anyone has authenticated.

  Anything the browser might interpret as HTML now goes out as a download
  with `application/octet-stream`, never inline. The files come from the
  same origin as the panel, so a script inside one would run with the
  session of whoever opened it. Uploads reject SVG already; this keeps the
  decision correct even if that list ever grows.

  Three checks keep it that way: `tools/test_file_access.php` (21 access
  cases plus 10 delivery cases, run against the SQLite mirror of the real
  schema), a `tools/check.sh` rule that the four `.htaccess` files actually
  deny rather than merely exist — the old ones were present and still only
  blocked PHP execution — and a scan for `href="uploads/…"` links that would
  bypass `file.php`.

### Added- **Draggable dashboard.** The eleven widgets on the start page sat in four
  fixed Bootstrap rows; they now sit in a twelve-column Gridstack grid and
  can be moved and resized with the mouse. Drag by the widget title bar, or
  by a slim grip in the top padding for the five widgets that have no title
  bar; resize from the bottom-right corner. Every widget carries a minimum
  size, so the project list and the notes field cannot be shrunk out of
  their own card. A "Widgets" menu in the page header hides and restores
  individual widgets and resets the layout to the default. Saving happens
  automatically after each move, debounced into one request.

  The layout lives in `includes/dashboard_layout.php` — one place that
  declares each widget's default position, minimum size, grip and label —
  and is stored as JSON under the `dashboard_layout` settings key. PHP
  writes the coordinates into the markup as `gs-` attributes, so the page
  renders in its final arrangement instead of flashing the default layout
  and then jumping. A stored layout survives widgets being added or
  removed later: unknown names are dropped, missing ones fall back to their
  default position, and unusable JSON yields the default layout rather than
  an empty page. Below 768 px the grid collapses to one column and dragging
  is off — a move there would otherwise overwrite the desktop arrangement.

  In demo mode the layout goes to the visitor's session instead of the
  database, the same route language and colours already take: the demo
  database user may only read, and every visitor should be able to
  rearrange the page for themselves without changing it for everyone else.
- **Public demo mode.** `DEMO_MODE=true` turns the panel into a publicly
  reachable, read-only instance: every page opens without a login and
  every POST is rejected before a handler runs. Because every
  state-changing action in this codebase is a POST, that one guard in
  `includes/auth.php` also puts the eight mail send sites, the four upload
  sites and every delete out of reach; `portal.php` and `invoice.php` call
  it themselves as they do not include `auth.php`. Form submissions
  redirect back with a notice, AJAX callers get
  `{"ok":false,"demo":true}`. Filters, search, paging, detail views and
  dark mode keep working. See `docs/DEMO.md`.
- `tools/seed_demo.php` replaces `install/seed_demo.sql`: it inserts row by
  row and reads back each generated id instead of hardcoding ids 1 and 2,
  and produces a full year of invented data (~450 rows across 20 tables)
  with every date relative to the run, so the demo does not age. It
  refuses to run unless `DEMO_MODE=true`, because it empties the tables
  first.
- `tools/check_demo.php`, `tools/test_demo.php` and
  `tools/test_seed_demo.php`, all wired into `tools/check.sh`. The seed
  test translates `install/schema.sql` to SQLite and runs the seed against
  it for real, so a wrong column name fails the build.
- `.env`-based configuration with a dependency-free loader
  (`includes/env.php`), so a plain FTP deployment still works without
  Composer. A missing `.env` now shows setup instructions instead of a
  raw database error.
- `install/schema.sql` covering every table the application uses, so a
  fresh install has a schema to import. Example data comes from
  `tools/seed_demo.php` (see below).
- Versioned migrations (`includes/migrations.php`, gated by a
  `schema_version` row in `settings`) replacing the per-request
  AUTO-PATCH blocks that used to attempt `CREATE`/`ALTER TABLE` on every
  page load across ten files.
- Design tokens (`assets/css/tokens.css`) and a token-based rewrite of the
  stylesheet (`assets/css/app.css`), replacing the old `design.css`.
- Shared layout partials (`includes/head.php`,
  `includes/layout_start.php`, `includes/layout_end.php`) — all eleven
  admin pages now share one head and page shell instead of duplicating
  markup; `login.php` and `portal.php` intentionally keep their own.
- Verification tooling: `tools/check.sh` (syntax, credential and private-
  identifier leak scan, unpinned CDN references, stray files under
  `uploads/`, CSS checks), `tools/check_schema.php` (structural validation
  of `install/schema.sql` without a real MySQL import), `tools/check_css.php`
  (stylesheet parity and structural checks) and `tools/test_env.php`
  (`.env` parser tests). CI runs `tools/check.sh` on PHP 8.1, 8.2 and 8.3.
- `csrf_check_get()` for the two GET-based status handlers in
  `quotes.php`/`finances.php` (later removed again — see Removed).
- `install/preflight.php`, a self-contained installation pre-flight check
  for both a fresh install and a migration of an existing installation
  onto this codebase. Runs before `.env` or the database exist, reports
  PHP version/extensions, `uploads/` permissions, and — once `.env` is
  present — database connectivity, table/row counts and `schema_version`,
  without ever printing a credential. Refuses to run once the
  installation is already set up, and instructs (never self-deletes) the
  operator to remove it after use.

- The quote system (`quotes.php`) is reachable from the sidebar. It has PDF
  output and mail dispatch, but no page linked to it, so it could only be
  opened by typing the URL. The twelve navigation entries are now grouped
  under four headings instead of forming one undifferentiated list.
- Shared building blocks in `assets/css/app.css` for what the pages used to
  express as inline styles: `.widget-accent-left`, `.widget-count`,
  `.section-label`, `.icon-tile`, `.due-chip`, `.tint-*`, `.k-badge-*`, and
  token-backed surface and text utilities. New tokens for tinted surfaces
  (`--accent-soft`, `--success-soft` and siblings), a two-step elevation
  scale (`--elev-rest`, `--elev-raised`), and a focus ring.
- A visible keyboard focus ring on links, buttons and navigation items.
  Bootstrap suppresses the browser default in several places and nothing
  replaced it. `prefers-reduced-motion` is now honoured.
- `.header-actions` and `.filter-bar` components. `includes/layout_start.php`
  now wraps `$header_actions` itself, so pages supply buttons rather than
  their arrangement, and the filter bar replaces a utility string that had
  been copied verbatim onto five pages. Wrapping, spacing and narrow-screen
  behaviour are defined once instead of eleven times.
- `.btn-label` and `.btn-label-xs` mark the parts of a button caption that
  may be dropped below 480px. Secondary header actions fall back to their
  icon; the primary action keeps its wording, because an unlabelled primary
  button is a guess.
- A dark theme for the client portal, with a toggle in its sticky
  navigation bar. Without a stored choice the portal follows the visitor's
  device setting (`prefers-color-scheme`), which suits a page someone opens
  once or twice and never configures; the toggle then fixes the choice for
  that browser. The admin panel is unaffected and still starts light.
  `includes/theme.php` gained a `$theme_follow_system` flag for this.
- `tools/test_csrf.php` and `tools/test_upload.php`, in the style of the
  existing checks and wired into CI. They cover the token comparison
  (including the empty-session case `hash_equals` would otherwise accept),
  the upload type and size rules, and `safe_filename()` against path
  traversal, null bytes and overlong names.
- `includes/numbering.php`, one place that hands out invoice and quote
  numbers, and `finances.invoice_number` with a unique index (migration 3).
- An editor for the seven e-mail templates under Settings › E-Mail-Vorlagen.
  Subject and message text are editable per template with a placeholder
  reference, a live preview rendered from example data, and a reset to the
  built-in default. The frame around every HTML mail — header, accent
  colour, button, signature, footer — is configured once and applies to all
  of them, so the wording can change without anyone having to touch the
  table layout that keeps a mail intact in Outlook.
- `includes/mail_templates.php` holds the templates and renders them;
  `tools/test_mail_templates.php` covers substitution, escaping and the
  frame, and runs in CI.
- A search across everything, opened with Ctrl+K (⌘+K) or the magnifier in
  the page header. It covers contacts, projects, invoices and expenses,
  quotes, support tickets and the wiki in one query and jumps straight to
  the hit. Until now every page had its own search, so finding a name meant
  knowing first whether it belonged to a contact, a project, an invoice or
  a ticket. Results are navigable by arrow keys and built with DOM methods
  rather than innerHTML, so a title containing markup is shown as text.
- A trash. Deleting a contact, project, invoice or quote now sets
  `deleted_at` (migration 4) instead of removing the row; `trash.php` lists
  what was deleted, restores it, or removes it for good, and clears
  anything older than 30 days when the page is opened. Deliberately limited
  to those four: for logs, milestones, comments and files deleting is cheap
  and a trash would only be in the way.
- `tools/check_soft_delete.php`, in CI. It reads every SELECT on the four
  tables out of the source with `token_get_all()` and fails when one does
  not account for `deleted_at`. Exceptions are listed in the tool with a
  reason, so an exception stays a decision instead of becoming an oversight.
- Quotes in the client and partner portal. The quote system was complete
  but its recipient never saw it — a PDF arrived by mail and they had to
  reply. A quote marked "Gesendet" now appears in the portal with
  "Annehmen" and "Rückfrage"; accepting sets the same status
  `quotes.php` already knows, so it lands in the existing workflow, and
  the sender is notified. Drafts stay out of the portal.
- Payment details on unpaid invoices in the portal, with a transfer code
  (EPC069-12, "Girocode") that German banking apps scan. Bank details are
  configured under Settings › Firma. The code is generated in the
  visitor's browser: handing an IBAN and an amount to an image service
  would be the wrong trade for payment data.
- Several contacts can share a project (migration 5, `task_contacts`).
  Everyone assigned sees it in their own portal, so customers and business
  partners can work on the same project. Access stays per person — own
  link, own PIN, own lockout — rather than one shared token: an individual
  can be removed without locking out the rest, and every action in the
  portal carries a name. `tasks.contact_id` remains the main contact, so
  invoicing and reporting are untouched, and existing projects are
  backfilled as members.
- Working together on a project in the portal (migration 6). A discussion
  per project for anything that does not belong to a single step, with the
  author's name on each entry and everyone assigned seeing the thread; the
  list of participants; and a "wer ist dran" marker per step that says
  whether the ball is with us or with them. Set in `tasks.php`, shown in the
  portal, counted in the sidebar badge.
- Participants can be set while editing a project, not only from the
  project card. The edit form carries a multi-select and reconciles
  membership on save, so `added_at` survives.
- `includes/logging.php` as the one place that writes a log entry, and
  `tools/check_forms.php` in CI, which fails when a `csrf_field()` ends up
  inside a form tag instead of within the form.
- The system log gained figures at the top (entries, today, failed
  attempts over seven days, last sign-in), a period filter and paging.
  Older entries were previously unreachable: the page showed the newest N
  and nothing else.
### Changed
- **Project participants are picked with checkboxes.** Both places that
  choose them did it differently: the edit dialog had a
  `<select multiple size="5">` with the note "hold Ctrl or ⌘ to select
  several" — unusable on a touchscreen, where one tap discards the whole
  selection — and the "Beteiligte am Projekt" dialog had a list with an X
  per row plus a dropdown to add one person at a time, each a separate
  save and page reload.

  Both now share one checkbox list from `includes/task_members.php`, with
  a search field above it that filters in the browser. The main contact is
  ticked and locked, labelled as such, and the lock follows along when the
  customer is changed in the edit dialog. The participants dialog saves
  the whole selection in one submission (`set_task_contacts`) instead of
  one change at a time.

  The reconciliation — compare wanted against current, keep existing rows
  so `added_at` survives, fix the roles — moved into
  `task_members_abgleichen()` and is shared by both handlers rather than
  written twice. `add_task_contact` and `remove_task_contact` are gone.
  `tools/test_task_members.php` runs it against the SQLite mirror of the
  real schema: changing the main contact, unselectable main contact,
  duplicate and unusable ids, and that two projects do not affect each
  other.
- **The global search sits with the other header buttons.** It used to be
  its own child of the page header, between the title and the action
  buttons. On narrow screens the title and the actions each claim a full
  row, so the magnifier was pushed onto a row of its own and the header
  became three rows tall — on every page, from 768 px down. It now lives
  at the end of the same group as the buttons, which makes the header two
  rows on a phone and puts the magnifier to the right of the actions on
  the desktop. On that phone row the whole group is right-aligned, so the
  buttons sit where they do on the desktop instead of jumping to the left
  edge. `.header-actions` is therefore always rendered, even on pages that
  bring no buttons of their own.
- **The task filter bar fits on one line.** The bar is built in two
  stages so the filters can collapse behind a button on a phone; that
  nesting also forced two rows on the desktop, with the search field
  alone above the filters. From 768 px up the intermediate wrappers are
  now dissolved with `display: contents`, so search field, filters and
  buttons share one flex row. The filters' widths moved out of `style`
  attributes into `.filter-field`, and their wrap threshold is lower, so
  everything fits on a 1366 px laptop instead of dropping the "Filter"
  button onto a second row.
- `SSO_ENABLED` is now forced to `false` in demo mode regardless of the
  `.env`, because `sso.php` writes to `sso_tokens` before any POST is
  involved.
- The uptime monitor makes no outbound request in demo mode; without
  that, anyone could point the server's `curl` at an arbitrary address
  through the monitored-URL list.

- The two parallel login paths (a settings-table password check with no
  rate limiting, and a separate users-table check) are consolidated into
  one, on the `users` table, with CSRF protection, an IP-based lockout,
  and session ID regeneration on success.
- PHPMailer and FPDF are now managed by Composer instead of hand-placed
  vendor copies; `vendor/` is committed so the project still deploys to
  shared hosting without SSH access.
- `logs` gained a dedicated `ip` column (schema version 2). The lockout
  check and the audit log both read this column instead of matching
  free-text descriptions, and login-related log descriptions no longer
  embed the client address as text at all.
- The `.txt` log export (`logs.php`) now writes **four** pipe-delimited
  fields per line — date, type, IP, description — instead of three; the
  IP column was reinstated in the export, the on-screen table and the
  search predicate after being pulled out of the free-text description.
- `.htaccess` uses `Require all denied` with a version-safe fallback to
  the Apache 2.2 `Order deny,allow` / `Deny from all` syntax, instead of
  only the legacy form.

- Colour carries meaning again instead of decoration. Every widget used to
  wear its own accent hue as a border — seven different ones on the
  dashboard alone — so no colour distinguished anything from anything else.
  The brand colour now carries the brand and the navigation; green, amber
  and red are reserved for status. Urgency moved out of the card border and
  into the counter beside the widget title, which stays grey until
  something is actually open.
- The eleven admin pages take their colours from the design tokens instead
  of hard-coded values. 291 literal hex colours are down to 86, and every
  one that remains is deliberate: HTML e-mail bodies (mail clients do not
  resolve custom properties), the user-selectable appointment palette, the
  QR code, and the hex placeholders in the colour fields. The 147
  `bg-white`/`bg-light` utilities are gone, so the dark theme no longer has
  to override them back at the end of the stylesheet.
- The page-local `<style>` blocks of the dashboard and the calendar moved
  into `assets/css/app.css`. Both defined component styles that the rest of
  the application had no way to reuse.
- The page header now defines how it behaves when it runs out of room. The
  heading may shrink (`min-width: 0`) instead of being squeezed into three
  lines while the buttons spill past the edge, and below 768px the actions
  move onto their own full-width row under the title.
- Header buttons are one size (`btn-sm`) and take their elevation from the
  card they sit on. `shadow-sm` was set on some and not others.
- Filter bars no longer carry per-page inline widths (`flex-grow`,
  `min-width`, `max-width`). Those overrode the stylesheet and were what
  stopped the search field from shrinking; the width cap now lives in the
  component. On `contacts.php` the search field also sat inside a wrapper
  `<div>`, so the component's rules never reached the flex child at all.
- `portal.php` takes its colours from the design tokens: 117 literal hex
  values are down to none, and `assets/css/tokens.css` is loaded by both of
  the HTML documents the file contains (the PIN gate and the portal itself).
  Card shadows use the shared elevation scale. Without this the theme
  attribute had nothing to act on — the page would have stayed light
  whatever the setting.
- The dashboard no longer checks monitored sites while building the page.
  `getParallelSiteStatuses()` ran on every load with a six-second timeout,
  so one slow or dead site delayed the entire page by up to six seconds —
  and the widget was refreshed over AJAX anyway. The initial render now
  shows a placeholder and the existing partial is fetched immediately.
- The sidebar's dashboard badge counts unseen portal activity again.
  `$_sb_portal` was computed with five queries per page load and never
  used.
- The dark theme's blanket `.rounded` rule no longer overrides an explicit
  background. It painted a card surface onto every rounded element,
  including ones meant to show the card behind them.
- The seven mails no longer carry their text in the code. `tasks.php`,
  `contacts.php`, `tickets.php` and `calendar.php` each held a complete HTML
  document inline, and `quotes.php` and `finances.php` built their prefill
  text in JavaScript. All of them now read from the templates; the three
  prefills substitute in the browser through `assets/js/mail-templates.js`,
  following the same rule as the PHP side.
- 64 queries across 13 files now exclude deleted rows. Without that a
  deleted record comes back in a list, a total or a dropdown — which is why
  the check above exists rather than a promise that all of them were found.
- Every log entry records the caller's IP. Only three of eighty did before,
  while the log view has always shown an "IP-Adresse" column — so the
  column was almost always empty. The eighty inserts became `log_event()`
  calls; the two in the login path keep their own insert, because the
  lockout counter depends on the IP they are handed rather than on
  `REMOTE_ADDR`.
- Twenty state-changing actions now leave a trace. All eleven settings
  actions were silent — company data, colours, logo, favicon, mail
  templates and system values could be changed without any record.
  Dismissing a dashboard notice stays unlogged on purpose: it flips a
  "seen" flag and would only add noise.
### Removed
- `clear_lockout.php`, which deleted every failed-login record with no
  authentication at all.
- The two `login_process*.php` variants, superseded by the consolidated
  login path in `includes/auth_login.php`.
- The unreachable GET status handlers in `quotes.php`/`finances.php` and
  `csrf_check_get()` — nothing in this repository or its private source
  ever linked to them, and a CSRF token read from a query string leaks
  through Referer headers, browser history and access logs for no
  benefit once the entry point is gone.

### Fixed
- **Filters survived no change.** Every list page follows post / redirect /
  get, but the redirect rebuilt its target from scratch —
  `header("Location: tasks")` — and dropped the query string with it. So
  filtering by "Offen" and then changing one task's status handed back the
  full list. It affected tasks, contacts, finances, tickets, quotes and
  wiki; on tasks that was eight filters, of which exactly one, the search
  term, had been rescued by hand through a hidden `back_q` field.

  `includes/filter_state.php` now carries the view across the submission.
  `filter_redirect()` replaces all 36 hand-built redirects, and the 18
  POST forms that named their own page in `action` lost that attribute —
  a form without one submits to the address it sits on, filters included.
  What gets carried is a fixed list per page, not "whatever arrived": a
  one-off `msg=1` must not be replayed after every change, and a
  redirect target taken from the request would be an open redirect. Every
  value is re-encoded through `http_build_query`, so a newline in a search
  term cannot split the header.

  `tools/test_filter_state.php` checks the list against the parameters the
  pages actually read, so a new filter cannot be forgotten silently — it
  found `qstatus` on finances, which this change had missed.
- `install/schema.sql` could not be imported at all. Two columns added by
  migrations (`task_milestones.waiting_on`, `client_assets.uploaded_by_name`)
  were appended to the end of their `CREATE TABLE` list with the preceding
  comma left in place, which MySQL rejects — so a fresh install had been
  broken since migration 5, while every existing installation kept running
  and never noticed. `check_schema.php` now checks for it.
- `invoice.php` verifies the session itself instead of including
  `includes/auth.php`, duplicating logic that belongs in one place. It now
  at least calls the demo guard explicitly, so its POST handler — which
  writes to `finances` and stores a PDF — cannot run in demo mode.
- The toast used for demo notices was confined to 180 px on a 360 px
  screen: a fixed-position box with `left: 50%` can only occupy the right
  half, so its `max-width` never applied. Centred via `left`/`right` and
  `margin` instead.
- Ten of the eleven converted pages emitted their own PHP source into the
  browser. The layout block was inserted after an existing closing tag without
  reopening PHP, so the code was literal text — and because literal text is
  always syntactically valid, `php -l` could not see it. `tools/check_php_tags.php`
  now uses the tokenizer to detect PHP code outside the tags, and `tools/check.sh`
  runs it.
- `tools/` is denied at the web server level. A local `tools/leakscan-local.txt`
  lists exactly the strings that must never be published — database host, name
  and user of the operator’s own installation — so a reachable `tools/` would
  have disclosed them.
- **CSRF bypass on first run:** `csrf_check()` used `hash_equals()`
  directly on the session and POST tokens; with both empty (a fresh
  session, no cookie yet) `hash_equals('', '')` returns `true`, letting an
  attacker page auto-submit the setup action on a freshly deployed,
  not-yet-configured instance. Both sides are now rejected outright when
  empty.
- **Poisonable lockout counter:** the login lockout matched on
  `description LIKE '%(IP: <ip>)%'`, and the description embedded the
  attacker-controlled e-mail field — posting
  `email=x (IP: <victim-ip>)` could lock out a real administrator
  indefinitely at zero cost. Matching now goes through the dedicated `ip`
  column exclusively.
- **SSO session fixation and replay:** `sso.php` set the authenticated
  session without `session_regenerate_id()`, and validated a token with a
  `SELECT` followed by a separate `UPDATE`, letting two simultaneous
  requests for the same token both pass. Token consumption is now one
  atomic `UPDATE ... WHERE used = 0`, gated on `rowCount()`, followed by
  `session_regenerate_id(true)`.
- **Login timing side-channel:** the dummy password hash used to keep
  failed-lookup timing constant was pinned at bcrypt cost 10, while real
  hashes used `PASSWORD_DEFAULT` (cost 12 on the deployed PHP version) —
  the ~4x cost gap meant an unknown e-mail answered measurably faster
  than a known one. Both sides are now pinned to the same cost (12), and
  a successful login rehashes its stored password in place if the cost
  no longer matches.
- **Migrations silently marked "done" on real failure:** a genuine
  migration error (permissions, lock, full disk) used to be swallowed the
  same way as a merely-already-applied step, stamping the schema version
  anyway and leaving the schema incomplete without ever retrying. Real
  failures are now logged distinctly and the version is left unstamped so
  the next request retries.
- `php_flag engine off`, meant to disable PHP execution under `uploads/`,
  sat in the root `.htaccess` outside any directory scope — on a mod_php
  host that disables PHP for the entire application. It now lives only in
  `uploads/.htaccess`, alongside a wider denied-extension list.
- **Favicon and company-logo uploads still accepted SVG:** `settings.php`
  carries its own upload allow-lists instead of using
  `includes/upload_helper.php`, and still listed `image/svg+xml`/`svg` for
  favicons (checked with `OR`, so a spoofed `Content-Type` header could
  bypass the MIME check on its own), while the logo upload never
  validated the file extension at all and could be tricked into saving a
  `.svg` file the same way. Both are the same stored-XSS risk SVG uploads
  were already removed for elsewhere: served inline from the panel's own
  origin, an SVG can carry `<script>`. SVG is now rejected for both, MIME
  type and extension are required together (`AND`, not `OR`), and the
  delete-on-replace loop still unlinks a pre-existing `.svg` file left
  over from before this fix.
- **Unvalidated fallback file upload in `tasks.php`:** the no-JS fallback
  path in the `edit_task` action moved uploaded files straight into
  `uploads/client_assets/` without calling `validate_upload()`, unlike
  every other upload endpoint in the app — any file type, including SVG,
  could be stored and served from the panel's own origin. It now runs
  through the same `validate_upload()`/`safe_filename()` checks as the
  AJAX upload path next to it.
- The calendar ignored the dark theme. Its twelve event colours were fixed
  pastel values with no dark counterpart, so the grid stayed bright however
  the theme was set. They now come from the `--state-*` token pairs.
- The finance chart drew a light grid and light axis labels onto a dark
  background. Chart.js renders to a canvas and cannot resolve CSS custom
  properties, so the token values are read once through `getComputedStyle`.
- The sidebar could not be scrolled when it outgrew the viewport. It is
  fixed-position at the full viewport height with no \`overflow-y\`, so
  anything past the bottom edge was not merely hidden but unreachable —
  there was no surface to scroll. Twelve entries in four groups overflow a
  375x667 screen by 252px, which is the lower half of the navigation
  including Logout, and a tablet in landscape is affected as well. The
  height now follows \`100dvh\` so it tracks the mobile address bar, and
  \`transition: all\` is narrowed to the properties that actually change,
  which kept the height from animating as that bar slid in and out.
- Four page headers overflowed horizontally on a phone: the calendar by 67px
  at 360px wide, projects by 47px, finances by 40px, the dashboard by 5px.
  `finances.php` overflowed even though it was the one page that already set
  `flex-wrap` — the header wrapped, but the button group inside it did not,
  so a button still crossed the edge. Verified from 320px to 1440px.
- `btn-outline-dark` was the only button variant without a dark-theme rule,
  leaving a dark border and dark text on a dark surface: the CSV export
  button in the finance header was invisible. The three places that used it
  now use `btn-outline-secondary`, which has one.
- The portal never set a text colour on `body`. While everything was light
  this was invisible, because the browser default is black and that fit.
  Under a dark theme every element without its own colour disappeared:
  ticket headings, the hint inside the upload area, the feedback card. The
  page also carries the handful of `[data-theme]` rules for the Bootstrap
  utilities it uses, which it could not inherit because it does not load
  `app.css`.
- Uploads crashed outright on a server without the `fileinfo` extension.
  `validate_upload()` called `mime_content_type()` directly, which only
  exists when that extension is loaded — the result was a fatal error on
  every upload in every form, not a rejected file. Type detection is now
  behind `detect_mime_type()`, and a file whose type cannot be established
  is rejected rather than waved through: the extension comes from whoever
  uploaded the file and is not a check.
- Invoice and quote numbers were derived from `SELECT COUNT(*)` for the
  current year. That counts rows, not numbers: delete one invoice and the
  next one issued repeats a number already printed on a sent PDF, and
  nothing rejected the duplicate because the number lived in the shared
  `title` column with no unique index. Two concurrent requests got the same
  number as well. §14 UStG requires outgoing invoices to carry a
  consecutive, once-only number.

  Numbers now come from the highest number already issued, are stored in
  their own `finances.invoice_number` column, and a unique index rejects a
  duplicate rather than accepting it silently. Migration 3 backfills in two
  steps: invoices whose number was already kept in `title` (how
  `invoice.php` writes them) keep it unchanged, because it is printed on
  PDFs that have gone out; anything left is numbered by invoice date,
  continuing after the highest number already used in that year.
- The client portal had no CSRF protection at all, though profile data,
  tickets, milestone approvals and file deletion are all changed there.
  All twelve forms now carry a token, the three AJAX calls send one, and
  every POST is checked. The access token in the URL looks like a
  credential but appears in history, in referrers and in any shared link —
  and after the PIN it is the session that authenticates anyway.
- `qrcode.min.js` is loaded with a Subresource Integrity hash in
  `portal.php` and `contacts.php`. It renders payment data, so a
  compromised CDN could put a different IBAN into the code.
- The portal's file upload never checked which project it was writing to.
  `task_id` came straight out of the form into the insert, so any signed-in
  portal user could place a file in any project, including another
  client's. It now verifies membership and answers 403.
- The portal showed "Sie" as the author of every client comment, whoever
  wrote it. With several people on a project (#11) that was simply wrong.
  Comments now carry the author's name, uploads and feedback record who
  they came from (migration 7), and the panel shows those names too.
- Table rows stayed white in the dark theme while their text turned light,
  which made them close to unreadable. Bootstrap colours table cells
  through its own variables, which were never pointed at the design
  tokens. Affected every table in the panel, not just the log.
- `csrf_field()` had landed inside a form tag in `portal.php` rather than
  inside the form: the `?>` of a PHP echo in the `id` attribute ended the
  tag for the expression that placed it. That form lost both its token and
  its id, and deleting a file from the portal would have failed twice over.
