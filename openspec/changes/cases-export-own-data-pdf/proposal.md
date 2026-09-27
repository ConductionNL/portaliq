# Proposal: cases-export-own-data-pdf

## Why

A resident wants a copy of their own information to keep, print or hand to someone: their payment statements, their budget plan, their list of requests. The portal shows it on screen and offers no way to take it along.

The row comes from a tender. Portaliq matrix, row `dem-tnd-export-pdf`, origin `tender`, originUrl https://www.tenderned.nl/aankondigingen/overzicht/409958, with the note:

> TenderNed 409958: 'Client kan via het inwonerportaal verschillende gegevens (bv afschriften betalingsverkeer, of budgetplan) exporteren in PDF'

The matrix `built.evidence`: "grep -riE 'pdf|print|export' src/portal: only TasksPage.jsx:52 (accepted upload types); portalMessage.dataCopy (lib/Settings/portaliq_register.json:715) is a JSON copy of submitted fields, not an export". The `built.note`: "No PDF or print export of the resident's own data, statements or plan; the WMEBV receipt carries a field copy in the inbox message only."

The one competitor cell rated `yes`, quoted from `gap-rows.json`:

- Open Inwoner Platform: "src/open_inwoner/plans/views.py:756 PlanExportView; route src/open_inwoner/cms/collaborate/urls.py:66 [reached on plan detail page, export]". No URL recorded.

## What changes

- **A collection can offer a PDF.** The contributing app sets `exportPdf: true` on a collection. The portal then shows "Download as PDF" above that list, and on the detail of one row.
- **The PDF holds what the screen holds.** The rows come from the same subject-scoped read as the list, projected to the same fields, labelled with the same column labels. Nothing the resident cannot see on screen ends up in the file.
- **OpenRegister renders it.** Portaliq adds no PDF library. It hands the scoped rows to OpenRegister's sandboxed PDF renderer, the one `ExportService::exportToPdf()` uses.
- **Every export is audited** as a download.

## Rows this closes

| Matrix | Row id | Row name | Own rating | What is missing |
|---|---|---|---|---|
| portaliq | `dem-tnd-export-pdf` | Export your own information from the portal, such as statements or a plan, as a PDF. | no | A PDF of a list or of one record, scoped to the resident, from the portal screen. |

## Existing work it builds on

- OpenRegister `openspec/changes/archive/2026-07-13-export-pdf-format`: `ExportService::exportToPdf()`, Dompdf with `isRemoteEnabled` and `isPhpEnabled` off, A4 landscape with page numbers, and the row cap `MAX_PDF_EXPORT_ROWS` (5000) with `ExportTooLargeException`.
- `openspec/changes/archive/2026-09-07-portal-scoped-crud` and `openspec/changes/archive/2026-09-07-field-projection`: the scoped read and the field projection the export reuses unchanged.
- `openspec/changes/archive/2026-07-23-portal-document-download`: the opt-in flag pattern (`filesDownload`) and the `download` audit verb.

## Out of scope

- Branded or templated documents (a letterhead, a signed statement). That is document generation, owned by filinq under ADR-075.
- Exporting across contributions, a "download everything" for a data request. OpenRegister owns the AVG data-subject request workflow under ADR-047.
- Formats other than PDF. The tender asks for PDF.
- Raising the list's 200-row read limit for an export.

## Sibling halves

- **ConductionNL/openregister owes** a public method that renders rows the caller already fetched, for example `ExportService::renderRowsToPdf(string $title, array $columns, array $rows): string`, with the same Dompdf sandbox and row cap. Portaliq cannot use `exportToPdf()` itself. That method fetches its own objects with Nextcloud-user RBAC (`_rbac: true`) and skips every filter that is not an `@self.` metadata filter (openregister `lib/Service/ExportService.php:801-806`). A portal resident is not a Nextcloud user, and portal scoping is a property filter such as `portalSubject`, so the fetch either returns nothing or ignores the scope. The renderer it would call, `buildPdfSection()` and `renderPdfDocument()`, is private.
