# Security policy

php-ai-guard sits between your application, your users and paid AI APIs, so
we treat these as security issues:

- a way to make a call that bypasses or exceeds a configured budget or rate limit;
- API keys, prompts or redacted personal data leaking into logs, caches,
  exceptions, URLs or the dashboard;
- personal data reaching a provider marked `allows_personal_data = false`
  when redaction should have applied;
- injection (SQL, HTML, CSV formula) through tenant/user ids, feature tags or
  model names;
- anything that lets one tenant read or affect another tenant's usage data.

## Supported versions

| Version | Supported |
|---|---|
| 0.x (latest minor) | yes |

## Reporting a vulnerability

Please **do not open a public issue.** Use GitHub's private vulnerability
reporting ("Security" tab → "Report a vulnerability") or email
security@example.com (replace with the maintainer's address).

Include the version, a description, reproduction steps or a proof of concept,
and the impact you expect. We aim to acknowledge reports within 3 working
days and to publish a fix or mitigation within 30 days, crediting you unless
you prefer otherwise.

## Hardening checklist for users

- Keep config, SQLite databases, caches and `vendor/` outside the web root.
- Load API keys from the environment or a private file; never commit them.
- Protect the dashboard with your own authentication.
- Set `max_tokens` and budgets for every feature exposed to end users.
- Mark free tiers that may retain prompts with `allows_personal_data => false`.
