## ADDED Requirements

### Requirement: Saving a form first says what saving does (REQ-FSR-001)

On a form whose action can keep a draft, "Save and continue later" SHALL open a dialog before anything is saved. The dialog SHALL say that nothing is sent and no case is made yet, and how many days the answers are kept and until which date. It SHALL offer three choices:

- "Opslaan en stoppen" saves the draft and leaves the form the way cancelling does.
- "Doorgaan met invullen" closes the dialog.
- "Stoppen zonder opslaan" leaves the form without saving.

#### Scenario: Save and stop
@e2e exclude Unit test in node: tests/form-save-and-resume.spec.mjs
- GIVEN a signed-in resident halfway through a form whose action keeps drafts for 30 days
- WHEN they choose "Save and continue later" and then "Opslaan en stoppen"
- THEN the draft is saved and the form is left as when cancelling

### Requirement: Reopening a form asks before it continues (REQ-FSR-002)

When a resident opens a form for which they have a live draft, the site SHALL ask before it fills anything in. The question SHALL name when the answers were last saved. "Verder met mijn antwoorden" SHALL restore the draft's answers. "Opnieuw beginnen" SHALL discard the draft and leave the form empty.

#### Scenario: Start again
@e2e exclude Unit test in node: tests/form-save-and-resume.spec.mjs
- GIVEN a live draft of a form
- WHEN the resident opens the form and chooses "Opnieuw beginnen"
- THEN the draft is discarded and the fields are empty

### Requirement: The own area lists unfinished forms (REQ-FSR-003)

`GET /portal/api/drafts` SHALL list the signed-in subject's live drafts. Each entry SHALL carry its app, its action, the action's label, `updatedAt` and `expiresAt`. A draft past its retention SHALL NOT be listed. Mijn zaken SHALL show each draft as an "Onvoltooid formulier" card that names the form and the day it was saved, with a "Formulier afmaken" link to the form.

#### Scenario: A saved Woo-verzoek
@e2e exclude PHPUnit PortalDraftControllerTest; unit test in node: tests/form-save-and-resume.spec.mjs
- GIVEN a resident with a live draft of a create action labelled "Woo-verzoek"
- WHEN they open Mijn zaken
- THEN a card "Onvoltooid formulier" names "Woo-verzoek" and the day it was saved, and "Formulier afmaken" opens that form
