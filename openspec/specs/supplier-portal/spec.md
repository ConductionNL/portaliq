# supplier-portal Specification

**Status**: in-progress
**Scope**: portaliq
**OpenSpec changes**:

- [portal-document-download](../../changes/archive/2026-07-23-portal-document-download/)

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

