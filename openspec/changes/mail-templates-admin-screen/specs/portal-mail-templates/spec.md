## ADDED Requirements

### Requirement: An admin can edit the text of each portal mail (REQ-PMT-001)

The admin screen E-mailsjablonen SHALL list one row per template key with its subject, its variable count and when it changed. An admin SHALL edit subject and body per portal, insert variables from chips, preview with sample values, and reset to the default text. A stored template SHALL override the default for that portal only; without one, the default text SHALL be sent.

#### Scenario: A changed status mail
- **WHEN** an admin changes the status mail's body to start with "Beste {voornaam}," and saves
- **THEN** the next status mail of that portal starts with "Beste Sanne,"
- **AND** another portal on the instance still gets the default text

#### Scenario: Back to the default
- **WHEN** the admin chooses "Standaardtekst terugzetten"
- **THEN** the stored template is removed and the default text is sent again

### Requirement: A template accepts only its own variables and no record content (REQ-PMT-002)

Saving a template with a variable its key does not declare SHALL be refused with the variable's name. No template key SHALL declare a variable that carries a field value of a record beyond its reference number and type.

#### Scenario: An unknown variable
- **WHEN** an admin saves the status mail with `{besluit}`
- **THEN** the save is refused with "Onbekende variabele: {besluit}"

### Requirement: Every sent mail is logged and a failed one can be sent again (REQ-PMT-003)

Every mail the portal sends SHALL be logged with time, masked recipient, template, case reference and status, and kept 90 days. The log SHALL filter on Mislukt and In de wachtrij and SHALL export as CSV. A failed mail SHALL offer "Opnieuw versturen", which sends it again to the account's current address and logs a new row linked to the first. The log MUST NOT store the full address.

#### Scenario: Resending a failed mail
- **WHEN** a status mail failed with "mailbox vol" and the admin chooses "Opnieuw versturen"
- **THEN** a new log row is added with status In de wachtrij, linked to the failed one

#### Scenario: Old rows go
- **WHEN** a log row is 91 days old
- **THEN** the daily job removes it

### Requirement: An admin can send a test mail (REQ-PMT-004)

"Testmail versturen" SHALL send the selected template with sample values to the signed-in admin's own address and to no other address.

#### Scenario: Test before saving
- **WHEN** an admin chooses "Testmail versturen" on the invitation template
- **THEN** only the admin's own address receives it, filled with sample values
