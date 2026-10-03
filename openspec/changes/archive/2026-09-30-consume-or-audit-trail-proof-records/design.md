# Design: consume-or-audit-trail-proof-records

## The row

| audit trail column | from the portal fact |
|---|---|
| `uuid` | a new v4 uuid; the old object's uuid when moved |
| `action` | `portaliq.<verb>` (so OpenRegister's own `create`/`update` buckets never count it) |
| `user`, `user_name` | `subjectRef` (the portal's pseudonymous subject, never a BSN) |
| `session` | the session token id (`jti`), when known |
| `organisation_id` | the subject's organisation |
| `object_uuid` | the target id, only when it is a uuid, so a download shows in that object's history |
| `changed` | `{appId, register, schema, targetId}`: the target, never payload |
| `created` | now; the old record's `timestamp` when moved |

Written through `insertAuditTrails([$row])`, which accepts a pre-built row,
keeps every field as given and seals it into the hash chain. The single-row
`createAuditTrailEntry()` needs an `ObjectEntity`, and a sign-in has none.

OpenRegister is optional for portaliq, so the mapper is resolved from the
container by name and the entity class is named, not imported (as
`Cms/PageHistory` does). Without OpenRegister `record()` logs and returns, as
before.

## The move

`MovePortalAuditEntries` is a post-migration repair step. It reads the old
schema through `PortalRegisterContext` (the schema row stays in the database
after it leaves the configuration, because the import never deletes one), 100
at a time. Per record: write the row unless a row with that uuid exists, then
delete the object. A record whose delete fails is kept and counted, and the
step pages past it; a bound of 10,000 pages keeps an upgrade from running on.

## Counting

The mapper has no count by action. `countsByVerb()` reads the rows per verb
with `findAll(filters: {action})` and counts them. That loads every row of a
verb per scrape, which is fine at today's volumes and not at a large
municipality's. The drafted OpenRegister ask adds `countByAction()`.
