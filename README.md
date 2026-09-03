# Admin Dashboard

Self-hosted admin panel for freelancers and small agencies. Projects with
milestones and time tracking, a CRM, invoicing and quotes with PDF output,
support tickets, a wiki, and a client portal your customers log into with a
token and a PIN.

Plain PHP and MySQL. No framework, no build step — upload it and it runs.

## Features

- **Dashboard** — KPIs, upcoming deadlines, parallel uptime monitoring for a
  list of URLs, an inbox for leads coming from your website's contact form
- **Projects** — milestones, file attachments, time tracking, client feedback
- **Kanban board** — drag and drop across three columns
- **CRM** — contacts, companies, portal access per contact
- **Finances** — income and expenses, invoice PDFs, recurring entries, charts
  by month, year or lifetime
- **Quotes** — PDF generation, status tracking, one-click conversion to an
  invoice, e-mail delivery with the PDF attached
- **Support tickets** — priorities, internal and client-visible notes
- **Calendar** — deadlines and due dates, appointments with .ics invitations
- **Wiki** — articles with attachments, selectively shareable with clients
- **Client portal** — projects, milestone approval, file upload, tickets,
  invoices and shared wiki articles, reached with a token and a PIN
- **Dark mode**, responsive down to phone width

Screenshots: see [docs/screenshots/README.md](docs/screenshots/README.md) —
none are checked in yet, since taking them needs a running install.

## Requirements

- **PHP 8.1 or newer**, with the `pdo_mysql`, `curl`, `mbstring` and
  `fileinfo` extensions. `gd` is needed only if your company logo (placed
  into invoice and quote PDFs via FPDF's `Image()`) is a GIF or a WebP —
  FPDF converts those through `gd` internally. A PNG or JPEG logo needs no
  `gd` at all. `zlib` is also expected — FPDF compresses PDF streams with
  it by default.
- **MySQL 5.7.8+ or MariaDB 10.2.7+.** This is a hard floor, not a
  suggestion: `install/schema.sql` gives `quotes.items` a `JSON` column,
  which older servers reject outright, and `wiki_articles` declares two
  `TIMESTAMP` columns that both default to `CURRENT_TIMESTAMP` (one of them
  also `ON UPDATE CURRENT_TIMESTAMP`) — MySQL before 5.6.5 allows only one
  such column per table and the import fails.
- **Apache with `mod_rewrite` and `mod_headers`.** The shipped `.htaccess`
  files (root and `uploads/`) work unchanged on both Apache 2.2 and 2.4. On
  nginx there is no `.htaccess` equivalent — you must translate the rules
  yourself. They protect four things: `.env` and `config.php` from direct
  download, everything under `includes/` from direct access, PHP execution
  inside `uploads/` (a hard requirement — see Security), and they add a set
  of security response headers. `mod_rewrite` also serves extension-free
  URLs (`/tasks` instead of `/tasks.php`) — see "Clean URLs" below.

### Clean URLs

The panel is built and linked internally to use extension-free URLs —
`/tasks` rather than `/tasks.php`. This is implemented entirely in the
root `.htaccess`, inside an `<IfModule mod_rewrite.c>` block:

- A request for `/tasks` is served internally by `tasks.php`, but only if
  `tasks.php` actually exists and no real file or directory already sits
  at that path — so `assets/`, `uploads/` and `vendor/` are never touched.
- A direct request for `/tasks.php` gets a `301` redirect to `/tasks`, so
  the old URL doesn't stay indexed alongside the new one. **That redirect
  only fires for `GET` requests.** A `301` on a `POST` gets replayed by
  the browser as a `GET` (per RFC 7231), which would drop the request
  body — and nearly every action in this panel (every form, `board.php`'s
  and `finances.php`'s own AJAX calls, file uploads) is a `POST` straight
  to a `.php` file. So `POST` requests to `*.php` are left alone entirely
  and hit the real file directly; only `GET` requests get redirected.

**On a host without `mod_rewrite`**, that whole block is skipped and the
site keeps working exactly as before, with `.php` visible in every URL —
nothing else depends on the rewrite being active.

**On nginx**, there is no `.htaccess` to translate automatically. Add the
equivalent of both rules to your `server {}` block yourself: a `try_files`
fallback for the extension-free form, plus a redirect that is likewise
gated to `GET`/`HEAD` only:

```nginx
location / {
    try_files $uri $uri.php $uri/ =404;
}

location ~ ^/(.+)\.php$ {
    if ($request_method !~ ^(GET|HEAD)$) { break; }
    return 301 /$1$is_args$args;
}
```

## Installation

```bash
git clone https://github.com/<user>/admin-dashboard.git
cd admin-dashboard
cp .env.example .env
```

Edit `.env` and fill in at least `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS`.

`vendor/` is committed, so `composer install` is **optional** — run it only
if you want to update PHPMailer or FPDF to a newer version.

Import the schema, then optionally the demo data:

```bash
mysql -u USER -p DATABASE < install/schema.sql
mysql -u USER -p DATABASE < install/seed_demo.sql   # optional, see below
```

Make `uploads/` writable by the web server. Then open the site in a
browser — the first visit creates the administrator account.

## Database notes

- **Foreign keys are new.** `install/schema.sql` declares `FOREIGN KEY`
  constraints (`ON DELETE CASCADE` / `ON DELETE SET NULL`) that an
  installation predating this schema file never had. On a fresh install,
  deleting a task cascades away its milestones, client assets and time
  entries, and deleting a contact sets `contact_id` to `NULL` on tasks,
  finances and support tickets. An older database that was migrated up
  (see below) does none of this automatically — the child rows are left in
  place, orphaned but harmless, since the application already filters on
  the parent existing. Adding the constraints to such a database later is
  possible but not automatic: you must find and remove the orphan rows
  first, or `ALTER TABLE ... ADD CONSTRAINT` fails on the first offending
  row.
- **`install/seed_demo.sql` hardcodes ids 1 and 2** (contacts, tasks) on the
  assumption that it is loading into a freshly created, empty database
  where `AUTO_INCREMENT` starts at 1. Import it once, immediately after
  `schema.sql`, and never against a database that already has rows —
  otherwise the demo milestones and invoices silently attach to whatever
  unrelated contact or task already happens to own that id.
- **Migrations run automatically** on every request, gated by a
  `schema_version` row in the `settings` table (`includes/migrations.php`).
  A step that is already applied (table/column/index already exists) is
  logged as informational and skipped. A step that fails for a real reason
  — permissions, a lock, a full disk — is logged as a failure and the
  version is deliberately **not** stamped, so the next request retries it
  rather than leaving the schema half-upgraded. Check the PHP error log
  after an upgrade if something looks wrong.

## Configuration

Everything in `.env` can be changed later under **Settings**, which stores
overrides in the `settings` table and takes precedence over the `.env`
value at render time. Colours, company name, contact addresses, logo and
favicon are all editable from the UI once you're logged in.

### Cross-domain single sign-on

`SSO_ENABLED` is `false` by default. `sso.php` only **consumes** tokens — it
reads a 64-character hex token from `sso_tokens`, atomically marks it used,
and starts an authenticated session. Nothing in this repository creates
those tokens. If you want SSO from a separate site, you have to insert rows
into `sso_tokens` yourself over there, and if the panel's session should
carry a specific user identity rather than a generic admin session, you
need to extend `sso.php` to associate the token with a `users` row — as
shipped, it does not. Turn this on only if you understand and accept that
a valid token grants a full admin session, and only across domains you
trust.

### Locked out

Five failed logins from one IP address within fifteen minutes trigger a
lockout (`includes/auth_login.php`). It expires on its own after fifteen
minutes; to clear it immediately:

```sql
DELETE FROM logs WHERE action_type IN ('LOGIN_FAILED', 'SYSTEM_LOCKOUT');
```

Two deployment details change how this behaves in practice, and neither is
a bug:

- **Behind a reverse proxy, CDN or load balancer that terminates TLS**,
  `REMOTE_ADDR` as PHP sees it is the proxy's own address for every
  visitor. Five bad passwords from anyone then locks out the panel for
  everyone. If you deploy behind one, make sure the real client address
  reaches PHP (e.g. via a trusted `X-Forwarded-For`) before relying on this.
- **An attacker with an IPv6 /64 block** has 2^64 source addresses
  available and effectively gets one login attempt per address, sidestepping
  the per-IP counter entirely.

### Lead inbox

The dashboard reads pending enquiries from the `leads_inbox` table. Nothing
in this project writes to it — point your website's contact form at it
directly:

```sql
INSERT INTO leads_inbox (name, email, phone, subject, message, source)
VALUES (?, ?, ?, ?, ?, 'Contact form');
```

## Security

- `.env`, `config.php`, everything under `includes/`, and PHP execution
  inside `uploads/` are all denied at the web server level (see
  Requirements above for what that depends on).
- CSRF tokens on every state-changing form.
- All queries go through prepared statements.
- Uploads are validated by MIME type and extension.
- Session cookies are `HttpOnly` and `SameSite=Lax`; the session ID is
  regenerated on login (and on SSO login) to prevent fixation.
- Passwords are hashed with bcrypt at a pinned cost of 12. A login attempt
  against an e-mail address that doesn't exist still runs a
  `password_verify()` call against a dummy hash at the same cost, so a
  failed lookup takes about as long as a real one (roughly 165 ms) and
  doesn't reveal whether an address exists.

Report a vulnerability as described in [SECURITY.md](SECURITY.md).

## Tooling

```bash
bash tools/check.sh          # syntax, credential/identifier leak scan,
                              # unpinned CDN references, stray upload
                              # files, and CSS structural checks
php tools/check_schema.php   # table coverage and structural DDL validation
                              # for install/schema.sql
php tools/check_css.php [path/to/old/design.css]
                              # CSS checks; the baseline argument is
                              # optional — without it, only the structural
                              # checks run and the parity checks are skipped
php tools/test_env.php       # unit tests for the .env parser
```

`bash tools/check.sh` runs `check_css.php` internally (without a baseline)
as part of its own checks; run `check_schema.php` and `test_env.php`
separately. All must exit 0 before you open a pull request — see
[CONTRIBUTING.md](CONTRIBUTING.md).

## Language

The interface is German. Code comments are German; documentation is
English. There is no translation layer — changing the interface language
means editing the strings.

## License

MIT — see [LICENSE](LICENSE).
