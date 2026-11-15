# portal-shared-runtime Delta: portal-shared-runtime

**Status**: in-progress
**Scope**: portaliq
**OpenSpec changes**:

- [portal-shared-runtime](../../)

## Purpose

Replaces Portaliq's bespoke React portal with the shared manifest runtime, so
portal pages get the grid, the communal widget catalog, markdown pages and
journeys. Implements the Portaliq half of ADR-084. Related: ADR-071 (nc-vue
owns the runtime), ADR-036 (widget manifest), ADR-046 (contribution contract).

## ADDED Requirements

### Requirement: The portal MUST boot the shared runtime and ship no React

The public portal SHALL boot through `bootstrapCnApp({ host: 'public' })`.
`src/portal/*.jsx`, `webpack.portal.js`, `react` and `react-dom` SHALL be
removed.

#### Scenario: No React remains

- **GIVEN** the repository after this change
- **WHEN** it is searched for `.jsx`/`.tsx` sources and React dependencies
- **THEN** none is present
- **AND** a gate fails on any future addition

#### Scenario: One build output, one config

- **GIVEN** the build after this change
- **WHEN** an admin-only rebuild runs
- **THEN** the portal bundle is unaffected — the `output.clean` keep-guard is
  no longer needed because there is no second config to race

#### Scenario: The portal renders at a public origin

- **GIVEN** the portal served from its own origin with no Nextcloud globals
- **WHEN** a visitor loads a site
- **THEN** its pages render

### Requirement: A portal page MUST be a manifest-v2 page

Portal pages SHALL be described as manifest-v2 pages, and the aggregation and
normalisation path SHALL produce that shape. Every manifest page type SHALL be
available to a portal page.

#### Scenario: A contributed page renders through the shared renderer

- **GIVEN** a contribution from procest, pipelinq or shillinq
- **WHEN** its page is served
- **THEN** it renders through `CnPageRenderer`, with no provider signature
  changed

#### Scenario: A markdown page renders through the existing wiki path

- **GIVEN** a page with a markdown body
- **WHEN** it is served
- **THEN** it renders through the existing markdown path, not a portal-local
  renderer

### Requirement: Portal pages MUST use the manifest grid unchanged

Widget placement SHALL use `$defs.widgetEntry` on any page type, with the same
12-column geometry and the same runtime overflow check.

#### Scenario: A page authored in OpenBuild renders identically here

- **GIVEN** a page whose widgets were authored in OpenBuild's Page Designer
- **WHEN** it is served as a portal page
- **THEN** each widget occupies the same cell

#### Scenario: An overflowing placement fails validation with the canonical message

- **GIVEN** a portal page widget with `gridX: 9, gridWidth: 6`
- **WHEN** the manifest is validated
- **THEN** it fails with the existing message naming the widget, `gridX` and
  `gridWidth`

### Requirement: The portal MUST consume the communal widget catalog, filtered

Portaliq SHALL read `dashboardWidgetRegistry` and render only entries with
`public: true`. It MAY register host overrides; it SHALL NOT maintain its own
catalog of widget types.

#### Scenario: A new library widget appears without a Portaliq change

- **GIVEN** a widget newly registered in nc-vue with `public: true`
- **WHEN** a portal page places it
- **THEN** it renders, with no change to Portaliq

#### Scenario: A non-public widget renders inert

- **GIVEN** a portal page placing a widget that is not public
- **WHEN** it renders
- **THEN** a placeholder renders and the widget's code does not execute

#### Scenario: A forked catalog is refused

- **GIVEN** Portaliq source enumerating widget types of its own
- **WHEN** the gate runs
- **THEN** it fails

### Requirement: Journeys MUST mount in-page

A portal page SHALL be able to mount `CnJourney` in the page, for anonymous and
authenticated journeys alike.

#### Scenario: An anonymous journey completes in the portal

- **GIVEN** a journey declaring `access: anonymous`
- **WHEN** an unauthenticated visitor completes it
- **THEN** the declared objects are written, with no ownership stamped

#### Scenario: The journey renderer is the library's

- **GIVEN** a journey rendered in the portal
- **WHEN** its step sequence, validation and submit behaviour are compared with
  the same journey in a nc-vue modal
- **THEN** they are identical

### Requirement: The portal MUST hold a first-load budget measured on transferred bytes

The build SHALL assert a first-load transfer budget as a failure. Widget code
and `CnJourney` SHALL be route-split.

#### Scenario: Exceeding the budget fails the build

- **GIVEN** a change pushing first-load transfer over the budget
- **WHEN** CI runs
- **THEN** the build fails, naming the responsible assets

#### Scenario: The budget measures what a visitor pays

- **GIVEN** a build whose emitted size differs from what a browser transfers
- **WHEN** the budget is evaluated
- **THEN** the transferred bytes are compared, on a first visit with an empty
  cache

### Requirement: Parity with the React portal MUST be measured, not asserted

Each surface the React portal served SHALL be compared against its replacement
before the React source is removed.

#### Scenario: A surface is compared before its predecessor is deleted

- **GIVEN** a portal surface served by both implementations
- **WHEN** parity is assessed
- **THEN** the comparison covers the rendered content and the requests issued
- **AND** the result is recorded in this change, because a page that renders is
  not a page that matches
