# Tasks: attached-form-confirmation-drops-cancel

- [x] **T1**: `src/site/components/c/AttachedActions.vue` keeps `sent` after a stepped send with a confirmation; the button reads "Sluiten" then and closes the form
  - node `tests/site-form-steps.spec.mjs` "an attached Woo action runs the same steps and forwards through the row action"
- [ ] **T2**: Live check on the rehearsal: send a Woo request from a dossier; the confirmation offers "Sluiten", not "Annuleren"
