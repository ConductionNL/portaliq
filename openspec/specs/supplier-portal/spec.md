# supplier-portal Specification

**Status**: in-progress
**Scope**: portaliq
**OpenSpec changes**:

- [portal-document-download](../../changes/archive/2026-07-23-portal-document-download/)
- [portal-inbox-v2](../../changes/archive/2026-07-23-portal-inbox-v2/)
- [wmebv-submission-receipts](../../changes/archive/2026-07-23-wmebv-submission-receipts/)
- [portal-notifications-dispatch](../../changes/archive/2026-07-24-portal-notifications-dispatch/)

## Purpose

Defines the subject-scoped document DOWNLOAD boundary of the `supplier-portal`
capability: a subject may retrieve a file attached to a contribution row they
own, after the SAME per-row ownership + tenant + trust re-verification as the
scoped single-object read, opt-in per collection (`filesDownload: true`,
mirroring the existing `filesUpload` upload opt-in), with strict
no-existence-oracle discipline — a non-existent file, a foreign-owned row, and
a non-opted-in collection all return the identical 404, and the raw stored
file path is never exposed. Closes the upload/download asymmetry: the portal
could already take documents in (`filesUpload` / `ContributionController::
uploadFile()`) but could not give them back before this. Related:
`portal-scoped-crud` (the scoped read boundary this mirrors) and
`portal-session-hardening-v2` (the audit ENTRY this change's audit hook call
feeds, once that change lands).

> **Note on canonical coverage**: this file was created by archiving
> `portal-document-download` — it currently reflects ONLY that change's
> requirements. The wider `supplier-portal` capability (contract v2, scoped
> CRUD, field projection, contribution-manifest v3, `filesUpload`, and others)
> is implemented and covered by its own OpenSpec changes, several of which are
> still open in `openspec/changes/` awaiting their own archive/sync pass — this
> file does not yet describe them. Treat this spec as partial until those land.
## Requirements
### Requirement: Scoped file download re-verifies ownership before serving a byte

The reader MUST expose `GET /portal/api/collections/{register}/{schema}/{id}/files/{fileId}`
that streams a file attached to the row `{id}` ONLY after re-verifying, against
OpenRegister, that the row is the subject's own by the IDENTICAL per-row ownership
+ tenant + trust boundary as the scoped single-object read. The row MUST be
re-fetched and its ownership confirmed BEFORE the file is resolved or streamed;
the client-supplied `id`/`fileId` MUST NEVER be trusted as a capability. On any
failure (OR error, missing OpenRegister, malformed row) it MUST fail closed to
"not found".

#### Scenario: A subject downloads a file on a row they own

- **GIVEN** a subject, an opted-in collection, a row they own, and a file attached to that row
- **WHEN** they request that file by `fileId`
- **THEN** ownership + tenant + trust are re-verified first, then the file streams with `Content-Disposition: attachment` and a sanitised filename
- Covered by `tests/e2e/portal-document-download.spec.ts` ("a subject downloads a file on a row they own") + `ContributionControllerTest::testDownloadStreamsOwnedFileAndInvokesAuditHookOnSuccess`.

#### Scenario: A file on a foreign-owned row is refused before it is resolved

- **GIVEN** a subject and a `fileId` on a row owned by a DIFFERENT subject or tenant
- **WHEN** they request it
- **THEN** ownership is re-verified first, the file is never resolved or streamed, and the response is 404
- @e2e exclude ownership-before-stream invariant — covered by PHPUnit (`ContributionControllerTest::testDownloadForeignOrAbsentObjectIs404BeforeAnyStream`); no distinct UI surface from the identical-404 scenario below.

### Requirement: Download is opt-in per collection, fail-closed

A collection MUST NOT serve files unless it declares `filesDownload: true`.
`PortalManifestNormaliser` MUST normalise `filesDownload` to a strict boolean
defaulting to `false`, exactly as it normalises `filesUpload` — a malformed or
absent value means `false`. A download request against a collection that has not
opted in MUST return the identical 404 as any other refusal.

#### Scenario: A non-opted-in collection serves no files

- **GIVEN** a collection without `filesDownload: true` (absent or malformed)
- **WHEN** a subject requests a file on one of its rows
- **THEN** the normaliser has set `filesDownload` to `false` and the request returns 404 — no file is served
- @e2e exclude opt-in gate — covered by PHPUnit (`PortalManifestNormaliserTest::testFilesDownloadIsCoercedToAStrictBoolean`, `ContributionControllerTest::testDownloadRequiresTheCollectionToOptIntoFileDownloads`); no UI surface.

### Requirement: Identical-404 discipline (no existence oracle)

The server MUST return the IDENTICAL 404 (same body) for a file that does not
exist, hangs off a row the subject does not own, or belongs
to a non-opted-in collection.
A subject MUST NOT be able to distinguish "file does not exist" from "not yours"
from "collection cannot serve files". The raw stored file path MUST NEVER be
exposed in any response.

#### Scenario: Non-existent, foreign, and non-opted-in all 404 identically

- **GIVEN** three requests — a non-existent `fileId`, a `fileId` on a foreign-owned row, and a `fileId` on a non-opted-in collection
- **WHEN** each is made by the subject
- **THEN** all three return an identical 404 body — no response distinguishes the three cases and no stored path leaks
- Covered by `tests/e2e/portal-document-download.spec.ts` ("a foreign or absent file 404s identically") at the API level, plus PHPUnit (`ContributionControllerTest::testDownloadRequiresTheCollectionToOptIntoFileDownloads`, `testDownloadForeignOrAbsentObjectIs404BeforeAnyStream`, `testDownloadNonExistentFileIs404IdenticalToOtherRefusals`) for the three-way identical body.

### Requirement: Download emits an audit hook

A successful download MUST invoke the audit hook so a `download` event can be
recorded append-only. The audit ENTRY schema and writer are delivered by
`portal-session-hardening-v2`; this change places the call so downloads are
covered the moment that change lands.

#### Scenario: A successful download calls the audit hook

- **GIVEN** the audit hook is available
- **WHEN** a subject successfully downloads a file
- **THEN** the download path invokes the audit hook with the verb `download` and the target register/schema/id (the entry is written by the audit service from `portal-session-hardening-v2`)
- @e2e exclude audit-hook placement — covered by PHPUnit (`PortalAuditHookTest`, `ContributionControllerTest::testDownloadStreamsOwnedFileAndInvokesAuditHookOnSuccess`) asserting the hook is called; the persisted entry is tested in `portal-session-hardening-v2`.

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
- Covered by `tests/e2e/portal-inbox.spec.ts` ("merged inbox with unread badge, 2:10 metadata, and read-state toggle") at the SPA level, plus `PortalInboxReaderTest::testMergesInboxCollectionsAcrossAppsSortedByReceivedAtDesc` for the multi-app merge/sort/provenance contract.

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
- Covered by `tests/e2e/portal-inbox.spec.ts` (the read-state toggle assertion) at the SPA level, plus PHPUnit (`ContributionControllerTest::testMarkReadSetsOnlyTheReadFieldOnTheSubjectsOwnMessage`) for the writer/controller contract.

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
- Covered by `tests/e2e/portal-inbox.spec.ts` (the nav badge assertion) for the badge render, plus PHPUnit (`ContributionControllerTest::testIndexIncludesTheSubjectsUnreadCount`, `PortalInboxReaderTest::testUnreadCountCountsOnlyUnreadMessages`) for the count contract.

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
- Covered by `tests/e2e/portal-inbox.spec.ts` ("merged inbox with unread badge, 2:10 metadata, and read-state toggle") asserting both the metadata render and its absence.

### Requirement: Session refresh rotates the token within an absolute cap

The server MUST expose `POST /portal/api/session/refresh`. Given a valid,
unexpired, not-yet-revoked bearer, it MUST mint a NEW session with a NEW `jti`,
revoke the OLD `jti` (so the previous bearer stops validating), and slide the
expiry forward — capped by an absolute maximum session lifetime (config, default
8h) measured from the original login. A refresh on a revoked, expired, or
past-the-cap bearer, or when the signing secret is unconfigured, MUST fail closed
with a generic error and mint nothing.

#### Scenario: A valid bearer refreshes and rotates its jti

- **GIVEN** a subject with a valid, unexpired bearer within the absolute lifetime
- **WHEN** they POST to `/portal/api/session/refresh`
- **THEN** a new bearer with a new `jti` and a slid-forward expiry is returned, and the old `jti` is revoked (the old bearer no longer validates)
- @e2e tests/e2e/portal-session-refresh.spec.ts

#### Scenario: Refresh past the absolute cap is refused

- **GIVEN** a bearer whose original login is older than the absolute maximum lifetime
- **WHEN** they attempt to refresh
- **THEN** the refresh fails closed with a generic error — the subject must re-authenticate — and no new token is minted
- @e2e exclude absolute-cap invariant — covered by PHPUnit; no UI surface

#### Scenario: Refresh on a revoked or expired bearer fails closed

- **GIVEN** a bearer that is revoked or already expired
- **WHEN** they attempt to refresh
- **THEN** the refresh fails closed, nothing is minted, and the response is the same generic error as any other rejection
- @e2e exclude fail-closed refresh — covered by PHPUnit; no UI surface

### Requirement: Public portal endpoints are rate limited

The server MUST apply Nextcloud anon rate-limit / brute-force-protection
attributes with conservative defaults to the public session endpoints (especially
`dev-login`) and the collection / create / update / action endpoints, so the
public surface is not brute-forceable. The limits MUST combine with, not replace,
the existing fail-closed middleware and `jti` revocation.

#### Scenario: Repeated dev-login attempts are throttled

- **GIVEN** an anonymous client hammering `POST /portal/api/session/dev-login`
- **WHEN** it exceeds the configured anon rate limit
- **THEN** further attempts within the window are throttled (429), not processed
- @e2e exclude rate-limit attribute — asserted by an integration/attribute test; no distinct UI flow

#### Scenario: The scoped-CRUD surface carries a limit

- **GIVEN** the collection / create / update / action endpoints
- **WHEN** their route methods are inspected
- **THEN** each declares an anon rate-limit posture with a sane default
- @e2e exclude posture check — covered by a static/attribute test; no UI surface

### Requirement: Append-only portal audit trail on every mutation, download, and session event

The server MUST write a `portalAuditEntry` (append-only, `publicRead:false`) for
every portal mutation (`create`, `update`, `forward`), every `download`, and
every session event (`login`, `logout`, `refresh`): `jti`, `subjectRef`,
`organisation`, `appId`, `verb`, target `register`/`schema`/`id`, `timestamp`.
The entry MUST be a fact record and MUST NOT carry payload content. Audit writing
MUST NOT fail the audited action — a `record()` failure is caught, logged, and
never propagated. The audit count MUST be exposed count-only via
`MetricsController`, never the subjects.

#### Scenario: A mutation and a session event are both audited

- **GIVEN** a subject who logs in, creates an object, and logs out
- **WHEN** each action completes
- **THEN** a `portalAuditEntry` exists for `login`, `create` (with the target register/schema/id), and `logout`, each with the session `jti`, subject, organisation, and timestamp — and none carries the object's payload
- @e2e exclude audit-record contract — covered by PHPUnit across the write/session paths; no UI surface

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
- Covered by `tests/e2e/wmebv-submission-receipts.spec.ts` ("a successful create-action submission produces a bilingual receipt in the inbox") at the SPA level, plus `SubmissionReceiptServiceTest::testSuccessfulRecordWritesAReceiptMessageAndALinkedProofLog` and `ContributionControllerTest::testSuccessfulCreateTriggersReceiptRecordWithWhitelistedMap` for the write/whitelist contract.

#### Scenario: The receipt copy carries only whitelisted fields

- **GIVEN** a create-action whose `fields` whitelist excludes a client-supplied extra key
- **WHEN** the receipt is generated
- **THEN** the data copy in the `portalMessage` contains only the whitelisted, persisted fields — the non-whitelisted key never appears in the receipt
- @e2e exclude data-copy invariant — covered by PHPUnit (`ContributionControllerTest::testCreateNeverPassesClaimsToTheWriter`, `SubmissionReceiptServiceTest`); no distinct UI surface

#### Scenario: A failed create produces no receipt

- **GIVEN** a create-action whose domain write is rejected (validation / OR error)
- **WHEN** the create fails
- **THEN** no receipt message and no proof log claiming delivery are written for that attempt
- @e2e exclude negative path — covered by PHPUnit (`ContributionControllerTest::testFailedDomainWriteNeverTriggersAuditOrReceiptRecord`, `testForbiddenCreateNeverTriggersReceiptRecord`); no UI surface

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
- @e2e exclude log-record contract — covered by PHPUnit (`SubmissionReceiptServiceTest::testSuccessfulRecordWritesAReceiptMessageAndALinkedProofLog`); no UI surface

#### Scenario: The submission log is subject-scoped

- **GIVEN** two subjects in two tenants who each submitted
- **WHEN** either reads submission log entries
- **THEN** each sees only their own `portalSubmission` records — never the other subject's or another tenant's
- @e2e exclude tenant-isolation invariant — the write path stamps `subjectRef`/`organisation` server-side through the SAME `PortalObjectWriter::createObject()` scoping every other portal write uses (`PortalObjectWriterTest`); no dedicated read endpoint or UI surface exists for this schema yet

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
- @e2e exclude failure-isolation invariant — covered by PHPUnit (`SubmissionReceiptServiceTest::testReceiptMessageWriteFailureStillWritesAFailedProofLogAndNeverThrows`, `testProofLogWriteFailureRetriesWithAMinimalFallbackRecord`, `testAThrownExceptionAnywhereIsNeverPropagatedAndStillYieldsAFallbackRecord`; writer throws, create still 200); no UI surface

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
- @e2e exclude normalisation guard — covered by PHPUnit (`PortalManifestNormaliserTest::testRequiredIsDroppedOnANonMandatoryField`, `testRequiredIsDroppedWhenSchemaIsUnresolvable`, `testRequiredIsDroppedWhenActionHasNoSchemaKey`); no UI surface

#### Scenario: A genuinely mandatory field keeps its required flag

- **GIVEN** a `fieldConfigs` entry marking `required: true` on a field that IS in the schema's `required` set
- **WHEN** the manifest is normalised
- **THEN** the `required` flag is preserved
- @e2e exclude positive-path normalisation — covered by PHPUnit (`PortalManifestNormaliserTest::testRequiredIsPreservedOnAGenuinelyMandatoryField`); the demo `createExample` action exercises this exact path live (its `title` fieldConfig `required: true` matches `exampleDocument`'s genuinely mandatory `title`), asserted indirectly by `tests/e2e/wmebv-submission-receipts.spec.ts` filling and submitting that same field

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
- @e2e exclude email content/delivery needs a mail-catcher not available in the apply pass — covered at unit level (`NotificationDispatchJobTest`: IMailer called with content-free template); `tests/e2e/portal-notifications-dispatch.spec.ts` covers the Playwright-observable half (deep link resolves; the create-action request is never blocked by dispatch)

#### Scenario: No matching rule key means no email

- **GIVEN** a contribution whose manifest declares no rule key matching the trigger
- **WHEN** a `portalMessage` is created or a transition succeeds
- **THEN** no email is dispatched
- @e2e exclude opt-in gate — covered by PHPUnit (`NotificationDispatchServiceTest`); no UI surface

#### Scenario: A subject with no email is not dispatched to

- **GIVEN** a subject whose `portalAccount.email` is unset
- **WHEN** a matching trigger fires
- **THEN** no email is attempted and the attempt is recorded as such (no send to an empty address)
- @e2e exclude null-email guard — covered by PHPUnit (`NotificationDispatchJobTest::testNoEmailRecordsAFailedAttemptWithoutSending`); no UI surface

### Requirement: Dispatch is decoupled from the request path

The email send MUST NOT run inline in the request that created the message or
completed the transition. The trigger MUST enqueue a background job
(`OCP\BackgroundJob\IJobList`); the send MUST happen in the job. A slow or failing
mail server MUST NOT slow or fail the subject's original request.

#### Scenario: A failing mail server does not fail the subject's action

- **GIVEN** a create-action / transition that fires a notification trigger AND a mail server that is slow or erroring
- **WHEN** the subject's request completes
- **THEN** the request returns its normal success without waiting on or failing because of the email; the send is attempted later in the background job
- @e2e exclude decoupling invariant — covered by PHPUnit (`NotificationDispatchServiceTest`: enqueue asserted, no inline send); `tests/e2e/portal-notifications-dispatch.spec.ts` asserts the create-action request completes promptly

### Requirement: Every dispatch attempt is logged

The server MUST write / update a `portalNotification` record per attempt:
`accountRef`, `ruleKey`, `channel`, `status` (sent | failed), `attempts`
(consecutive-failure counter), and `lastAttemptAt`. The record MUST be
subject-scoped and MUST NOT expose recipients to other subjects or tenants.

#### Scenario: A send and a failure are both logged

- **GIVEN** a dispatch job that runs
- **WHEN** the send succeeds, then on a later trigger the send fails
- **THEN** a `portalNotification` records `status: sent` for the first and `status: failed` with an incremented `attempts` for the second, each with `lastAttemptAt`
- @e2e exclude log contract — covered by PHPUnit (`NotificationDispatchJobTest`); no UI surface

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
- @e2e exclude fallback + metrics contract — covered by PHPUnit (`NotificationDispatchJobTest::testReachingTheThresholdFlagsNeedsAlternativeContact`, `MetricsControllerTest`); metrics assertion is count-only; no UI surface

