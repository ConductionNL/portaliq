# Migration: contract-v2

## Current State

`lib/Settings/portaliq_register.json`: register `info.version` `0.1.1`;
schema `portalAccount` version `0.1.0` with properties `audience`,
`identityType`, `identityRef`, `subjectRef`, `organisation`, `displayName`,
`email`, `status`, `lastLoginAt`. No claim map exists; the only scoping value
available to the reader is the session's `subjectRef`.

## Target State

Same file: `portalAccount` gains one optional, server-managed object property
`claims` shaped `{appId: {claimName: uuid}}` (example:
`{"pipelinq": {"linkedContactId": "00000000-0000-0000-0000-000000000000"}}`);
`portalAccount.version` → `0.2.0`; `info.version` → `0.2.0`. All other
schemas unchanged. Dev seed `portalAccount` objects (placeholder values only,
see design.md "Seed Data") demonstrate the claim shape.

## Migration Class

```
Version: none
File: n/a
Key operations:
- No Nextcloud migration class. Portaliq is a thin client (ADR-001) with no
  own tables; register/schema changes deploy through the existing
  ConfigurationService::importFromApp() repair path, which re-imports the
  register JSON when the bumped version is seen on app upgrade.
```

## Migration Steps

1. Edit `lib/Settings/portaliq_register.json`: add the `claims` property to
   `portalAccount.properties`; bump `portalAccount.version` to `0.2.0` and
   `info.version` to `0.2.0` (verifiable: JSON diff shows exactly these
   edits).
2. App upgrade triggers the existing repair-step import; OpenRegister updates
   the `portalAccount` schema definition in place (verifiable: schema version
   in OR shows `0.2.0`).
3. No object rewrite runs — the property is optional and absent on existing
   objects until portaliq server code writes it.

## Data Impact

Zero records transformed. Existing `portalAccount` objects remain valid (the
new property is optional, not required). Runs safely on live data — the
import only updates the schema definition. Union-merge caution applies as
always to register re-imports: verify after import that `portalAccount.required`
still lists exactly `audience`, `subjectRef`, `organisation`.

## Rollback Procedure

Revert the JSON edit (drop `claims`, restore versions) and re-import via the
repair path / app upgrade. Objects that already gained a `claims` value keep
it as an ignored extra property (no validation failure, no data loss); prune
manually only if desired.

## Validation

- OR admin UI / API shows `portalAccount` schema version `0.2.0` with the
  `claims` object property.
- `required` on `portalAccount` unchanged (`audience`, `subjectRef`,
  `organisation`).
- A dev-seeded account (subjectRef `dev-supplier`) carries the placeholder
  claim map and the claim-resolution unit suite resolves it; a claimless account
  yields the fail-closed empty collection.
