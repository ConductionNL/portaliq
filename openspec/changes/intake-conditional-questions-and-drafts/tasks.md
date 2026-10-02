# Tasks: intake-conditional-questions-and-drafts

## Conditions

- [x] **T01**: `tests/fixtures/visible-when-local.json` from nextcloud-vue's `tests/utils/visibleWhen.spec.js` local-mode cases (v2.57.3, the installed version); `tests/visible-when-local.spec.mjs` runs it under `node --test` against the imported `evaluateVisibleWhenLocal` (REQ-ICQ-002). Portaliq has no Vitest, so the design's Vitest test is a node test (design D2). Verification: `npm run check:visible-when-local` green, 66 cases.
- [x] **T02**: `lib/Service/Intake/VisibleWhenLocal.php` and its use in `PortalFormValidator::validate()`: skip required, drop answers, declared order (REQ-ICQ-002). Verification: `VisibleWhenLocalTest` over the same fixture; `PortalFormValidatorTest::testHiddenRequiredFieldIsNotRequired`, `::testHiddenAnswerIsDropped`.
- [x] **T03**: `PortalFormBindingResolver::fieldsOf()` resolves a form with an `endpoint` or `source` condition to no form, reason `unsupportedCondition`; `PortalBindingPreview` shows the sentence (REQ-ICQ-003). Verification: `PortalFormBindingResolverTest::testNonLocalConditionResolvesToNoForm`.
- [ ] **T04**: The embed entry's `EmbeddedForm.vue` (`site-reaches-portal-parity` T23) evaluates each field's condition with the imported predicate on every change (REQ-ICQ-001). Verification: node test on `EmbeddedForm.vue`; the Playwright spec's embed case.

## The intake page

- [ ] **T05**: `src/site/components/IntakeFormBlock.vue` as widget `intakeForm`: form fetch, `CnFormPage`, challenge, submit, reference (REQ-ICQ-004, REQ-ICQ-001). Verification: `tests/e2e/intake-conditional-questions-and-drafts.spec.ts` submits from a site page.

## Drafts (after openregister `or-form-and-journey-registry` Tasks 2 and 3)

- [ ] **T06**: Routes `POST /portal/api/intake/drafts`, `GET /portal/api/intake/drafts`, `POST /portal/api/intake/drafts/resume` over openregister's run service, owner by `subjectRef` or by resume code, visible answers only (REQ-ICQ-005). Verification: `PortalIntakeDraftControllerTest::testSaveCreatesNoCase`, `::testWrongCodeAndForeignCodeLookIdentical`, `::testOnlyOwnDraftIsReturned`.
- [ ] **T07**: "Save and continue later" and the resume prompt on `IntakeFormBlock.vue` and the embed's `EmbeddedForm.vue`, with the retention date from the run (REQ-ICQ-005). Verification: the Playwright spec saves, reopens signed in, and resumes anonymously with the code.

## Docs, strings and validation

- [ ] **T08**: English and Dutch strings ("Save and continue later", "We saved your answers. Open this form again to continue. We keep them until {date}.", "Keep this code to continue. We keep your answers until {date}.", "You have unsent answers from {date}. Continue or start again.", "We could not find answers for this code.", "This form uses a condition the portal cannot check. Change it to a condition on another answer."); a docs page for form authors on which conditions a public form supports. Verification: `npm run lint`, `test:l10n`.
- [ ] **T09**: `openspec validate intake-conditional-questions-and-drafts --strict`.
