---
kind: code
depends_on: [case-page-tasks-decision-dates-and-next-step]
---

# Proposal: woo-dossier-in-my-cases

## Summary

A resident works on their Woo request in Mijn zaken: they answer the question on the case page, read why the term stands still or moved, and follow the link to what was made public.

- Decision: Ruben, 2026-10-09. "OpenCatalogi now has screens for handling Woo requests, but that functionality belongs in dossiq as a case type; we can work together with citizens on their Woo dossier." The citizen side of that is Mijn zaken.
- Counterparts:
  - `dossiq/woo-dossier-shared-with-the-requester` (new, this round): declares `beantwoordVraag`, writes `termNote`, `legalDecisionDate` and `resultLink` on the case.
  - `opencatalogi/woo-request-screens-move-to-dossiq` (new, this round): OpenCatalogi retires its request screens and keeps publishing. The `resultLink` points at its publication.
  - `portaliq/case-page-tasks-decision-dates-and-next-step` (open): the decision dates and the banner on the case page. This change adds one sentence under them.
  - `portaliq/woo-intake-delivers-to-dossiq` (open): the Woo form delivers to dossiq.
- Board: `portaliq/ZaakWooVerzoek` (new, design-system PR of this round). `portaliq/MijnZaken` links its Woo card to it.

## Why

What the case page offers today, read on `development` at 3fee99f4:

- The case app's steps, the decision dates (`plannedDecisionDate`, `legalDecisionDate`), documents, contact moments, a message box, and the actions withdraw, amend, objection and complaint.
- The questions the organisation asked are a separate collection (dossiq's `vragenAanU`, "Wat wij nog van u nodig hebben"). They are listed, not answerable on the case.

What a Woo requester needs on top, and what portaliq lacks for it:

1. **Answer the question where it is asked.** A Woo request that is too broad gets a question back (Woo art. 4.1 lid 5). The term stands still until the requester answers. The resident sees the question in a list, and has no place to answer it on the case.
2. **Read why the date moved.** A paused term has no date, and an extension has a reason the law says the requester must get (Woo art. 4.4 lid 2). The case page shows a date or nothing, and never why.
3. **Find what was made public.** After the decision, the documents are public in OpenCatalogi. The case page does not link there.

All three are generic: any case app can use them. The Woo case is the first to.

## What changes

1. **The question on the case.** The case page lists the open rows of a questions collection whose `case` is this case, above the steps, in the warning-toned banner. Each row carries the contributing app's endpoint action. For dossiq that is `beantwoordVraag`: a text field "Uw antwoord" and "Bestanden toevoegen". After sending, the row reads "Wij hebben uw antwoord. De behandelaar kijkt ernaar." (REQ-WDM-001).
2. **A sentence on the term.** `portalCase` gains `termNote`, a plain sentence the case app writes. The case page shows it under "Uiterlijk klaar op", and in its place when no date is set. Portaliq adds no words of its own (REQ-WDM-002).
3. **A link to the result.** `portalCase` gains `resultLink`, `{label, url}`. The case page shows it as a link under the steps. Only an `https` url on one of the instance's `trusted_domains` is shown (REQ-WDM-003).

## Out of scope

- Whether the answer is enough. The handler decides that in dossiq, and only then does the term run again.
- Term arithmetic. Portaliq shows the date and the sentence the case app wrote.
- The officer's screens. They are dossiq's.

## Release notes

- Answer the organisation's question on your case page.
- See why the term stands still or moved, and open what was made public.
