## ADDED Requirements

### Requirement: A portal chooses the words of the last crumb

The portal record MAY carry `breadcrumb`, `menu` or `page`; anything else MUST read as `menu`. With
`menu` every crumb that the header menu links to MUST read the menu's words. With `page` the last
crumb MUST read the page's own title when it has one; the crumbs above it keep the menu's words.

#### Scenario: "Nieuws en documenten" on a school portal
@e2e exclude Unit checks in node and PHPUnit: tests/site-look/breadcrumb-words.spec.mjs, PortalShellTest; live screenshot on :8092 in the PR
- GIVEN a portal with `breadcrumb: "page"` and a menu item "Nieuws" linking to `/zoeken`, the page titled "Nieuws en documenten"
- WHEN a visitor opens `/zoeken`
- THEN the breadcrumb reads "Home › Nieuws en documenten"

#### Scenario: Zuiddrecht keeps the menu's words
@e2e exclude Unit check in node: tests/site-look/breadcrumb-words.spec.mjs
- GIVEN a portal without `breadcrumb`
- WHEN a visitor opens a page the menu links to as "Afval"
- THEN the last crumb reads "Afval"

### Requirement: The sign-in page is named sign-in in the breadcrumb

When the own area is shown to a visitor who is not signed in, the breadcrumb MUST read home and then
"Inloggen" ("Log in" in English).

#### Scenario: Signed out on Mijn Wilgenboom
@e2e exclude Unit check in node: tests/site-look/breadcrumb-words.spec.mjs; live screenshot on :8092 in the PR
- GIVEN a visitor who is not signed in
- WHEN they open `/mijn` on De Wilgenboom
- THEN the breadcrumb reads "Home › Inloggen"

### Requirement: A lone sign-in card reads as one row

On the designed site, when a portal offers one way in, its card MUST show the line under the title,
beside the mark, in the muted text colour; with two or more cards the line stays under the mark and
the title.

#### Scenario: De Wilgenboom's DigiD card
@e2e exclude CSS checked in node: tests/site-look/breadcrumb-words.spec.mjs; live screenshot on :8092 in the PR
- GIVEN De Wilgenboom with DigiD as its only way in
- WHEN the sign-in page renders
- THEN "Met de DigiD-app of met een sms-code" stands under "Ouder of verzorger", beside the DigiD mark
- AND "Werkt u bij De Wilgenboom? Log in op de werkplek" reads as one line
