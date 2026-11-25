# Tasks: publication-detail-page-complete

Wave 2. Rows 6.4, 6.19, 6.35, 6.36 and 7.20. Decision D11. Kind: code. Build rules:
`openspec/woo-build-rules.md`.

**Before starting**, read on `development`: opencatalogi's `publication-detail-for-the-portal` (merged
or not; the keys for document id, metadata URL, DiWoo URL and comment period), and nextcloud-vue's
`files-preview-in-place` (is `CnFilePreview` and the public opener in `@conduction/nextcloud-vue/public`
in the version portaliq pins). Write both answers in the PR body. A test marked **fails today** must be
run on `origin/development` first and seen red.

## 1. View in the browser

- [x] 1.1 Open each document through the public opener when the pinned nextcloud-vue has it, else through
  a `target="_blank"` inline link for PDF and images; data files stay downloads until then. Refuse any
  URL that is not the opencatalogi public file URL (`safeLink` already exists) (REQ-PDC-001).
  - **fails today**: node test `tests/publication-documents.spec.mjs` `a pdf opens rather than
    downloads`, `a data file goes to the preview when the library has it`,
    `a foreign url is not opened`.

## 2. The document page

- [x] 2.1 (`/document/<publicationId>/<documentId>`, matched by `src/site/lib/documentRoute.js` so the shell bundle stays light; `DocumentPage.vue` reads `src/site/lib/documentPage.js::loadDocumentPage()`: the federation read of the publication, then its attachment list, the row whose `documentId` matches; nothing found emits `notfound` and the shell renders `NotFoundPage`; the publication page links each document with an id; `tests/document-page.spec.mjs` in `check:specs`; the e2e spec is left for the live pass) Route `/document/{id}` in the site router and a `DocumentPage` component; it reads the
  document through the public read of its publication and renders not-found with 404 otherwise. The
  publication page links each document to it (REQ-PDC-002).
  - node test `tests/document-page.spec.mjs` (wire into `check:specs`): `the page links back to its
    publication`, `a document of a non-public publication renders not found`.
  - e2e `tests/e2e/document-page.spec.ts`: open a seeded public document page anonymously, follow the
    link back. Then request a draft's document page and get 404. Cite REQ-PDC-002.

## 3. Download everything and metadata

- [x] 3.1 "Download alles (n documenten)" to opencatalogi's ZIP route (REQ-PDC-003).
  - **fails today**: node test `tests/publication-documents.spec.mjs` `download alles links the zip
    route with the count`, `no documents, no download alles`.
  - e2e: the link answers 200 with `application/zip` for a seeded publication.
- [x] 3.2 Metadata links from the read's URLs only (REQ-PDC-004).
  - **fails today**: node test `a document without a diwoo url has no diwoo link`,
    `metadata json links the url the read carries`.
  - Contract: the fixture is the public read shape from opencatalogi's change, copied with a source
    line; opencatalogi tests the same keys on its side.

## 4. The reaction period

- [x] 4.1 A period line and form link from the reported state; nothing when the state is absent
  (REQ-PDC-005).
  - **fails today**: node test `tests/publication-detail.spec.mjs` (wire into `check:specs` if not
    already): `upcoming says from`, `open says until and links the form`, `closed links no form`,
    `no reported state shows nothing`.
  - Strings in nl, en and en_US; dates through `formatDutchDate()`.

## 5. Live

- [x] 5.1 Live check after merge on the dev instance: a publication with a PDF, a CSV and an open comment
  period. Record the document page, the ZIP download status and the period line.
  — done 25 Nov 2026 (live-portaliq-1) on a sandbox Nextcloud 34.0.6 + MariaDB with OpenRegister main,
  opencatalogi, dossiq, pipelinq and portaliq development and the Zuiddrecht example set: the public
  publication "Overeenkomst levering en plaatsing verlichting fietspad Lindelaan" with its two PDFs, a
  CSV added and published as sandbox seed, and a comment period opened 20 Nov 2026 to 1 Jan 2027
  (zienswijze). The page reads "Reageren kan tot en met 4 januari 2027" (1 January rolled to the next
  working day) (REQ-PDC-005) and "Download alles (3 documenten)"; that ZIP answers 200
  `application/zip` with the three files under Bijlagen/ and a summary PDF (REQ-PDC-003). Each document
  row offers Downloaden, Details, Metadata (JSON) and Metadata (DiWoo XML) (REQ-PDC-004), and Bekijken
  (opens in a new tab) for the PDFs only (REQ-PDC-001). The document page
  `/document/<publication>/<file>` reads "Onderdeel van" the publication, the file name, Bestandstype PDF
  or CSV and Grootte, with Bekijken (PDF), Downloaden and both metadata links (REQ-PDC-002). Seen, not
  this change's: the publication page ends with an "Alle gegevens" table in raw English field names
  and ids (Title, Organization <uuid>, Themes <uuids>, Unlist), which publication-page-all-details 1.4
  owns.

## 6. Verify and deliver

- [x] 6.1 `TMPDIR` set to a sibling directory beside the clone.
- [x] 6.2 While building, run `node --test` on the touched node tests.
- [x] 6.3 Before push, once: `COMPOSER_PROCESS_TIMEOUT=0 composer check:strict`, then `npm run lint`,
  `npm run format`, `npm run check:l10n-js`, `npm run check:schema-l10n`, `npm run check:manifest`,
  `npm run check:specs` and `npm run build:site`, plus any other leg `code-quality.yml` requires. Then
  hydra's `scripts/run-hydra-gates.sh --base origin/development`; count the gates that ran.
- [x] 6.4 Project coverage of the added statements: no coverage driver runs locally, so take the base percentages from the last green push run on `development`, intersect its clover uncovered lines with the lines you add, and say in the PR body that the number is arithmetic, not a local green.
- [x] 6.5 One PR, `--base development`. Merge, never rebase. No `Co-Authored-By`. Done means merged on
  `development` with CI green. The rows then read `yes` (build), 6.4 fully only with
  `files-preview-in-place` in the pinned library, and `production` only with a store release.

> 6.3 to 6.5 for the live round (25 Nov 2026, live-portaliq-1): this round changes only openspec files
> and `@spec` paths, so no new statements and no coverage arithmetic; hydra diff-check with only the
> inherited gate-23 finding; one PR on development, merged.
