# Design: cases-export-own-data-pdf

Read at portaliq development `eeda3fa` and openregister development on 2026-09-27.

## What is there today

- `lib/Controller/ContributionController.php:469` `collection()` answers `GET /portal/api/collections/{register}/{schema}` (`appinfo/routes.php:289`). It resolves the subject, finds the collection in the subject's own contributions with `authorisedCollection()`, re-checks `minTrust`, and reads through `PortalObjectReader::readCollection()` (`lib/Service/PortalObjectReader.php:161`) with the collection's `scopeField`, `scopeClaim`, `via`, `fields` and `filter`, limit 200.
- `ContributionController.php:584` `object()` answers `GET /portal/api/collections/{register}/{schema}/{id}` (`routes.php:296`) through `PortalObjectReader::readObject()` (line 324). A foreign or missing object is one 404.
- `lib/Contribution/CollectionConfigNormaliser.php:214` normalises the boolean flags `filesUpload` and `filesDownload` on a collection.
- `lib/Service/AuditTrailService.php:157` accepts the verbs `create`, `update`, `forward`, `download`, `login`, `logout`, `refresh`, `complete`.
- `src/portal/components/PageView.jsx:227` `DetailCard` renders one row; the block switch around `PageView.jsx:314-370` renders `collection` blocks through `CollectionTable`.
- OpenRegister `lib/Service/ExportService.php:332` `exportToPdf(?Register, ?Schema, array $filters, ?IUser)` fetches through `fetchObjectsForExport()` (line 788), which calls `ObjectService::searchObjects()` with `_rbac: true` and drops every filter not prefixed `@self.` (lines 801-806). The render path, `buildPdfSection()` and `renderPdfDocument()`, is private. `MAX_PDF_EXPORT_ROWS` is 5000 (line 74).

## D1. Opt in per collection

A collection may set `exportPdf: true`. `CollectionConfigNormaliser` adds it to the boolean flags it already normalises at line 214, so anything but `true` is `false`. A collection that does not opt in offers no export, and its export route answers 404.

The app opts in because only the app knows whether a list is something a resident would take along. A statement list is; a list of open tasks may not be.

## D2. Two routes that reuse the scoped read

- `GET /portal/api/collections/{register}/{schema}/export.pdf?collection=` on `ContributionController::exportCollectionPdf()`.
- `GET /portal/api/collections/{register}/{schema}/{id}/export.pdf?collection=` on `ContributionController::exportObjectPdf()`.

Both are `#[PublicPage]`, `#[NoCSRFRequired]` and `#[AnonRateLimit(limit: 10, period: 60)]`, the lower limit because a render is costly (ADR-082). Both are registered before the `/portal/{path}` catch-all. The collection route is also registered before `contribution#object` (`routes.php:296`), whose `{id}` would otherwise read `export.pdf` as an object id.

Each runs exactly the authorisation of its screen twin: subject, `authorisedCollection()`, `minTrust`, and then `readCollection()` or `readObject()` with the same arguments. The export adds no read path of its own.

## D3. Columns and labels come from the collection

The collection route uses the collection's `columns` labels where declared, else its `fields`. The object route uses `detail.fields`, else `fields`. Values are rendered as text, the way `DetailCard` shows them. The title is the collection's `label`, followed by the organisation name and the date of the export.

## D4. OpenRegister renders, through a method that takes rows

Portaliq resolves `OCA\OpenRegister\Service\ExportService` from the container, the same duck-typed way `PortalFileReader` resolves OpenRegister's `FileService` (`lib/Service/PortalFileReader.php:63`). It calls the rows-based render method from the sibling half. When OpenRegister is absent or the method is missing, the route answers 503 and the button is not shown, because the contributions payload reports `exportPdf` only when the renderer is there.

## D5. Too large is a message, not a broken file

OpenRegister refuses more than `MAX_PDF_EXPORT_ROWS` rows with `ExportTooLargeException`. Portaliq's own read stops at 200, so a portal export never reaches the cap today. If it does, the route answers 400 and the SPA shows: "This list is too long for one PDF. Filter it first." (Dutch: "Deze lijst is te lang voor één pdf. Filter hem eerst.")

## D6. The screen

`PageView.jsx` shows a "Download as PDF" button (Dutch: "Download als pdf") above a `collection` block whose collection has `exportPdf`, and in `DetailCard` for one row. A new `portalApi.downloadPdf(collection, id?)` fetches with the bearer and saves the blob, the same way `downloadFile()` does (`src/portal/lib/portalApi.js:580`). A failure shows "The PDF could not be made. Try again later." (Dutch: "De pdf kon niet worden gemaakt. Probeer het later opnieuw.")

## D7. Audit

Each export is recorded with the `download` verb, the register, the schema, and the object id for a single record or the collection id for a list.

## Risks

- **ADR-075 names filinq as the fleet's PDF owner.** It records OpenRegister's own Dompdf use as an exception pending a consolidation review. This change uses it because it exists, is sandboxed and renders a plain table. If filinq publishes its contract and OpenRegister's renderer is folded into it, only D4's seam moves.
- **A rendered table can hold personal data the resident later deletes.** The PDF is theirs and leaves the system with them. That is the point of the row; the docs page says so.

## What this change does not do

- It does not add a PDF library to portaliq.
- It does not widen any read: no field, row or collection a resident cannot already see.
- It does not make branded or signed documents.
