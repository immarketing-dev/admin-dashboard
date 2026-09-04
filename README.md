# Admin Dashboard

[![CI](https://github.com/immarketing-dev/admin-dashboard/actions/workflows/ci.yml/badge.svg)](https://github.com/immarketing-dev/admin-dashboard/actions/workflows/ci.yml)
[![PHP 8.1+](https://img.shields.io/badge/PHP-8.1%2B-777bb4)](https://www.php.net/)
[![No build step](https://img.shields.io/badge/build%20step-none-lightgrey)](#installation)
[![License: MIT](https://img.shields.io/badge/license-MIT-blue)](LICENSE)

Self-hosted admin panel for freelancers and small agencies. Projects with
milestones and time tracking, a CRM, invoicing and quotes with PDF output,
support tickets, a wiki, and a client portal your customers log into with a
token and a PIN.

Plain PHP and MySQL. No framework, no build step — upload it and it runs.

### ▶ [Try the live demo](https://admin.david-imminger.de/demo)

No login, nothing to install. Every page is open; saving is disabled, so
the data stays as it is. The client portal is part of it — open
[the portal as a customer sees it](https://admin.david-imminger.de/demo/portal?token=6cd6b22dedac3c1a6b9acbd6f928860b0accbd5f658c45554913d24c2fc3e616)
and enter the access code `1234`, which the page shows you anyway.

Every name, company, invoice and address in it is invented. How the demo
mode works, and how to run one yourself, is in [docs/DEMO.md](docs/DEMO.md).

---

**Contents** — [Features](#features) · [Screenshots](#screenshots) · [Requirements](#requirements) · [Installation](#installation) · [Configuration](#configuration) · [Security](#security) · [Tooling](#tooling)

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
- **Reports** — revenue per client, outstanding invoices by age, hours
  worked but not yet billed, and a timesheet with CSV export
- **Support tickets** — priorities, internal and client-visible notes
- **Calendar** — deadlines and due dates, appointments with .ics invitations
- **Wiki** — articles with attachments, selectively shareable with clients
- **Client portal** — projects, milestone approval, file upload, tickets,
  invoices and shared wiki articles, reached with a token and a PIN
- **German or English**, switchable in the settings; the client portal
  follows each contact's own language
- **Dark mode**, responsive down to phone width

## Screenshots

Taken from the live demo linked above.

| | |
|---|---|
| ![Dashboard](docs/screenshots/dashboard.png) | ![Projects](docs/screenshots/projects.png) |
| **Dashboard** — KPIs, deadlines, uptime monitor, lead inbox | **Projects** — milestones, time tracking, client feedback |
| ![Finances](docs/screenshots/finances.png) | ![Client portal](docs/screenshots/portal.png) |
| **Finances** — twelve months of income and expenses | **Client portal** — what a customer sees after logging in |

How they were captured: [docs/screenshots/README.md](docs/screenshots/README.md).

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

### Pre-flight check (recommended)

Before editing `.env` or importing the schema, upload the project to the
target server and open `install/preflight.php` in a browser. It works
without any configuration and reports what the server already has — PHP
version and extensions, whether `uploads/` and its six subdirectories are
writable, and, once `.env` exists, whether the database is reachable,
which of the 21 expected tables are present, and current row counts
(useful for confirming nothing was lost when migrating an existing
installation onto this codebase). Act on anything it reports as FAIL, then
**delete the file** — it reads server and database internals and must not
stay reachable after setup.

Edit `.env` and fill in at least `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS`.

`vendor/` is committed, so `composer install` is **optional** — run it only
if you want to update PHPMailer or FPDF to a newer version.

Import the schema:

```bash
mysql -u USER -p DATABASE < install/schema.sql
```

To fill a database with example data instead of starting empty, see
[Public demo mode](#public-demo-mode).

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
- **Example data comes from `tools/seed_demo.php`**, not from a `.sql`
  file. It inserts row by row and reads back each generated id, so the
  references always match — the earlier `install/seed_demo.sql` hardcoded
  ids 1 and 2 and silently attached its milestones and invoices to
  whatever unrelated rows already owned those ids. The script refuses to
  run unless `DEMO_MODE=true`, because it empties the tables first.
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

### Scheduled tasks

Until you set this up, **nothing in the panel happens on its own.** Overdue
invoices were only stamped when someone opened the finance page, the log
was only trimmed on login, and the uptime checks only ran while a dashboard
was on screen. `cron.php` collects that work into one entry point and adds
what needs a schedule to work at all: payment reminders and recurring
entries.

Hourly is a sensible interval. Every task is repeatable — a second run in
the same hour finds nothing left to do, and reminders carry their own
20-hour lock per invoice on top of that.

**With shell access**, no token is needed — whoever can run this already
has the `.env`:

```cron
0 * * * * /usr/bin/php /path/to/panel/cron.php >/dev/null 2>&1
```

**Without shell access**, most shared hosts offer a "web cron" that fetches
a URL. Set `CRON_TOKEN` in `.env` to a value of at least 16 characters
(`php -r "echo bin2hex(random_bytes(24)), PHP_EOL;"`) and point it at:

```
https://admin.example.com/cron?token=THE_VALUE
```

Leaving `CRON_TOKEN` empty keeps the HTTP route **closed** — it is not a
fallback to "no check". An open endpoint here would let anyone trigger
mails to your customers.

The run prints a plain-text report of what it did and exits non-zero if a
task failed, so a monitoring service can watch it. In demo mode it refuses
to run at all.

### Payment reminders

Off by default, and deliberately so: installing an update should never make
a panel start mailing its customers unprompted. Under **Settings → System**,
"reminder stages" takes a list of days after the due date — `7, 21` sends a
friendly reminder after a week and a second one after three. Empty means no
automatic reminders at all.

The **bell button** in the invoice list works regardless of that setting and
regardless of whether a cron run is set up. It opens the mail prefilled from
the `payment_reminder` template, lets you edit it, and attaches the invoice
PDF if one exists. Both routes go through the same code, so a reminder you
send by hand advances the counter and pushes back the next automatic stage
rather than running alongside it.

After the last configured stage the panel stops. It does not escalate, and
it adds no late fees or interest — those are decisions with legal
consequences, not features.

### Recurring entries

`is_recurring` used to be a label and nothing more: the switch said
"monthly fixed costs", set a `1`, and was read as a filter, a badge and a
CSV column. It never created anything — a maintenance contract had to be
retyped every month.

The entry form now has a **Repeat** field (monthly, quarterly, yearly) plus
the next date. On each cron run, entries that have come due are created
from the template, carrying over the client, amount, line items and tax
type. Income gets a fresh invoice number; an expense does not — it is not
an outgoing invoice and must not consume a number from that sequence.

Two details worth knowing:

- **The end of the month is handled.** A series anchored on the 31st becomes
  the 28th in February and returns to the 31st in March, rather than
  drifting forward permanently.
- **No PDF is generated.** The entry appears as an open invoice in the list;
  use the existing button to produce the PDF — which is the moment you'd
  look over a recurring invoice anyway, before it goes out.

A single run creates at most twelve entries per template, so a `next_run`
accidentally left in the distant past cannot produce a decade of invoices
at once. The rest follows on the next run.

### Reports

Everything these need was already in the database — an hourly rate on
client and project, tracked minutes with a "billed" marker, invoice
amounts with due dates. None of it was evaluated: the finance page drew
income against expenses over time, and `time_entries` had no view of its
own at all. So this page adds no table and no migration; it only reads.

Four answers, on two tabs:

- **Outstanding invoices by age** — 1–30, 31–60, 61–90 and older, with the
  reminder count per invoice beside it. Here the traffic-light colouring
  is an actual statement about state, which is why it is used.
- **Revenue per client**, per year, with paid and outstanding kept apart —
  a single figure mixing both says nothing about whether the money
  arrived.
- **Worked but not yet billed**, per project, valued at the rate in force
  today (project before client before default). This is the number the
  page exists for: what has been done and not yet invoiced.
- **A timesheet** — by week, month or year, grouped by day and by project,
  with a CSV export.

**What is deliberately absent:** the hourly rate actually achieved per
project. That would require attributing an invoice amount to a project,
and the data does not support it — `finances` knows a contact, not a
`task_id`. The only link is `time_entries.invoice_id`, and an invoice
usually covers more than one project's time. A number that pretended to
know would be worse than no number.

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

### Public demo mode

`DEMO_MODE=false` by default. Setting it to `true` turns the panel into a
publicly reachable, read-only instance: every page opens without a login,
and every POST is rejected before a handler runs — which also puts the
mail, upload and delete paths out of reach, since all of them sit behind
POST. `tools/seed_demo.php` fills a throwaway database with a year of
invented data.

This grants an admin session to anyone with the URL. It belongs on its own
subdomain with its own database and a `SELECT`-only database user — never
on a real installation. See [docs/DEMO.md](docs/DEMO.md) for the full
setup.

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

### Reserved paths

The log viewer is `systemlogs.php`, not `logs.php`. Some shared hosts -
IONOS among them - reserve `/logs` at the server level and answer it with
403 no matter what the application does. A rewrite cannot override a deny
that matches the URL rather than the file, so the page carries a different
name instead. Keep that in mind before adding a page called `stats`,
`admin` or `cgi-bin`.

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

Every check exits 0 when it passes. All must pass before a pull request —
see [CONTRIBUTING.md](CONTRIBUTING.md).

```bash
bash tools/check.sh            # the whole suite (see below)
php tools/check_schema.php     # install/schema.sql: tables, keys, foreign
                               #   key targets, stray commas
php tools/test_env.php         # unit tests for the .env parser
```

`tools/check.sh` runs these internally:

| Check | What it catches |
|---|---|
| `check_css.php` | raw colours, undefined tokens, misplaced `[data-theme]` rules |
| `check_php_tags.php` | PHP code outside its tags — a page serving its own source |
| `check_includes.php` | a function called but never loaded (HTTP 500 at runtime) |
| `check_i18n.php` | untranslated strings, orphaned entries, mismatched placeholders |
| `check_demo.php` | the demo-mode write guard, and writes on the display path |
| `test_session.php` | session cookie name, path and flags |
| `test_i18n.php` | renders the navigation in both languages and compares |
| `test_demo.php` | which requests the demo guard lets through |
| `test_seed_demo.php` | runs the demo seed against SQLite and checks the result |
| `test_cron_billing.php` | reminder stages, recurrence dates, double-send guards |
| `test_reports.php` | ageing buckets, period boundaries, hourly-rate resolution |
| `test_reports_render.php` | renders the reports page in eight states, empty database included |

Run separately when you need them:

```bash
php tools/check_soft_delete.php   # every SELECT filters deleted_at
php tools/check_forms.php         # no form tag swallows its own CSRF field
php tools/test_csrf.php           # CSRF helpers
php tools/test_upload.php         # upload validation
php tools/test_mail_templates.php # mail templates render without CSS variables

php tools/export_demo_sql.php <file.sql>
                                  # demo data as an importable MySQL file,
                                  #   for hosting without a shell
php tools/deploy.php <dir>        # build an upload folder, keeping .env
                                  #   and uploads/ in place
```
## Language

The interface runs in **German or English**, chosen on the settings page.
The client portal follows each contact's own language, so you can work in
German while an English-speaking client sees their portal in English.

Translations live in `lang/en.php`, keyed by the German source text:
`t('Speichern')` looks up `'Speichern'`. A missing entry falls back to the
German original, so the interface is never empty and never shows a raw key.
`includes/i18n.php` explains why database values — `'Offen'`, `'Bezahlt'` —
are translated for display only and never on write.

Translation is being done in stages; anything not yet covered stays German.
`php tools/check_i18n.php` reports what is still missing.

Code comments are German. Documentation, commit messages and pull request
descriptions are English.
## License

MIT — see [LICENSE](LICENSE).
