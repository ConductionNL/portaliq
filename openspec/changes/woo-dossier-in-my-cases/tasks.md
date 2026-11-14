# Tasks: woo-dossier-in-my-cases

Read `openspec/woo-build-rules.md` first. New frontend work lands in the Vue site `src/site/`. Start group 1 once `dossiq/woo-dossier-shared-with-the-requester` task 1.2 (`beantwoordVraag`) is merged on dossiq `development`; read its action declaration there before writing a double. One PR, `--base development`.

## 1. The question on the case

- [x] 1.1 (`lib/Service/CaseQuestions.php`, called from `CitizenCaseController::show()`; reads each `aanvullingsverzoek` collection of the case's app under its own scope and trust, keeps rows on this case whose `state` is `open`, and returns each with the endpoint row action declared on that collection, which for dossiq is `beantwoordVraag`; any failure gives none; `tests/Unit/Service/CaseQuestionsTest.php` for the edges) Answer `questions` from `CitizenCaseController` for the case's contributing app, scoped and `open` only (REQ-WDM-001). Verify: `tests/Unit/Controller/CitizenCaseControllerTest.php::testOpenQuestionsOfThisCaseAreListed`, `::testQuestionsOfAnotherCaseAreNotAnswered`.
- [x] 1.2 (`src/site/components/e/CitizenCase.vue` banner over `src/shared/caseQuestions.js`. It sends through `forwardRowAction`, so the server proves the row and signs the subject. After a 2xx it reads the sentence and offers no second form; a 404 or 409 says "Deze vraag is al afgehandeld." and reads the case again; any other failure keeps the form with a reason. portaliq has no vitest, so the test is `tests/citizen-case-question.spec.mjs` (node --test with the repo's `mount-sfc` helper, run by `check:woo-dossier-in-my-cases`)) Render the questions in the banner of `src/site/components/e/CitizenCase.vue` with the action's form, the sent state and the refused state (REQ-WDM-001). Verify: `CitizenCaseQuestion.spec.js` (vitest).

## 2. Term sentence

- [x] 2.1 Declare `termNote` on `portalCase` in `lib/Settings/portaliq_register.json` and bump the schema version (REQ-WDM-002). Verify: a register test that saves and reads it back. (portalCase 0.3.0, register 0.109.0; `PortaliqRegisterConfigTest` reads it back.)
- [x] 2.2 Show it in `decisionDateRows` (`src/shared/casePage.js`) as text (REQ-WDM-002). Verify: `casePage.spec.js` `termNoteRows`. (`termNoteRows()` in `src/shared/casePage.js`; `node --test tests/woo-dossier-in-my-cases.spec.mjs`, in `check:specs`.)

## 3. Result link

- [x] 3.1 Declare `resultLink` on `portalCase` (REQ-WDM-003). Verify: the register test. (with 2.1.)
- [x] 3.2 Filter it on the server against `https` and the instance's `trusted_domains`, and render it under the steps (REQ-WDM-003). Verify: `tests/Unit/Controller/CitizenCaseResultLinkTest.php`. (`CaseResultLink` drops any link that is not https on a trusted domain, with one warning; every case answer of `CitizenCaseController` passes through it; the case page shows the link under the steps. `tests/Unit/Controller/CitizenCaseResultLinkTest.php`, `CitizenCaseControllerTest::testTheResultLinkIsShownOnlyOnATrustedDomain`.)

## 4. End to end

- [ ] 4.1 `tests/e2e/woo-dossier-in-my-cases.spec.ts`, the three scenarios marked e2e, against the board `portaliq/ZaakWooVerzoek`. Verify: the run.
- [x] 4.2 (ran on the branch merged with development. `lint`, phpstan and the unit suite (5211 tests) pass; `npm run lint` has 0 errors. The remaining findings are all inherited from development, on lines this change did not touch: phpcs on `NotificationDispatchJob.php:354`, psalm `UndefinedClass ObjectLeavingEvent` in `ListenerRegistrar.php:125`, and phpmd on classes that were already over the limit, including `CitizenCaseController` at 1040 lines. The repo has no `test:l10n` script.) Before push, once: `COMPOSER_PROCESS_TIMEOUT=0 composer check:strict`, `npm run lint`, `npm run test:l10n`. No `Co-Authored-By`.
