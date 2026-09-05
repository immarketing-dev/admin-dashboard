# Screenshots

## Never capture from an installation holding real customer data

Capture only against a demo instance — fictional contacts, projects and
invoices on reserved example domains. Never against a production database.
A screenshot is a permanent, public artifact; a customer name, e-mail
address or invoice amount that ends up in one cannot be un-published by
deleting the file later.

Running the panel with `DEMO_MODE=true` and the data from
`tools/seed_demo.php` guarantees this: that data is invented end to end,
and demo mode blocks every write, so nothing you click while capturing can
change what the next screenshot shows.

## The set

Set up a demo instance following [docs/DEMO.md](../DEMO.md), then capture
at a desktop width of 1440px and save as PNG into this directory:

| File | Page | What it should show |
|---|---|---|
| `dashboard.png` | `/` | KPIs, deadlines, uptime history, lead inbox |
| `projects.png` | `/tasks` | milestones, time tracking, client feedback |
| `board.png` | `/board` | the three columns with progress on each card |
| `finances.png` | `/finances?period=year` | income and expenses, the twelve-month chart |
| `reports.png` | `/reports` | outstanding by age, revenue per client, unbilled hours |
| `portal.png` | `/portal?token=…` | the portal as a contact sees it, past the PIN |

## Which language

Capture the **German** interface. The demo data is German — client names,
project titles, ticket subjects — and the English interface is not
finished: page-level action buttons, status badges, chart labels and month
names still come through in German. An English shell around German content,
with German buttons in it, looks worse in a screenshot than a German
interface that is consistent with itself.

Switch it in Settings, or set `ui_language` in the `settings` table to `de`
before capturing. When the English translation is complete, this section
should say the opposite.

## A demo instance without a database server

`php tools/serve_demo.php` copies the project into a throwaway directory,
points its `config.php` at the SQLite mirror the tests already use, seeds
it, and starts PHP's built-in server. No MySQL, no configuration, and the
repository stays untouched.

```bash
php tools/serve_demo.php --port=8099 --lang=de
```

It skips the portal PIN (a POST, which a headless run cannot send) and
hides the notification toasts, so the pages below are reachable straight
away. It is a tool for looking at things, not a second supported way to
run the panel: the mirror covers what the pages ask for, not what MySQL
can do. If something misbehaves there, suspect the mirror before the page.

## How to capture


A headless browser does it without a window manager and gives the same
result every time. Edge and Chrome take the same flags:

```bash
"/c/Program Files (x86)/Microsoft/Edge/Application/msedge.exe" \
  --headless=new --disable-gpu --hide-scrollbars \
  --user-data-dir=/tmp/shot-profile \
  --window-size=1440,1020 \
  --screenshot="dashboard.png" \
  "http://localhost:8000/"
```

Two things worth doing before pressing the button:

- **Dismiss the notification toasts on the dashboard.** Four of them stack
  in the top-right corner and cover a whole widget. In demo mode their
  close button is a POST and gets rejected, and a headless run cannot click
  anyway — add `.toast-container, .toast { display: none !important; }` to
  the capture instance's `assets/css/app.css` for the duration.
- **Open the finance page on the year view** (`?period=year`). The default
  is the current month, which is a handful of bars. The year view is what
  makes the chart worth a screenshot.

The client portal sits behind a PIN, and that is a POST as well. On the
capture instance, set the session flag directly after `$_sess_key` is
assigned in `portal.php` instead of automating the form.

Then add the embeds to the main `README.md`:

```markdown
[![Dashboard](docs/screenshots/dashboard.png)](docs/screenshots/dashboard.png)
```

Afterwards run `bash tools/check.sh` — it flags stray files under
`uploads/`, so clean up anything the seed or the capture left behind.

## Keeping them current

The demo data is generated relative to the day it is seeded, so a
screenshot ages with the instance behind it rather than with the calendar.
Re-capture when the interface changes visibly, not on a schedule.
