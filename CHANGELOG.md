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
