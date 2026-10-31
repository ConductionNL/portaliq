# site-public-records Delta: site-voting-record-board

## ADDED Requirements

### Requirement: A public record reads as the voting record board

On a public record's view the side column SHALL have no border box but a 4px rule in the accent colour above it, the
record's summary figures SHALL be a two-column list (the label, then the value in bold), and the links SHALL be a list
without bullets. The record's table SHALL draw its header row on a tinted ground at 16px and its cells with 12px 16px of padding
and a hairline between rows.

#### Scenario: PtStemgedrag

- **GIVEN** a council member's voting record with three figures
- **THEN** the side column shows the member, then "Voor 3", "Tegen 0", "Onthouden 0" as label and bold number, then the
  links, under a 4px accent rule

### Requirement: The voting record page carries its help

The example site's page "Hoe stemden de raadsleden" SHALL carry a help text, shown as "Hulp bij deze pagina".

#### Scenario: a fresh example site

- **GIVEN** the Zuiddrecht example site installed on an empty instance
- **THEN** the page shows the "Hulp bij deze pagina" disclosure with the explanation of the voting record
