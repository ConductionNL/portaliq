## ADDED Requirements

### Requirement: A documents block for each record shows one group per record

A documents block with `eachRecord: true` MUST load its collection and draw one group per row, in the collection's order. Each group MUST be headed by the row's `titleFields` joined by a space, else the block's `label`, with the row's `subtitleFields`, else the block's `subtitle`, beside the heading, and MUST carry a heading id of its own. A group that lists nothing MUST say the block's `emptyLabel` when it has one. A document of a collection that is not a case collection MUST read its day, then its type and size ("2 oktober 2026 · PDF, 84 kB"), without naming a sender.

#### Scenario: Vera and Sami
- GIVEN Fatima may read Vera (Groep 7) and Sami (Groep 4) in `parentChildren`
- WHEN she opens "Rapporten en documenten"
- THEN she sees a group "Vera" with "Groep 7" beside it and a group "Sami" with "Groep 4"
- AND Vera's report reads "12 februari 2026 · PDF"
- @e2e exclude page logic, covered by `node --test tests/site-documents-groups.spec.mjs` ("a page that is no record page draws a group per child the guardian may read", "a paper that is not a case's reads its day, type and size, without a sender")
