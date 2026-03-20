# Production Safety

Laravel Root Cause keeps capture enabled by default only in `APP_ENV=local`. In non-local environments you must opt in with `ROOT_CAUSE_ENABLED=true`, and you should still make collection and retention explicit in production-like environments.

## Runtime Toggle

```env
ROOT_CAUSE_ENABLED=true
```

Local development enables collection automatically. Set `ROOT_CAUSE_ENABLED=true` to opt in outside local development, or `ROOT_CAUSE_ENABLED=false` to force collection off without removing the package.

## Retention

```env
ROOT_CAUSE_RETENTION_DAYS=7
```

Stored traces live on disk. Prune old traces on a schedule:

```bash
php artisan root-cause:prune --days=7
```

Use a shorter window if you only need recent incidents, or a longer window if you rely on historical trace comparison.

## Redaction

The package redacts common secrets by default:

- request keys such as `password`, `token`, `secret`, and `credit_card`
- headers such as `authorization` and `cookie`
- SQL bindings when query exceptions are sanitized
- common inline secrets in exception messages such as emails, URLs, and token-like values
- application file paths as app-relative paths instead of absolute filesystem paths

Adjust `config/root_cause.php` if your application uses additional sensitive keys.

## Config Example

```php
return [
    'enabled' => env('ROOT_CAUSE_ENABLED', env('APP_ENV', 'production') === 'local'),
    'storage' => [
        'path' => env('ROOT_CAUSE_STORAGE_PATH', storage_path('app/root-cause')),
        'retention_days' => env('ROOT_CAUSE_RETENTION_DAYS', 7),
    ],
    'redact' => [
        'request_keys' => ['password', 'token', 'secret', 'credit_card'],
        'headers' => ['authorization', 'cookie'],
        'sql_bindings' => true,
    ],
];
```

## Operational Guidance

- Keep collection disabled unless you explicitly need trace capture in that environment.
- Export only sanitized traces when sharing examples publicly.
- Review retention before enabling the package in staging or production.
