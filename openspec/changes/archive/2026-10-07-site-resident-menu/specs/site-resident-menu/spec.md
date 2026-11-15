---
status: proposed
---

# Spec: site-resident-menu

## Purpose

The website's pages and the resident's own items live in two menus. The blue
bar is the website's. The resident's own area has its own menu beside the
content.

## ADDED Requirements

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
not empty, the site SHALL show a `nav` landmark named "Mijn omgeving" beside
the content. It SHALL group the items: cases and tasks, one group per
contributing app named by the app's label (else its id), messages and news,
then details and account; an empty group SHALL be left out. Each group's list
SHALL be named by its label. The item of the page on screen SHALL carry
`aria-current="page"` and a visible mark that is not colour alone. No two
items SHALL read the same: where an app's item has the name of another item,
it SHALL carry its group's name too. The inbox item SHALL show the unread
count, read out in words.

#### Scenario: A resident opens their own area

- **GIVEN** a signed-in resident with dossiq and opencatalogi contributing pages
- **WHEN** they open `/mijn/dossiq/verzoeken`
- **THEN** the menu beside the content shows the groups in that order
- **AND** "Mijn verzoeken" is marked as the current page
- **AND** dossiq's "Mijn zaken" reads "Mijn zaken (Dossiq)"

#### Scenario: A visitor who is not signed in

- **GIVEN** a visitor who is not signed in
- **WHEN** they open `/mijn`
- **THEN** the page shows the ways to sign in and no resident menu

### Requirement: The header must hold the name, the way to the own area and sign-out (REQ-SRM-003)

Signed in, the header's right side SHALL show who is signed in, a link
"Mijn omgeving" to `/mijn`, and the sign-out button. Signed out it SHALL show
the portal's sign-in links as before.

#### Scenario: The resident goes to their own area

- **GIVEN** a signed-in resident on a public page
- **WHEN** they choose "Mijn omgeving" in the header
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
