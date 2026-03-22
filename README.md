# Laravel Root Cause

Deterministic root-cause diagnostics for Laravel failures.

Laravel Root Cause turns requests, validation failures, exceptions, and query pathologies into reproducible diagnoses with structured JSON output. It is built for cases where you want a deterministic answer, not a generic observability dashboard.

## Why This Exists

Telescope is broad observability. Debugbar is a local debugging UI. Ray is a workflow-friendly inspector. Laravel Root Cause is narrower: it classifies the failure, explains why it matched, and exports AI-consumable JSON with candidate fixes.

| Tool | Best for | Output |
| --- | --- | --- |
| Telescope | Wide framework observability | UI-heavy trace inspection |
| Debugbar | Local page-level debugging | Browser overlay and debug panels |
| Ray | Fast feedback in your workflow | Interactive developer console |
| Laravel Root Cause | Deterministic diagnosis of a failing request | CLI summary + structured JSON |

## Try It In 3 Minutes

```bash
composer require noir4y/laravel-root-cause
php artisan root-cause:install
# trigger one failing or pathological request first
php artisan root-cause:trace latest
php artisan root-cause:export latest --format=json
```

The package auto-registers request collection for the `web` and `api` groups by default. Collection is enabled automatically in `APP_ENV=local` and stays off elsewhere until you set `ROOT_CAUSE_ENABLED=true`. Traces are written to `storage/app/root-cause`.

## For Users

If you are adopting the package in an application, start with the install and export flow in [Try It In 3 Minutes](#try-it-in-3-minutes).

Use these docs as the canonical follow-up:

- [Quickstart](docs/quickstart.md) for install and first diagnosis flow
- [Production safety](docs/production-safety.md) for non-local environments

## What The Output Looks Like

CLI diagnosis:

```text
Trace: trc_validation_failure
Root cause: validation_contract_mismatch
Confidence: 0.76

Summary: Error 422 occurred due to a mismatch between StoreUserRequest and payload.

Evidence
- StoreUserRequest failed on email.required
- Payload keys: [name]
- Route: users.store / Controller: App\Http\Controllers\UserController@store

Suggested fix
- Include the required field "email" in the request payload or make the rule nullable.
- Review StoreUserRequest to confirm the expected payload keys still match the frontend contract.

Repro
- {"method":"POST","uri":"/users","route_name":"users.store","payload_keys":["name"]}
```

JSON export:

```json
{
  "trace_id": "trc_exception",
  "diagnosis": {
    "root_cause_category": "unhandled_exception",
    "confidence": 0.62,
    "candidate_fixes": [
      "Inspect the first application frame and add a focused regression test for RuntimeException."
    ]
  }
}
```

Screenshots:

![Terminal diagnosis](docs/assets/terminal-diagnosis.svg)

![JSON export](docs/assets/json-export.svg)

## Public Docs

- [Quickstart](docs/quickstart.md)
- [Production safety](docs/production-safety.md)
- [Validation failure incident](docs/incidents/validation-failure.md)
- [Exception incident](docs/incidents/exception.md)
- [Query pathology incident](docs/incidents/query-pathology.md)
- [v0.2.0 release notes](docs/releases/v0.2.0.md)
- Demo app: [`examples/laravel12-demo`](examples/laravel12-demo)
  It reproduces the same incident classes locally; canonical snapshots live under `docs/incidents`.

## Commands

```bash
php artisan root-cause:trace latest
php artisan root-cause:failed-request
php artisan root-cause:query-pathology
php artisan root-cause:export latest --format=json
php artisan root-cause:prune --days=7
```

## For Contributors

```bash
composer install
composer check
```

Optional Git hooks:

```bash
composer hooks:install
```

Local Git hooks live in `.githooks/`. Run `composer hooks:install` only if you want local commits to execute `composer lint`, `composer analyse`, and `composer test` before the commit is created.

## Data Model

Each stored artifact is a `TraceEnvelope` containing:

- entrypoint metadata
- sanitized request context
- normalized signals
- a deterministic `DiagnosisReport`

This JSON format is the primary machine-readable interface for downstream tooling, including AI agents.
