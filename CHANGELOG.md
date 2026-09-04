# Changelog

All notable changes to this project are documented here. Format based on
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/). This changelog
covers the process of turning a private, single-install PHP admin panel
into this public repository — it does not track the panel's earlier
private history.

## [Unreleased]

### Added
- **Draggable dashboard.** The eleven widgets on the start page sat in four
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
