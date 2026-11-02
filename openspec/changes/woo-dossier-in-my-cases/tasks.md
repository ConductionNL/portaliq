# Tasks: woo-dossier-in-my-cases

Read `openspec/woo-build-rules.md` first. New frontend work lands in the Vue site `src/site/`. Start group 1 once `dossiq/woo-dossier-shared-with-the-requester` task 1.2 (`beantwoordVraag`) is merged on dossiq `development`; read its action declaration there before writing a double. One PR, `--base development`.

## 1. The question on the case

- [ ] 1.1 Answer `questions` from `CitizenCaseController` for the case's contributing app, scoped and `open` only (REQ-WDM-001). Verify: `tests/Unit/Controller/CitizenCaseControllerTest.php::testOpenQuestionsOfThisCaseAreListed`, `::testQuestionsOfAnotherCaseAreNotAnswered`.
- [ ] 1.2 Render the questions in the banner of `src/site/components/e/CitizenCase.vue` with the action's form, the sent state and the refused state (REQ-WDM-001). Verify: `CitizenCaseQuestion.spec.js` (vitest).

## 2. Term sentence

- [ ] 2.1 Declare `termNote` on `portalCase` in `lib/Settings/portaliq_register.json` and bump the schema version (REQ-WDM-002). Verify: a register test that saves and reads it back.
- [ ] 2.2 Show it in `decisionDateRows` (`src/shared/casePage.js`) as text (REQ-WDM-002). Verify: `casePage.spec.js` `termNoteRows`.

## 3. Result link

- [ ] 3.1 Declare `resultLink` on `portalCase` (REQ-WDM-003). Verify: the register test.
- [ ] 3.2 Filter it on the server against `https` and the instance's `trusted_domains`, and render it under the steps (REQ-WDM-003). Verify: `tests/Unit/Controller/CitizenCaseResultLinkTest.php`.

## 4. End to end

- [ ] 4.1 `tests/e2e/woo-dossier-in-my-cases.spec.ts`, the three scenarios marked e2e, against the board `portaliq/ZaakWooVerzoek`. Verify: the run.
- [ ] 4.2 Before push, once: `COMPOSER_PROCESS_TIMEOUT=0 composer check:strict`, `npm run lint`, `npm run test:l10n`. No `Co-Authored-By`.
