# Proposal: wmebv-submission-receipts (ontvangstbevestiging + burden-of-proof log)

## Why

The **Wet modernisering elektronisch bestuurlijk verkeer (WMEBV)** is in force
from **2026-01-01** (BWBR0048252; article numbers below are indicative and MUST
be verified against the consolidated text before implementation). It turns three
things Portaliq does today only implicitly into hard, testable duties the moment
a citizen or business submits a form through the portal:

- **Ontvangstbevestiging (~Awb 2:17)** — a bestuursorgaan MUST automatically
  confirm receipt of an electronically submitted message.
- **Copy of the submitted data** — the sender is entitled to a copy of what they
  submitted, in a form they can keep.
- **Burden of proof of delivery / inbox log (~Awb 2:25)** — the government bears
  the burden of proving a message was received, and the citizen is entitled to a
  copy of the log entries for their own submissions.
- **Data-minimisation on forms (~Awb 2:15, forms-may-not-require-non-mandatory
  fields)** — an electronic form MAY NOT require a field that is not legally
  mandatory for the request.

The demand is evidenced and specific: `ontvangstbevestiging` appears in **169
tender requirements across 72 distinct tenders**, `wmebv` explicitly in **35 /
16**, and `formulier` (the create-action surface this rides on) in **1940 /
293** — the highest single count in the corpus. GEMMA's *Mijngemeentecomponent*
lists ontvangstbevestiging as a baseline service.

Portaliq already has the create-action pipeline
(`ContributionController::create()`), a subject inbox schema (`portalMessage`),
and a fail-closed manifest normaliser — but on a successful create it writes the
domain object and returns, leaving **no receipt, no data copy, and no
proof-of-receipt log**. This change closes that gap so any fleet app's
create-action is WMEBV-compliant *by construction*, without each app
re-implementing receipts.

## What Changes

- On every **successful** portal create-action (`ContributionController::create()`
  / the endpoint `action` create path), the server generates an
  **ontvangstbevestiging**: a `portalMessage` in the submitting subject's inbox
  carrying a reference id, an ISO-8601 timestamp, human-readable NL/EN body text
  at B1 language level, and a copy of the **whitelisted** submitted field data
  (never raw client input — the same whitelist the create already applies).
- The server writes a **`portalSubmission`** log record (new schema in
  `lib/Settings/portaliq_register.json`): `subjectRef`, `organisation`, `appId`,
  `actionId`, `payloadCopy` (whitelisted), `receiptMessageRef`, `submittedAt`,
  `deliveryStatus`. This is the append-only artefact that satisfies the
  burden-of-proof / logging duty and backs the citizen's right to a copy.
- **Receipt failure never loses the submission**: the domain create is the
  authoritative write. If receipt-message or log writing fails, the create still
  succeeds (200), the `portalSubmission` (or a minimal fallback record) captures
  `deliveryStatus: failed`, and the failure is retriable — a submission is never
  silently dropped because a side-effect failed.
- **Data-minimisation guard** in `PortalManifestNormaliser`: a `fieldConfigs`
  entry may not elevate a field to `required: true` unless that field is
  genuinely mandatory per the action's schema `required` set. A manifest that
  marks an optional field required has that flag dropped fail-closed, so a portal
  form can never require a non-mandatory field.

## Out of scope

- The 2:10 notification-content duties (aard / rechtsgevolg / termijn) — deferred
  to 2027 (readiness handled in `portal-inbox-v2`).
- The email/push notification of the receipt out-of-band — that is
  `portal-notifications-dispatch`; this change only guarantees the in-portal
  receipt + the proof log.

## Dependencies

- Builds on the create-action pipeline (`portal-contribution-endpoint-actions`,
  `contribution-manifest-v3`) and the `portalMessage` inbox schema. Additive; no
  existing endpoint contract changes shape.
