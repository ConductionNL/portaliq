---
kind: code
depends_on: []
---

# Proposal: publication-detail-page-complete

Woo capability programme, round 1, wave 2. Rows 6.4, 6.19, 6.35, 6.36 and 7.20.

| row | text | our rating today |
| --- | --- | --- |
| 6.4 | A detail page renders the document in the browser | partial (build) |
| 6.19 | A document has a public page of its own that links back to its publication | partial (production) |
| 6.35 | A citizen downloads a publication with all its documents as one archive | partial (production) |
| 6.36 | A citizen downloads a document's metadata in an open format beside the file | partial (production) |
| 7.20 | The portal tells the citizen whether the reaction period has not started, is open, or has passed | no |

Implements Ruben's decision **D11** for 6.19: a content hit resolves to the document's own public page,
which this change builds, so `portal-federated-search` (row 6.2) and `search-filter-by-kind` (row
6.30) have a page to link to.

## Summary

Complete the public publication detail page: render a document in the browser, give each document its own public page, download a publication as one archive and a document's metadata in an open format, and show the reaction period.

- Rows: 6.4, 6.19, 6.35, 6.36 and 7.20 (none statutory).
- Wave: 2.
- Depends on: `opencatalogi/publication-detail-for-the-portal` (https://github.com/ConductionNL/opencatalogi/issues/1761). Outside the plan: `nextcloud-vue/files-preview-in-place` (no issue; https://github.com/ConductionNL/nextcloud-vue/tree/development/openspec/changes/files-preview-in-place); without it 6.4 stays partial.
- Decision: D11 (2026-10-05) for 6.19, a content hit resolves to the document's own public page, which this change builds.

Build rules: openspec/woo-build-rules.md

## Why

What portaliq does today, read on `development` at ca591037: `PublicationDetailBlock.vue` lists a
publication's documents (`toDocuments()` in `src/site/lib/publicationDetail.js`) and offers each as a
`download` link. Nothing renders a document in the browser, no document has a page of its own, the
publication's ZIP download that opencatalogi already serves
(`GET /index.php/apps/opencatalogi/api/{catalogSlug}/{id}/download`) is not linked, no metadata link
sits beside a file, and a publication with a public comment period does not say whether the period
is upcoming, open or closed, although opencatalogi computes exactly that (`publication-comment-periods`,
REQ-PCP-004, implemented).

## What changes

1. **View in the browser** (6.4): each document opens through nextcloud-vue's public file opener: a
   PDF or an image in the browser's own viewer, a CSV, TSV, JSON or XML file in `CnFilePreview`, and
   anything else as a download. The download stays offered beside it.
2. **A page per document** (6.19): `/document/{id}` shows the document's own metadata, the viewer or
   the download, and a link back to its publication. It answers only for a document of a public
   publication; otherwise the site's not-found page.
3. **Download everything** (6.35): "Download alles" on the publication page, pointing at
   opencatalogi's existing ZIP route.
4. **Metadata beside the file** (6.36): next to each document, a link to its metadata as JSON, and to
   the DiWoo XML record when opencatalogi offers one.
5. **The reaction period** (7.20): when the publication carries a comment period, the page says
   "Reageren kan vanaf {start}", "Reageren kan tot en met {end}" with the reaction form link, or
   "De reactietermijn is verlopen op {end}", from the state opencatalogi reports. The form link is
   shown only while the period is open.

## What does not change

- What opencatalogi publishes and how it computes the period's state.
- Documents of a non-public or withdrawn publication stay unreachable.

## Dependencies

- Planned, opencatalogi, wave 1: `publication-detail-for-the-portal` (row 6.25), which shapes the
  public publication read this page uses. The exact keys for the document id, its metadata URL, the
  DiWoo record URL and the comment period are read from that change when building and pinned in a
  contract test on each side.
- Open, nextcloud-vue, outside the plan: `files-preview-in-place` (0/9), which ships `CnFilePreview`
  and the public opener. Without it, PDFs and images still open in the browser's own viewer through
  a plain `target="_blank"` link to the inline URL, and data files stay downloads; 6.4 reads partial
  until it lands.
- Followed by: `portal-federated-search` amendment (6.2) and `search-filter-by-kind` (6.30) link to the
  document page.

**App absent.** Without opencatalogi there are no publications to show, as today.

## Wave and done

Wave 2. Done means merged on `development` with CI green. The five rows then read `yes` (build), 6.4
only with `files-preview-in-place` merged for data files, and `production` only once a portaliq store
release carries them.
