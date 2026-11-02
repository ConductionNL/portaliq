## MODIFIED Requirements

### Requirement: Every result from another catalogue MUST name that catalogue (REQ-SNF-001)

Replaces "Every result MUST name the catalogue it came from" of `portal-federated-search`.

A result whose `@self.directory` names another catalogue SHALL display that directory with the
source label. A result of this portal, carrying no `@self.directory` or the API's own word `local`,
SHALL display no source, and the word `local` SHALL never be shown.

#### Scenario: A federated row names its peer
@e2e exclude Asserted on the rendered block in `tests/site-woo-pages.spec.mjs`; the dev rig has no second catalogue to federate with.
- **GIVEN** results drawn from this portal and from `opencatalogi.nl`
- **THEN** the row from `opencatalogi.nl` SHALL show "opencatalogi.nl" as its source
- **AND** the row of this portal SHALL show no source

#### Scenario: A row the API calls local
@e2e exclude Asserted in `tests/federated-search.spec.mjs`; a pure mapping of the response row.
- **GIVEN** a row with `@self.directory` set to `local`, or no `@self` at all
- **WHEN** it is mapped for the block
- **THEN** its `directory` SHALL be empty and the card SHALL show no source
