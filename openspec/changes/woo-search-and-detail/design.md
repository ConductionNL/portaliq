# Design: woo-search-and-detail

Read at portaliq `development` `0e0cfc8d`, opencatalogi `development`
`c658467d` and openregister `development`.

## What exists

- `src/site/components/FederatedSearchBlock.vue` searches opencatalogi's
  `/api/federation/publications` with one `facetField` prop (default
  `themes`). Selected values travel as `_facets=a,b` in the page address.
- `src/site/lib/federatedSearch.js` `buildRequestUrl()` asks one
  `_facets[<field>][type]=terms` and appends each selected value as
  `<field>=<value>`. `toBuckets()` reads both facet dialects.
- `src/site/components/PublicationDetailBlock.vue` reads
  `/api/federation/publications?id=<id>` and renders every property through
  `src/site/lib/publicationDetail.js`. It never asks for the documents.
- opencatalogi routes `GET /api/federation/publications/{id}/attachments`
  (public). It answers OpenRegister's file envelope:
  `{ results: [{ id, title, type, size, downloadUrl, accessUrl, ... }], total }`,
  shared files only.
- opencatalogi's `publication` schema names the information category
  `wooCategory` (TOOI codes `infocat001` to `infocat017`) and the publishing
  organisation `organization` (American spelling, a uuid). There is no
  `informatiecategorie` property (hydra C6 as settled on 30 September).
- OpenRegister reads `<field>[gte]` and `<field>[lte]` as range filters.

## D1. Several facet fields, one request

`facetFields` (array prop) replaces the single `facetField`; the old prop stays
accepted as a one-element list, so existing placements keep working. The
default is `['wooCategory', 'organization']`, each with a heading from
`facetLabels`. `buildRequestUrl()` asks one `_facets[<field>][type]=terms` per
field in the same request, and appends selected values per field. The facet
column renders one group per field that returned buckets. A field without
buckets renders nothing, as today.

In the page address each field has its own parameter, `f.<field>=a,b`. The old
`_facets=` parameter is still read, as values of the first field, so links that
were shared before this change still open the same search.

## D2. The period as two dates

A period group with two date inputs, "Van" and "Tot". They map to
`<periodField>[gte]=<from>` and `<periodField>[lte]=<to>T23:59:59Z`.
`periodField` defaults to `publicationDate`: every publication has it, while
`period` (C6) is new and optional. In the page address they are `periodFrom`
and `periodTo`, the names contract C2 uses. A date that does not parse is
dropped, not sent.

## D3. The search as one object

`searchQuery(state)` in `federatedSearch.js` returns
`{ text, filters: { informatiecategorie: [], organisation: [], periodFrom, periodTo }, catalog }`.
The facet field `wooCategory` maps to the C2 key `informatiecategorie`, and
`organization` to `organisation`, the names `GET /api/search` also accepts. `catalog` is
the block's `catalog` prop, empty by default. The object is pure data, so the
save action in `woo-journey-entry-points` and a node test share one source.

## D4. Documents on the publication page

`PublicationDetailBlock` fetches `<endpoint>/<id>/attachments` after the
publication loaded. `publicationDetail.js` gains `toDocuments(envelope)`,
which returns `{ id, title, type, size, href }` per file. `href` is
`downloadUrl`, else `accessUrl`; a link that is not http(s) or same-origin is
dropped, so a file never renders a link a visitor cannot follow. The list sits
under the heading "Documenten", each row a download link with the type and a
human size ("1,2 MB"). No documents: the section says "Deze publicatie heeft
geen documenten." A failed attachment call shows the same page without the
section, never an error over the publication itself.

## D5. Budget

Both blocks are async chunks (`WidgetGrid.vue`). The entry gains only the
`propsFor()` lines of `woo-journey-entry-points`. The build's
`performance.hints: 'error'` at 412 KiB is the check.
