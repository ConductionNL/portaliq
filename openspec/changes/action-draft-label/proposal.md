# Proposal: action-draft-label

## Why

live-learniq-1 (26 Nov 2026): the esdoornveen trainer board draws the button that keeps an unfinished werkproces assessment as "Bewaren voor later" (learniq site-workplace-trainer-portal-design, scenario "Saving halfway"; the same words on the student's hours form in bpv-hours-per-day). portaliq always draws its own "Opslaan en later verdergaan", so an app cannot follow its board, and the learniq trainer e2e cannot find the button.

## What changes

- An action MAY declare `draftLabel`, a string: the words of the form's "save for later" button. The normaliser keeps it only as a string, like `submitLabel`. An attached action carries it to the record's form.
- The site form shows the action's `draftLabel` when it is not blank, else portaliq's own words. The save dialog and the resume dialog stay as they are.

## Out of scope

- The learniq declaration (`createWerkprocesAssessment.draftLabel`), learniq's own PR.
