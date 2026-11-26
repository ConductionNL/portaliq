# portal-intake-form Delta: intake-catalogue-and-address-without-sign-in

## ADDED Requirements

### Requirement: The intake catalogue and the address lookup answer without a sign-in (REQ-PIFO-009)

The intake catalogue (`GET /portal/api/intake/catalogue`) and the address lookup (`GET /portal/api/intake/address`) serve public data and SHALL answer a visitor without a portal session, rate limited per client. Every other intake route SHALL keep requiring a session. The exemption SHALL hold for these two methods of the intake controller only.

#### Scenario: A signed-out visitor reads the catalogue
- **GIVEN** a visitor without a portal session
- **WHEN** the entry point asks for the intake catalogue
- **THEN** the answer is the catalogue, not a 401

#### Scenario: A signed-out visitor looks up an address
- **GIVEN** a visitor without a portal session filling in an anonymous form
- **WHEN** the form looks up a postcode and house number
- **THEN** the answer is the street and town, or a 404 when there is none, never a 401

#### Scenario: The other intake routes still need a session
- **GIVEN** a visitor without a portal session
- **WHEN** they call the intake form route
- **THEN** the answer is a 401
