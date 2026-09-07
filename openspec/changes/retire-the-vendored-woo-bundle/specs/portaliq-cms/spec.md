# portaliq-cms Delta: retire-the-vendored-woo-bundle

**Status**: proposed
**Scope**: portaliq
**OpenSpec changes**:

- [retire-the-vendored-woo-bundle](../../)

## Purpose

One municipality's pre-built React application is served from inside the
fleet's generic portal app. These requirements move that public surface onto
the content model Portaliq already has, and remove the bundle once parity is
demonstrated rather than assumed.

## ADDED Requirements

### Requirement: The publication blocks MUST serve a real publication register

`federatedSearch` and `publicationDetail` SHALL read the WOO publication
register, with facets and paging, rather than demo rows. A publication that
does not exist SHALL answer identically to one the caller may not see, so the
endpoint is not an existence oracle.

#### Scenario: Search returns real publications with facets

- **GIVEN** a portal whose search block is pointed at the publication register
- **WHEN** a visitor searches
- **THEN** results come from that register, facets reflect the matched set, and
  paging is exercised against a recorded row count rather than a fixture handful

#### Scenario: An absent publication reveals nothing

- **GIVEN** a publication id that does not exist
- **WHEN** an anonymous visitor requests its detail page
- **THEN** the response is indistinguishable from one for a publication the
  visitor may not see

### Requirement: The WOO public surface MUST be authored as portal content

The home, themes, search and publication surfaces SHALL exist as `page` rows on
a `portal`, with grid bodies composed only of blocks already in the public
catalogue, and navigation SHALL be a `menu` row. No new renderer and no new
block type may be introduced to express them.

#### Scenario: An editor changes the navigation without a deploy

- **GIVEN** the authored portal is serving
- **WHEN** an editor changes the menu row
- **THEN** the site navigation changes with no rebuild and no release

#### Scenario: Every block used is already in the catalogue

- **GIVEN** the authored pages
- **WHEN** their widget keys are compared against the public block catalogue
- **THEN** every key is present, and the comparison is asserted rather than
  reviewed by eye

### Requirement: The bundled auth and forms screens MUST NOT be reimplemented as content

Sign-in SHALL enter the existing OIDC edge (DigiD, eHerkenning, eIDAS). The
authenticated area SHALL resolve to the portal and its contribution aggregate.
Each bundled `/forms/*` route SHALL be expressed as a portal-contribution
action or recorded as out of scope with a stated reason.

#### Scenario: Sign-in uses the auth edge, not a bundled screen

- **GIVEN** a visitor on the authored portal
- **WHEN** they sign in
- **THEN** they enter the configured OIDC provider, and no bundled login,
  registration or password-reminder screen exists anywhere in the surface

#### Scenario: No form route is dropped in silence

- **GIVEN** the set of `/forms/*` routes the bundle served
- **WHEN** the authored portal is compared against it
- **THEN** each route is either a contribution action or carries a written
  out-of-scope reason, and the check enumerates the set rather than sampling it

### Requirement: The authored portal MUST answer on the paths the bundle answered on

Every path the bundle served that carries a public surface SHALL resolve to the
authored portal, or redirect to where that surface now lives. No previously
served public path may silently 404.

#### Scenario: A previously served path still resolves

- **GIVEN** the enumerated set of public paths the bundle served
- **WHEN** each is requested against the authored portal
- **THEN** each renders or redirects, and none 404s

### Requirement: Parity MUST be demonstrated before the bundle is removed

An end-to-end test SHALL walk the authored portal's home, themes, search and a
publication detail and assert each renders its expected blocks with real
content. It SHALL fail, never skip, when the portal content is absent.

#### Scenario: Absent content fails the parity test

- **GIVEN** the authored portal content has not been provisioned
- **WHEN** the parity spec runs
- **THEN** it fails and names what is missing, rather than skipping

### Requirement: The vendored bundle MUST leave the repository

`woo/`, `WooController` and the `woo#serve` and `woo#servePath` routes SHALL be
removed once parity is demonstrated. Tests asserting the retired surface SHALL
be inverted rather than deleted, so a silent return of the bundle is caught.

#### Scenario: The retired surface stays retired

- **GIVEN** the bundle has been removed
- **WHEN** the suite runs
- **THEN** a test asserts the `/woo` routes no longer resolve, rather than
  having been deleted along with the surface
