## ADDED Requirements

### Requirement: A sent stepped form offers no cancel

An action attached to a record that runs in steps MUST offer to cancel only until it is sent. When its confirmation shows, it MUST NOT offer "Annuleren"; it MUST offer to close the form instead, which shows the record's actions again.

#### Scenario: A Woo request from a dossier
- GIVEN a resident on their dossier starts dossiq's Woo request
- WHEN they send it and the confirmation shows
- THEN no "Annuleren" is offered
- AND "Sluiten" puts the form away
- @e2e exclude pinned by `tests/site-form-steps.spec.mjs` "an attached Woo action runs the same steps and forwards through the row action"; the live check on the rehearsal follows the merge
