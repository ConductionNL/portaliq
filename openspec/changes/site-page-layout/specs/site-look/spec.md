## ADDED Requirements

### Requirement: A page must have one title heading

The renderer MUST NOT print its own page title when the page's hero or main region holds a `hero`,
a `publicationDetail` or an `nlHeading` at level 1.

#### Scenario: A content page that opens with its own heading
@e2e exclude Unit and source checks in node: tests/site-look/page-layout.spec.mjs; live screenshot on :8092 in the PR
- GIVEN the wilgenboom page "Uw kind afwezig melden" whose first block is an `nlHeading` at level 1
- WHEN it renders
- THEN "Uw kind afwezig melden" shows once, as the page's only h1

#### Scenario: A section heading
@e2e exclude Unit check in node: tests/site-look/page-layout.spec.mjs
- GIVEN a page whose first block is an `nlHeading` at level 2
- WHEN it renders
- THEN the renderer prints the page title as the h1 above it

### Requirement: The menu must mark the section of the page on screen

A top-level menu item whose link is the route MUST carry `aria-current="page"`. An item whose link
is a section the route sits in (the route starts with the link and a slash, and the link is not
`/`) MUST carry `aria-current="true"` and the same visible mark as the current page.

#### Scenario: A page inside a section
@e2e exclude Rendered in node: tests/site-look/page-layout.spec.mjs; live screenshot on :8092 in the PR
- GIVEN the wilgenboom menu with "Praktisch" at `/praktisch`
- WHEN a visitor opens `/praktisch/afwezig-melden`
- THEN "Praktisch" is bold with the accent bar and carries `aria-current="true"`, and "Home" carries none

### Requirement: The designed footer must keep every column on one row on a desktop

Above 1024px the designed footer MUST show the brand column and then every contact and menu column
on one row, however many there are. At 1024px and below it MUST wrap into two columns, and into one
on a phone.

#### Scenario: Four columns after the brand
@e2e exclude CSS checked in node: tests/site-look/page-layout.spec.mjs; live screenshot on :8092 in the PR
- GIVEN a footer with a contact block and three menus, the last "Over deze website"
- WHEN it renders at 1440px
- THEN "Over deze website" stands beside the other columns, not under the logo
