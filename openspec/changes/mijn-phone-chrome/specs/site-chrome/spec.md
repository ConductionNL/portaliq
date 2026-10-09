## ADDED Requirements

### Requirement: The own area may show the person in the phone header

A portal MAY declare `residentMenu.phoneHeader: "person"`. Inside the own area on a phone the header MUST
then show the person's initials instead of the sign-out link, the resident menu MUST end in a sign-out
button, the crumb trail MUST be left out, and the room under the page MUST be at most 24px. Without the key
the phone chrome MUST stay as it is.

#### Scenario: Noor on her phone
@e2e exclude Unit test in node: tests/site-look/mijn-phone-chrome.spec.mjs; PHPUnit PortalShellTest
- GIVEN the Vaartveld portal with `residentMenu.phoneHeader: "person"`
- WHEN Noor opens `/mijn` on a phone
- THEN the header shows "NB" and no "Uitloggen", and "Uitloggen" ends the opened menu

### Requirement: The own area may end in a short footer on a phone

A portal MAY declare `footer.compact` with a `text` and up to four `links`. Inside the own area on a phone
the site MUST then show the logo, that line and those links instead of the full footer.

#### Scenario: The short footer
@e2e exclude Unit test in node: tests/site-look/mijn-phone-chrome.spec.mjs; PHPUnit PortalShellTest
- GIVEN `footer.compact` with "Telefoon: [telefoonnummer]", Toegankelijkheid and Privacy
- WHEN a pupil opens an own-area page on a phone
- THEN the page ends in the logo, "Telefoon: [telefoonnummer]" and the two links, and the full footer is not shown
