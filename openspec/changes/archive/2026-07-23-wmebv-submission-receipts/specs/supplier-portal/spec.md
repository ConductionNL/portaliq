---
status: proposed
---

# Spec: supplier-portal (WMEBV submission receipts)

## Purpose

Adds the WMEBV (in force 2026-01-01) post-submit compliance layer to the portal
create-action pipeline: an automatic ontvangstbevestiging with a copy of the
submitted data, an append-only proof-of-receipt log, a guarantee that a
side-effect failure never loses the submission, and a form data-minimisation
guard in the manifest normaliser. Additive; no existing endpoint contract
changes shape. Related: ADR-005 (fail-closed), ADR-022 (writes via OpenRegister),
`portal-contribution-endpoint-actions`.

## ADDED Requirements

### Requirement: Automatic ontvangstbevestiging on a successful create-action

The server MUST generate a receipt message on every SUCCESSFUL portal
create-action (`ContributionController::create()` and the create branch of
`action()`): a
`portalMessage` in the SUBMITTING subject's inbox, scoped by `subjectRef` and
`organisation` exactly like any other subject-owned object, carrying a reference
id, an ISO-8601 timestamp, human-readable NL and EN body text at B1 language
level, and a copy of the WHITELISTED submitted field data (the same array the
writer persisted — never the raw client body). The receipt MUST be generated for
the subject who submitted, MUST NOT be generated on a failed create, and MUST NOT
be visible to any other subject or tenant.

#### Scenario: A successful submission produces a receipt in the subject's inbox

- **GIVEN** a subject with a `type: create` action they are entitled to
- **WHEN** they submit valid whitelisted field data and the domain object is created
- **THEN** a `portalMessage` receipt appears in that subject's inbox with a reference id, an ISO-8601 timestamp, NL + EN B1 body text, and a copy of the whitelisted submitted data
- @e2e exclude add Playwright e2e in apply phase — spec-only PR; asserted at unit level (receipt written, whitelisted copy, subject-scoped)

#### Scenario: The receipt copy carries only whitelisted fields

- **GIVEN** a create-action whose `fields` whitelist excludes a client-supplied extra key
- **WHEN** the receipt is generated
- **THEN** the data copy in the `portalMessage` contains only the whitelisted, persisted fields — the non-whitelisted key never appears in the receipt
- @e2e exclude data-copy invariant — covered by PHPUnit; no distinct UI surface

#### Scenario: A failed create produces no receipt

- **GIVEN** a create-action whose domain write is rejected (validation / OR error)
- **WHEN** the create fails
- **THEN** no receipt message and no proof log claiming delivery are written for that attempt
- @e2e exclude negative path — covered by PHPUnit; no UI surface

### Requirement: Proof-of-receipt log satisfying the WMEBV burden of proof

The server MUST write a `portalSubmission` log record for each successful
create-action: `subjectRef`, `organisation`, `appId`, `actionId`, `payloadCopy`
(the whitelisted submitted data), `receiptMessageRef` (linking to the generated
`portalMessage`), `submittedAt` (ISO-8601), and `deliveryStatus`. The record is
the append-only evidence the government relies on to prove receipt, and backs the
subject's right to a copy of their own submission log entries. It MUST be
subject-scoped and tenant-scoped, and MUST NOT be readable by another subject.

#### Scenario: Each submission is logged with a linked receipt

- **GIVEN** a successful create-action that generated a receipt
- **WHEN** the proof log is written
- **THEN** a `portalSubmission` record exists with the subject, organisation, appId, actionId, a whitelisted `payloadCopy`, `submittedAt`, and a `receiptMessageRef` pointing at the receipt `portalMessage`
- @e2e exclude log-record contract — covered by PHPUnit; no UI surface

#### Scenario: The submission log is subject-scoped

- **GIVEN** two subjects in two tenants who each submitted
- **WHEN** either reads submission log entries
- **THEN** each sees only their own `portalSubmission` records — never the other subject's or another tenant's
- @e2e exclude tenant-isolation invariant — covered by PHPUnit scope matrix; no UI surface

### Requirement: A receipt or log failure never loses the submission

The domain object create MUST remain the authoritative write. If receipt-message
generation or proof-log writing fails, the create MUST still return success, the
failure MUST be logged, and the proof log (or a minimal fallback record) MUST
capture `deliveryStatus: failed` so the receipt is retriable. A submission MUST
NEVER be dropped or reported as failed to the subject because a WMEBV side-effect
failed.

#### Scenario: Receipt write failure does not fail the submission

- **GIVEN** a create whose domain object write succeeds but whose receipt/log write then throws
- **WHEN** the request completes
- **THEN** the create returns 200, the failure is logged, and the submission is recorded with `deliveryStatus: failed` (retriable) — the subject's submission is never lost
- @e2e exclude failure-isolation invariant — covered by PHPUnit (writer throws, create still 200); no UI surface

### Requirement: Form data-minimisation — no non-mandatory field may be required

`PortalManifestNormaliser` MUST NOT allow a `fieldConfigs` entry to set
`required: true` on a field that is not genuinely mandatory per the action's
schema `required` set. Such a `required` flag MUST be dropped fail-closed (the
field stays optional). If the schema cannot be resolved, `required` MUST be
dropped rather than elevated on a guess. This enforces the WMEBV rule that an
electronic form may not require a non-mandatory field.

#### Scenario: A manifest cannot elevate an optional field to required

- **GIVEN** a `fieldConfigs` entry `{someOptionalField: {required: true}}` where `someOptionalField` is NOT in the action schema's `required` set
- **WHEN** the manifest is normalised
- **THEN** the `required` flag is dropped (the field stays optional) and the rest of the field config survives
- @e2e exclude normalisation guard — covered by PHPUnit normaliser matrix; no UI surface

#### Scenario: A genuinely mandatory field keeps its required flag

- **GIVEN** a `fieldConfigs` entry marking `required: true` on a field that IS in the schema's `required` set
- **WHEN** the manifest is normalised
- **THEN** the `required` flag is preserved
- @e2e exclude positive-path normalisation — covered by PHPUnit; no UI surface

## Non-Functional Requirements

- **Security (ADR-005):** the receipt and log are subject- and tenant-scoped with
  the same per-row boundary as reads; the data copy is the whitelisted map, never
  raw client input; the data-minimisation guard fails closed.
- **Reliability:** the primary domain write is authoritative; compliance
  side-effects never abort or reverse it.
- **Accessibility / i18n:** receipt body text is provided in NL and EN at B1
  language level; new user-facing strings go through the existing i18n keys.

## Acceptance Criteria

- A successful create writes a subject-scoped receipt `portalMessage` (ref id,
  ISO timestamp, NL/EN B1 text, whitelisted data copy) and a `portalSubmission`
  proof record linking to it
- A failed create writes neither a receipt nor a "delivered" proof record
- A side-effect failure leaves the create at 200 and the submission logged
  `deliveryStatus: failed` (retriable)
- The normaliser drops a `required: true` flag on a non-mandatory field and keeps
  it on a mandatory one
- `portalSubmission` is added to `portaliq_register.json` and documented in README

## Notes

- Article citations are indicative pending reconciliation with BWBR0048252 (see
  design.md).
- Out-of-band email of the receipt is `portal-notifications-dispatch`; the
  2:10 aard/rechtsgevolg/termijn content is `portal-inbox-v2` (2027 readiness).
