---
kind: code
---

# Proposal: portal-notices-as-drawn

## Why

Board PtMededelingenActies draws a portal's page with the Mededelingen tab open. portaliq drew a compact list of notice texts and dates, a header with Bewerken and Meer only, and side cards of label and value pairs. The board draws:

- the notices as a whole list: quick filters with counts (Alles, Nu zichtbaar, Komend, Concept), Filter, the view switch, search, selection, a level tile and a "where it shows" line under each notice, Zichtbaar van and Tot with the time, a status pill that says whether the notice shows now, six rows a page with a note, and the list's Acties menu beside the tabs;
- a header with Website bekijken beside Bewerken, the primary Nieuwe mededeling on this tab, and a line naming the domain, the theme and the number of pages;
- side cards that read as cards: the domain card as terms with the language in words, the theme as a swatch with its name and a link, the status as a pill with the lines under it, and the administrators as people with their role.

## What changes

- `PortalDetail`'s `portal-notices` widget becomes an `index-list` of the portal's notices, configured as the board (nc-vue `notice-list-in-a-tab`: row icon by level, list labels on the secondary line, the status by rule, the list's menu in the tab strip, rows that open the notice).
- `PortalDetail` declares `headerActions` "Website bekijken" (opens the portal's site) and "New notice", `quickActions` Website bekijken, the primary action New notice beside the notices tab, and `headerMeta` with a count of the portal's pages.
- `PortalSummaryCard` draws the four side cards as the board does. The status card names how many pages wait to be published.
- `src/icons.js` registers `AlertOutline` for a warning notice.

## Out of scope

- The data the board shows: a domain and certificate, the editors Pieter Jansen, Anouk Bakker and Lars de Koning, pages waiting, and notices dated around the measure day. Those are example-set lines for the next window (R20/DQ-DATA/INBOX.md).
- "Laatste publicatie ... door ...": the portal records when it changed, not who published it. The card says when it last changed.

## Impact

`src/manifest.json`, `src/widgets/PortalSummaryCard.vue`, `src/lib/portalSummary.js`, `src/icons.js`, l10n. Needs nc-vue with `notice-list-in-a-tab` for the level tile, the list labels, the status rules, the strip menu and the row links; without it the list still lists, filters and pages.
