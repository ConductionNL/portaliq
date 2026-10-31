# portal-identity-space Delta

## ADDED Requirements

### Requirement: Staff can ask whether a citizen has a portal account

portaliq SHALL answer, for a signed-in Nextcloud user who is not a portal citizen, whether the citizen with a given
BSN has an active DigiD or eIDAS portal account, and the portal's name, and nothing else. It SHALL refuse the question
to a portal citizen's own session and to a caller who is not signed in.

#### Scenario: dossiq's Bereikbaar via

- **GIVEN** Sanne de Vries has an active DigiD portal account
- **WHEN** Pieter (a dossiq handler) opens her contact page
- **THEN** portaliq answers `{allowed: true, hasAccount: true, portalName: "Mijn Zuiddrecht"}`

#### Scenario: A citizen asks

- **WHEN** a portal citizen's own session asks about another BSN
- **THEN** the answer is `{allowed: false}`
