## ADDED Requirements

### Requirement: A form can ask the same questions once per item (REQ-FFL-001)

A published form field of type `group` with `repeat` SHALL render as a list of item cards with "Wijzigen" and "Verwijderen", and an add button labelled `repeat.addLabel`. The add button SHALL be hidden once `repeat.max` items exist. The server SHALL validate every item against the group's sub-fields and MUST refuse a submission with fewer than `repeat.min` or more than `repeat.max` items.

#### Scenario: Two neighbours are the minimum
- **WHEN** the street party form declares `repeat.min: 2` and the resident adds one neighbour and presses "Volgende stap"
- **THEN** the error summary says "Voeg nog 1 bewoner toe" and links to "Nog een bewoner toevoegen"

#### Scenario: Removing an item
- **WHEN** the resident chooses "Verwijderen" on "Bewoner 2"
- **THEN** the card is gone and the remaining cards are numbered again from 1

#### Scenario: The server checks each item
- **WHEN** a submission arrives with a neighbour whose required name is empty
- **THEN** the server refuses it and names the item and the field

### Requirement: A calculated value is worked out by the server (REQ-FFL-002)

A field with `calculate` SHALL show the value the portal works out from earlier answers while the form is filled in. On submit the server SHALL work the value out again and MUST store its own result, whatever value the browser sent. A form whose `calculate.op` the portal does not know SHALL NOT open.

#### Scenario: An end date one year on
- **WHEN** the resident enters start date 1 November 2026 and the field `einddatum` declares `addDays` of 365
- **THEN** the form shows "Uw vergunning loopt tot 1 november 2027"

#### Scenario: A tampered value
- **WHEN** a submission sends `einddatum` as 1 January 2030
- **THEN** the stored submission holds 1 November 2027

#### Scenario: An unknown operation
- **WHEN** a published form declares `calculate.op: "power"`
- **THEN** the route shows the form as not available and the admin's "Formulier controleren" names the unknown operation

### Requirement: A decision table can decide an answer or the next step (REQ-FFL-003)

A step that names a `decision` SHALL ask the rule engine on the server at the step change, with the declared inputs. The outcome SHALL be written to the declared output field, and when `nextStep` maps the outcome to a step, that step SHALL open next. The server SHALL evaluate the decision again on submit. The rule MUST NOT be sent to the browser.

#### Scenario: A business permit route
- **WHEN** the rule returns `bedrijf` for the resident's answers
- **THEN** the step "Uw bedrijf" opens next and `soortVergunning` holds `bedrijf`

#### Scenario: The rule engine is down
- **WHEN** the rule engine does not answer at the step change
- **THEN** the step shows that a connection has a fault, offers "Opnieuw proberen", and the answers stay
