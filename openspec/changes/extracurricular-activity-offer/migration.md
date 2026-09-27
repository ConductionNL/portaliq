# Migration: extracurricular-activity-offer

## Current State

The `portaliq` register (0.33.0) has 45 schemas. No activity, activity sign-up
or activity attendance schema exists.

## Target State

Register 0.34.0 with 48 schemas: `activityOffer`, `activitySignup` and
`activityAttendance` at schema version 0.1.0, listed in
`components.registers.portaliq.schemas`, plus two seed activities, two seed
sign-ups and one seed attendance row in `components.objects`.

## Migration Class

None. Portaliq owns no tables (ADR-001); OpenRegister imports the register
through the existing repair step, and `importFromApp` is version gated, so
the bump of `info.version` and `components.registers.portaliq.version` to
0.34.0 is what makes an existing install pick up the new schemas.

```
Version: n/a (register descriptor bump 0.33.0 -> 0.34.0)
File: lib/Settings/portaliq_register.json
Key operations:
- add three schemas
- add their slugs to the register's schema list
- add seed objects
```

## Migration Steps

1. On upgrade, the repair step imports the register; OpenRegister sees 0.34.0
   and creates the three schemas.
2. The seed objects are created under their slugs; a re-import matches on slug
   and does not duplicate them.

## Data Impact

No existing row changes. The new schemas start empty apart from the seed rows.
Safe on live data.

## Rollback Procedure

Revert the PR. OpenRegister keeps the three schemas and any rows created since;
they are unreferenced by the reverted code and can be removed with
OpenRegister's own schema delete when wanted.

## Validation

- `PortaliqRegisterConfigTest` pins 0.34.0 and the exact schema slug list.
- `RegisterAuthorizationTest`: every new schema has a read rule and no broad
  write grant.
- gate-101: three valid demo objects per new schema in the mock register.
- After upgrade: `GET /apps/openregister/api/objects/portaliq/activityOffer`
  as admin returns the two seed activities.
