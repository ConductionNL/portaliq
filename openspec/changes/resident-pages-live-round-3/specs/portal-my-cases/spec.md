## ADDED Requirements

### Requirement: Everyone is the default and reads every active mandate (REQ-RR3-002)

Mijn zaken SHALL open on "Iedereen voor wie ik werk" unless the page is fixed to a mandate or the resident chose a party earlier in the session. Reading everyone SHALL read the resident's own cases first, SHALL learn the mandates held from that answer, and SHALL then read the cases of each mandate it names, listing each case once. Choosing everyone SHALL forget the party chosen on the page, so the next visit opens on everyone again.

#### Scenario: Linda's first visit
<!-- @e2e exclude Pinned by tests/my-cases-parties.spec.mjs ("without a choice Mijn zaken opens on everyone and reads every mandate the first answer names"). Live check: task 3.1. -->

- **GIVEN** Linda holds a mandate from Henk and has not chosen a party this session
- **WHEN** she opens Mijn zaken
- **THEN** "Zaken van" shows "Iedereen voor wie ik werk"
- **AND** the list holds her own cases and Henk's, each once
