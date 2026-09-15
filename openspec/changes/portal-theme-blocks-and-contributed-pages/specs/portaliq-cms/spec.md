---
status: proposed
---

# Delta: portaliq-cms

**Change:** `portal-theme-blocks-and-contributed-pages`
**Scope:** portaliq, the public site renderer and its content contract
**Depends on:** `nldesign-theme-integration` (theme adoption), `portal-page-designer` (the designer that must not lose regions), `portal-contribution-contract` (the pages a contribution declares)

## Purpose

Make a portal's header, hero and footer blocks in regions, paint them only
from thematiq tokens, and give contributed pages a route. The reference
implementation is branch `feat/portal-nextcloud-signin`. `design.md` maps each
requirement to the commits it came from.

## ADDED Requirements

### Requirement: Every surface the site paints MUST read a theme token (REQ-PTB-001)

The site SHALL link thematiq's public bridge, then the portal's token set and
its ancestors, then its own `css/site-theme.css`, in that order. Every colour a
portaliq stylesheet declares SHALL be a `var()` whose last fallback is another
token or a CSS keyword (`inherit`, `currentColor`, `transparent`), never a
colour literal. A band that paints its own background SHALL also name the token
for the text on it.

#### Scenario: One token repaints every surface

- **GIVEN** a rendered portal
- **WHEN** only `--utrecht-document-background-color` is changed on `:root`
- **THEN** the page, its container, both header bars, the primary navigation
  and the category cards all compute the new colour

#### Scenario: A colour literal in a portaliq stylesheet is refused

- **GIVEN** a stylesheet under `css/` that is not vendored design system CSS
- **WHEN** it declares `#fff`, `rgb(…)` or a named colour outside a token chain
- **THEN** the style check fails and names the file and line

#### Scenario: A band names its own foreground

- **GIVEN** a theme whose hero band paints itself dark
- **WHEN** the hero renders
- **THEN** its title and lead take `--nldesign-hero-title-color` and
  `--nldesign-hero-body-color`, and neither inherits the browser's black

### Requirement: A portal MUST be able to override its theme's tokens safely (REQ-PTB-002)

A portal SHALL carry optional `tokens`, a map of token name to value, rendered
as a `:root` block after its theme. A name SHALL belong to a themed family
(`nldesign`, `utrecht`, `tilburg`, `conduction`, `ams`, `c`). A value SHALL
contain only the characters a token value needs and SHALL NOT contain `url(`,
`expression`, `javascript:`, `data:`, `@import` or a backslash. A declaration
that fails either rule SHALL be dropped, not escaped.

#### Scenario: A valid override paints

- **GIVEN** a portal on the `conduction-new` set with
  `tokens: {"--nldesign-header-link-color": "#f36c21"}`
- **WHEN** the site renders
- **THEN** the header's navigation links compute that colour and every other
  token keeps the set's value

#### Scenario: A value that closes the rule is dropped

- **GIVEN** a portal token whose value is `#111; } body { display:none } .x {`
- **WHEN** the site renders
- **THEN** the served CSS contains no `display:none` and the body renders

#### Scenario: An unknown token family is dropped

- **GIVEN** a portal token named `--evil-background`
- **WHEN** the site renders
- **THEN** no declaration for it is served

### Requirement: A theme that extends another MUST load its parent first (REQ-PTB-003)

When a token set declares `extends` in thematiq's catalogue, the site SHALL link
the parent before the child, so the child's declarations win on order. It SHALL
follow at most four `extends` hops and SHALL stop at a set already in the chain.

#### Scenario: The child wins a shared token

- **GIVEN** `frankendesk` extends `lasuite` and both declare
  `--nldesign-color-primary`
- **WHEN** a portal on `frankendesk` renders
- **THEN** the computed primary colour is `frankendesk`'s

#### Scenario: A cycle does not hang the page

- **GIVEN** two sets that extend each other
- **WHEN** a portal on either renders
- **THEN** each set is linked once and the page renders

### Requirement: The header MUST be a block whose shape the portal chooses (REQ-PTB-004)

The header SHALL render through a `brandHeader` block in the `header` region.
A portal SHALL choose `headerVariant` `double` (title bar above a navigation
bar) or `single` (logo, navigation and sign-in on one bar). An unknown or
missing value SHALL render `double`. The navigation SHALL render in one place
per variant. The site name SHALL NOT be a heading. A register control SHALL
render only when the portal declares a register destination.

#### Scenario: An existing portal keeps its header

- **GIVEN** a portal with no `headerVariant` and no `regions`
- **WHEN** it renders
- **THEN** its header markup matches the hard-coded header on `development`
  before this change, apart from Vue's scoped-style attributes

#### Scenario: A single-bar header lists each link once

- **GIVEN** a portal with `headerVariant: "single"`
- **WHEN** it renders
- **THEN** the accessibility tree holds exactly one "Home" link

#### Scenario: No register destination, no register control

- **GIVEN** a portal whose auth settings name no register destination
- **WHEN** it renders
- **THEN** only the sign-in control appears

### Requirement: The footer MUST be a block whose bands are styled by role (REQ-PTB-005)

The footer SHALL render through a `footerColumns` block in the `footer` region:
a brand column, link columns, and a legal bar with colophon, legal links and
badges. Each band SHALL carry a role class, and no portaliq rule SHALL select a
band by its position. The content contract SHALL project `portal.footer` onto
named keys only, and SHALL drop a social link, legal link or badge that lacks a
label or a destination. An empty colophon SHALL fall back to the portal title.

#### Scenario: A third band changes nothing about the first two

- **GIVEN** a footer with a content band and a legal band
- **WHEN** a third band is appended
- **THEN** the first two compute the same background, padding, font and colour
  as before

#### Scenario: A link without a destination is dropped

- **GIVEN** `portal.footer.socials` holds an entry with a label and no href
- **WHEN** the content API serves the site
- **THEN** that entry is absent from the response

#### Scenario: The legal bar always names someone

- **GIVEN** a portal with no `footer.colophon`
- **WHEN** it renders
- **THEN** the legal bar shows the portal title

### Requirement: The hero MUST cap its calls to action and keep one outline entry (REQ-PTB-006)

The `hero` block SHALL render an optional eyebrow as a paragraph, a heading, a
lead, and at most two actions. Actions SHALL be links, and an action without a
label or a destination SHALL be dropped. A heading icon and an illustration
SHALL be `aria-hidden`. A full-bleed band that splits the grid SHALL NOT leave
empty grid rows above or below it.

#### Scenario: A third action is not rendered

- **GIVEN** a hero with four actions
- **WHEN** it renders
- **THEN** exactly the first two appear, as links

#### Scenario: The eyebrow is not a heading

- **GIVEN** a hero with an eyebrow "Diensten" and a title
- **WHEN** a screen reader lists the headings
- **THEN** only the title is listed for the hero

#### Scenario: A band leaves no hole in the grid

- **GIVEN** a page with a hero band at `gridY` 0 and markdown at `gridY` 4
- **WHEN** it renders
- **THEN** the markdown starts directly below the band, with no empty row between

### Requirement: Blocks MUST take their data as props and nothing else (REQ-PTB-007)

A shell block SHALL receive every visible string as a prop, SHALL emit
navigation and sign-out as events, and SHALL NOT call a translation function or
a router. Before any block mounts, the renderer SHALL remove `style` and `class`
from authored props.

#### Scenario: Authored style never reaches the page

- **GIVEN** a widget whose stored props include
  `style: "position:absolute;top:0"` and `class: "evil"`
- **WHEN** the page renders
- **THEN** no element carries that style or that class

#### Scenario: A block mounts without Nextcloud

- **GIVEN** the header block
- **WHEN** it is mounted at a public origin with no Nextcloud globals
- **THEN** it renders its default strings and emits `navigate` on a link click

### Requirement: A widget's slot MUST select one of five regions (REQ-PTB-008)

A page SHALL have exactly five regions: `header`, `hero`, `main`, `aside` and
`footer`. A widget's `slot` SHALL name its region, and `body` or an empty slot
SHALL mean `main`. The content contract SHALL serve `body.regions` beside the
existing `body.widgets`, and SHALL list a slot that names no region in
`body.unknownRegions` instead of dropping the widget silently.

#### Scenario: Existing pages need no migration

- **GIVEN** a stored page whose widgets all carry `slot: "body"`
- **WHEN** the content API serves it
- **THEN** every widget is in `body.regions.main` and `body.widgets` is unchanged

#### Scenario: A misspelt region is reported

- **GIVEN** a widget with `slot: "heder"`
- **WHEN** the content API serves the page
- **THEN** `body.unknownRegions` contains `heder`

### Requirement: Regions MUST resolve page first, then portal, then default (REQ-PTB-009)

For each region the renderer SHALL use the page's widgets when the page fills
it, the portal's `regions` entry when the page does not, and the built-in
default otherwise. A page SHALL be able to clear a region by naming it in
`body.clearedRegions`, and a cleared region SHALL render nothing. Resolution
SHALL test for a key's presence, never for an empty value.

#### Scenario: A page inherits the portal header

- **GIVEN** a portal whose `regions.header` holds a brand header
- **WHEN** a page with no header widgets renders
- **THEN** it shows the portal's header

#### Scenario: A page replaces one region

- **GIVEN** a portal hero and a landing page with its own hero widget
- **WHEN** the landing page renders
- **THEN** exactly one hero and one `h1` appear, and they are the page's

#### Scenario: A page clears a region

- **GIVEN** a portal with a hero and a page with
  `clearedRegions: ["hero"]`
- **WHEN** that page renders
- **THEN** no hero renders on it and every other page still shows the portal's

### Requirement: The page designer MUST preserve regions it does not edit (REQ-PTB-010)

The designer SHALL show and edit only the widgets of the `main` region. Saving
a draft, publishing and discarding SHALL write every widget outside `main`, and
`clearedRegions`, back unchanged.

#### Scenario: Publishing a layout keeps the page's hero

- **GIVEN** a page with a hero widget and three `main` widgets
- **WHEN** an editor moves a `main` widget and publishes
- **THEN** the served page still has the hero widget with its props and
  geometry, and the moved widget has its new position

### Requirement: A contributed page MUST be reachable at its own route (REQ-PTB-011)

The site SHALL route `/diensten/{app}/{page}` to the page that contribution
declares, resolved from the contributions already loaded for the portal. It
SHALL render `richText` blocks through the site's markdown block and `action`
blocks as forms. An index entry SHALL be a link only when its page exists. An
unknown app or page SHALL show the site's not-found state.

#### Scenario: An index entry opens its page

- **GIVEN** a contribution that declares a page with a `richText` and an
  `action` block
- **WHEN** a visitor follows its entry in the contributions block
- **THEN** the URL is `/diensten/{app}/{page}` and both blocks render

#### Scenario: An entry without a page stays text

- **GIVEN** a contribution entry with no declared page
- **WHEN** the contributions block renders
- **THEN** the entry is not a link

#### Scenario: An unknown page is not found

- **GIVEN** no contribution declares page `onbekend`
- **WHEN** a visitor opens `/diensten/{app}/onbekend`
- **THEN** the not-found state renders with `data-portaliq-status="404"`

### Requirement: A contributed action MUST post where its type is honoured (REQ-PTB-012)

An action form SHALL send only the fields its manifest declares. A `create`
action SHALL post to `POST /portal/api/collections/{register}/{schema}`, and
every other type SHALL post to the endpoint-forward route. A form SHALL render
for a signed-out visitor only when the action is `type: create` and
`anonymous: true`; otherwise it SHALL show that sign-in is needed. An anonymous
create SHALL be stored with the system identity as owner, even when the browser
holds a Nextcloud session.

#### Scenario: An anonymous report is sent without a session

- **GIVEN** a signed-out visitor on a page with a `create` action marked
  `anonymous: true`
- **WHEN** they submit the form
- **THEN** one request goes to the collection route with no `Authorization`
  header, and the object is created

#### Scenario: The flag on another type raises no form

- **GIVEN** an action of another type marked `anonymous: true`
- **WHEN** a signed-out visitor opens the page
- **THEN** the page shows that sign-in is needed and no form

#### Scenario: A Nextcloud session does not claim an anonymous report

- **GIVEN** a browser signed in to Nextcloud as `admin`
- **WHEN** it submits the anonymous create
- **THEN** the stored object's `_owner` is not `admin`

### Requirement: The chrome MUST meet WCAG 2.1 AA on what is actually painted (REQ-PTB-013)

Every text node in the header, hero, cards, footer and contributed pages SHALL
reach 4.5:1, or 3:1 for large text, against the first ancestor that paints a
background, with translucent colours composited first. Each page SHALL have
exactly one `h1`, SHALL NOT skip a heading level downwards, and SHALL NOT
scroll horizontally at 390px. The check SHALL prove it can fail before it
reports a pass.

#### Scenario: The check refuses to pass blind

- **GIVEN** the surface check starting a run
- **WHEN** its injected low-contrast text, second `h1` and 3000px element are
  not all detected
- **THEN** the run exits non-zero without judging any page

#### Scenario: A skipped heading level is named

- **GIVEN** a page that renders an `h1` and then card headings at `h3`
- **WHEN** the surface check runs
- **THEN** it fails naming the jump and the heading text
