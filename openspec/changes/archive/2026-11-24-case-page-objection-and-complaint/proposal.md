---
kind: code
depends_on: [portal-create-cross-refs, zuiddrecht-resident-pages-match-the-boards]
---

# Proposal: case-page-objection-and-complaint

## Why

A resident who disagrees with a decision files a bezwaar against that decision. The place they look for it is the case the decision was made on. Today the portal puts "Bezwaar maken" and "Klacht indienen" on the overview, and the bezwaar form asks which case it is about.

The Zuiddrecht design canvas decided otherwise (7 October 2026, design programme): Bezwaar and Klacht live on the case page. The board `Zaak` draws them in the group "Meer acties voor deze zaak", next to "Wijziging voorstellen" and "Aanvraag intrekken". "Mijn dossiers" is now "Mijn zaken", so the case page is the one place a resident reaches every case.

What portaliq does today, read on `development` at `6cc375af`:

- dossiq declares the citizen action `createBezwaar` (type `create`, schema `portaalVerzoek`) with a required `crossRefs` entry `againstCaseId`, scoped to the resident's own cases. `PortalCrossRefGuard` refuses a foreign case with 403 `cross_ref_refused`. The guard works.
- The action renders as a generic create form on a page (`src/site/components/c/ActionBlock.vue`). The resident picks or types the case. Nothing on the case page (`src/site/components/e/CitizenCase.vue`) offers it.
- `zuiddrecht-resident-pages-match-the-boards` pins "Bezwaar maken and Klacht indienen MUST be on the overview". The canvas decision replaces that line.
- No rule says when a bezwaar is still possible. The Algemene wet bestuursrecht (Awb) gives six weeks from the day after the decision is sent (art. 6:7 and 6:8). The portal offers the button forever.

## What changes

- **A create action may ask to sit on the case page.** A contribution adds `onCase` to a create action that has a `crossRefs` entry pointing at its case collection. Portaliq keeps the key only when well formed.
- **The case page lists those actions.** `GET /portal/api/citizen/cases/...` answers `caseActions`: each with its label, and whether it is open or closed with a sentence. The case screen draws them in "Meer acties voor deze zaak", as the `Zaak` board does.
- **The case is filled in by the server.** Pressing "Bezwaar maken" opens the action's form without the case field. On submit the server writes the case's id into the declared cross-reference field. The guard still runs.
- **A window, when the app declares one.** `onCase.window` names a date field on the case and a number of days. Before the date or after the last day the button is replaced by the app's sentence. Portaliq computes this on the server.
- **The receipt comes back on the case page.** After submitting, the resident sees the reference and a line that the bezwaar or klacht is now a case of its own in Mijn zaken.

## Rows this covers

| Matrix | Row id | Row name | Own rating | What is missing |
| --- | --- | --- | --- | --- |
| portaliq | `cmp-int-objection` | File an objection (bezwaar) against a decision online. | partial | The bezwaar on the case page, the case filled in by the server, and the Awb window. |
| portaliq | `act-complaint-on-case` | File a complaint (klacht) about how a case was handled, from that case's page. | partial | The same placement for the klacht. Row added in the spec round of 7 October 2026. |

## Design

The board is `Zaak` on the Zuiddrecht canvas (`5NkFW28vZUUij43xzxHg5a`). See `design.md`.

## Out of scope

- Handling a bezwaar or klacht. dossiq owns both case types and their workflow (`bezwaar-beroep-workflow`, `complaint-management`).
- A bezwaar against a decision that is not a case in the portal, for example a tax assessment. That stays a page form.
- The legal deadline itself. Portaliq applies the window the case app declares; it does not know Awb terms.

## Sibling halves

- **ConductionNL/dossiq owes** `onCase` on `createBezwaar` and `createKlacht` in `lib/Portal/PortalContributionProvider.php`, with `window: {from: <decision sent date field>, days: 42}` on the bezwaar, and the removal of both from the overview page declaration. Until then the case page shows no button and the overview keeps them.
