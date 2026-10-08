# portal-page-designer Delta: portal-page-designer

**Status**: in-progress
**Scope**: portaliq
**OpenSpec changes**:

- [portal-page-designer](../../)

## Purpose

Direct-manipulation editing of a portal page's widget grid, reachable from the
site it renders. Extends `openspec/specs/portaliq-cms/spec.md`, which fixes the
grid geometry and the public allow-list this editor writes against. Related:
ADR-022 (writes through OpenRegister), ADR-084 (the public host), ADR-005
(fail-closed).

## ADDED Requirements

### Requirement: A page MUST carry a draft body that is never served publicly

A page SHALL be able to hold unpublished layout work in a `draftBody` separate
from the `body` the content API serves. Publishing SHALL promote the draft to
the body and clear the draft; discarding SHALL clear the draft and leave the
body untouched.

#### Scenario: A saved draft does not change the public page

- **GIVEN** a published page with a grid body
- **WHEN** an editor saves a changed layout as a draft
- **THEN** the public content API still returns the previous body, and the
  response carries no draft in any form

#### Scenario: Publishing promotes the draft

- **GIVEN** a page with a saved draft
- **WHEN** the editor publishes it
- **THEN** the public content API returns the draft's widgets as the page body,
  and the page no longer carries a draft

#### Scenario: Discarding leaves the published page intact

- **GIVEN** a page with a saved draft
- **WHEN** the editor discards it
- **THEN** the page keeps the body it had, and no draft remains

### Requirement: Who may edit pages MUST be configurable and enforced at the write

The groups whose members may create, update and delete portal pages SHALL be
configurable in Portaliq's admin settings. The configured groups SHALL be
written into the `page` schema's authorization block, so the check runs where
the write happens rather than only in the interface that offers it.

#### Scenario: A configured editor may write

- **GIVEN** a user in a configured editor group
- **WHEN** they save a page through OpenRegister's object API
- **THEN** the write succeeds

#### Scenario: An authenticated non-editor is refused

- **GIVEN** an authenticated user in no configured editor group and without
  admin rights
- **WHEN** they attempt to save a page through OpenRegister's object API
- **THEN** the write is refused

#### Scenario: Clearing the setting does not open the schema

- **GIVEN** editor groups are configured
- **WHEN** an administrator clears the setting
- **THEN** page writes are restricted to administrators, not opened to every
  authenticated user

#### Scenario: The read rules survive the write

- **GIVEN** the `page` schema's public read rule for published pages
- **WHEN** the editor groups are saved
- **THEN** anonymous visitors can still read published pages

### Requirement: A CMS read MUST NOT inherit another app's OpenRegister context

A read of this app's content SHALL address its own register and schema in a way
that cannot be captured by state another app left on the shared object service.

#### Scenario: A foreign pending schema does not break a content read

- **GIVEN** an earlier caller in the same request left a schema reference
  pending on the shared object service
- **WHEN** a portal's content is read
- **THEN** the read resolves this app's own schema and succeeds

#### Scenario: A schema owned by another app is never read

- **GIVEN** another app owns a schema with the same slug
- **WHEN** this app resolves that slug
- **THEN** it resolves the schema this app owns, or none at all

### Requirement: The site MUST offer an editing entry point only to a visitor who may edit

The rendered site SHALL show a floating editing control in the bottom-right
corner to a visitor whose session may edit pages, and SHALL show nothing to any
other visitor. The control SHALL open a menu whose actions reach the page
designer for the page being viewed, the page listing, and page creation.

#### Scenario: An anonymous visitor sees no control

- **GIVEN** a visitor with no session
- **WHEN** they open any page of the site
- **THEN** no editing control is present in the document

#### Scenario: An editor sees the control and reaches the designer

- **GIVEN** a signed-in visitor who may edit pages
- **WHEN** they open a page of the site and activate the editing control
- **THEN** a menu offers to edit that page, and choosing it opens the designer
  for the page at that route

#### Scenario: The identity of the page is not disclosed to others

- **GIVEN** a visitor who may not edit
- **WHEN** the editing context for a route is requested
- **THEN** the response states only that editing is unavailable, and names no
  page identifier

### Requirement: A page's widget grid MUST be editable by direct manipulation

The designer SHALL let an editor add a widget to a page, move it, resize it and
remove it on the shared 12-column grid, and SHALL persist the resulting
placements in the canonical widget-entry shape.

#### Scenario: A moved widget keeps its new cell

- **GIVEN** a page open in the designer
- **WHEN** the editor moves a widget to another cell and saves
- **THEN** the stored placement carries the new `gridX`/`gridY`, and reopening
  the designer shows it there

#### Scenario: A widget is added from the palette

- **GIVEN** a page open in the designer
- **WHEN** the editor adds a widget from the palette
- **THEN** the widget is placed on the grid with a valid geometry and its own
  identifier

#### Scenario: The grid may be edited without a pointer

- **GIVEN** a page open in the designer
- **WHEN** the editor moves a widget using the keyboard
- **THEN** the placement changes and the change is announced

### Requirement: The palette MUST mark widgets that cannot render on a public page

The palette SHALL offer the whole shared widget catalogue and SHALL mark every
entry that the public renderer will not mount, stating why, so an editor is not
offered a widget that would render as an inert placeholder without warning.

#### Scenario: A non-public widget is marked

- **GIVEN** a widget the public renderer does not mount
- **WHEN** the editor opens the palette
- **THEN** the entry is shown as unavailable for a public page, with the reason

#### Scenario: A public widget is offered normally

- **GIVEN** a widget the public renderer mounts
- **WHEN** the editor opens the palette
- **THEN** the entry is selectable
