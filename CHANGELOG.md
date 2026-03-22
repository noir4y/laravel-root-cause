# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.2.1] - 2026-03-22

### Added

- Added a documented JSON compatibility contract for stable export fields in `docs/json-contract.md`.
- Split README onboarding into separate user and contributor paths.

### Changed

- Stopped auto-installing repository Git hooks during `composer install` and `composer update`; contributors can opt in with `composer hooks:install`.
- Updated contributor docs to describe Git hook installation as optional instead of automatic.

## [0.2.0] - 2026-03-20

### Breaking

- Collection now defaults to `enabled` only in `APP_ENV=local`.
- Staging, production, and other non-local environments must set `ROOT_CAUSE_ENABLED=true` to keep capturing traces after upgrading.

### Added

- Reworked public docs with quickstart, incident walkthroughs, production safety guidance, screenshots, and release notes.
- Added curated incident fixtures and expected diagnosis snapshots to lock public examples to deterministic outputs.
- Added `root_cause.enabled`, `ROOT_CAUSE_ENABLED`, `root_cause.storage.retention_days`, and `ROOT_CAUSE_RETENTION_DAYS`.
- Added `php artisan root-cause:prune` for file-based trace retention management.
- Added CI coverage for PHP 8.2/8.3 across Laravel 11/12 and Testbench 9/10.
- Added `CHANGELOG.md`, `SECURITY.md`, `CONTRIBUTING.md`, issue templates, PR template, and repository git hook script.
- Added an in-repo Laravel 12 demo app under `examples/laravel12-demo`.

### Fixed

- Aligned the installation command in the README with the canonical package name `noir4y/laravel-root-cause`.
- Prevented request and query collection from running when the package is globally disabled.
- Documented retention, redaction, and production/staging usage instead of leaving those behaviors implicit.

### Known limitations

- The package still targets HTTP request diagnostics only; jobs, mail, notifications, logs, and UI dashboards remain out of scope.
- File retention is explicit via command scheduling; automatic background pruning is not included.
- GitHub repository metadata and the published GitHub Release still require authenticated remote publication.
