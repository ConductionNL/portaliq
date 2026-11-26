## ADDED Requirements

### Requirement: The sign-in page leads to Nextcloud's own password reset (REQ-PWR-001)

For a portal that offers the account route, the sign-in page SHALL show "Wachtwoord vergeten" leading to Nextcloud's lost-password flow, with a return address that brings the visitor back to the portal's account route. A portal that does not offer the account route MUST NOT show the link. Portaliq MUST NOT receive, store or check a password.

#### Scenario: A forgotten password
- **WHEN** a resident on a portal with the account route chooses "Wachtwoord vergeten"
- **THEN** Nextcloud's lost-password page opens
- **AND** after the reset and sign-in she lands back on the portal signed in

#### Scenario: A DigiD-only portal
- **WHEN** a portal offers only DigiD
- **THEN** the sign-in page shows no "Wachtwoord vergeten"
