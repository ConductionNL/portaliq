---
kind: code
---

# Proposal: portal-federated-search

## Summary

Give the public portal a search page that queries **OpenCatalogi's federated
publication endpoint**, so a visitor searches every catalogue this instance
federates with, not just the rows this instance owns.

Portaliq adds a block and no back end. The endpoint already exists, is already
`@PublicPage`, and already aggregates local and federated results into one
envelope.

## Motivation

The portal has no search. On a catalogue portal that is not a missing feature —
it is the primary way anybody finds anything, and the reference implementation
this renderer mirrors puts it on the home page.

Federation is also, today, invisible. This instance holds **711 publications**
carrying `@self.directory` values of `local`, `opencatalogi.nl` and
`directory.opencatalogi.nl` (measured 2026-08-20), and nothing on any page
shows a visitor that more than one catalogue is involved. A federated search
that names each result's source is the first surface on which federation is
observable at all — including for the operator checking whether it works.

## Why this does NOT go through OpenRegister object search

`portal-public-search` proposes exactly that, and is **blocked** on
`openregister/openspec/changes/rbac-default-authenticated`: 504 of 571 fleet
schemas declare no authorization block, and OpenRegister's default for an
unmarked schema is still fail-OPEN. Opening an anonymous search path over that
default would make all 504 anonymously searchable.

This change is not blocked by that, and the reason is not a workaround:

| | `portal-public-search` | this change |
| --- | --- | --- |
| Who runs the query | Portaliq | OpenCatalogi |
| Whose schemas are reachable | any register the portal names | the publication schemas OpenCatalogi owns |
| Who decides visibility | OR RBAC, invoked by Portaliq | OR RBAC, invoked by OpenCatalogi |
| Schemas marked | portaliq: 0 of 9 | opencatalogi: **37 of 37** |

OpenCatalogi is one of three apps in the fleet whose schemas all declare their
authorization, and `getLocalPublicationsUltraFast()` calls OpenRegister with
`_rbac: true`. So the anonymous-visibility decision this change relies on is
one that has already been made explicitly, by the app that owns the data.

Portaliq therefore holds **no** visibility logic, no allow-list of searchable
schemas, and no second copy of the published predicate. `portal-public-search`
remains open on its own merits and its blocker is unchanged.

## What is deliberately NOT built

**A filter on the source directory.** Every row carries `@self.directory`, and
the API accepts `@self[directory]=opencatalogi.nl` — and answers `total: 0`, on
a corpus where all 711 rows have that field populated. `_directory=` is
accepted and ignored (711 unchanged). Measured 2026-08-20.

So the directory is *shown* on every result and cannot be *filtered* on. A
control that silently empties the page is worse than an absent one: the visitor
concludes there is nothing there, and nothing about the page says otherwise.
Fixing the filter belongs to OpenCatalogi, not here.

## Cost

The site bundle grows **8,045 B gzipped (+7.8%)**, 103,209 → 111,254 B,
measured with `NODE_ENV=production` against the same tree with only the
registration reverted. That is the number a visitor pays.

## Affected Projects

- `portaliq` — one public block, its pure helpers and their test
- `nldesign` — none (the Rotterdam token set that styles it ships separately)
- `opencatalogi` — none; this consumes an endpoint that already exists

## Amendment, 2026-10-05: Woo capability programme, wave 2

Row 6.2 of the Woo capability register, "Search reaches the text inside documents, not only their
metadata", rated partial (production), is added to this change. It implements Ruben's decision **D11**:
a content hit resolves to the document's own public page, which `publication-detail-page-complete`
builds (row 6.19). Re-checked on `development` at ca591037: this change is open (Task 3's e2e box), so
the amendment adds REQ-PFS-CONTENT-001 and Task 4 and changes nothing already written.

**Why.** opencatalogi's `add-document-content-search` (6 of 8 on `development` at 35999c29) adds an
opt-in `_content=true` that widens matching to the extracted text of documents. The portal's search
block never sends it, so a visitor's search stays on metadata.

**Dependencies.** Open, opencatalogi, outside the plan: `add-document-content-search`. D11 requires it
to be amended first, because it returns content hits as rows of the `document` schema that
`attachments-are-files` retired. Planned, portaliq, wave 2: `publication-detail-page-complete` for the
document page a hit links to. Without opencatalogi nothing is searched at all, as today.
