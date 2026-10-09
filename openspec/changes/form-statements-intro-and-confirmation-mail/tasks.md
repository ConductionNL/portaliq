# Tasks: form-statements-intro-and-confirmation-mail

- [x] **T01**: `lib/Settings/portaliq_register.json`: `intro`, `statements`, `confirmation`, `confirmationMail` on `portalFormBinding`; `statements` on `portalIntakeSubmission`; portal statement texts on `portal`; register bump; import and grep for `PARTIAL IMPORT`
- [x] **T02**: `PortalFormBindingResolver` passes the new keys; `src/site/components/forms/FormIntro.vue` per FormulierStart; route opens it before step 1 (REQ-FCI-001)
- [x] **T03**: "Verklaringen" in `ReviewList.vue` per WooVerzoekControleren; error summary item; submit refuses a missing required statement and records the accepted ones; PHPUnit (REQ-FCI-002)
- [x] **T04**: confirmation page per WooVerzoekVerstuurd: title, body with `{reference}` and `{deadline}`, next steps, PDF, Mijn zaken, print (REQ-FCI-003)
- [x] **T05**: confirmation mail: template `form-confirmation` in the mail templates screen, summary builder without files, signatures or BSN, PDF from `SubmissionReceiptService` (not built: this app has no PDF writer, so the mail and the page carry no PDF), failure into the submission (`confirmationMailState`); PHPUnit for the summary filter and the failure mark (REQ-FCI-003)
- [ ] **T06**: tabs Bevestiging and Verklaringen and the intro toggle on the form settings page per PtFormulierInstellingen — not run: the keys are editable on the binding object from the schema, but the tabbed settings page is not built
- [ ] **T07**: strings in nl, en, en_US; Playwright from intro to confirmation, keyboard only, citing REQ-FCI-001 to REQ-FCI-003 with `@e2e` — not run: needs a live instance
- [ ] **T08**: Live check against FormulierStart, WooVerzoekControleren and WooVerzoekVerstuurd; screenshots in the build PR — not run: needs a live instance

## Build notes

- The portal owns the statement wording (`portal.statementTexts`, with a version). A required statement with no text blocks the form: it cannot be ticked and the server refuses the submission, so no acceptance is ever recorded for words nobody saw.
- The introduction offers "Start" only. The "Hoe wilt u verdergaan?" sign-in choices are not drawn: the intake block has no sign-in routes yet.
- The decision date (`{deadline}`) is empty at submit time because the case does not exist yet, so that sentence is left out.
- Receipt PDF, "Download uw aanvraag als PDF" and the receipt-log mark are not built: this repo has no PDF writer. A failed mail is recorded as `confirmationMailState: failed` on the submission.
