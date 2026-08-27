---
status: proposed
---

# Spec: supplier-portal (notification dispatch)

## Purpose

Makes the contribution contract's previously-inert `notifications` rule keys
drive a real, privacy-minimal, out-of-band email nudge to the external subject,
dispatched off the request path via a background job, logged per attempt, with a
WMEBV notificatieplicht fallback after repeated failure. Related: WMEBV ~Awb
2:11, ADR-005 (fail-closed), ADR-031 (must not emit the legacy notification
dialect).

## ADDED Requirements

### Requirement: Manifest notification rule keys drive an out-of-band email

The server MUST dispatch an email to the subject's `portalAccount.email` when a
`portalMessage` is created for a subject, OR a status-transition update
succeeds for a subject, AND the contributing app's manifest `notifications` list
declares a rule key matching that trigger. The email MUST be privacy-minimal: it MUST
carry only the organisation name, a generic subject/body, and a deep link into
the portal. It MUST NOT carry the message subject, body, case identifiers, or any
personal data beyond the recipient address. A contribution that declares no
matching rule key MUST NOT trigger an email. Unknown or malformed rule keys MUST
be ignored fail-closed.

#### Scenario: A declared rule key sends a content-free nudge

- **GIVEN** a subject with an email and a contribution whose manifest declares a matching notification rule key
- **WHEN** a `portalMessage` is created for that subject
- **THEN** an email is dispatched to `portalAccount.email` naming only the organisation and a deep link — no message subject, body, or case data
- @e2e exclude add Playwright e2e in apply phase — spec-only PR; asserted at unit level (IMailer called with content-free template)

#### Scenario: No matching rule key means no email

- **GIVEN** a contribution whose manifest declares no rule key matching the trigger
- **WHEN** a `portalMessage` is created or a transition succeeds
- **THEN** no email is dispatched
- @e2e exclude opt-in gate — covered by PHPUnit; no UI surface

#### Scenario: A subject with no email is not dispatched to

- **GIVEN** a subject whose `portalAccount.email` is unset
- **WHEN** a matching trigger fires
- **THEN** no email is attempted and the attempt is recorded as such (no send to an empty address)
- @e2e exclude null-email guard — covered by PHPUnit; no UI surface

### Requirement: Dispatch is decoupled from the request path

The email send MUST NOT run inline in the request that created the message or
completed the transition. The trigger MUST enqueue a background job
(`OCP\BackgroundJob\IJobList`); the send MUST happen in the job. A slow or failing
mail server MUST NOT slow or fail the subject's original request.

#### Scenario: A failing mail server does not fail the subject's action

- **GIVEN** a create-action / transition that fires a notification trigger AND a mail server that is slow or erroring
- **WHEN** the subject's request completes
- **THEN** the request returns its normal success without waiting on or failing because of the email; the send is attempted later in the background job
- @e2e exclude decoupling invariant — covered by PHPUnit (enqueue asserted, no inline send); no UI surface

### Requirement: Every dispatch attempt is logged

The server MUST write / update a `portalNotification` record per attempt:
`accountRef`, `ruleKey`, `channel`, `status` (sent | failed), `attempts`
(consecutive-failure counter), and `lastAttemptAt`. The record MUST be
subject-scoped and MUST NOT expose recipients to other subjects or tenants.

#### Scenario: A send and a failure are both logged

- **GIVEN** a dispatch job that runs
- **WHEN** the send succeeds, then on a later trigger the send fails
- **THEN** a `portalNotification` records `status: sent` for the first and `status: failed` with an incremented `attempts` for the second, each with `lastAttemptAt`
- @e2e exclude log contract — covered by PHPUnit; no UI surface

### Requirement: Repeated failure flags an alternative-contact fallback

The server MUST flag the `portalAccount` `needs-alternative-contact` after N
consecutive failed attempts for an account (N configurable, small
default) — the WMEBV notificatieplicht fallback signal — and MUST surface the failure /
fallback counts count-only via the existing metrics endpoint. The metrics MUST
NOT expose which subjects or addresses failed.

#### Scenario: N consecutive failures set the fallback flag and a metric

- **GIVEN** an account whose notification has failed N consecutive times
- **WHEN** the Nth failure is recorded
- **THEN** the `portalAccount` is flagged `needs-alternative-contact` and the count is reflected count-only in `MetricsController` output — no recipient identity is exposed
- @e2e exclude fallback + metrics contract — covered by PHPUnit; metrics assertion is count-only; no UI surface

## Non-Functional Requirements

- **Privacy (ADR-005 / AVG dataminimalisatie):** the email is content-free; the
  metrics are count-only; recipients never appear in logs or metrics.
- **Reliability:** dispatch is off the request path; the subject's action never
  depends on the mail server.
- **Accessibility / i18n:** NL + EN templates at B1 language level; English i18n
  key names.
- **ADR-031:** no legacy notification dialect is emitted into the register.

## Acceptance Criteria

- A declared rule key sends a content-free email to `portalAccount.email`; no key
  → no email; no address → no attempt
- The send runs in an `IJobList` background job, never inline
- Each attempt writes a `portalNotification` (sent | failed, attempts counter)
- N consecutive failures flag `needs-alternative-contact` and a count-only metric
- `portalNotification` is added to `portaliq_register.json`; behaviour documented
  in README

## Notes

- Only the `email` channel is implemented; `channel` is future-proofed for SMS /
  push.
- Complementary to OR's ADR-031 notification engine (server-side); this is the
  external-subject side keyed off the portal manifest (design.md).
