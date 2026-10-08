# Tasks: resident-identity-in-forms

- [ ] **T01**: `lib/Settings/portaliq_register.json`: `cosign`, `staffMayFill`, `yiviAttributes` on `portalFormBinding`; `filledBy`, `applicant`, `cosign`, `verifiedEmails` on `portalIntakeSubmission`; register bump; import and grep for `PARTIAL IMPORT`
- [ ] **T02**: `src/site/components/forms/SignatureField.vue` per FormulierKaart, with the typed-name alternative; `PortalFormValidator` accepts a PNG under 200 kB and refuses an empty required signature; file stored on the submission; PHPUnit and node test (REQ-RIF-001)
- [ ] **T03**: e-mail code routes and `lib/Service/Intake/PortalEmailVerification.php` (15 min, 5 tries, 60 s resend, throttle); template `form-email-code`; the field UI per FormulierVelden; submit refuses an unverified address; PHPUnit (REQ-RIF-002)
- [ ] **T04**: co-sign: state `awaiting-cosign` in `PortalIntakeQueue`, template `form-cosign-invite`, `/mede-ondertekenen` start and review pages per MedeOndertekenen, refusal and deadline job, status line on the confirmation and on Mijn zaken; same-subject refusal; PHPUnit and Playwright (REQ-RIF-003)
- [ ] **T05**: broker route kind `yivi` (REQ-BEL-001 settings), attribute list on the form, prefill mapping, trust check; PHPUnit with a fake envelope (REQ-RIF-004). Ask integriq in its tracker for the Yivi route on the broker side.
- [ ] **T06**: staff mode: "Voor medewerkers" section per FormulierInloggen, desk group check, BRP and KvK prefill through OpenRegister's providers, `filledBy` and `applicant` on the submission and the delivery, audit lines; IDOR and route-auth gates green; PHPUnit (REQ-RIF-005)
- [ ] **T07**: Mede-ondertekenen tab and the staff setting on the form settings page per PtFormulierInstellingen
- [ ] **T08**: strings in nl, en, en_US; Playwright scenarios cite REQ-RIF-001 to REQ-RIF-005 with `@e2e`
- [ ] **T09**: Live check against FormulierKaart, FormulierVelden, MedeOndertekenen, FormulierInloggen; screenshots in the build PR
