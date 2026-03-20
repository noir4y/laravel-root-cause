# Security Policy

## Supported Versions

Security fixes are currently applied to the latest `0.x` release line.

## Reporting a Vulnerability

Please use [GitHub Security Advisories](https://github.com/noir4y/laravel-root-cause/security/advisories/new) for non-public reports.

If you cannot use GitHub Security Advisories, open a private channel first and avoid posting a public issue with exploit details or raw trace payloads.

## Data Handling Notes

This package stores request diagnostics. Before using it outside local development, review:

- `ROOT_CAUSE_ENABLED` so collection is an explicit choice per environment
- `ROOT_CAUSE_RETENTION_DAYS` so traces do not persist longer than intended
- `config/root_cause.php` redaction keys for request fields, headers, SQL bindings, and exception-message hygiene

The default config enables capture only in `APP_ENV=local`. Non-local environments must opt in explicitly with `ROOT_CAUSE_ENABLED=true`.

Do not attach secrets, tokens, or raw production payloads to public issues. Use sanitized exports when reporting bugs.
