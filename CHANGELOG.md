# Changelog

All notable changes to this project are documented here. Format based on
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/). This changelog
covers the process of turning a private, single-install PHP admin panel
into this public repository — it does not track the panel's earlier
private history.

## [Unreleased]

### Added
- `.env`-based configuration with a dependency-free loader
  (`includes/env.php`), so a plain FTP deployment still works without
  Composer. A missing `.env` now shows setup instructions instead of a
  raw database error.
- `install/schema.sql` covering all 21 tables the application uses, and
  `install/seed_demo.sql` with fictional demo data (reserved
  `.example`/`.org`/`.net` domains only) so a fresh install doesn't start
  empty.
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
### Changed
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
