# site-look Specification

## Purpose
How the public site looks once a portal has a design: the type faces, the hero, the content blocks and the page layout follow the portal's theme and the design boards, using theme tokens only.

## Requirements

### Requirement: A designed portal reads its body and heading faces everywhere

On a portal with the designed header, `body` MUST read the set's document font, and every heading
MUST read the set's heading font unless a rule names that heading's own family.

#### Scenario: Vaartveld
@e2e exclude CSS checks in node: tests/site-look/designed-site-type.spec.mjs; measured on :8092 (proof run 2 instance)
- GIVEN the vaartveld set, with Red Hat Text for text and Red Hat Display for headings
- WHEN the home page renders
- THEN `body` computes Red Hat Text and the hero heading Red Hat Display

### Requirement: A melding must be a card with the tint of its kind

A melding (`nlAlert`) MUST have padding inside, a gap between its heading and text, and a
background in the tint of its kind: info the set's primary light, ok, warning and error the set's
status badge background for that kind. Each MUST read the Utrecht alert token first. Two meldingen
side by side in the grid MUST be one height.

#### Scenario: Two cards side by side
@e2e exclude CSS rules checked in node: tests/site-look/content-blocks.spec.mjs; live screenshot on :8092 in the PR
- GIVEN the meldingen "Online melden" and "Liever bellen?" in one grid row
- WHEN the page renders
- THEN both show as tinted cards of the same height with room around the text

### Requirement: A link list must be a named landmark with targets of at least 24px

A link list with a heading MUST be a `nav` named by its heading through an id unique on the page. A
link list without a heading MUST NOT be a landmark. Each link MUST be at least 24px high.

#### Scenario: Three link lists on one page
@e2e exclude Rendered in node: tests/site-look/content-blocks.spec.mjs; axe on :8092 in the PR
- GIVEN a home page with the link lists "Over onze school", "Praktisch" and "Meedoen"
- WHEN axe checks the page
- THEN it reports no `landmark-unique` and no `target-size` finding

### Requirement: A hero must show its heading, lead and search box together

A hero block MUST show its heading and lead whether or not it holds a search box, unless the author
sets `headingVisible: false`. The search box MUST have an accessible name: the author's
`searchLabel`, shown above the box; else, when the heading is hidden, the heading, shown above the
box; else the submit button's word, for screen readers only. The plain hero's lead MUST be 20px on
a line of at most 40rem unless the set names another size.

#### Scenario: A school hero with a search box
@e2e exclude Rendered in node: tests/site-look/hero-heading.spec.mjs; live screenshot on :8092 in the PR
- GIVEN the Esdoornveen home hero "Een vak leer je door het te doen" with a search box labelled "Zoek een opleiding"
- WHEN the page renders
- THEN the heading, the lead and the labelled search box all show

#### Scenario: No label of its own
@e2e exclude Rendered in node: tests/site-look/hero-heading.spec.mjs
- GIVEN a hero "Wat wilt u regelen?" with a search box and no `searchLabel`
- WHEN it renders
- THEN the heading shows and the box is named "Zoeken" for screen readers, not by the heading again

#### Scenario: The author hides the heading
@e2e exclude Rendered in node: tests/site-look/hero-heading.spec.mjs
- GIVEN a hero with a search box and `headingVisible: false`
- WHEN it renders
- THEN the heading is for screen readers only and its text labels the box, visibly

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
