## ADDED Requirements

### Requirement: A resident keeps a list of own actions (REQ-RAL-001)

The portal SHALL offer "Mijn acties" to a signed-in resident: their `portalAction` rows with Actie, Status, Uiterlijk and Wie, and a count "{n} van {m} klaar". The resident SHALL add, edit and delete actions in a dialog with Titel, Omschrijving, Soort, Status, Uiterlijk klaar op, Toegewezen aan and one Bestand of type PDF, JPG or PNG up to 10 MB. A resident MUST only read actions they own or are assigned to.

#### Scenario: Adding an action
- **WHEN** Sanne adds "Bankafschriften van drie maanden uploaden", due 14 October, status Te doen
- **THEN** it is listed with "Te doen" and "14 oktober"

#### Scenario: A file too large
- **WHEN** she attaches a 12 MB PDF
- **THEN** the dialog refuses it with "Eén bestand per actie. PDF, JPG of PNG, maximaal 10 MB."

### Requirement: An action can be shared with an approved contact (REQ-RAL-002)

The owner SHALL be able to assign an action to an approved contact. The assignee SHALL see that action and change its status, file and description, and MUST NOT change the assignee or delete it. The dialog SHALL show the history of the action with who changed what and when.

#### Scenario: The caseworker updates the status
- **WHEN** Mark Jansen, an approved contact, sets the action "Aanvraag schuldhulpverlening indienen" to Bezig
- **THEN** Sanne's history shows "Mark Jansen: Status van Te doen naar Bezig"

#### Scenario: An assignee cannot delete
- **WHEN** the assignee tries to delete the action
- **THEN** the portal refuses it

### Requirement: The assignee gets a reminder before the end date (REQ-RAL-003)

Three days before the end date of an action that is not Klaar, the portal SHALL send the assignee one portal notification and one mail "Uw actie {actie} loopt bijna af", and no second reminder for the same end date.

#### Scenario: One reminder
- **WHEN** an open action ends on 14 October and the daily job runs on 11 and 12 October
- **THEN** exactly one reminder is sent, on 11 October
