## ADDED Requirements

### Requirement: A published form with steps MUST be filled in one step at a time with visible progress (REQ-SMF-010)

When a published form declares `steps`, the form render MUST carry them, keeping only steps whose fields the form knows. A field in no step MUST be placed in a last step of its own. The site MUST show one step at a time, a progress indicator listing every step with its state, and "Vorige stap" and "Volgende stap". "Volgende stap" MUST validate only the step's visible fields. "Vorige stap" MUST NOT validate. A step whose fields are all hidden MUST be skipped in both directions. Moving to a step MUST move focus to the step heading. A form without `steps` MUST render as one page, as before.

#### Scenario: Step 2 of 4
- GIVEN the Woo request form declares four steps: "Uw vraag", "Periode en documenten", "Uw gegevens", "Controleren en versturen"
- AND the resident has completed step 1
- WHEN step 2 renders
- THEN the progress shows step 1 as done and step 2 as current with `aria-current="step"`
- AND the heading "Stap 2 van 4: periode en documenten" has focus

#### Scenario: A missing answer keeps the resident on the step
- GIVEN step 2 with the required start date empty
- WHEN the resident presses "Volgende stap"
- THEN step 3 does not open
- AND the error summary of REQ-SMF-002 names the start date

#### Scenario: A phone shows the short progress
- GIVEN a phone-width screen
- WHEN step 2 renders
- THEN the progress reads "Stap 2 van 4" and the full list sits behind a button

### Requirement: A form with steps MUST end with a review and a confirmation (REQ-SMF-011)

A published form with steps MUST show a review step before sending. It MUST list each visible answer under its question, grouped per step, with a link that reopens that step. After changing a step from the review, "Volgende stap" MUST return to the review. After a successful submit the site MUST show a confirmation with a heading, the reference, the form's `confirmationText` and, when the portal has one, its contact line. Focus MUST move to the confirmation heading.

#### Scenario: The resident corrects the period from the review
- GIVEN the review of a Woo request
- WHEN the resident follows "Stap 2 wijzigen", changes the end date and presses "Volgende stap"
- THEN the review shows the new end date

#### Scenario: The confirmation names the reference
- GIVEN a Woo request sent successfully with reference `2026-0003`
- WHEN the confirmation renders
- THEN its heading has focus
- AND it shows `2026-0003` and the form's confirmation text

### Requirement: Save and resume MUST sit in the step navigation (REQ-SMF-012)

When drafts are available (`intake-conditional-questions-and-drafts`, REQ-ICQ-005), the step navigation MUST offer "Opslaan en later verdergaan" after "Volgende stap". A resumed draft MUST open on the first step with a missing required answer, or on the review when none is missing. The retention date shown MUST come from the saved draft, never from a fixed number.

#### Scenario: A resumed draft opens where the gap is
- GIVEN a saved Woo request draft with steps 1 and 2 complete
- WHEN the resident opens the form again
- THEN step 3 opens
