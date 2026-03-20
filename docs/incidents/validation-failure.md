# Validation Failure

## Raw Symptom

`POST /users` returns `422` because the payload has `name` but the validation contract requires `email`.

![Terminal diagnosis output](../assets/terminal-diagnosis.svg)

## `root_cause` Output

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

## Why This Was Classified

- The trace contains a `validation_failed` signal with one failed field and a non-empty payload key set.
- `RuleEngine` maps that signal to `validation_contract_mismatch` before any exception or query-pathology rule runs.
- The confidence score is deterministic for this shape of trace: one failed field, a form-request hint, and payload keys present.

## Suggested Fixes

- Send the missing `email` key or relax the rule to `nullable` where the business rule allows it.
- Re-check the form request contract before changing the controller.
- Keep this incident fixture as a regression test for validation failures that should not be treated as domain 422s.

## Reference

- Fixture: `docs/incidents/fixtures/validation-failure.trace.json`
- Snapshot: `docs/incidents/snapshots/validation-failure.diagnosis.json`
