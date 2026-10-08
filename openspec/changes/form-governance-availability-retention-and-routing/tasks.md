# Tasks: form-governance-availability-retention-and-routing

- [ ] **T01**: `lib/Settings/portaliq_register.json`: `availability`, `retention`, `delivery` on `portalFormBinding`, `expiresAt` and `attempts` on `portalIntakeSubmission`; register bump; import and grep for `PARTIAL IMPORT`
- [ ] **T02**: `lib/Service/Intake/PortalFormAvailability.php`: active period, maintenance, limit per period (OpenRegister count), replacement; called from `PortalFormBindingResolver::render()` and from the submit path; PHPUnit per situation and for the limit check inside the submit request (REQ-FGV-001)
- [ ] **T03**: `src/site/components/forms/FormUnavailable.vue` with the three situations of the FormulierNietBeschikbaar board and the not-yet-open text; node test (REQ-FGV-001)
- [ ] **T04**: `lib/Service/Intake/PortalDeliveryRouter.php`: default plus rules, kinds `caseType`, `email` (Nextcloud mailer, template `form-delivery-mail`), `integriq` (integriq's call API); `PortalIntakeQueue` uses it; PHPUnit per kind and for rule order (REQ-FGV-002)
- [ ] **T05**: `lib/BackgroundJob/IntakeRetryJob.php` with the 5 min, 1 h, 6 h schedule; retry routes for the resident (own submission, by reference and session) and for staff; evidence log line per attempt; IDOR gate green; PHPUnit (REQ-FGV-003)
- [ ] **T06**: FormulierVerwerken situation 2 on the reference page: text, kept-until date, "Opnieuw proberen", error code `OF-xxxx` (REQ-FGV-003)
- [ ] **T07**: `lib/BackgroundJob/FormFailureDigestJob.php` at 07:00, template `form-failure-digest` listed in the mail templates screen; no mail when empty; PHPUnit (REQ-FGV-003)
- [ ] **T08**: `lib/BackgroundJob/IntakeRetentionJob.php`: expiry per state, delete through OpenRegister or anonymise; PHPUnit for both methods and the defaults (REQ-FGV-004)
- [ ] **T09**: export route and "Inzendingen downloaden" under "Meer" on the form settings page, built on OpenRegister's object export filtered on the binding; audit line; PtInzendingen "Downloaden" without answers; PHPUnit for the rights check (REQ-FGV-005)
- [ ] **T10**: admin tabs Beschikbaarheid, Bewaartermijn and Doorsturen and the status panel on the form settings page, per PtFormulierInstellingen; strings in nl, en, en_US
- [ ] **T11**: Playwright: a form at its limit shows situation 3 (cites REQ-FGV-001); a retry from the reference page (cites REQ-FGV-003)
- [ ] **T12**: Live check against PtFormulierInstellingen, FormulierNietBeschikbaar, FormulierVerwerken and PtInzendingen; screenshots in the build PR
