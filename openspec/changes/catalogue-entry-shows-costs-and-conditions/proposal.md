---
kind: code
---

# Proposal: catalogue-entry-shows-costs-and-conditions

## Why

A resident browsing what she can request wants to know, before she starts, what it costs and whether she qualifies. The intake catalogue lists the published request entries by topic with a title and a summary (`src/site/components/IntakeCatalogueBlock.vue`, `lib/Service/Intake/PortalCatalogueReader.php::topicsFor`). Costs and conditions show only when an editor types them into the summary. Yet the fee is already data: each entry's binding resolves to a case type, and `PortalFee::forBinding()` reads the fee that case type declares for the payment step. REQ-PIFO-006 says the entry point lists the catalogue; no requirement says an entry shows its costs or conditions. The boards **PtProducten** and **PtProduct** draw a cost line and a short list of conditions per product.

## What changes

- **The fee shows on the entry.** The catalogue read adds the fee of the entry's binding, read on the server from the case type, never from the browser. The block shows it as "Kosten: € 45,00". An entry whose case type declares no fee shows no cost line.
- **Conditions show when the publication carries them.** A publication may carry `conditions`, a list of short lines. The block lists them under the summary. Anything else in that field is ignored.

## Rows covered

- `cmp-int-product-catalogue` (built for browsing; costs and conditions are this change). Boards: PtProducten, PtProduct, Home.
- `cmp-int-prefill` is already described by REQ-PIFO-003 in `specs/portal-intake-form`; this PR records that link in capabilities.json and adds no requirement for it.

## Out of scope

- A price per product variant. That is `data-lookups-and-checks-in-forms`.
- Product pages as CMS content. Those stay pages an editor writes.
