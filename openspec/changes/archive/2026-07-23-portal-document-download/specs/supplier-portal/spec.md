---
status: proposed
---

# Spec: supplier-portal (scoped document download)

## Purpose

Adds a subject-scoped file DOWNLOAD that mirrors the existing upload path,
closing the inzage asymmetry: a subject can now retrieve a file attached to a row
they own, after the same ownership + tenant + trust re-verification as a read,
opt-in per collection, with strict no-existence-oracle discipline. Related:
`portal-scoped-crud` (scoped read boundary), the `filesUpload` normalisation it
mirrors, ADR-005 (fail-closed), and `portal-session-hardening-v2` (the audit
entry this download will emit).

## ADDED Requirements

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
- @e2e exclude add Playwright e2e in apply phase — spec-only PR; asserted at unit level (ownership re-verify, stream, headers)

#### Scenario: A file on a foreign-owned row is refused before it is resolved

- **GIVEN** a subject and a `fileId` on a row owned by a DIFFERENT subject or tenant
- **WHEN** they request it
- **THEN** ownership is re-verified first, the file is never resolved or streamed, and the response is 404
- @e2e exclude ownership-before-stream invariant — covered by PHPUnit; no UI surface

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
- @e2e exclude opt-in gate — covered by PHPUnit normaliser + controller matrix; no UI surface

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
- @e2e exclude no-oracle invariant — covered by PHPUnit (three-way identical 404); no UI surface

### Requirement: Download emits an audit hook

A successful download MUST invoke the audit hook so a `download` event can be
recorded append-only. The audit ENTRY schema and writer are delivered by
`portal-session-hardening-v2`; this change places the call so downloads are
covered the moment that change lands.

#### Scenario: A successful download calls the audit hook

- **GIVEN** the audit hook is available
- **WHEN** a subject successfully downloads a file
- **THEN** the download path invokes the audit hook with the verb `download` and the target register/schema/id (the entry is written by the audit service from `portal-session-hardening-v2`)
- @e2e exclude audit-hook placement — covered by PHPUnit asserting the hook is called; the persisted entry is tested in portal-session-hardening-v2

## Non-Functional Requirements

- **Security (ADR-005):** ownership is re-verified before any byte is resolved;
  the client id/fileId is never a capability; every refusal is an identical 404;
  no stored path is exposed.
- **Performance:** one by-id ownership fetch + a stream; no full-collection scan.
- **Accessibility / i18n:** the detail-view file list and download links follow
  NLDS and existing i18n keys.

## Acceptance Criteria

- `GET .../{id}/files/{fileId}` streams only after ownership + tenant + trust
  re-verification, with `Content-Disposition: attachment` + a sanitised filename
- `filesDownload` defaults to `false` and is normalised fail-closed like
  `filesUpload`; a non-opted-in collection serves nothing
- Non-existent, foreign-owned, and non-opted-in requests return an identical 404;
  no stored path leaks
- A successful download invokes the audit hook (verb `download`)
- The SPA shows a file list with download links on the detail view; README
  documents the endpoint and `filesDownload`

## Notes

- Mirrors the existing `filesUpload` opt-in and `ContributionController::uploadFile`
  ownership pattern; download is the read-side counterpart.
- The audit entry itself is written by `portal-session-hardening-v2`.
