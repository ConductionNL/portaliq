## ADDED Requirements

### Requirement: A page may show a count in its menu entry

A contributed page MAY declare `badge` with a `collection` and an optional `label`. The server MUST keep it only when `collection` names one of the same contribution's collections, and MUST keep `label` only as text of at most 80 characters. A `badge` that is not an object, names no collection or names a collection of another contribution MUST be dropped. The site counts the rows of that collection the subject may read and shows the count beside the page's menu entry.

#### Scenario: A count of parent evenings to book
- GIVEN a page "Oudergesprekken" with `badge: {collection: "parentConferenceTasks", label: "{count} om te doen"}` and that collection in the same contribution
- WHEN the contribution is normalised
- THEN the page keeps `badge` with that collection and label

#### Scenario: A badge on another app's rows
- GIVEN a page whose `badge.collection` is not a collection of its contribution
- WHEN the contribution is normalised
- THEN the page carries no badge
