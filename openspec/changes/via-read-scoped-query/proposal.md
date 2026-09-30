# Proposal: via-read-scoped-query

## Why

Found while testing a primary school end to end (learniq's `po` example set on a clean install, 2026-09-30). A parent reads their child's attendance and report cards through learniq's `parent` contribution, whose collections use a reverse `via` join (`match: scopeField`).

The via read had two defects:

1. **The outer read asked OpenRegister for the first 200 rows of the whole schema, unfiltered**, then kept the rows of the parent's children. The example school holds 1,189 attendance records and 396 report cards, so most parents saw an empty or partial list: their child's rows were never in the first page.
2. **The collection's declared `filter` was not applied on the via path.** learniq's `parentReportCards` declares `filter: {lifecycle: published-to-parents}` so a parent never sees a report card still under internal review. The direct path honours it; the via path dropped it, so a draft report card of the parent's own child was returned.

## What changes

- In the reverse mode the outer read queries OpenRegister once per verified target, with the target in the collection's own scope field, so OpenRegister returns the subject's own rows. Rows found twice are kept once.
- The declared `filter` narrows the via read in both modes. The scope field always wins over it, so a filter can never widen the read.
- A read by id (`GET /portal/api/collections/{register}/{schema}/{id}`) honours the declared `filter` too, on the via and the direct path. Before, a report card under review of the parent's own child was returned by id.
- The per-row membership and tenant checks stay unchanged and remain the security boundary.

## Out of scope

- The forward mode (`match: id`) still reads one page and filters in PHP; it now honours the declared `filter`. Querying by id per target is a follow-up.
