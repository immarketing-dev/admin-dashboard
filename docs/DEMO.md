# Public demo mode

`DEMO_MODE=true` turns the panel into a **publicly reachable, read-only
instance**: visitors reach every page without logging in, and nothing they
do is saved.

It exists so you can hand someone a link and let them look around — the
dashboard, the project view, the invoices, and the customer portal from
the customer's own side.

> **Never enable this on a real installation.** It grants an admin session
> to anyone who opens the URL. It belongs on its own subdomain, pointed at
> its own throwaway database.

---

## What demo mode changes

| | Normal | Demo |
|---|---|---|
| Login | Password required | Session granted automatically; `login` redirects away |
| Every POST | Handled | Rejected before any handler runs |
| Outbound mail | 8 send sites | Unreachable — all of them sit behind POST |
| File uploads | 4 upload sites | Unreachable — same reason |
| Uptime checks | Live `curl` per monitored URL | No request leaves the server; a derived status is shown |
| Trash auto-purge | Runs on page load | Skipped |
| Cross-domain SSO | Follows `SSO_ENABLED` | Forced off, whatever the `.env` says |
| Portal PIN | Verified, with a lockout counter | Verified, no counter; the PIN is printed on the card |

Everything that does **not** write still works: filters, search
(<kbd>Ctrl</kbd>+<kbd>K</kbd>), paging, tabs, detail views, the calendar,
dark mode, and the invoice preview. A visitor who tries to save gets a
short notice instead of an error.

### Why POST is the whole story

Every state-changing action in this codebase is a POST. `includes/auth.php`
rejects POSTs in demo mode before any handler runs, which covers twelve
pages at once; `portal.php` and `invoice.php` carry their own guard because
they do not include `auth.php`.

Two places wrote on the *display* path and had to be handled separately —
`trash.php` (auto-purge) and `sso.php` (token cleanup). `tools/check_demo.php`
enforces that no third one appears.

---

## Setting it up

### 1. A separate database

Demo mode must never point at production data. Create a new, empty
database and import the schema:

```sh
mysql -u ADMIN -p demo_dashboard < install/schema.sql
```

### 2. A `.env` for the demo

Copy `.env.example` and set at least:

```ini
DB_NAME=demo_dashboard
DB_USER=demo_reader
DB_PASS=...

DEMO_MODE=true
DEMO_PORTAL_PIN=1234

BASE_URL=https://demo.example.com

# Leave empty. Belt and braces: even if a write path were ever found,
# there would be nothing to send mail with.
SMTP_HOST=
SMTP_USER=
SMTP_PASS=

SSO_ENABLED=false
```

Use a demo company name and address too — `tools/seed_demo.php` writes a
fictional one into `settings`, but `COMPANY_NAME` in the `.env` is the
fallback.

### 3. Seed the data

The seed needs write access, so run it **before** switching to the
read-only user — or pass the privileged credentials on the command line so
they never touch the `.env`:

```sh
php tools/seed_demo.php --yes
php tools/seed_demo.php --yes --db-user=ADMIN --db-pass=SECRET
```

It refuses to run unless `DEMO_MODE=true`, because it empties the tables
before filling them. On success it prints the portal links.

The data covers a full year: 6 contacts, 8 projects with milestones,
comments and tracked time, 12 months of invoices and expenses, quotes in
every state, tickets, wiki articles, calendar entries and a log history —
about 450 rows. All dates are relative to the day you run it, so the demo
does not age.

### 4. A database user that can only read

This is the backstop under the application guard. If a future page ever
forgets its guard, the write fails at the database instead of succeeding:

```sql
CREATE USER 'demo_reader'@'%' IDENTIFIED BY '...';
GRANT SELECT ON demo_dashboard.* TO 'demo_reader'@'%';
```

`run_migrations()` returns after a single `SELECT` once the schema is at
the current version, so a read-only user is enough at runtime.

### 5. Keep it out of search engines

Fictional company names and invoice amounts should not end up in the
index, competing with your real site. Add to the demo's `.htaccess`:

```apache
<IfModule mod_headers.c>
    Header always set X-Robots-Tag "noindex, nofollow"
</IfModule>
```

---

## The portal links

`tools/seed_demo.php` prints them at the end. The tokens are derived from
a fixed string rather than random, so a link you have shared keeps working
after you re-seed:

```
https://demo.example.com/portal?token=<64 hex characters>
```

The PIN card shows `DEMO_PORTAL_PIN` in plain text, so a visitor can walk
through the real login step and then see the portal as a customer does.

Five of the six seeded contacts have portal access. The sixth is a
prospect who has not been invited yet — so the panel shows both states.

---

## Updating the demo

Deploy as usual, then re-seed if the data changed:

```sh
php tools/seed_demo.php --yes --db-user=ADMIN --db-pass=SECRET
```

Because nobody can write, the data never drifts — there is no scheduled
reset to set up.

---

## Checks

`tools/check.sh` runs both of these:

- `tools/check_demo.php` — verifies the guard is in place on every
  POST-handling page, that nothing writes on the display path outside two
  documented exceptions, that the uptime check is disabled, and that
  `.env.example` does not ship with `DEMO_MODE=true`.
- `tools/test_seed_demo.php` — translates `install/schema.sql` to SQLite
  and actually runs the seed against it, then checks row counts, foreign
  keys, invoice numbers, quote JSON and that every address stays inside a
  reserved namespace (RFC 2606).

`tools/test_demo.php` covers the guard itself: which requests pass, the
JSON shape returned to AJAX callers, and that the redirect target can never
leave the site.
