# Spec: Site resident menu

## ADDED Requirements

### Requirement: A portal may lay out the resident menu and its cases page
A portal MAY declare `residentMenu.groups`: an ordered list of groups, each a `title` and its
`items` by name, where a name is a section of the own area (`overview`, `cases`, `tasks`,
`inbox`, `messages`, `news`, `access`, `details`, `account`) or a contributed page as
`app:page`. The menu MUST then show those groups in that order, `overview` as an item that opens
`/mijn` itself, without icons, and MUST append every item the layout does not name in the groups
the site builds itself, so nothing becomes unreachable. A portal MAY declare
`myCases.display: rows`: the page that lists every case then draws one row per case with its
number, title, tag and the day it is due by. Without either key the menu and the page MUST be as
they were.

#### Scenario: The Zuiddrecht menu
@e2e exclude Static: tests/zuiddrecht-resident-boards.spec.mjs builds the groups from a nav
- GIVEN the Zuiddrecht portal's groups (Mijn Zuiddrecht: overview, inbox; Zaken en taken: cases, tasks; Vragen en meldingen: portaliq:meldingen; Uw gegevens: details, account)
- AND a nav that also holds access
- WHEN the groups are built
- THEN the five declared groups MUST come first, in order, Overzicht linking to /mijn
- AND Toegang tot zaken MUST follow in the site's own Zaken en taken group

#### Scenario: Mijn zaken as rows
@e2e exclude Static: tests/zuiddrecht-resident-boards.spec.mjs renders the page
- GIVEN a portal with `myCases.display: rows` and a contribution whose cases collection names a due field
- WHEN Mijn zaken renders
- THEN each case MUST be a row with "Uiterlijk klaar op" and the day in words
