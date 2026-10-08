# Tasks: own-contacts-and-invitations

- [ ] **T01**: `lib/Settings/portaliq_register.json`: new schema `portalContact` with the properties of design.md "Data", each with title and description; register version bump; import and grep for `PARTIAL IMPORT` (REQ-ROC-001)
- [ ] **T02**: Portaliq's own contribution declares a `contacts` collection (`scopeField: owner`) and create/update actions whose whitelist allows only `contact` rows and the states a resident may set (REQ-ROC-001, REQ-ROC-002)
- [ ] **T03**: `lib/Service/Identity/ContactInvitationService.php`: create invitation (token hash, 14 days, rate limit, one open per address), resend, withdraw, accept on activation, approval request for an existing account; PHPUnit per rule (REQ-ROC-003)
- [ ] **T04**: `PortalIdentityMailer`: template `contact-invitation` with the inviter's name and message (REQ-ROC-003)
- [ ] **T05**: Self-registration (portal-ways-in) reads the invitation token, pre-fills the e-mail and approves both rows on activation (REQ-ROC-003)
- [ ] **T06**: `src/site/pages/e/ContactsPage.vue` per the Contacten board; `src/site/modals/InviteContactModal.vue` per ContactUitnodigen; menu item "Mijn contacten" under Mijn Zuiddrecht; strings in Dutch and English (REQ-ROC-001, REQ-ROC-002, REQ-ROC-003)
- [ ] **T07**: "Bericht sturen" opens a `messageThread` limited to approved contacts (REQ-ROC-001)
- [ ] **T08**: node test `tests/resident-contacts.spec.mjs` and PHPUnit for scope (another subjectRef reads nothing); Playwright flow invite, register, both listed
- [ ] **T09**: Live check against the Contacten and ContactUitnodigen boards; screenshots in the build PR
