# Exception

## Raw Symptom

`GET /explode` throws a `RuntimeException` and returns `500`.

![JSON export output](../assets/json-export.svg)

## `root_cause` Output

```text
Trace: trc_exception
Root cause: unhandled_exception
Confidence: 0.62

Summary: An Unhandled RuntimeException returned a 500 error.

Evidence
- RuntimeException: Boom from route
- /app/Http/Controllers/BoomController.php:18
- Query fingerprint seen 3 times (6.4ms total)

Likely files
- /app/Http/Controllers/BoomController.php

Suggested fix
- Inspect the first application frame and add a focused regression test for RuntimeException.
- Reproduce the failure with the exported payload keys and route metadata before changing behavior.
- Start from /app/Http/Controllers/BoomController.php:18 to confirm the thrown branch is intended.

Repro
- {"method":"GET","uri":"/explode","exception_class":"RuntimeException"}
```

## Why This Was Classified

- The trace contains an `exception_thrown` signal, so the exception rule runs after validation and route-binding rules.
- The application frame is present, so `RuleEngine` classifies it as `unhandled_exception` instead of a handled or framework-mapped 404 path.
- Query summary evidence is preserved, but it only raises confidence; it does not change the category.

## Suggested Fixes

- Add a focused regression test around the first application frame.
- Verify the route or controller branch is intentionally throwing.
- Use the exported JSON when reproducing the failure locally.

## Reference

- Fixture: `docs/incidents/fixtures/exception.trace.json`
- Snapshot: `docs/incidents/snapshots/exception.diagnosis.json`
