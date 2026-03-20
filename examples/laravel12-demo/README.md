# Laravel Root Cause Demo

This is a small Laravel 12 demo app for `noir4y/laravel-root-cause`.

## Setup

```bash
composer install
cp .env.example .env
touch database/database.sqlite
php artisan key:generate
php artisan migrate --seed
php artisan serve
```

The app uses SQLite by default. `composer install` resolves the package from the local repository path `../..`, so edits to the main package are reflected in this demo. The demo reproduces the same incident classes as the public docs, but the canonical diagnosis snapshots remain under `docs/incidents`.

## Incident Routes

Validation failure:

```bash
curl -X POST http://localhost:8000/api/demo/validation-failure \
  -H 'Accept: application/json' \
  -H 'Content-Type: application/json' \
  -d '{}'
```

Unhandled exception:

```bash
curl http://localhost:8000/api/demo/unhandled-exception
```

Duplicate query burst:

```bash
curl http://localhost:8000/api/demo/duplicate-query-burst
```

N+1 query pathology:

```bash
curl http://localhost:8000/api/demo/n-plus-one
```

## Inspecting Traces

After hitting a route, inspect the output with:

```bash
php artisan root-cause:trace latest
php artisan root-cause:failed-request
php artisan root-cause:query-pathology
php artisan root-cause:export latest --format=json
```

If you want to prune captured traces:

```bash
php artisan root-cause:prune --days=7
```

The CI smoke test hits the validation-failure route and verifies that `php artisan root-cause:failed-request` returns a diagnosis before the demo job passes.
