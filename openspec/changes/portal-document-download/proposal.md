# Proposal: portal-document-download (scoped file download closes the upload/inzage asymmetry)

## Why

The portal can take documents IN but cannot give them back. Agent A:
*"document upload EXISTS (`ContributionController::uploadFile` + `PortalFileWriter::attachFile`, opt-in `filesUpload`, ownership verified). document DOWNLOAD MISSING (no serve endpoint)."* A subject can attach a file to their own
row, but cannot retrieve the decision, contract, or besluit the organisation
attached for them.

That is precisely the **inzagerecht** citizens ask for: *"download docs"* is
table-stakes in the case-status flow (agent E flow 2), and `inzage` (data
access / inspection) appears in **415 tender requirements across 156 distinct
tenders** — one of the highest counts in the corpus. GEMMA's
*Mijngemeentecomponent* lists "tonen ... mijn gegevens" as a baseline service and
the *Ketenpartner-portaalcomponent* lists document exchange for suppliers. A
portal that can only receive files fails half of both components.

## What Changes

- **`GET /portal/api/collections/{register}/{schema}/{id}/files/{fileId}`** —
  streams a file attached to an owned row AFTER the SAME ownership + tenant +
  trust re-verification as the scoped read. The subject must own the row the file
  hangs off before a single byte is served.
- **Opt-in `filesDownload: true`** on a collection (default `false`), normalised
  fail-closed exactly like the existing `filesUpload` flag in
  `PortalManifestNormaliser` — a collection that does not opt in cannot serve
  files.
- **Identical-404 discipline** — a file that does not exist, hangs off a row the
  subject does not own, or belongs to a non-opted-in collection all return the
  SAME 404. No existence oracle: a subject can never probe which files exist.
- **Safe response** — `Content-Disposition: attachment` with a sanitised
  filename; the raw stored path is never exposed.
- **Audit hook** — a download is an audit-relevant event; this change places the
  hook, and the actual append-only audit entries land in
  `portal-session-hardening-v2` (`portalAuditEntry` / `AuditTrailService`).
- **SPA** — a file list on the detail view with download links for opted-in
  collections.

## Out of scope

- Uploading (already exists) and file deletion (a later slice).
- The audit-entry record itself — placed here, written by
  `portal-session-hardening-v2`.

## Dependencies

- Builds on the scoped read boundary (`portal-scoped-crud`), the existing
  `PortalFileWriter`/upload path, and the `filesUpload` normalisation pattern it
  mirrors. Additive; default `false` means no collection serves files until it
  opts in.
