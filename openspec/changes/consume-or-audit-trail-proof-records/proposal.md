---
kind: code
---

# Proposal: consume-or-audit-trail-proof-records

## Why

The portal keeps its proof records (who signed in, who sent what, who
downloaded a document, who completed a task) as `portalAuditEntry` objects in
its own register. OpenRegister already keeps a hash-chained audit trail for
every app, with retention and an admin-only reading surface. Two trails for the
same kind of fact is the pattern ADR-022 retires, and hydra gate 23
(`consume-or-audit-trail-fleet-wide`) turns blocking on 3 October 2026 for
`lib/Service/AuditTrailService.php`.

It also closes an exposure: the `portalAuditEntry` schema grants read to
`authenticated`, so any Nextcloud account can list every resident's sign-ins
and downloads through OpenRegister's objects API. OpenRegister's audit trail is
read by administrators only.

Ruben decided on 29 September 2026 (build-all DECISIONS row 5): move the portal
proof records onto OpenRegister's audit trail, with a migration of the existing
records.

## What changes

- `AuditTrailService::record()` writes one row into OpenRegister's audit trail
  (`AuditTrailMapper::insertAuditTrails()`, hash-chained) with action
  `portaliq.<verb>`. The callers do not change.
- `AuditTrailService::countsByVerb()` counts those rows, so
  `portaliq_audit_entries_total{verb}` keeps its meaning.
- A repair step, `MovePortalAuditEntries`, moves every existing
  `portalAuditEntry` object into the trail with its own uuid and time, and
  deletes the old object after its row is written. A second run writes nothing
  twice.
- The `portalAuditEntry` schema leaves the register configuration (register
  0.48.0), with its demo rows and its seed entry. Nothing in `lib/` can write
  one any more.

## Out of scope

- `portalSubmission` (the WMEBV proof-of-receipt log) stays a portal record: it
  is the resident's own copy of what they sent, shown back to them, not an
  audit fact.
- A counting method on OpenRegister's mapper. The counts read the rows today;
  the ask is drafted for Ruben (`for-ruben/openregister-audit-count-by-action.md`).
