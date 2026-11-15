# Spec: nldesign-theme-integration

## ADDED Requirements

### Requirement: A portal MUST adopt a token set that exists

A portal's `theme` MUST resolve against the `nldesign` catalogue of installed
token sets. An id absent from the catalogue MUST resolve to nothing.

#### Scenario: An unknown theme renders unstyled rather than branded

- **WHEN** a portal declares a `theme` no installed token set provides
- **THEN** the portal renders with no token layer
- **AND** it MUST NOT fall back to another token set

A portal wearing another municipality's colours looks correct in every
screenshot and is wrong in the only way that matters.

### Requirement: A portal MUST paint its surfaces from tokens before it serves a dark variant

This requirement replaces "a portal MUST serve the generated dark variant when
one exists", which was written, implemented and then **measured**. Linking the
variant is one line, and both versions of the artefact were rendered on a live
portal:

| artefact | result |
| --- | --- |
| as generated then | **0 of 1,152,000 pixels changed** — it rewrote `--nldesign-color-*` and the site is painted from `--utrecht-*` |
| after `nldesign` was fixed to darken those too | **53% of pixels changed** and **10 of 11 text nodes fell below 4.5:1** — `#e5e5e5` headings on white bands, ratio **1.26** |

The cause is upstream of the theme app: this site has no token-driven surface
layer. Its bands paint their own backgrounds, and the page itself is unpainted
— the white is the browser canvas, with no rule setting it. Painting `body`
from `--utrecht-document-*` was tried and verified harmless (0 pixels changed
in light mode) and is **not sufficient on its own**: the inner bands stayed
white.

#### Scenario: The surfaces read their tokens

- **WHEN** every band, card and page surface derives its background from the adopted token set
- **THEN** a portal MAY link `css/tokens/dark/<id>.css` after the light layer
- **AND** the dark rendering MUST be measured for contrast before it ships,
  because a half-darkened page is worse than an undarkened one

#### Scenario: The surfaces do not read their tokens

- **WHEN** any painted surface takes its background from something other than the token set
- **THEN** the portal MUST NOT link the dark variant
- **AND** the refusal MUST carry the measurement, so it is not mistaken for an oversight

A dark mode that darkens text and not the surfaces behind it is not an
incomplete feature; it is an unreadable public government page.

### Requirement: An adopted theme MUST be contrast-checked against the surfaces the portal paints

The check MUST compare each text colour against the first ancestor that
actually PAINTS a background, not against the nearest named band.

#### Scenario: A token set fails AA on a painted surface

- **WHEN** an adopted set produces text below AA on a band the portal paints
- **THEN** the verdict is recorded and surfaced to whoever adopts it

#### Scenario: The probe measures the real backdrop

- **WHEN** an element sits on a descendant surface that paints its own background
- **THEN** the ratio is computed against THAT surface

Measured against the nearest named band instead, this check reported a passing
label as a 1.06 failure — and the fix for the phantom failure made a working
search form invisible.

### Requirement: A shared token set MUST be validated before it can style a portal

A token set shared through OpenRegister MUST pass `CustomTokenSetValidator` before a portal may adopt it.

#### Scenario: A shared set carries a hostile declaration

- **WHEN** a token set shared through OpenRegister is adopted by a portal
- **THEN** it passes `CustomTokenSetValidator` first
- **AND** a set that fails is refused with a visible reason
- @e2e exclude pinned by PortalCustomThemeSetsTest::testAHostileDeclarationIsRefusedByName and PortalThemeChoiceTest::testCustomSetsAreOfferedAndAHostileOneIsRefusedVisibly

### Requirement: Portaliq MUST NOT ship design tokens or a theming mechanism

Portaliq MUST NOT define `--utrecht-*` or `--tilburg-*` tokens of its own; they come from `nldesign`.

#### Scenario: The app is inspected for tokens

- **WHEN** the repository is searched for `--utrecht-*` or `--tilburg-*` definitions
- **THEN** none are found outside vendored third-party component CSS

`nldesign` is the single source. Two derivations of one token set drifted apart
once already, with ZERO tokens in common between the halves.

<!-- Sibling requirements added 2026-09-14 by portal-theme-blocks-and-contributed-pages.
     They record decisions built and measured on branch feat/portal-nextcloud-signin,
     which never merged. The requirements above are unchanged in substance. -->

### Requirement: A shared theme MUST be a copy on this instance, not a link to its source

A token set shared from another instance MUST reach a portal as the theme
app's own custom set on this instance: the theme app's shareable config type
imports it as one, and the portal lists it with every other custom set. A
portal MUST keep rendering what it adopted when the source instance changes or
withdraws the set. Only a deletion on this instance ends it, and the portal's
House style widget then says so.

#### Scenario: The source withdraws the set

- **GIVEN** a portal wearing a set the theme app imported from another instance
- **WHEN** that instance withdraws the set
- **THEN** the portal renders exactly as before
- @e2e exclude Needs two instances sharing through OpenRegister; the copy is the theme app's custom set file, which the source cannot reach

#### Scenario: The set is deleted on this instance

- **GIVEN** a portal wearing a custom set
- **WHEN** an administrator deletes the set in the theme app
- **THEN** the portal shows without a house style
- **AND** its House style widget says the theme app no longer offers the set
- @e2e exclude pinned by PortalThemeChoiceTest::testATypedThemeTheAppDoesNotOfferIsNamedAsNotResolving

A link to the source would let another instance change or remove what a live
government portal looks like, at a moment nobody at that portal chose. The
branch design (19fbcd6, `PortalSharedTheme::adopt()`) copied the bundle into
the portal record; built 2026-09-29 the copy is the theme app's own, so a set
is made, edited, shared and checked in one place.

#### Scenario: The validator is unavailable

- **GIVEN** a custom set and no reachable `CustomTokenSetValidator`
- **WHEN** a portal would link the set
- **THEN** nothing is linked and the House style widget lists the set with the reason
- @e2e exclude pinned by PortalCustomThemeSetsTest::testWithoutTheValidatorNothingIsLinked

### Requirement: A contrast verdict MUST say when nothing was measured

A theme's contrast verdict MUST carry the number of pairs it measured. A set
with zero measured pairs MUST report "not checked", never a pass.

#### Scenario: A set declares none of the surface tokens

- **GIVEN** a token set that declares no token for any surface the portal paints
- **WHEN** its verdict is computed
- **THEN** the verdict shows "not checked" with a measured count of zero

The branch's first verdict reported 46 of 46 sets passing while 43 had zero
pairs compared (09e6ffe). A pass and an absent measurement looked identical.

### Requirement: The theme catalogue MUST be a choice, and never public

The portal's own page MUST list the adoptable token sets with their names and
verdicts, so `portal.theme` is picked rather than typed. The list is served by
`GET /api/portals/{slug}/theme` and saved by `PUT` on the same route; both
MUST be admin-only and MUST NOT be a public page. A set the resolver would not
render MUST be refused on save, and a set whose verdict has findings MUST be
saved only after the administrator confirms, with the findings shown.

Design fixed while building (2026-09-29): the picker is a widget on the
portal's page rather than a section in the admin settings, next to the other
per-portal choices (case types), and its route names the portal. The earlier
`GET /api/themes` in admin settings was the unmerged branch's shape (09e6ffe).

#### Scenario: An administrator picks a theme

- **GIVEN** an administrator on a portal's page
- **WHEN** they open the House style widget
- **THEN** every adoptable set is listed with its verdict: Readable, Hard to read, or Not checked

#### Scenario: A hard-to-read set asks before it saves

- **GIVEN** a set whose text token fails AA on the page or footer surface
- **WHEN** the administrator saves it
- **THEN** the save is refused with the failing tokens and their ratios, and "Use it anyway" saves it

#### Scenario: An anonymous caller asks for the catalogue

- **GIVEN** no session, or a signed-in user who is not an administrator
- **WHEN** `GET /api/portals/{slug}/theme` is called
- **THEN** the response is refused

The catalogue includes admin-uploaded custom sets. thematiq's own
`CatalogController` is deliberately not public for that reason (09e6ffe).
