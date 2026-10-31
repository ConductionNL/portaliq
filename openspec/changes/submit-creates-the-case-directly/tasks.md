# Tasks: submit-creates-the-case-directly

## 1. Binding

- [x] 1.1 `portalFormBinding.destination`; migrate `caseRegister`/`caseSchema`/`deliverTo` into it (repair step)
  - Files: lib/Settings/portaliq_register.json, lib/Service/Intake/PortalFormBindingResolver.php, lib/Repair/
  - Built: `destination { register, schema, subjectField, paymentField, fields, fixed }` (register 0.94.0, binding 0.5.0); `lib/Repair/MoveBindingDestination.php` moves the pair and counts, never guesses, a binding without one (MoveBindingDestinationTest). `DestinationMapping::destinationOf` still reads the old pair until the repair ran.
- [x] 1.2 Binding save and publish call OpenRegister's validator; `PortalBindingPreview` and `FormBindingAdminController::preview` show findings
  - Spec ref: specs/portal-intake-form/spec.md, REQ-PIFO-007
  - Built: `lib/Listener/BindingDestinationGuardListener.php` refuses saving a `published` binding with findings, a `draft` saves (decision 189); findings come from OpenRegister's `FormDestinationValidator` through `DestinationSubmit::findings`, audience `public` (every portal submit is an anonymous write). The preview and the admin Check form name them. Tests: BindingDestinationGuardListenerTest, DestinationIntakeTest (real validator over the real dossiq `case` schema), PortalBindingPreviewTest, tests/form-binding-preview.spec.mjs.

## 2. Submit

- [x] 2.1 `PortalIntakeController::submit` calls `FormSubmitService` with the subject; keeps honeypot, rate limit, challenge and statements checks before it; returns reference, `receivedAt`, confirmation fields
  - Spec ref: REQ-PIFO-004, REQ-PIFO-005
  - Test: unit test with a fake submit service; a control proving no portaliq object is written
  - Built: `DestinationIntake` + `DestinationSubmit` + `DestinationMapping`; the subject goes in `destination.subjectField` as a fixed value, OpenRegister gets no user (anonymous, RBAC on). PortalIntakeControllerTest asserts the one call to the fake and, on refusals, none (assertPostConditions).
- [x] 2.2 `PortalEmbedController::submit` the same, plus the honeypot
- [x] 2.3 Stop using `PortalObjectWriter::createAnonymousObject` (`_rbac: false`) for submits
- [x] 2.4 Confirmation page and mail render case number, received moment, term start and deadline; `status()` reads the case
- [x] 2.5 422 findings render per field in the shared runtime form; 503 keeps the answers and says to try again

## 3. Woo, landing pages, payment

- [x] 3.1 Woo request forms submit into the dossiq Woo case; remove `PortalWooRequestDelivery`
  - Built generic: a Woo form is a binding whose destination is the dossiq case; `PortalWooRequestDelivery` and its test are removed. A `deliverTo: wooRequest` binding without a case pair is reported by the repair step and refuses to send until an administrator names the destination (live pass: set it on each Woo binding).
- [x] 3.2 Landing-page forms declare the source app's destination
  - Built: `form.destination { register, schema, fields, fixed }` (register 0.96.0, form 0.3.0); `LandingPageProvisioningService` carries the requesting app's `form.destination`; `PortalContributionProvider` synthesises each active form's action with that destination and its tracking values as `server` values (the public post address stays `portaliq/landingPageSubmission`, since a published page carries only its form id; nothing is written under it), and a form without one is logged and not submittable; `ContributionController::createAnonymous` submits such an action through `DestinationIntake` (no `landingPageSubmission` written). Tests: PortalContributionProviderTest, LandingPageProvisioningServiceTest, ContributionControllerLandingDestinationTest.
  - `LandingPageSubmissionDispatchListener` stays until the drain reports zero landing-page submissions (task 4.3); nothing creates a `landingPageSubmission` any more, so it no longer fires for new submits.
  - Sibling owed: pipelinq's `LandingPageRequestedEvent` must send `form.destination` (its `lead`); until it does, its new landing pages are not submittable (live pass: set the destination per form).
- [x] 3.3 `PortalIntakePayments` reads the case's payment status (decision 181)

## 4. Drain and remove

- [x] 4.1 `occ portaliq:intake:drain` for `portalIntakeSubmission` (`queued`, `failed`) and `landingPageSubmission`; old reference to `externalReference`; report delivered and refused
  - Built for `portalIntakeSubmission`: `lib/Command/IntakeDrain.php` + `lib/Service/Intake/IntakeQueueDrain.php` (old reference as `externalReference` and as the Idempotency-Key; exit 0 only at zero waiting).
  - Built for `landingPageSubmission`: `lib/Service/Intake/LandingSubmissionDrain.php` (into the form's destination through `DestinationIntake`, stored id as `externalReference` and Idempotency-Key; a form without a destination or a refusal is reported and kept). Tests: LandingSubmissionDrainTest, IntakeDrainTest.
- [x] 4.2 Old `AANVRAAG-` lookups resolve to the case through `externalReference`
- [ ] 4.4 (live pass, decision 139) Run `tests/e2e/site-form-submission.spec.ts`: it now reads the destination object back as an administrator instead of a `landingPageSubmission`, against the fixture's own destination `e2efixture/signup` (`tests/e2e/fixtures/e2e-signup-register.json`, imported by `seed-cms.sh`). Written, not run. First thing to check live: the configuration import of the fixture register (`appId=e2efixture`); on an instance seeded before this change, delete the old form and page at `/campagne/e2e-form-test` first, because the seeder never updates.
- [ ] 4.3 (live pass, decision 139) When the drain reports zero: remove `PortalIntakeQueue`, `PortalIntakeDeliveryJob` (and its `info.xml` entry), both schemas, `landingPageSubmission` with `LandingPageSubmissionDispatchListener`, and the `@spec` tags pointing at the archived intake changes; record the count in the pull request
  - Already done: `PortalIntakeDeliveryJob` and its `info.xml` entry are removed now (it was the `_rbac: false` path, task 2.3); queued entries wait for `occ portaliq:intake:drain`. Left until zero: `PortalIntakeQueue` (drain and old-reference status), both schemas.

## 5. Drafts

- [x] 5.1 "Save and carry on later" saves the destination object in status `draft` through OpenRegister (decision 180); a type-invalid answer is refused on its field, a missing required answer is allowed
  - Built: `DestinationDraft::saveDraft/findDraft/sendDraft/discardDraft` (owner `portal:<subjectRef>`, decision 195; discard through openregister#4591 `DraftDiscard`), `ActionDraft` (action, body, retention 30 days by default on read from `updatedAt`, decision 201), `PortalDraftController` on top. Tests: DestinationDraftTest, ActionDraftTest, PortalDraftControllerTest.
  - Spec ref: specs/portal-intake-form/spec.md, REQ-PIFO-008
  - Files: lib/Service/Intake/DestinationDraft.php, lib/Service/Intake/ActionDraft.php, lib/Controller/PortalDraftController.php, lib/Controller/ContributionController.php
- [x] 5.2 Resume by account loads the draft object; sending moves it out of `draft` through `FormSubmitService`
  - Built: `ContributionController::createFrom` sends a live draft instead of writing a second object (ContributionControllerDraftSendTest). Resume link for a signed-out visitor: not offered, anonymous drafts wait on Q-openregister-F2.
- [x] 5.3 Drain `portalDraft` into draft destination objects (a draft holding a type-invalid answer is reported, not converted)
  - Built: `PortalDraftDrain` in `occ portaliq:intake:drain` (PortalDraftDrainTest, IntakeDrainTest). `journeyRun` was never built in portaliq, so there is nothing of it to drain.
- [x] 5.4 Files on a draft: uploads attach to the draft object, so drafts stop dropping uploaded files
  - Built: `SchemaForm.saveDraft` uploads picked files into the draft id through the existing upload route; the site shows the draft as "Voorlopig" (decision 200). Test: tests/site-form-drafts.spec.mjs.
- [ ] 5.5 (live pass, decision 139) When the drain reports zero drafts waiting: remove `portalDraft`, `PortalDraftStore`, `PortalDraftPurgeJob` (and its `info.xml` entry)

## 6. Amend the open changes this touches

- [x] 6.1 `woo-intake-delivers-to-dossiq`: superseded by 3.1
- [x] 6.2 `form-governance-availability-retention-and-routing`: REQ-FGV-002 destination, REQ-FGV-003 retry removed, REQ-FGV-004/005 read from destinations
- [x] 6.3 `intake-conditional-questions-and-drafts`: its draft requirements move to draft destination objects (decision 180); open PR #1152 follows the same rule
- [x] 6.4 `embedded-intake-form` REQ-EIF-003/004, `intake-pay-on-submit` REQ-IPS-003/005, `form-statements-intro-and-confirmation-mail` REQ-FCI-003, `resident-identity-in-forms` REQ-RIF-002/005, `portal-shared-runtime` task 69

## 6b. Answer OpenRegister when a destination schema changes

- [x] 6b.1 `lib/Listener/FormDestinationDependentsListener.php` adds one `FormDependent` per published `portalFormBinding` whose destination is the event's schema (slug, uuid or id), with the mapping `DestinationMapping::mappingFor()` builds against the proposed schema, audience `public`, and an unpublish that sets the binding to `draft`. An external form and a binding that renders no form are left out. Registered by `lib/AppInfo/FormDependentsRegistrar.php`, called from `Application::register()`. `PortalFormBindingResolver::allPublishedBindings()` reads every portal's published bindings.
  - PHPUnit `FormDestinationDependentsListenerTest` (OpenRegister's real event, schema, validator and `FormDependentsCheck`), `FormDependentsRegistrarTest::testTheAppRegistersTheListener`, `PortalFormBindingResolverTest::testEveryPortalsPublishedBindingsAreListedAndNoDraft`
- [ ] 6b.2 (live pass, decision 139) On an instance with dossiq: publish a binding into a case schema, remove a property its form writes, save the schema. The save answers with the binding under `affected`, the binding reads `draft`, and its owner gets the notification

## 7. Verification

- [ ] 7.1 `composer check:strict`, `npm run lint`, `openspec validate submit-creates-the-case-directly --strict`
