# Security Policy

## Supported versions

The `main` branch is the only supported version.

## Reporting a vulnerability

Please do not open a public issue for security problems.

Use GitHub's private vulnerability reporting (Security → Report a
vulnerability) instead. Expect an acknowledgement within seven days.

Helpful details: affected file and version, reproduction steps, and what an
attacker could reach.

## Scope

This is a self-hosted application. Its security depends on the deployment
as much as on the code. Out of scope:

- Installations serving `.env` or `config.php` because the web server
  ignores `.htaccess` (for example, nginx without an equivalent rule — see
  the Requirements section of the README).
- Installations running with `SSO_ENABLED=true` where the token-issuing
  side is not trusted, or where tokens are created without validating who
  they're for.
- A login lockout that locks out everyone, because the deployment sits
  behind a reverse proxy or CDN that doesn't forward the real client
  address — see the README's "Locked out" section.
- Missing HTTPS.

In scope: everything reachable by an unauthenticated request, anything
that lets a portal client see another client's data, and any injection,
XSS or CSRF in the application code.
