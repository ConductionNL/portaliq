# portal-store-page Specification

## Purpose
An admin installs a published portal configuration (a theme, a set of pages, or a form's flows) from a store page inside portaliq. The page is the shared nextcloud-vue store page declared in the manifest; the store backend is OpenRegister's generic store controller, which portaliq reaches through two aliased routes and does not reimplement.

## Requirements

### Requirement: The store page lists and installs published portal configurations

portaliq SHALL declare a manifest page of type `store` that renders the shared store page, and SHALL register the routes `GET /api/store/items` (search) and `POST /api/store/items/{slug}/install` (install), both aliased to OpenRegister's generic store controller. portaliq SHALL NOT ship a store controller of its own.

#### Scenario: The store page shows the published items
- **GIVEN** an admin is signed in to portaliq
- **WHEN** the admin opens the store page
- **THEN** the page SHALL request `/apps/portaliq/api/store/items`
- **AND** the page SHALL list the published themes, page sets and form flows the store returns

#### Scenario: An admin installs an item
- **GIVEN** the store page lists a published page set
- **WHEN** the admin chooses install on that item
- **THEN** the page SHALL post to `/apps/portaliq/api/store/items/{slug}/install`
- **AND** the installed configuration SHALL be available in the portal afterwards

#### Scenario: The store routes answer instead of the page fallback
- **GIVEN** portaliq is installed
- **WHEN** a client requests `GET /apps/portaliq/api/store/items`
- **THEN** the answer SHALL come from the store controller as JSON
- **AND** it SHALL NOT be the single-page app's HTML fallback
