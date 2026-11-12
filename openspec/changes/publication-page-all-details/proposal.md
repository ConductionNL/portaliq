---
kind: code
---

# The publication page ends with an "All details" table

## Why

Ruben decided on 12 Nov (decision 391) that a publication page keeps its worded part and adds
an "All details" table of the stored properties below it. Today `portal-federated-search`
forbids showing any stored code on the page, while the matrix row asks for the full property
set with links.

### Matrix rows (portaliq `openspec/parity/capabilities.json`)

| row | what changes |
|---|---|
| `srch-detail-generic-properties` | the full property set shows in a table below the worded page |

## What changes

- The publication page keeps its worded part as it is.
- Below it, an "All details" table lists every stored property with a readable label, URL-shaped
  values as links, and empty values left out.
- The rule against stored codes now applies to the worded part only.

## Impact

- `src/site/components/PublicationDetailBlock.vue`, `src/site/lib/publicationDetail.js`, board
  Publicatie. Not built yet: the matrix row moves to `specified`.
