# Contributing

## Local Setup

```bash
composer install
composer check
```

`composer install` configures `core.hooksPath` to `.githooks`, so local commits run `composer lint`, `composer analyse`, and `composer test`.

## Supported Matrix

- PHP 8.2 and 8.3
- Laravel 11 and 12
- Orchestra Testbench 9 and 10

CI installs from the committed lockfiles in `.github/locks/`. If you change the supported Laravel/Testbench matrix or root Composer constraints, refresh the matching lockfiles in the same change.

## Working on Diagnosis Quality

When a rule changes:

- update or add a curated incident fixture under `docs/incidents/fixtures`
- update the expected snapshot under `docs/incidents/snapshots`
- update the public docs if the diagnosis examples changed
- keep the negative cases for domain 422 payloads and handled exceptions passing

## Pull Requests

- keep changes scoped to one behavior or documentation area when possible
- include tests for new rules, retention behavior, or config toggles
- mention any public docs, release notes, or demo app updates in the PR summary

## Demo App

The example Laravel 12 app lives in `examples/laravel12-demo`. Use it when you need a repeatable local repro for the public incident docs.
