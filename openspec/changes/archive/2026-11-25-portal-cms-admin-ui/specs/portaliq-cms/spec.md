# portaliq-cms Delta: portal-cms-admin-ui

**Status**: in-progress
**Scope**: portaliq
**OpenSpec changes**:

- [portal-cms-admin-ui](../../)

## Purpose

An editorial surface for the CMS, with publish-time validation that stops the
two failure shapes the rig already produced: a menu item pointing at nothing,
and a site with no front door. Prerequisite for `cms-handover`.

## ADDED Requirements

### Requirement: An editor MUST be able to manage content without the API

Portaliq SHALL provide admin surfaces to create, edit and publish `website`,
`menu`, `page` and `glossaryTerm` objects, built on the shared manifest-driven
admin components rather than hand-rolled views.

#### Scenario: A site is created and published end to end

- **GIVEN** an administrator with no API client
- **WHEN** they create a website, a menu, a markdown page and a glossary term
  through the UI and publish them
- **THEN** the public content API serves them and the site renders

#### Scenario: The surfaces are the shared components

- **WHEN** the admin views are inspected
- **THEN** they use the existing index/detail components over OpenRegister
  objects, and no parallel object store is introduced

### Requirement: Publishing MUST refuse content that would render broken

Publishing SHALL validate that every menu item's in-site link resolves to a
page on the same website, and that a published website has a page at `/`.

#### Scenario: A menu item pointing at nothing is refused

- **GIVEN** a menu item linking to an in-site route with no page
- **WHEN** the menu is published
- **THEN** it is refused, naming the route
- **AND** this is the exact defect the rig produced: `/begrippen` sat in the
  menu with no page behind it, and the only symptom was a console 404 on a
  page that otherwise looked correct

#### Scenario: A drafted target is distinguished from a missing one

- **GIVEN** a menu item pointing at a page that exists but is still a draft
- **WHEN** the menu is published
- **THEN** the editor is warned rather than blocked — an editorial workflow
  routinely links to something not yet released, and refusing it would make
  the validation the obstacle instead of the guard

#### Scenario: A site with no root page is refused

- **GIVEN** a website with no published page at `/`
- **WHEN** it is published
- **THEN** it is refused
- **AND** this prevents a site that 404s on its own front door, which
  `open-venray` currently does

#### Scenario: Duplicate routes within a site are refused

- **GIVEN** two pages on one website with the same route
- **WHEN** the second is saved
- **THEN** it is refused

### Requirement: Domain verification MUST be triggerable from the UI

The admin surface SHALL show the TXT record a tenant must publish, and allow
verification to be run and re-run.

#### Scenario: The record to publish is shown

- **GIVEN** a pending domain
- **WHEN** the administrator views it
- **THEN** the exact record name and value are shown, copyable

#### Scenario: Verification can be retried

- **GIVEN** a verification that failed because DNS had not propagated
- **WHEN** it is run again later
- **THEN** it succeeds without re-adding the domain
