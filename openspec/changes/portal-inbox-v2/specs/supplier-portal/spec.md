---
status: proposed
---

# Spec: supplier-portal (unified inbox v2)

## Purpose

Aggregates every `kind: inbox` collection across a subject's contributions into
one merged, sorted, provenance-tagged inbox; adds a tamper-proof mark-read; puts
an unread count on the contributions payload; and makes the SPA ready to render
the WMEBV 2:10 aard/rechtsgevolg/termijn metadata when supplied. Every read and
write reuses the existing per-row tenant + trust boundary. Related:
`portal-scoped-crud` (scoped read/update), WMEBV art 2:10 (2027 readiness),
ADR-005 (fail-closed).

## ADDED Requirements

### Requirement: Unified inbox aggregates every inbox collection subject-scoped

The reader MUST expose `GET /portal/api/inbox` that aggregates every collection
declared `kind: inbox` across ALL of the subject's contributions. Each row MUST
pass the IDENTICAL per-row subject + tenant + trust verification as a normal
collection read (an inbox collection's `minTrust` is re-checked; a row failing
ownership/tenant is dropped). Rows MUST be merged across apps, sorted by
`receivedAt` descending, and each tagged with its source `appId` and label. The
endpoint MUST fail closed (missing OpenRegister, OR error, malformed row) to an
empty inbox, never leaking another subject's or tenant's messages.

#### Scenario: Messages from multiple apps merge into one sorted inbox

- **GIVEN** a subject with `kind: inbox` collections in two contributions, each holding messages they own
- **WHEN** they call `GET /portal/api/inbox`
- **THEN** all their messages are returned merged, sorted by `receivedAt` descending, each tagged with its source `appId` and label — and no message they do not own is included
- @e2e exclude add Playwright e2e in apply phase — spec-only PR; asserted at unit level (aggregation, sort, provenance, scope)

#### Scenario: The unified inbox honours the per-row trust and tenant boundary

- **GIVEN** an inbox collection with a `minTrust` the subject's session does not meet, and a message row in a different tenant
- **WHEN** the subject calls `GET /portal/api/inbox`
- **THEN** the under-trust collection contributes nothing and the foreign-tenant row is dropped — the same boundary as a normal collection read
- @e2e exclude boundary invariant — covered by PHPUnit scope/trust matrix; no distinct UI flow

### Requirement: Tamper-proof mark-read

The writer MUST expose `PATCH /portal/api/inbox/{register}/{schema}/{id}/read`
that sets `read: true` on a message. It MUST re-verify ownership + tenant + trust
against OpenRegister BEFORE any write (the same boundary as the scoped update),
MUST change ONLY the `read` field (no other field is writable through this
endpoint), and MUST return an identical "not found" for a foreign-owned,
wrong-tenant, or non-existent id — no existence oracle. It MUST fail closed on OR
error.

#### Scenario: A subject marks their own message read

- **GIVEN** a subject and an unread message they own
- **WHEN** they PATCH that message's read endpoint
- **THEN** only `read` becomes `true`, all other fields are unchanged, and 200 is returned
- @e2e exclude mark-read contract — covered by PHPUnit writer/controller matrix

#### Scenario: A mark-read on a foreign message is an identical 404 with no write

- **GIVEN** a subject and a message id owned by a DIFFERENT subject or tenant, or non-existent
- **WHEN** they PATCH its read endpoint
- **THEN** ownership is re-verified first, the OpenRegister save is never called, and the response is an identical 404 in every case
- @e2e exclude write-IDOR / no-oracle invariant — pinned by PHPUnit asserting save never called; no UI surface

#### Scenario: Mark-read cannot write any field but read

- **GIVEN** a subject PATCHing their own message with a body that (against contract) includes other fields
- **WHEN** the update is applied
- **THEN** only `read` is set to `true`; any other field in the body is ignored
- @e2e exclude field-whitelist invariant — covered by PHPUnit; no UI surface

### Requirement: Unread count on the contributions payload

The `GET /portal/api/contributions` response MUST include the subject's total
unread inbox count (across all their inbox collections), computed with the same
per-row boundary, so the shell can render a badge without a second request. The
count MUST reflect only messages the subject owns.

#### Scenario: The contributions response carries the unread count

- **GIVEN** a subject with some unread and some read messages across their inbox collections
- **WHEN** they call `GET /portal/api/contributions`
- **THEN** the response includes an unread count equal to their own unread messages only
- @e2e exclude count contract — covered by PHPUnit; badge assertion deferred to the inbox e2e

### Requirement: Optional 2:10 message metadata rendered when present

The SPA MUST render the optional message metadata fields `aard`, `rechtsgevolg`,
and/or `termijn` when a message carries them (WMEBV art 2:10 readiness for 2027).
When absent, nothing is rendered and no app is required to supply them. These
fields MUST be projected through the same field boundary as any other message
field.

#### Scenario: 2:10 metadata renders only when supplied

- **GIVEN** one message carrying `aard`/`rechtsgevolg`/`termijn` and one message without them
- **WHEN** the subject opens the inbox
- **THEN** the first message shows the nature / legal-effect / deadline; the second shows the inbox row with no such fields and no empty placeholders
- @e2e exclude conditional-render readiness — SPA rendering asserted in the inbox e2e added in apply phase; spec-only PR

## Non-Functional Requirements

- **Security (ADR-005):** every read and the mark-read reuse the list read's
  exact per-row subject + tenant + trust boundary; mark-read re-verifies before
  any write and writes only `read`; no existence oracle.
- **Performance:** aggregation reuses the existing scoped reader per inbox
  collection; the unread count is computed within the same pass, no extra scan.
- **Accessibility / i18n:** inbox page and unread badges follow NLDS and the
  existing i18n keys; metadata labels are NL/EN.

## Acceptance Criteria

- `GET /portal/api/inbox` merges all inbox collections, sorts by `receivedAt`
  desc, tags each row with `appId`/label, and honours the per-row trust/tenant
  boundary
- `PATCH .../inbox/{register}/{schema}/{id}/read` sets only `read`, re-verifies
  ownership before any write, and 404s identically on a foreign/absent id
- `GET /portal/api/contributions` includes the subject's own unread count
- The SPA renders `aard`/`rechtsgevolg`/`termijn` when present, nothing when absent
- README documents the inbox endpoints and the 2:10-readiness metadata

## Notes

- Composing / replying to messages is out of scope (a later slice).
- The 2:10 fields are readiness only — population is each contributing app's
  choice; nothing is made mandatory before 2027.
