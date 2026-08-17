# Spec: portal-page-composition

## ADDED Requirements

### Requirement: Every region of a portal page MUST be composed from widgets

A page declares `regions` — at least `header`, `hero`, `main`, `aside` and
`footer` — each an ordered widget list carrying the existing grid geometry. No
region may be hard-coded markup in the renderer.

#### Scenario: A portal moves its call-to-action into the header

- **WHEN** an author places a button block in the `header` region
- **THEN** it renders in the header on every page that inherits the region
- **AND** no application code changes

#### Scenario: A portal declares three footer bands

- **WHEN** a footer region carries three band blocks
- **THEN** all three render in the authored order
- **AND** their styling does not depend on being first or last

The footer's two bands are currently selected in CSS by `:first-of-type` and
`:last-of-type:not(:only-of-type)`. Position-as-identity is why a third band is
impossible today, and why adding one silently restyles the others.

### Requirement: `slot` MUST select the region a widget renders in

#### Scenario: A widget names a region

- **WHEN** a widget declares `slot: "header"`
- **THEN** it renders in the header region

#### Scenario: A widget names an unknown region

- **WHEN** a widget declares a `slot` no region provides
- **THEN** the renderer reports it visibly rather than dropping the widget

Every seeded widget already carries `slot`, and nothing reads it: nine widgets
declare `"slot":"body"` and a search for a consumer returns nothing. A field
that is written, stored and ignored is indistinguishable from one that works
until somebody depends on it.

### Requirement: Region defaults MUST live on the portal and be overridable per page

#### Scenario: A page inherits the shell

- **WHEN** a page declares no `header` region
- **THEN** it renders the portal's header

#### Scenario: A page overrides one region

- **WHEN** a landing page declares its own `hero`
- **THEN** that hero renders INSTEAD of the portal default
- **AND** every other region still comes from the portal

#### Scenario: A page suppresses a region

- **WHEN** a page declares a region as explicitly empty
- **THEN** the region renders nothing
- **AND** it is NOT treated as "unset, use the default"

"I said none" and "I did not say" are different statements. Conflating them
makes a page without a footer impossible to author.

### Requirement: The editor MUST render the real blocks on its canvas

The editing canvas mounts the same components the public renderer mounts.

#### Scenario: An author places a block

- **WHEN** a block is added on the canvas
- **THEN** what the author sees is what the public page renders, including its theme tokens

An editor that previews an approximation is how a page ships broken while
looking correct in the tool. This is the single most important property of the
editor and the reason the canvas may not be a bespoke preview.

### Requirement: The editor MUST offer a block library, an inspector and a layer tree

#### Scenario: Inserting a block

- **WHEN** an author opens the block library
- **THEN** blocks are grouped by category with a human name and a description of what each does

#### Scenario: Configuring a block

- **WHEN** a block is selected
- **THEN** an inspector shows its fields, derived from the block's declared configuration rather than hand-written per block

#### Scenario: Understanding structure

- **WHEN** a page has nested or overlapping blocks
- **THEN** a layer tree shows the region → block hierarchy and selecting a node selects the block

This is the layout every comparable tool converged on — Puck, GrapesJS and
Plasmic all pair a categorised library with a canvas and a per-selection
inspector — so it is the one authors have already learned.

### Requirement: The editor MUST support responsive preview and undo

#### Scenario: Checking a phone layout

- **WHEN** an author switches the canvas to a narrow breakpoint
- **THEN** the canvas reflows exactly as the public page does at that width

#### Scenario: Reversing a change

- **WHEN** an author deletes or moves a block
- **THEN** the action can be undone without reloading, and the undo history survives until save

A layout tool without undo is one a cautious author will not use, and a portal
edited by nobody is worse than one edited imperfectly.

### Requirement: An authored page MUST NOT be able to produce an inaccessible result

#### Scenario: Heading levels

- **WHEN** blocks with headings are placed in sequence
- **THEN** the editor surfaces a skipped heading level

#### Scenario: Contrast

- **WHEN** a block is placed on a band whose background it fails against
- **THEN** the editor warns, naming the measured ratio

The grid is deliberately the only layout primitive: freehand positioning is
what makes builder output unusable on a phone and unreadable to a screen
reader. Constraint is the feature.

### Requirement: The reference template MUST be expressible in the region model

`conduction-docs` composes the shipped blocks into the docs.conduction.nl
layout: a white header with logo, navigation and a call-to-action; a hero band
with eyebrow, heading, supporting text and two buttons; a card grid with icons;
a footer of link columns above a legal bar.

#### Scenario: The template renders

- **WHEN** a portal adopts `conduction-docs`
- **THEN** the layout matches the reference structurally, without application code changes

#### Scenario: The template cannot be expressed

- **WHEN** any part of the reference cannot be composed from regions and blocks
- **THEN** the gap is recorded against this spec rather than worked around in CSS

The template is the conformance test for the region model. A model that cannot
express one real design will not express the next one either.
