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

Outside the stable field set above, response metadata may include both transport and diagnostic status values. `response.status_code` and `response.transport_status_code` reflect the final HTTP response status observed from Laravel's response object, while `response.diagnostic_status_code` preserves the failure code used for diagnosis and trace filtering when the transport response is intentionally different, such as validation redirects or custom exception renderers. For streamed responses that fail after sending has started, the transport status may be a best-effort approximation from the response object or thrown exception because the on-wire status may already be committed.
