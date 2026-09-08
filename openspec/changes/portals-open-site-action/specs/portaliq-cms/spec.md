# portaliq-cms Delta: portals-open-site-action

**Status**: in-progress
**Scope**: portaliq
**OpenSpec changes**:

- [portals-open-site-action](../../)

## Purpose

The admin surface that lists portals should be able to open one. Portal
resolution already accepts an explicit slug for a caller that does not reach
Portaliq over the site's own hostname; this makes the admin overview such a
caller, so an administrator never has to assemble that URL by hand.

## ADDED Requirements

### Requirement: The Portals overview MUST open a portal's public site

The Portals index page in the Portaliq admin SHALL offer, per portal row, an
action that opens that portal's public site in a new browser tab. The action
SHALL address the site by the row's `slug` through the app's own site route
(`portalPage#site`) resolved with Nextcloud's URL generator, so the link holds
on instances with and without URL rewriting, and the slug SHALL be
percent-encoded. When the row carries no slug, the action SHALL inform the
administrator and open nothing.

#### Scenario: An administrator opens a published portal from the overview

- **GIVEN** an administrator on `/apps/portaliq/portals` with a published
  portal whose slug is `demo`
- **WHEN** they choose "Open portal" in that row's action menu
- **THEN** a new tab opens on the app's site route carrying `?portal=demo`
- **AND** the site renders that portal's own title

#### Scenario: A slug that needs escaping stays intact

- **GIVEN** a portal row whose slug contains a character that is unsafe in a
  query string
- **WHEN** the action builds the site URL
- **THEN** the slug is percent-encoded in the `portal` parameter, so the site
  resolves the portal the row names and no other

#### Scenario: A portal without a slug reports instead of linking

- **GIVEN** a portal row whose `slug` is empty or absent
- **WHEN** the administrator chooses "Open portal"
- **THEN** no tab is opened
- **AND** the administrator is told the portal has no slug yet

## Non-Functional Requirements

- **Performance:** No additional network request. The action reads the slug off
  the row already rendered and builds the URL client-side.
- **Accessibility:** The entry is a standard `NcActionButton` in the existing
  row menu, so it inherits the shared component's keyboard and screen-reader
  behaviour; it carries a text label, not an icon alone (WCAG 2.2 AA 4.1.2).
- **Internationalization:** Dutch and English MUST be supported (ADR-005) for
  both the action label and the no-slug message.

## Acceptance Criteria

- Every row on `/apps/portaliq/portals` offers "Open portal" in its action menu.
- The opened URL is the app's site route plus `?portal=<row slug>`, correct on
  an instance without URL rewriting (`/index.php/apps/portaliq/site?...`).
- A slug needing escaping arrives percent-encoded.
- A row without a slug produces a message and no navigation.
- The pre-existing View / Edit / Delete row actions are unchanged.
- The manifest still validates against the v2 manifest schema.

## Notes

- Draft portals keep the action; the public site answers with its own
  not-found page. A row-level visibility predicate does not exist in the
  manifest grammar for index actions today — deferred deliberately, see the
  proposal's Out of Scope.
- The action targets the built-in site renderer (ADR-084), not the account
  portal SPA (`portalPage#index`), which has no slug parameter.
- Related: the portal-resolution requirement in this capability's main spec,
  which is what makes an explicit slug a supported entry point.
