# site-resident-menu Specification

## Purpose
The website's pages and the resident's own items live in two menus. The blue bar carries the website's pages. The resident's own area has its own menu beside the content, grouped as the contributing apps declare, with the name, the way to the own area and sign-out in the header, and the menu folds behind a button on a phone.

## Requirements

### Requirement: A contributed page may name the menu group it belongs to (REQ-SRM-005)

The site's resident menu MUST put every contributed page that declares a `group` under one heading with that name, whichever app contributes it, in the order the groups first appear. A page without a `group` MUST sit under its app's display name, else its app id. When two items in the menu read the same, an app's item MUST carry its app's name.

#### Scenario: Two apps share one heading
- GIVEN dossiq's "Mijn afspraken" and pipelinq's "Mijn verzoeken" both declare `group: "Mijn zaken en verzoeken"`
- AND dossiq's "Mijn uren" declares no group
- WHEN a signed-in resident opens a `/mijn` page
- THEN the menu shows "Mijn zaken en verzoeken" with both items, then "Dossiq" with "Mijn uren"
- @e2e exclude pinned by `tests/site-resident-menu.spec.mjs` ("pages with the same group share one heading across apps")

#### Scenario: Two items of one name are told apart
- GIVEN dossiq contributes "Mijn afspraken" in the group "Mijn zaken en verzoeken"
- AND the portal has its own "Mijn afspraken" under "Uw gegevens"
- WHEN the menu renders
- THEN dossiq's item reads "Mijn afspraken (Dossiq)" and the portal's keeps its name
- @e2e exclude pinned by `tests/site-resident-menu.spec.mjs` ("two items of one name in a shared group are told apart by their app")

### Requirement: The blue bar must carry the website's pages only (REQ-SRM-001)

The site's header menus SHALL be the portal's CMS header menus (position 0),
signed in or not. The signed-in navigation SHALL NOT be added to them.

#### Scenario: A signed-in resident on a public page

- **GIVEN** a resident who is signed in
- **WHEN** they open a public page of the site
- **THEN** the blue bar shows the website's pages and none of the resident's items
- **AND** the page shows no resident menu and keeps its full width

### Requirement: The resident's own items must sit in a menu beside the content (REQ-SRM-002)

On every `/mijn` page, while a resident is signed in and their navigation is
not empty, the site SHALL show a `nav` landmark beside the content, named by
the name the portal gives its own area ("Mijn Zuiddrecht"; "Mijn omgeving"
without one). It SHALL group the items: overview and messages under the area's
name, themes, the groups contributions name ("Mijn zaken en verzoeken",
"Vragen en contact"), one group per contributing app for a page without a group,
named by the app's label (else its id), then "Uw gegevens"; an empty group SHALL
be left out. Each group's list
SHALL be named by its label. The item of the page on screen SHALL carry
`aria-current="page"` and a visible mark that is not colour alone. No two
items SHALL read the same: where an app's item has the name of another item,
it SHALL carry its app's name too. The inbox item SHALL show the unread
count, read out in words.

#### Scenario: A resident opens their own area

- **GIVEN** a signed-in resident with dossiq and opencatalogi contributing pages
- **WHEN** they open `/mijn/dossiq/verzoeken`
- **THEN** the menu beside the content shows the groups in that order
- **AND** "Mijn verzoeken" is marked as the current page
- **AND** dossiq's "Mijn afspraken" reads "Mijn afspraken (Dossiq)" beside the portal's own "Mijn afspraken"

#### Scenario: A visitor who is not signed in

- **GIVEN** a visitor who is not signed in
- **WHEN** they open `/mijn`
- **THEN** the page shows the ways to sign in and no resident menu

### Requirement: The header must hold the name, the way to the own area and sign-out (REQ-SRM-003)

Signed in, the header's right side SHALL show the account pill, which names who
is signed in and links to `/mijn` under the name of the own area, and the
sign-out button. Signed out it SHALL show
the portal's sign-in links as before.

#### Scenario: The resident goes to their own area

- **GIVEN** a signed-in resident on a public page
- **WHEN** they choose the account pill in the header
- **THEN** the site opens their own area with the resident menu

### Requirement: The menu must fold behind a button on a phone (REQ-SRM-004)

Below 768 px wide the resident menu SHALL stand above the content, its list
hidden behind one button that carries `aria-expanded` and `aria-controls`.
Choosing an item SHALL fold the list. Neither the menu nor the area SHALL make
the page scroll sideways at 390 px.

#### Scenario: A resident on a phone

- **GIVEN** a signed-in resident on a 390 px wide screen
- **WHEN** they open a `/mijn` page
- **THEN** the content shows with one button above it to open the menu
- **AND** the page does not scroll sideways

### Requirement: A portal's layout may place a per-record page with all its rows

A page that declares `perRecord` MUST be named `<app>:<page>` in the resident menu, for each of its rows. When a portal's `residentMenu.groups` names it, every row of that page MUST be placed in that group, in the rows' order, with its name, subline and link, and MUST NOT be listed a second time elsewhere.

#### Scenario: Mijn kinderen comes second, as the board draws it
- GIVEN learniq's page `child` with `perRecord: parentChildren` and the rows Vera ("Groep 7 · Meester Daan") and Sami ("Groep 4")
- AND the portal's layout Mijn Wilgenboom (`overview`, `inbox`), Mijn kinderen (`learniq:child`), Regelen (`learniq:conferences`), Van school (`news`)
- WHEN the guardian opens her own area
- THEN the menu reads Mijn Wilgenboom, Mijn kinderen with Vera and Sami and their sublines, then Regelen with "Oudergesprekken" and its count
- @e2e exclude menu building is a pure function, covered by `node --test tests/resident-menu-badges.spec.mjs` ("a portal layout places a per-record page with all its rows"); the live wilgenboom menu by learniq's portal e2e once learniq declares the layout

### Requirement: A portal may leave items out of the resident menu

A portal MAY declare `residentMenu.leaveOut`, a list of item names. The site MUST leave those
items out of the resident menu and drop a group that is then empty. `overview` MUST always stay.
Without the key the menu MUST be built as before.

#### Scenario: A school portal without "Mijn zaken en verzoeken"
@e2e exclude Unit test in node: tests/site-look/resident-menu-leave-out.spec.mjs; PHPUnit PortalShellTest
- GIVEN a portal with `residentMenu.leaveOut` `["cases", "tasks", "access"]`
- WHEN a signed-in guardian opens the own area
- THEN the menu has no "Mijn zaken en verzoeken" group, and every other group is as before

#### Scenario: Nothing declared
@e2e exclude Unit test in node: tests/site-look/resident-menu-leave-out.spec.mjs
- GIVEN a portal without `residentMenu.leaveOut`
- WHEN the menu is built
- THEN it holds the "Mijn zaken en verzoeken" group as before

### Requirement: A per-record page in a group lists each row with its subtitle

When a contributed page declares `perRecord` and `group`, the resident menu MUST list each row of
that collection as one item of the group, named by the row's title fields, linking to the page with
that row chosen, with the row's `records.subtitleFields` joined by " · " as a second line. Without
`group` it MUST keep one group per row.

#### Scenario: The children of a guardian
@e2e exclude Rendered in node: tests/resident-menu-badges.spec.mjs
- GIVEN a page "Mijn kind" in group "Mijn kinderen", per record of the children, subtitle fields cohort and teacher
- WHEN the menu renders for Vera (Groep 7, Meester Daan) and Sami (Groep 4)
- THEN "Mijn kinderen" holds "Vera" with "Groep 7 · Meester Daan" and "Sami" with "Groep 4"

### Requirement: A menu entry may show the count of a collection

When a page declares `badge.collection`, one of its contribution's collections, the menu MUST
load that collection's rows for the resident and show their number beside the entry when it is
more than 0, with `badge.label` (`{count}` replaced) as its text for screen readers. The phone
button MUST show the total of all counts.

#### Scenario: Conferences to plan
@e2e exclude Rendered in node: tests/resident-menu-badges.spec.mjs
- GIVEN a page "Oudergesprekken" with a badge on a collection holding one row
- WHEN the menu renders
- THEN the entry shows "1" and reads "1 te plannen"

#### Scenario: Rows not known yet
@e2e exclude Rendered in node: tests/resident-menu-badges.spec.mjs
- GIVEN the badge collection has not loaded
- WHEN the menu renders
- THEN the entry shows no count

### Requirement: The menu may open with whom the resident acts for

When the portal declares `residentMenu.cardLabel` and the session acts for an organisation
(`organisationName`), the menu MUST open with a card showing the label and the organisation's
name. Otherwise it MUST show no card.

#### Scenario: An employer
@e2e exclude Rendered in node: tests/resident-menu-badges.spec.mjs; PortalShell projection by PHPUnit
- GIVEN a portal with card label "U regelt het voor" and a session for Jansen Installatietechniek BV
- WHEN the menu renders
- THEN it opens with "U regelt het voor" and "Jansen Installatietechniek BV"
