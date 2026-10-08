## ADDED Requirements

### Requirement: A portal may let an existing account sign in with a one-time e-mail link

A portal MAY declare the sign-in mode `email-link`. For an address of an existing portal account of that portal, portaliq MUST mail a link that is single use, expires after 15 minutes, is stored only as a hash, and signs the person in at assurance `low` when its page's button is pressed. The form MUST answer the same for a known and an unknown address, MUST be rate limited per address and per client, and a portal that does not declare the mode MUST NOT offer or accept it.

#### Scenario: Tom asks for a link
- **GIVEN** Tom has a portal account on the academy portal and the portal declares `email-link`
- **WHEN** he enters his address and opens the mailed link within 15 minutes and presses "Inloggen"
- **THEN** he is signed in at assurance `low`, and the same link a second time answers that it was used
- @e2e exclude spec-only proposal; the token rules asserted in PHPUnit

#### Scenario: An unknown address
- **GIVEN** an address without an account
- **WHEN** it is entered
- **THEN** the page answers the same sentence as for a known address and no mail is sent
- @e2e exclude as above
