## ADDED Requirements

### Requirement: A portal's layout may place a per-record page with all its rows

A page that declares `perRecord` MUST be named `<app>:<page>` in the resident menu, for each of its rows. When a portal's `residentMenu.groups` names it, every row of that page MUST be placed in that group, in the rows' order, with its name, subline and link, and MUST NOT be listed a second time elsewhere.

#### Scenario: Mijn kinderen comes second, as the board draws it
- GIVEN learniq's page `child` with `perRecord: parentChildren` and the rows Vera ("Groep 7 · Meester Daan") and Sami ("Groep 4")
- AND the portal's layout Mijn Wilgenboom (`overview`, `inbox`), Mijn kinderen (`learniq:child`), Regelen (`learniq:conferences`), Van school (`news`)
- WHEN the guardian opens her own area
- THEN the menu reads Mijn Wilgenboom, Mijn kinderen with Vera and Sami and their sublines, then Regelen with "Oudergesprekken" and its count
- @e2e exclude menu building is a pure function, covered by `node --test tests/resident-menu-badges.spec.mjs` ("a portal layout places a per-record page with all its rows"); the live wilgenboom menu by learniq's portal e2e once learniq declares the layout
