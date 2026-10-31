## ADDED Requirements

### Requirement: A request form has a settings page (REQ-FBS-001)

Each request form binding SHALL open a settings page from the Request forms list, with the tabs Algemeen (form, sign-in, status), Bevestiging, Verklaringen, Mede-ondertekenen and Historie. Every setting the page writes SHALL be a field of `portalFormBinding`, and every choice SHALL be one of that field's allowed values.

#### Scenario: Requiring DigiD
- **WHEN** an administrator opens the Woo-verzoek form, chooses "DigiD of eHerkenning, substantieel" under Inloggen and saves
- **THEN** the binding's `minTrust` is `substantial`
- **AND** the form asks for that sign-in before it is shown
