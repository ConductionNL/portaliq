---
status: proposed
---

# Spec: portal-shared-page-blocks

## Purpose

The portals of one organisation share page blocks instead of repeating them.
An editor changes a block once and every portal that places it shows the
change. From portaliq matrix row `dem-rm-shared-design-library`.

## ADDED Requirements

### Requirement: An organisation keeps shared blocks (REQ-SPB-001)

Portaliq SHALL store a shared block as an object that belongs to one
organisation and to no portal, with a title, a publication status and a grid
of widgets in the same shape as a page's grid body.

#### Scenario: An editor creates a shared block
- **GIVEN** an editor of the organisation `gemeente-voorbeeld`, which owns two portals
- **WHEN** the editor opens Shared blocks in the portaliq admin and creates "Contact and opening hours" with one text widget
- **THEN** the block is listed under Shared blocks with status draft and no portal
- @e2e exclude covered by tests/e2e/site-shared-page-blocks.spec.ts once T05 lands

### Requirement: A page shows a shared block it places (REQ-SPB-002)

When a published page places a shared block, the content API SHALL return
the placement with the block's published widgets in `props.widgets`, and the
public site SHALL render them inside the placement's grid cell.

#### Scenario: A resident sees the block on two portals
- **GIVEN** the published block "Contact and opening hours" placed on the home page of both portals of `gemeente-voorbeeld`
- **WHEN** a resident opens the home page of each portal
- **THEN** both pages show the block's text in the placement's cell

#### Scenario: One edit shows everywhere
- **GIVEN** the same two portals
- **WHEN** the editor changes the opening hours in the block and publishes it
- **THEN** the next read of each home page shows the new hours

### Requirement: A block never crosses its organisation (REQ-SPB-003)

A placement SHALL expand only when the block is published and belongs to the
organisation of the portal being served. Otherwise it SHALL expand to no
widgets, and the answer for a foreign, missing or unpublished block SHALL be
the same.

#### Scenario: Another organisation's block renders nothing
- **GIVEN** a page of a portal of `gemeente-noord` whose body names a block of `gemeente-voorbeeld`
- **WHEN** a visitor opens that page through the content API
- **THEN** the placement carries no widgets and is marked unavailable, exactly as for a block that does not exist
- @e2e exclude refusal at the read seam; pinned by CmsReaderTest

### Requirement: An edit to a block clears the cached pages of its organisation (REQ-SPB-004)

A write to a shared block SHALL clear the cached content of every portal of
the block's organisation, and SHALL never fail the write when clearing fails.

#### Scenario: A cached page does not keep the old block
- **GIVEN** a cached home page that places a block
- **WHEN** the editor publishes a new version of the block
- **THEN** the cache of both portals of the organisation is cleared and the next read carries the new version
- @e2e exclude cache seam; pinned by CmsCacheInvalidationListenerTest

### Requirement: Editors place and edit blocks in the designer (REQ-SPB-005)

The page designer SHALL offer "Shared block" in its palette, listing the
published blocks of the portal's organisation, and SHALL edit a block's grid
the same way it edits a page. A block's detail page SHALL list the pages that
place it.

#### Scenario: An editor places a block on a page
- **GIVEN** an editor in the layout designer of the home page of a portal of `gemeente-voorbeeld`
- **WHEN** the editor chooses Shared block in the palette and picks "Contact and opening hours"
- **THEN** the page's draft carries a placement of that block, and the block's detail page lists the home page once the draft is published

### Requirement: Page editors are block editors (REQ-SPB-006)

The groups allowed to edit pages SHALL be the groups allowed to create, edit
and delete shared blocks, and no one else outside the administrators.

#### Scenario: A communication officer edits a block
- **GIVEN** the group `communicatie` set as page editors in the portaliq settings
- **WHEN** a member of `communicatie` saves a change to a shared block
- **THEN** the save succeeds, and the same save by a user outside that group and not an administrator is refused
- @e2e exclude authorization seam; pinned by PageEditorServiceTest
