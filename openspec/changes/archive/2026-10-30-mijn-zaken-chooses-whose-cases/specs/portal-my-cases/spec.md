## ADDED Requirements

### Requirement: Mijn zaken lets a representative choose whose cases it lists (REQ-MZC-001)
Mijn zaken MUST offer "Zaken van" to a resident who holds a mandate, with the options everyone they act for, themself by name, and each party a mandate names. Choosing a party MUST list that party's cases and act for it for the rest of the session. Choosing everyone MUST list the resident's own cases and every mandate's, each case once. A resident without a mandate MUST NOT see the choice.

#### Scenario: Linda chooses her father's cases
@e2e exclude Node: tests/my-cases-parties.spec.mjs
- GIVEN Linda holds a mandate from Henk de Vries
- WHEN she opens Mijn zaken
- THEN she sees "Zaken van" with "Iedereen voor wie ik werk", "Mijzelf, Linda de Vries" and "Henk de Vries"
- AND choosing "Henk de Vries" lists Henk's cases

#### Scenario: Everyone lists every case once
@e2e exclude Node: tests/my-cases-parties.spec.mjs
- GIVEN Linda chooses "Iedereen voor wie ik werk"
- WHEN the list is read
- THEN her own cases and Henk's stand in one list, each case once
