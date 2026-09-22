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

### Requirement: A shared theme MUST be copied into the portal, not linked

Adopting a shared token set MUST copy its accepted declarations into the
portal's own record. A portal MUST keep rendering what it adopted when the
source instance changes or withdraws the set.

#### Scenario: The source withdraws the set

- **GIVEN** a portal that adopted a set shared from another instance
- **WHEN** that instance withdraws the set
- **THEN** the portal renders exactly as before
- **AND** an administrator can see that the source is gone

A link would let another instance change or remove what a live government
portal looks like, at a moment nobody at that portal chose. Decided and built
on the branch in 19fbcd6 (`PortalSharedTheme::adopt()`).

#### Scenario: The validator is unavailable

- **GIVEN** a shared set and no reachable `CustomTokenSetValidator`
- **WHEN** a portal tries to adopt the set
- **THEN** nothing is adopted and the refusal says why

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

The admin settings MUST list the adoptable token sets with their ids and
verdicts, so `portal.theme` is picked rather than typed. The catalogue endpoint
MUST require a signed-in user and MUST NOT be a public page.

#### Scenario: An administrator picks a theme

- **GIVEN** an administrator on the portaliq admin settings
- **WHEN** they open the theme choice for a portal
- **THEN** every adoptable set is listed by id with its verdict

#### Scenario: An anonymous caller asks for the catalogue

- **GIVEN** no session
- **WHEN** `GET /api/themes` is called
- **THEN** the response is refused

The catalogue includes admin-uploaded custom sets. thematiq's own
`CatalogController` is deliberately not public for that reason (09e6ffe).
