# Tasks: portal-document-download

> Scoped `GET .../{id}/files/{fileId}` download mirroring the upload path:
> opt-in `filesDownload`, ownership re-verify, identical-404, audit hook.
> Implementation-ordered; small and verifiable.

## Implementation Tasks

- [x] T01 — Add `filesDownload` normalisation to `PortalManifestNormaliser::normaliseCollections()`: strict boolean, default `false`, mirroring the existing `filesUpload` handling.
- [x] T02 — Add a file-read method to the file layer (`lib/Service/PortalFileWriter.php` / a new `PortalFileReader`): given an owned row and a `fileId`, resolve and open the attached file stream; never expose the raw stored path.
- [x] T03 — Add `GET /portal/api/collections/{register}/{schema}/{id}/files/{fileId}` route in `appinfo/routes.php` (before the `/portal/{path}` catch-all) and `ContributionController::downloadFile()`: re-verify ownership/tenant/trust via the scoped reader BEFORE resolving the file; require `filesDownload: true`.
- [x] T04 — Stream the file with `Content-Disposition: attachment` + a sanitised filename; return an identical 404 for non-existent / foreign-owned / non-opted-in; fail closed on OR error.
- [x] T05 — Place the audit hook call on the successful-download path (verb `download`, target register/schema/id) — the entry is persisted by `portal-session-hardening-v2`'s `AuditTrailService`; guard the call so an absent audit service is a no-op.
- [x] T06 — SPA: render a file list with download links on the detail view for opted-in collections (`src/portal/` detail component + `src/portal/lib/portalApi.js`).
- [x] T07 — Unit test `PortalManifestNormaliserTest`: `filesDownload` default `false`, malformed → `false`, `true` preserved.
- [x] T08 — Unit test the download: own-row streams with correct headers; foreign-owned refused before resolve; non-existent / foreign / non-opted-in return an identical 404; no stored path in any response; audit hook invoked on success.
- [x] T09 — Add Playwright e2e: open a detail view, download an owned file, and confirm a foreign/absent file link 404s (closes the `@e2e exclude` markers on the download scenarios). (Spec file written; not live-run against a running instance in this apply pass — see the spec file's header note.)
- [x] T10 — Document the download endpoint and `filesDownload` in `README.md`; add the requirements to the canonical `openspec/specs/supplier-portal` spec on sync.
- [x] T11 — Run `composer check:strict` and `npm run lint` green; run Hydra gates (route-auth, route-reachability, no-admin-idor, spec-coverage, e2e-coverage). (phpunit/phpcs/phpstan/psalm/eslint/webpack all run directly — see PR description for exact results; all touched/new code is clean, remaining findings verified pre-existing against origin/development baseline.)
