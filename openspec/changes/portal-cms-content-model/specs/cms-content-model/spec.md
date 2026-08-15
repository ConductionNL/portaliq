# cms-content-model Delta: portal-cms-content-model

**Status**: in-progress
**Scope**: openregister
**OpenSpec changes**:

- [portal-cms-content-model](../../)

## Purpose

Declares the `cms` register — `website`, `menu`, `page`, `glossaryTerm`,
`media` — as the canonical model for the fleet's public content. Preserves the
shapes OpenCatalogi already writes, adds the `website` scope multi-site needs,
and adds the grid-or-markdown page body. Related: ADR-086 (Portaliq is the
fleet's headless CMS), ADR-022 / ADR-070 (OR-backed persistence), ADR-011
(schema property standards), and the cross-app delta
`hydra/openspec/changes/portaliq-phase-two/specs/portaliq-cms/spec.md`.

## ADDED Requirements

### Requirement: The register MUST declare a website as the scope for all content

The `cms` register SHALL declare a `website` schema carrying: display name,
logo reference, a themiq theme reference, `domains[]` (each with its hostname
and verification state), authentication configuration, supported locales, and
publication status. `menu`, `page`, `glossaryTerm` and `media` SHALL each carry
a required reference to exactly one `website`.

#### Scenario: A content object cannot be created without a website

- **GIVEN** a `page` object with no `website` reference
- **WHEN** it is written
- **THEN** the write is rejected by schema validation

#### Scenario: A website declares everything the portal needs to render itself

- **GIVEN** a `website` object
- **WHEN** it is read
- **THEN** it carries name, logo, theme reference, domains, authentication
  configuration, locales and status — the full set the unimplemented
  white-label requirement enumerated

### Requirement: The menu shape MUST be preserved exactly as deployed

The `menu` schema SHALL declare the shape currently in production: a titled,
positioned menu holding `items[]`, each with `order`, `name`, `link`,
optional `description`, `icon`, `groups`, `hideBeforeLogin`, `hideAfterLogin`,
and an optional one level of `items[]` beneath it. Nesting deeper than two
levels SHALL be rejected.

#### Scenario: A menu written by OpenCatalogi validates unchanged

- **GIVEN** a menu object created by OpenCatalogi before this change, with
  two-level items
- **WHEN** it is validated against the declared schema
- **THEN** it is valid, with no property renamed, dropped or defaulted away

#### Scenario: Three-level nesting is rejected

- **GIVEN** a menu item whose sub-item declares its own sub-items
- **WHEN** it is written
- **THEN** the write is rejected

### Requirement: A page body MUST be a widget grid or markdown

The `page` schema SHALL declare `body` as an object with a closed
`type: "grid" | "markdown"`. A `grid` body SHALL hold entries conforming to the
canonical manifest-v2 `$defs.widgetEntry` shape, referenced rather than
restated. A `markdown` body SHALL hold markdown source as text.

#### Scenario: A grid body is validated against the canonical widget shape

- **GIVEN** a page whose `grid` body holds an entry missing `gridWidth`
- **WHEN** it is written
- **THEN** the write is rejected naming the missing property

#### Scenario: A markdown body stores source, not rendered output

- **GIVEN** a page with a `markdown` body
- **WHEN** it is read back
- **THEN** the markdown source is returned byte-identical to what was written

#### Scenario: An unknown body type is rejected

- **GIVEN** a page whose body declares `type: "html"`
- **WHEN** it is written
- **THEN** the write is rejected naming the accepted values

### Requirement: The register MUST declare a glossary term

The `cms` register SHALL declare a `glossaryTerm` schema carrying the term, its
definition, optional synonyms, an optional source reference, and optional
relations to other glossary terms, scoped to a website.

#### Scenario: A term relates to another term

- **GIVEN** two glossary terms on one website
- **WHEN** the first declares a relation to the second
- **THEN** the relation resolves through OpenRegister's own relation handling,
  not a string reference

#### Scenario: A term on another website is not a valid relation target

- **GIVEN** two glossary terms on different websites
- **WHEN** one declares a relation to the other
- **THEN** the write is rejected

### Requirement: Existing content MUST be adopted without loss

A repair step SHALL assign a `website` reference to existing `menu`, `page` and
glossary objects that have none. It SHALL be idempotent, SHALL report the
number of objects it changed, and SHALL NOT run as part of app installation.

#### Scenario: The back-fill reports what it did

- **WHEN** the repair step runs
- **THEN** it reports the count of objects assigned a website
- **AND** a run that assigns none is distinguishable from a run that did not
  execute

#### Scenario: Re-running the repair changes nothing

- **GIVEN** the repair step has completed
- **WHEN** it runs again
- **THEN** it reports zero objects changed and modifies nothing

#### Scenario: No content object is left unscoped

- **GIVEN** the repair step has completed
- **WHEN** the `cms` register is queried for objects with no website
- **THEN** the result is empty
