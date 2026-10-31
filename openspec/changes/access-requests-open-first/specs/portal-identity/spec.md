# portal-identity Delta: access-requests-open-first

## ADDED Requirements

### Requirement: Open access requests come first

An access request SHALL carry `stateRank`, 0 for `pending`, 1 for `granted` and 2 for `refused`, written together with
every write of `state`, with 0 as the schema default. A repair step SHALL give every request without a rank the rank of
its state. The Access requests list SHALL sort on the server by `stateRank` ascending, then `requestedAt` descending.

#### Scenario: a mixed list

- **GIVEN** a granted request from 1 October, a pending one from 5 October and a refused one from 3 October
- **THEN** the list reads the pending one first, then the granted one, then the refused one

#### Scenario: deciding a request

- **WHEN** an owner grants a pending request
- **THEN** its `state` is `granted` and its `stateRank` 1, so it moves below the open ones

### Requirement: The requested access reads as the board draws it

The "Gevraagde toegang" column SHALL draw "Zaken van" (`overlineText: "Cases of"`) in muted text above the
organisation the access is for.

#### Scenario: PtToegangsverzoeken

- **GIVEN** a request on behalf of Bakkerij De Basislijn BV
- **THEN** its cell reads "Zaken van" above "Bakkerij De Basislijn BV"
