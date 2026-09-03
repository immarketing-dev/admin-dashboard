# Screenshots

This directory is currently empty. The main `README.md` does not embed any
screenshot yet, on purpose — there is no web server, database or browser
available in the environment this repository was prepared in, so no image
could be produced or verified here. An embedded image that doesn't exist
is the first thing a visitor to the repository would see on GitHub, so
nothing is linked until the files below actually exist.

## Never capture screenshots from an installation holding real customer data

Take these screenshots only against an instance loaded with
`install/seed_demo.sql` — fictional contacts, projects and invoices on
reserved example domains. Never against a production database. A
screenshot is a permanent, public artifact; a customer name, e-mail
address or invoice amount that ends up in one cannot be un-published by
deleting the file later.

## How to capture them

1. Set up a local instance following the README's Installation section.
2. Import `install/schema.sql`, then `install/seed_demo.sql`.
3. Log in (the first visit creates the administrator account) and browse
   with the demo data loaded.
4. Capture, at a reasonable desktop width (around 1440px), and save as
   PNG directly into this directory:
   - `dashboard.png` — the Dashboard (KPIs, deadlines, uptime monitor,
     lead inbox)
   - `projects.png` — the Projects view (milestones, time tracking)
   - `finances.png` — Finances (income/expenses, charts)
   - `portal.png` — the client Portal, as a contact would see it after
     logging in with a token and PIN
5. Add the embeds to the main `README.md` once the files exist, e.g.:

   ```markdown
   ![Dashboard](docs/screenshots/dashboard.png)
   ```

6. Run `bash tools/check.sh` again afterwards — it also flags stray files
   under `uploads/`, so make sure any test uploads used while capturing
   the Portal or Projects screenshots are cleaned up first.
