## MODIFIED Requirements

### Requirement: Append-only portal audit trail on every mutation, download, and session event

The server MUST record every portal mutation (`create`, `update`, `forward`),
every `download`, every confirmed task completion (`complete`) and every session
event (`login`, `logout`, `refresh`) as one row in OpenRegister's hash-chained
audit trail, with action `portaliq.<verb>`, the subject as the actor, the
session `jti`, the organisation, the target `appId`/`register`/`schema`/`id`
and the time. The row MUST be a fact record and MUST NOT carry payload content.
No portal-owned schema MAY hold these records: the `portalAuditEntry` schema is
retired and an upgrade MUST move every existing `portalAuditEntry` object into
the audit trail with its uuid and time before removing it. Audit writing MUST
NOT fail the audited action: a `record()` failure is caught, logged, and never
propagated. The audit count MUST be exposed count-only via `MetricsController`,
never the subjects.

#### Scenario: A mutation and a session event are both audited

- **GIVEN** a subject who logs in, creates an object, and logs out
- **WHEN** each action completes
- **THEN** OpenRegister's audit trail holds a `portaliq.login`, a `portaliq.create` (with the target register/schema/id) and a `portaliq.logout` row, each with the session `jti`, subject, organisation, and time, and none carries the object's payload
- @e2e exclude audit-record contract, covered by PHPUnit (`AuditTrailServiceTest`) across the write/session paths; no UI surface

#### Scenario: Existing proof records move into the audit trail

- **GIVEN** an instance holding `portalAuditEntry` objects from an earlier version
- **WHEN** the app is upgraded
- **THEN** each becomes an audit-trail row with its own uuid and time, the object is removed only after its row exists, a second run writes no row twice, and `portaliq_audit_entries_total` reports the same count per verb as before the upgrade
- @e2e exclude upgrade repair step, covered by PHPUnit (`MovePortalAuditEntriesTest`); no UI surface

#### Scenario: An audit write failure never reverses the action

- **GIVEN** an action whose audit `record()` throws
- **WHEN** the action completes
- **THEN** the action still returns its normal success, the audit failure is logged, and the action is not reversed
- @e2e exclude failure-isolation invariant — covered by PHPUnit; no UI surface

#### Scenario: The audit count is exposed count-only

- **GIVEN** some audit entries across verbs
- **WHEN** `MetricsController` output is read
- **THEN** it reports audit-entry counts (e.g. by verb) with no subject identity, target id, or payload exposed
- @e2e exclude metrics count-only — covered by PHPUnit; no UI surface
