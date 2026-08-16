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

### Requirement: A portal MUST serve the generated dark variant when one exists

#### Scenario: A dark variant is present

- **WHEN** `nldesign` has generated `css/tokens/dark/<id>.css` for the adopted set
- **AND** the instance's dark-mode toggle is on
- **THEN** the portal links it AFTER the light layer

#### Scenario: No dark variant exists

- **WHEN** no generated variant exists for the adopted set
- **THEN** the portal links only the light layer and renders unchanged

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

#### Scenario: A shared set carries a hostile declaration

- **WHEN** a token set shared through OpenRegister is adopted by a portal
- **THEN** it passes `CustomTokenSetValidator` first
- **AND** a set that fails is refused with a visible reason

### Requirement: Portaliq MUST NOT ship design tokens or a theming mechanism

#### Scenario: The app is inspected for tokens

- **WHEN** the repository is searched for `--utrecht-*` or `--tilburg-*` definitions
- **THEN** none are found outside vendored third-party component CSS

`nldesign` is the single source. Two derivations of one token set drifted apart
once already, with ZERO tokens in common between the halves.
