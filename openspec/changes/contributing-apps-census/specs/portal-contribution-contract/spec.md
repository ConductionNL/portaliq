## ADDED Requirements

### Requirement: An administrator can see which apps contribute to the portal (REQ-CAC-001)

`occ portaliq:contributions` SHALL list every enabled app on the instance with: whether `PortalProviderLocator::locate()` finds a provider for it, the audiences the provider serves, and per audience the number of collections, pages and actions its contribution declares for a synthetic subject of that audience. An app without a provider SHALL be listed as contributing nothing. An app known to integrate without a provider (opencatalogi, through its public search and publication endpoints) SHALL be listed with that pattern, not as contributing nothing. A provider that throws SHALL be listed with the error class and SHALL NOT stop the listing. With `--output=json` the command SHALL print the same rows as JSON. The command SHALL read nothing of any real resident.

#### Scenario: A case app contributes, an office app does not
- **GIVEN** an instance with dossiq, which ships a provider for audience `client`, and keepiq, which ships none
- **WHEN** an administrator runs `occ portaliq:contributions`
- **THEN** dossiq is listed with audience `client` and its collection, page and action counts
- **AND** keepiq is listed as contributing nothing
- @e2e exclude CLI; pinned by ContributionsCommandTest

#### Scenario: A broken provider does not hide the others
- **GIVEN** an app whose provider throws on `getContribution()`
- **WHEN** the command runs
- **THEN** that app is listed with the error class and every other app is still listed
- @e2e exclude CLI; pinned by ContributionsCommandTest
