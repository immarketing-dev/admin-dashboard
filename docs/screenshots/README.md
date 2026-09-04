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

## How to capture them

Set up a demo instance following [docs/DEMO.md](../DEMO.md), then capture
at a desktop width of 1440px and save as PNG into this directory:

| File | Page | What it should show |
|---|---|---|
| `dashboard.png` | `/` | KPIs, deadlines, uptime monitor, lead inbox |
| `projects.png` | `/tasks` | milestones, time tracking, client feedback |
| `finances.png` | `/finances` | income and expenses, the twelve-month chart |
| `portal.png` | `/portal?token=…` | the portal as a contact sees it, past the PIN |

Two things worth doing before pressing the button:

- **Dismiss the notification toasts on the dashboard.** Four of them stack
  in the top-right corner and cover a whole widget. In demo mode their
  close button is a POST and gets rejected, so remove them from the browser
  console instead:
  `document.querySelectorAll('.toast').forEach(t => t.remove())`
- **Switch the finance page to "Dieses Jahr".** The default is the current
  month, which is a handful of bars. The year view is what makes the chart
  worth a screenshot.

Then add the embeds to the main `README.md`:

```markdown
![Dashboard](docs/screenshots/dashboard.png)
```

Afterwards run `bash tools/check.sh` — it flags stray files under
`uploads/`, so clean up anything uploaded while capturing.

## Keeping them current

The demo data is generated relative to the day it is seeded, so a
screenshot ages with the instance behind it rather than with the calendar.
Re-capture when the interface changes visibly, not on a schedule.
