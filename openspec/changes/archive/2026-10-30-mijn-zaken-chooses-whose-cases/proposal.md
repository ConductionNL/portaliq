---
kind: code
---

# Proposal: mijn-zaken-chooses-whose-cases

## Why

In the Zuiddrecht live test on 14 October 2026, Linda held a mandate for her father Henk but had no way to choose his cases. The designed header (`HeaderTools`) does not render the `#account` slot, so `ActingForSwitcher` never appears. The canon board MijnZakenTabel (`screens-src/zuiddrecht/`) puts the choice on Mijn zaken itself: a "Zaken van" select above the search.

## What changes

- `MyCasesPage` shows "Zaken van" when the resident holds a mandate. The options are "Iedereen voor wie ik werk", "Mijzelf, <naam>" and each party a mandate names.
- Choosing a party acts for it for the rest of the session (the shared acting-for store). Choosing everyone reads the resident's own list and each mandate's, and lists a case once. This view belongs to the page only.
- "Toegang tot zaken regelen" links to `portal.myCases.mandatesHref` when the portal names it. Without it there is no link.

## Screens

MijnZakenTabel: the "Zaken van" bar.
