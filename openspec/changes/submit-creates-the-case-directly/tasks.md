# Tasks: submit-creates-the-case-directly

## 1. Binding

- [ ] 1.1 `portalFormBinding.destination`; migrate `caseRegister`/`caseSchema`/`deliverTo` into it (repair step)
  - Files: lib/Settings/portaliq_register.json, lib/Service/Intake/PortalFormBindingResolver.php, lib/Repair/
- [ ] 1.2 Binding save and publish call OpenRegister's validator; `PortalBindingPreview` and `FormBindingAdminController::preview` show findings
  - Spec ref: specs/portal-intake-form/spec.md, REQ-PIFO-007

## 2. Submit

- [ ] 2.1 `PortalIntakeController::submit` calls `FormSubmitService` with the subject; keeps honeypot, rate limit, challenge and statements checks before it; returns reference, `receivedAt`, confirmation fields
  - Spec ref: REQ-PIFO-004, REQ-PIFO-005
  - Test: unit test with a fake submit service; a control proving no portaliq object is written
- [ ] 2.2 `PortalEmbedController::submit` the same, plus the honeypot
- [ ] 2.3 Stop using `PortalObjectWriter::createAnonymousObject` (`_rbac: false`) for submits
- [ ] 2.4 Confirmation page and mail render case number, received moment, term start and deadline; `status()` reads the case
- [ ] 2.5 422 findings render per field in the shared runtime form; 503 keeps the answers and says to try again

## 3. Woo, landing pages, payment

- [ ] 3.1 Woo request forms submit into the dossiq Woo case; remove `PortalWooRequestDelivery`
- [ ] 3.2 Landing-page forms declare the source app's destination; remove `LandingPageSubmissionDispatchListener`
- [ ] 3.3 `PortalIntakePayments` reads the case's payment status (decision 181)

## 4. Drain and remove

- [ ] 4.1 `occ portaliq:intake:drain` for `portalIntakeSubmission` (`queued`, `failed`) and `landingPageSubmission`; old reference to `externalReference`; report delivered and refused
- [ ] 4.2 Old `AANVRAAG-` lookups resolve to the case through `externalReference`
- [ ] 4.3 When the drain reports zero: remove `PortalIntakeQueue`, `PortalIntakeDeliveryJob` (and its `info.xml` entry), both schemas, and the `@spec` tags pointing at the archived intake changes; record the count in the pull request

## 5. Drafts

- [ ] 5.1 "Save and carry on later" saves the destination object in status `draft` through OpenRegister (decision 180); a type-invalid answer is refused on its field, a missing required answer is allowed
  - Spec ref: specs/portal-intake-form/spec.md, REQ-PIFO-008
  - Files: lib/Service/Intake/PortalDraftStore.php (replaced), lib/Controller/PortalIntakeController.php
- [ ] 5.2 Resume by account or resume link loads the draft object; sending moves it out of `draft` through `FormSubmitService`
- [ ] 5.3 Drain `portalDraft` into draft destination objects (a draft holding a type-invalid answer is reported, not converted); remove the schema at zero pending
- [ ] 5.4 Files on a draft: uploads attach to the draft object, so drafts stop dropping uploaded files

## 6. Amend the open changes this touches

- [ ] 6.1 `woo-intake-delivers-to-dossiq`: superseded by 3.1
- [ ] 6.2 `form-governance-availability-retention-and-routing`: REQ-FGV-002 destination, REQ-FGV-003 retry removed, REQ-FGV-004/005 read from destinations
- [ ] 6.3 `intake-conditional-questions-and-drafts`: its draft requirements move to draft destination objects (decision 180); open PR #1152 follows the same rule
- [ ] 6.4 `embedded-intake-form` REQ-EIF-003/004, `intake-pay-on-submit` REQ-IPS-003/005, `form-statements-intro-and-confirmation-mail` REQ-FCI-003, `resident-identity-in-forms` REQ-RIF-002/005, `portal-shared-runtime` task 69

## 7. Verification

- [ ] 7.1 `composer check:strict`, `npm run lint`, `openspec validate submit-creates-the-case-directly --strict`
