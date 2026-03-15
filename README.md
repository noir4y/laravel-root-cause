# Laravel Root Cause

Deterministic root-cause diagnostics for Laravel applications.

## Scope

This repository implements the v0.1 MVP described in the product spec:

- request trace collection
- validation failure normalization
- exception normalization
- query aggregation with simple N+1 / duplicate burst detection
- file-based trace storage
- human-facing Artisan commands
- JSON export designed for downstream AI agents

It intentionally does not ship a Telescope-style UI, Pulse-style dashboards, or a full MCP server yet.

## Installation

```bash
composer require noir/laravel-root-cause
php artisan root-cause:install
```

The package auto-registers a request middleware on the `web` and `api` groups by default. Traces are written to `storage/app/root-cause`.

## Commands

```bash
php artisan root-cause:trace latest
php artisan root-cause:failed-request
php artisan root-cause:query-pathology
php artisan root-cause:export latest --format=json
```

## Development

```bash
composer install
composer check
```

Local Git hooks live in `.githooks/`. `composer install` configures `core.hooksPath` automatically so `git commit` runs `composer lint`, `composer analyse`, and `composer test` before creating a commit.

## Data model

Each stored artifact is a `TraceEnvelope` with:

- entrypoint metadata
- sanitized request context
- normalized signals
- a deterministic `DiagnosisReport`

That JSON shape is the contract intended for AI agents.

## Next steps

The repository already includes a rules catalog and prompt templates under `resources/` so an MCP layer can be added without changing the underlying trace format.
