## ADDED Requirements

### Requirement: A resident changes their own name (REQ-RSP-002)

A signed-in resident SHALL be able to change the name the portal uses for them from their own account page. The change SHALL apply to their own account only and SHALL take effect without a confirmation step.

#### Scenario: A resident corrects their name
- **GIVEN** a resident signed in whose account reads "J. Jansen"
- **WHEN** they change the name to "Jan Jansen" on their account page and save
- **THEN** the portal greets them as "Jan Jansen" from then on
@e2e no browser test changes the name yet (tests/e2e/identity-profile-page.spec.ts only reads it); Unit: tests/Unit/Service/Identity/PortalSelfServiceUpdateDetailsTest.php, tests/Unit/Controller/PortalAccountSelfControllerTest.php
