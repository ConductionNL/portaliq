# leaves-library-card Specification

## Purpose
What the proposal queue reports to the leaves library card and the linked card in the admin.

## Requirements

### Requirement: The proposal queue reports to the library card and the linked card

The `portaliq-change-proposal-queue` leaf SHALL declare `chrome: "card"` and forward `onHeader` and `onSummary`. Given
`onHeader` it SHALL report its count and empty state once read and SHALL NOT draw its own empty line. Given `onSummary` it
SHALL draw nothing and SHALL report `{ count, noun, rows }` once read, and nothing while loading or after a failed read.

#### Scenario: a record with no waiting proposals

- **GIVEN** a record with no waiting proposals in a library card
- **THEN** the card shows a 0 badge and the library empty state "No proposals are waiting on this record."
