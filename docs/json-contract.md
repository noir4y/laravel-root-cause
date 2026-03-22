# JSON Contract

The JSON export is the primary machine-readable interface for downstream tooling and AI agents.

## Stable Fields

Laravel Root Cause treats these fields as the compatibility surface for `php artisan root-cause:export ... --format=json`:

- `trace_id`
- `diagnosis.root_cause_category`
- `diagnosis.confidence`
- `diagnosis.candidate_fixes`

These fields are expected to remain available with the same meaning across patch releases.

## Compatibility Policy

- New fields may be added in a backwards-compatible release.
- Existing fields may gain additional sibling fields without changing the meaning of the stable fields above.
- Removing or renaming `trace_id`, `diagnosis.root_cause_category`, `diagnosis.confidence`, or `diagnosis.candidate_fixes` requires at least a minor release.

## Scope

This document defines the contract in prose only. The package does not currently publish a versioned JSON Schema, and consumers should ignore unknown fields so future additions do not break integrations.
