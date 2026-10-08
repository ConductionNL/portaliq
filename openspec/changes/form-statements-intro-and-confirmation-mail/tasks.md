# Tasks: form-statements-intro-and-confirmation-mail

- [ ] **T01**: `lib/Settings/portaliq_register.json`: `intro`, `statements`, `confirmation`, `confirmationMail` on `portalFormBinding`; `statements` on `portalIntakeSubmission`; portal statement texts on `portal`; register bump; import and grep for `PARTIAL IMPORT`
- [ ] **T02**: `PortalFormBindingResolver` passes the new keys; `src/site/components/forms/FormIntro.vue` per FormulierStart; route opens it before step 1 (REQ-FCI-001)
- [ ] **T03**: "Verklaringen" in `ReviewList.vue` per WooVerzoekControleren; error summary item; submit refuses a missing required statement and records the accepted ones; PHPUnit (REQ-FCI-002)
- [ ] **T04**: confirmation page per WooVerzoekVerstuurd: title, body with `{reference}` and `{deadline}`, next steps, PDF, Mijn zaken, print (REQ-FCI-003)
- [ ] **T05**: confirmation mail: template `form-confirmation` in the mail templates screen, summary builder without files, signatures or BSN, PDF from `SubmissionReceiptService`, failure into the receipt log; PHPUnit for the summary filter and the failure mark (REQ-FCI-003)
- [ ] **T06**: tabs Bevestiging and Verklaringen and the intro toggle on the form settings page per PtFormulierInstellingen
- [ ] **T07**: strings in nl, en, en_US; Playwright from intro to confirmation, keyboard only, citing REQ-FCI-001 to REQ-FCI-003 with `@e2e`
- [ ] **T08**: Live check against FormulierStart, WooVerzoekControleren and WooVerzoekVerstuurd; screenshots in the build PR
