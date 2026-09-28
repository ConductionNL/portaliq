# Migration: activity-parental-consent

## Current State

Register 0.34.0; `activityOffer` and `activitySignup` at 0.1.0 without consent
fields.

## Target State

Register 0.35.0; `activityOffer` 0.2.0 with `consentRequired`,
`consentStatement`, `photosTaken`; `activitySignup` 0.2.0 with `consent`.

## Migration Class

None. The register import is version gated; the bump re-imports the two
schemas.

```
Version: n/a (register descriptor bump 0.34.0 -> 0.35.0)
File: lib/Settings/portaliq_register.json
Key operations:
- add three optional properties to activityOffer
- add one optional object property to activitySignup
```

## Migration Steps

1. The repair step imports the register at 0.35.0.
2. Existing rows keep their values; the new properties are absent, which reads
   as "no consent required" and "no photos taken".

## Data Impact

Additive. No row changes. Safe on live data.

## Rollback Procedure

Revert the PR; rows with a `consent` record keep it, unread.

## Validation

`PortaliqRegisterConfigTest` pins 0.35.0 and the two schema versions;
gate-101 validates the demo objects against the changed schemas.
