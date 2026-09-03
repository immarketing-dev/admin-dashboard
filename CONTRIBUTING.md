# Contributing

## Before you open a pull request

Run the checks:

```bash
bash tools/check.sh
php tools/check_schema.php
php tools/test_env.php
```

All three must exit 0. CI runs all three as separate steps on PHP 8.1, 8.2
and 8.3 (`.github/workflows/ci.yml`); run them locally before opening a
pull request so you catch failures before CI does.

## Conventions

- **PHP 8.1** is the floor. Do not use syntax from later versions.
- **Interface text and code comments are German.** Documentation, commit
  messages and pull request descriptions are English.
- **Every state-changing form needs `csrf_field()`** and its handler needs
  `csrf_check()`.
- **Every query is a prepared statement.** No string interpolation of user
  input, not even after casting.
- **Colours, spacing, radii and shadows come from `assets/css/tokens.css`.**
  A raw hex value in `app.css` will be rejected by `tools/check_css.php` —
  if a token is missing, add one to `tokens.css` rather than inlining a
  literal.
- **Dark mode is a token redefinition**, done once in `tokens.css`'s
  `[data-theme="dark"]` block. Do not add a `[data-theme]` rule for your
  own component in `app.css`. The one exception is the clearly
  banner-delimited section at the very end of `app.css`, reserved for
  third-party components (Bootstrap defaults and similar) that have no
  light-mode base rule of their own in this project to redefine against —
  even there, a rule must resolve through tokens, never a raw colour.
  `tools/check_css.php` enforces both the placement and the no-raw-colour
  rule.
- **Schema changes go in two places:**
  1. `install/schema.sql`, for fresh installs — and if the change affects
     the current schema shape, raise the seeded
     `INSERT INTO settings (k, v) VALUES ('schema_version', '<N>')` value
     at the bottom of that file to match the new `SCHEMA_VERSION`.
  2. A new entry appended to `migrations()` in `includes/migrations.php`,
     for existing installations, with `SCHEMA_VERSION` in that same file
     raised to match.

  Both numbers — the migration array key and the seeded `schema_version`
  row — must end up equal, or a fresh install and a migrated install
  diverge on what "up to date" means.
- **Never commit a `.env`, an upload, or a real customer record.**

## Commit messages

Conventional Commits: `feat:`, `fix:`, `refactor:`, `docs:`, `chore:`,
`build:`. Explain why in the body, not just what.

## Deploying to a live server

Work only in the repository. Never edit the folder you upload from — changes
there are overwritten on the next build and never reach git.

```bash
bash tools/check.sh              # before every commit
git commit && git push
php tools/deploy.php ../deploy-folder --dry-run   # see what changed
php tools/deploy.php ../deploy-folder             # build it
```

`deploy.php` refuses to build while any check fails, excludes `.git`,
`.github`, `docs` and `tools` from the output, preserves an existing `.env`
and everything under `uploads/`, and prints which files changed since the
last build so only those need uploading.

**An upload never deletes.** When a file is removed from the project, the
script lists it under "delete on the server" — that has to be done by hand,
or the old file stays reachable. This matters: the project once shipped two
login handlers with credentials in plain text, and merely uploading the
replacement would have left them in place.
