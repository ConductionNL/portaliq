---
kind: spec
---

# Proposal: capability-rows-without-a-requirement

## Why

Every row in `openspec/parity/capabilities.json` should record the requirement that says it, as `built.requirement`. On 12 November 2026, 241 of the 256 rows that recorded none were linked to a requirement whose scenarios already say the row. Fifteen rows were left. For thirteen of them no requirement says what they say; the other two are under Out of scope. Most of the thirteen describe behaviour that is built and reached (the row's evidence names the code), so the gap is in the spec, not in the app.

## What changes

Twelve requirements are added, each with a scenario, to the main spec nearest the row:

- **supplier-portal:** staff browse the portal sessions; staff browse and search every portal message; the staff dashboard counts unread portal messages; a submission receipt offers its answers as a PDF.
- **portaliq-cms:** a portal's minimum trust gates its content reads; the glossary and the contributed services are blocks an editor places; header, footer and legal menus follow one position field; the site fits a phone.
- **portal-federated-search:** the result page travels in the address; the result count is announced.
- **portal-traffic-experiments:** staff replay a masked recording; the client honours Do Not Track.

No code changes in this change. The PDF receipt and Do Not Track are not built yet (the row evidence names no PDF renderer and no `doNotTrack` check); the tasks say so.

## Rows covered

`sig-admin-sessions-index`, `sig-assurance-gate`, `inb-staff-admin-list`, `inb-staff-unread-widget`, `cmp-int-pdf-receipt`, `site-glossary-block`, `site-contributions-block`, `site-menus-layout`, `thm-mobile-responsive`, `srch-pagination`, `srch-a11y-live-region`, `ana-session-recording`, `ana-consent-and-dnt`.

## Out of scope

- `srch-detail-generic-properties`: the main spec "The publication page must show what a visitor needs, in words" forbids the generic property table this row describes. That is a decision, not a missing requirement.
- `dem-rm-house-style-ui`: the token editor is thematiq's. Portaliq ships no tokens ("Portaliq MUST NOT ship design tokens or a theming mechanism").
