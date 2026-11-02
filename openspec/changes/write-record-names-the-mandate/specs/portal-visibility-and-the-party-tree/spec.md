## MODIFIED Requirements

### Requirement: Acting for an entity below the mandate is recorded (REQ-PTV-006)

An identity whose mandate reaches down SHALL be able to act for an entity
below the one the mandate names, where the case allows it, and the switch
SHALL be offered in the same place as any other organisation switch. Every
write made that way SHALL record the entity acted for, the mandate it
was made under, and the mandate's label, so the case app can name the party
to the person it represents.

#### Scenario: The switcher offers the subsidiary
- **GIVEN** an identity with one mandate that reaches down over two subsidiaries
- **WHEN** they open the organisation switcher
- **THEN** both subsidiaries are offered
- e2e: `tests/e2e/portal-visibility-follows-the-party-tree.spec.ts`

#### Scenario: The write names the entity and the mandate
- **GIVEN** the same identity acting for a subsidiary
- **WHEN** they write on a case
- **THEN** the write records the subsidiary and the mandate on the parent

#### Scenario: The write record names the mandate by its label
- **GIVEN** a mandate labelled "Administratiekantoor Kramer voor Slagerij Van der Berg"
- **WHEN** a write is made while acting under it
- **THEN** the write record's `mandate` block carries `actingForLabel` with that label
- @e2e exclude a field on a server-side record; PHPUnit
