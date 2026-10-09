## ADDED Requirements

### Requirement: A declared menu item may carry the board's word

An item in `residentMenu.groups[].items` MAY be an object `{item, label}`. The site MUST show
`label` for that item. A page listed once per row (`perRecord`) that a group names MUST bring all
its rows into that group, each with its own name and second line; a label MUST NOT rename a row.

#### Scenario: The conversations read "Berichten"
@e2e exclude Unit test in node: tests/site-look/resident-menu-follows-the-boards.spec.mjs; PHPUnit PortalResidentMenuTest
- GIVEN a portal with the group "Mijn Wilgenboom" holding `overview` and `{item: "messages", label: "Berichten"}`
- WHEN a guardian with a conversation opens the own area
- THEN the group shows "Overzicht" and "Berichten", and "Berichten" opens `/mijn/messages`

#### Scenario: Both children in "Mijn kinderen"
@e2e exclude Unit test in node: tests/site-look/resident-menu-follows-the-boards.spec.mjs
- GIVEN a group "Mijn kinderen" naming the page `learniq:child`, listed once per child
- WHEN the menu is built with Vera and Sami known
- THEN the group shows "Vera" with "Groep 7 · Meester Daan" under it, then "Sami" with "Groep 4 · Juf Esra"

### Requirement: The menu may open with the person and their class

A portal MAY declare `residentMenu.person` with a `collection` (`app:id`) and up to four `fields`.
The menu MUST then open with the person's initials in a circle, the name from the session and the
fields of that collection's first row joined by " · ". When the menu opens with an organisation card,
the line MUST stand under the organisation's name instead. Without the key no person block shows.

#### Scenario: Noor's class under her name
@e2e exclude Unit test in node: tests/site-look/resident-menu-follows-the-boards.spec.mjs
- GIVEN `residentMenu.person` `{collection: "learniq:studentEnrolments", fields: ["levelLabel", "groupName"]}`
- WHEN Noor Bakker opens the own area
- THEN the menu opens with "NB", "Noor Bakker" and "4 havo · klas H4b"

#### Scenario: The employer card says how many employees
@e2e exclude Unit test in node: tests/site-look/resident-menu-follows-the-boards.spec.mjs
- GIVEN a portal with `cardLabel` and a person collection whose first row reads "4 medewerkers" and "via eHerkenning"
- WHEN Linda acts for Jansen Installatietechniek BV
- THEN the card shows "U regelt het voor", the company's name and "4 medewerkers · via eHerkenning"

### Requirement: A portal may give an item of the own area a second address

A portal MAY declare `residentMenu.routes`, a map of addresses under `/mijn/` to item names. Opening
such an address MUST open that item. Any other address under `/mijn/` that the navigation does not
offer MUST open the home `/mijn`.

#### Scenario: /mijn/berichten opens the conversations
@e2e exclude Unit test in node: tests/site-look/resident-menu-follows-the-boards.spec.mjs
- GIVEN `residentMenu.routes` `{"berichten": "messages"}`
- WHEN a pupil opens `/mijn/berichten`
- THEN the site shows `/mijn/messages`

#### Scenario: An unknown address opens the home
@e2e exclude Unit test in node: tests/site-look/resident-menu-follows-the-boards.spec.mjs; tests/site-signed-in-shell.spec.mjs
- GIVEN a portal that leaves "Mijn zaken" out of its menu
- WHEN a guardian opens `/mijn/documenten`, which no page offers
- THEN the site shows the overview `/mijn`, not "Mijn zaken"

### Requirement: The menu marks the page on screen with the theme's accent

The page on screen MUST show on the accent's light wash, in the accent's text colour and bold, and
carry `aria-current="page"`. Counts MUST use the theme's badge colours. A row's second line MUST
stand under its name. The menu MUST take every colour from theme tokens.

#### Scenario: Overzicht is highlighted
@e2e exclude Unit test in node: tests/site-look/resident-menu-follows-the-boards.spec.mjs
- GIVEN the vaartveld theme
- WHEN Noor opens `/mijn`
- THEN "Overzicht" carries `aria-current="page"` on the aqua wash, and the "2" next to "Berichten" sits on the aqua badge
