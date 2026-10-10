# Tasks: sign-in-with-an-email-link

- [x] 0. Security review of design.md's table (design.md, "Security review (8 Oct)").
- [x] 0a. Build behind an admin switch that is OFF by default (Q-portaliq-1, answered 9 Oct): `EmailLinkSetting` (key `email_link_signin`), shown in the admin settings with the warning that the security review must be accepted before it is turned on (`src/views/AdminRoot.vue`, `SettingsController`); `EmailLinkSettingTest`.
- [x] 1. Mode `email-link` in the portal's `authentication.modes`; the role card on the sign-in page; the mode does not open registration (REQ-IWI-014).
  - PHPUnit on `PortalWaysInResolver`: `email-link` alone shows no "Create an account"
- [x] 2. Schema: `identityType` gains `email`; the account's sign-in address as a field of its own (H1, H2).
  - Validate a real `email` account payload against the real schema fragment
- [x] 3. Eligibility: identity type `email`, `active`, the portal's organisation, no broker identity reference or claims, exactly one match (REQ-IWI-006).
  - PHPUnit: a DigiD account, a withdrawn account, another organisation and a shared address all get no link
- [x] 4. Token service: 48 characters from `ISecureRandom`, SHA-256 with account id, cookie hash and expiry, `hash_equals`, conditional spend, a new link voids older ones, inactive accounts refused at redeem (REQ-IWI-009).
  - PHPUnit for expiry, single use, two simultaneous redeems, voiding and the hash
- [x] 5. The request endpoint: same sentence, one queued job for every accepted request, the job looks up and mails, the address dropped after the run (REQ-IWI-007).
  - PHPUnit: known and unknown address queue the same job and answer the same
- [x] 6. Rate limits: per address hash (3 per hour, counted for unknown too), `AnonRateLimit` plus `UserRateLimit` per client (20 per hour), a per-portal mail cap, redeem with its own limit and `BruteForceProtection` (REQ-IWI-008).
- [x] 7. The link page: request cookie, one button in the requesting browser, typed address in another, a page-fetched value on the POST, the portal and masked address shown, fragment removed with `replaceState` first (REQ-IWI-010, REQ-IWI-012).
- [x] 8. The session: fresh `jti`, method `email-link`, trust `low` that refresh cannot raise, idle and cap rules, no silent sign-in, no `logoutUrl` (REQ-IWI-011).
- [x] 9. Self-service: the sign-in address cannot change in an `email-link` session; a change needs staff or `substantial` and mails the old address (REQ-IWI-011).
- [x] 10. Traffic ingest drops fragments from `pageLocation`; session recording skips the link page (REQ-IWI-012).
- [x] 11. Logging and audit by account id and address hash, never the token, link or address; the "u bent ingelogd" notice after each sign-in (REQ-IWI-012).
- [x] 12. Staff revoke one account's links and sessions (REQ-IWI-013).
- [x] 13. The mail (portal name, the address, one link to the portal's own address, ignore line); i18n en and nl.
- [ ] 14. learniq declares the mode on the academy portal once built and verified. (not run: sibling repo learniq, and the switch stays off until the security review is accepted; ask in for-ruben/portaliq-sibling-asks.md)

## Where it is built (lane B4, 9 Oct)
- 1: `PortalWaysInResolver` `emailLink`, `PortalSignInText::MODES`, `authApi.signInRoutes` (form route); PortalWaysInResolverTest, tests/email-link-signin.spec.mjs.
- 2: register 0.93.0: `portal` 0.24.0 mode `email-link`, `portalAccount` 0.18.0 `identityType: email` and `signInAddress`, `portalEmailLink` 0.1.0; PortaliqRegisterConfigTest.
- 3: `EmailLinkEligibility`; EmailLinkEligibilityTest. Broker claims are the claim keys `digid`, `eherkenning`, `eidas`, `broker`, `oidc`; an app's own claim (learniq) does not block.
- 4: `EmailLinkTokens`; EmailLinkTokensTest (hash, expiry, single use, two redeems at once, voiding, proofs).
- 5, 6: `EmailLinkController::request`, `EmailLinkRequestJob`, `EmailLinkSender`, `EmailLinkLimits`; EmailLinkControllerTest, EmailLinkSenderTest.
- 7: `EmailLinkController::describe`/`redeem`, `WayInLink.vue`, `EmailLinkForm.vue`, `waysIn.captureEmailLink` in `main.js`.
- 8: `PortalSessionService::issueSession(provider: 'email-link')` (method in the login audit entry), no broker `logoutUrl` (`SessionController`), refresh keeps `low`; PortalSessionServiceTest.
- 9: `SignInAddressChange` and `PortalAccountAdminController::signInAddress`; self-service writes only `email`/`contactAddresses`; SignInAddressChangeTest, EmailLinkEligibilityTest.
- 10: `TrafficEventValidator` drops the fragment, `traffic/client.js` sends none, `traffic/recorder.js` skips the link page.
- 11: logs by subjectRef and address hash; `PortalIdentityMailer::sendSignedInNotice`.
- 12: `SessionAdminController::revokeAccount`, `EmailLinkTokens::voidFor`, `revokeAllForOrganisation(subjectRef:)`.
- 13: `PortalIdentityMailer::TEMPLATE_EMAIL_LINK`; l10n en, en_US, nl.
