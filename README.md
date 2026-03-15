# Laravel Root Cause

Deterministic root-cause diagnostics for Laravel applications.

Laravel Root Cause collects structured runtime signals from Laravel requests and turns them into reproducible, machine-readable diagnostics. It is designed to help developers inspect failures, understand likely causes, and export trace data for downstream tooling.

It is not an APM platform, a Telescope-style UI, or a full observability suite.

## Scope

Current scope in v0.1 includes:

- request trace collection
- validation failure normalization
- exception normalization
- query aggregation with simple N+1 and duplicate burst detection
- file-based trace storage
- Artisan commands for inspecting traces and diagnostics
- structured JSON export for downstream tooling, including AI agents

The package intentionally does not include a Telescope-style UI, Pulse-style dashboards, or a full MCP server yet.

## Installation

```bash
composer require noir/laravel-root-cause
php artisan root-cause:install
```

By default, the package auto-registers request middleware for the `web` and `api` groups. Traces are written to `storage/app/root-cause`.

## Commands

```bash
php artisan root-cause:trace latest
php artisan root-cause:failed-request
php artisan root-cause:query-pathology
php artisan root-cause:export latest --format=json
```

These commands let you inspect the most recent trace, review failed requests, detect query pathologies, and export trace data as JSON.

## Development

```bash
composer install
composer check
```

Local Git hooks live in `.githooks/`. `composer install` configures `core.hooksPath` automatically so `git commit` runs `composer lint`, `composer analyse`, and `composer test` before creating a commit.
Local Git hooks live in `.githooks/`. Running `composer install` configures `core.hooksPath` automatically, so `git commit` runs `composer lint`, `composer analyse`, and `composer test` before creating a commit.

## Data model

Each stored artifact is a `TraceEnvelope` containing:

- entrypoint metadata
- sanitized request context
- normalized signals
- a deterministic `DiagnosisReport`

This JSON format is intended to be the primary machine-readable interface for downstream tooling, including AI agents.

## Roadmap

The repository already includes a rules catalog and prompt templates under `resources/`, so an MCP layer can be added later without changing the underlying trace format.
