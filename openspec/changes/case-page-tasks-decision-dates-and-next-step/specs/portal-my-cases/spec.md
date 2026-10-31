## ADDED Requirements

### Requirement: The case page shows the open tasks of that case (REQ-CPT-001)

The case page SHALL list the resident's open tasks whose `caseField` value equals the case's reference, read from every tasks collection that declares `caseField`. The list SHALL sit in a warning-toned banner above the status steps, with the sentence "Wij hebben nog stukken van u nodig. Stuur ze voor {earliest due date}" and, when `legalDecisionDate` is set, ", dan nemen wij uiterlijk {legalDecisionDate} een besluit." Each task SHALL link to its own task page. A tasks collection without `caseField` MUST NOT add tasks to the case page.

#### Scenario: One open task on the case
- **WHEN** a resident opens case 2026-0082, which has one open task "Stuur een kopie van uw ID-bewijs" due 18 October and a legal decision date of 1 November
- **THEN** the banner reads "Wij hebben nog stukken van u nodig. Stuur ze voor 18 oktober, dan nemen wij uiterlijk 1 november een besluit."
- **AND** the task links to its task page

#### Scenario: A task of another case stays away
- **WHEN** the resident opens case 2026-0082 while her only open task belongs to case 2026-0061
- **THEN** the case page shows no task banner

#### Scenario: The task read fails
- **WHEN** the tasks collection answers with an error
- **THEN** the page shows "Uw taken konden niet worden geladen." and never an empty banner

### Requirement: The case page tells the planned and the legal decision date apart (REQ-CPT-002)

`portalCase` SHALL accept the optional dates `plannedDecisionDate` and `legalDecisionDate`. Gegevens on the case page SHALL show "Verwacht besluit" with `plannedDecisionDate` and "Uiterlijk klaar op" with `legalDecisionDate`, each only when set. The case card on Mijn zaken SHALL keep one due day and SHALL take `legalDecisionDate` for it when set.

#### Scenario: Both dates are set
- **WHEN** a case has `plannedDecisionDate` 20 October and `legalDecisionDate` 1 November
- **THEN** Gegevens shows "Verwacht besluit: 20 oktober 2026" and "Uiterlijk klaar op: 1 november 2026"

#### Scenario: Only the legal date is set
- **WHEN** a case has only `legalDecisionDate`
- **THEN** Gegevens shows "Uiterlijk klaar op" and no "Verwacht besluit" row

### Requirement: The current status step offers the next action (REQ-CPT-003)

`portalCaseType` SHALL accept `portalStatusActions`, keyed by status, each with `label`, `kind` (`task`, `page`, `action`) and `target`. When the case's current status has an entry, the current step SHALL show a primary button with that label, leading to the first open task of the target type on this case, the target route, or the named case action. When the target task is not open or the action is not offered to this resident, the button MUST NOT show. The next status SHALL show greyed as "Volgende stap: {label}".

#### Scenario: Missing documents
- **WHEN** a case sits in status "In behandeling" and its case type maps that status to `{label: "Stuur de ontbrekende stukken", kind: "task", target: "aanvullen"}` with an open task of that type
- **THEN** the current step shows "Stuur de ontbrekende stukken", which opens that task
- **AND** the next step reads "Volgende stap: besluit" in grey

#### Scenario: The task is already done
- **WHEN** the mapped task type has no open task on the case
- **THEN** the current step shows no button
