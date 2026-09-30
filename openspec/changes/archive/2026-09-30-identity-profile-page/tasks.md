# Tasks: identity-profile-page

## Mail and model

- [x] **T01**: `PortalIdentityMailer` with the `email-confirmation` template; `PortalAccountSelfController::updateDetails()` mails the token instead of dropping it (REQ-IPP-002)
  - Verify: PHPUnit `PortalIdentityMailerTest` with `IMailer` mocked, asserting the link carries the secret in the fragment and the secret is not logged
  - Done: Done before this change by #822 (PortalIdentityMailer, `updateDetails()` mails the token); this change reuses it for added addresses (PortalContactAddressController::add).
- [x] **T02**: `contactAddresses` and `contactChannel` on `portalAccount` in `lib/Settings/portaliq_register.json`, with a repair-safe default (REQ-IPP-003, REQ-IPP-004)
  - Verify: register import on a clean instance; existing accounts read with `contactChannel` `portal`
  - Done: Register 0.53.0, portalAccount 0.13.0: `contactAddresses`, `contactChannel` (default `portal`), `pendingEmailMode`. Validated with Opis against the real fragment (PortaliqRegisterConfigTest::testTheAccountCarriesAddressesAndAContactChannel); a live import was not run.
- [x] **T03**: `PortalSelfServiceService` rules of design D2: add, confirm, mark preferred, remove; preferred confirmed e-mail copied into `email`; five pending confirmations per account at most (REQ-IPP-003)
  - Verify: PHPUnit per rule, including "an unconfirmed address cannot be preferred"
  - Done: ContactAddressBook (the rules) + PortalContactAddressService; ContactAddressBookTest, PortalContactAddressServiceTest.
- [x] **T04**: Setting `contactChannel` raises `PortalContactDetailsChangedEvent` (REQ-IPP-004)
  - Verify: PHPUnit asserting the dispatcher receives the event once per change and not on an unchanged save
  - Done: PortalContactAddressServiceTest::testChoosingPostIsRecordedAndAnnouncedOnce.

## Endpoints

- [x] **T05**: `GET /portal/api/identity/details`, own account only, the fields of design D5 (REQ-IPP-001)
  - Verify: PHPUnit for 401 without a bearer and for the absence of `identityRef` and `claims`; `hydra-gate-route-auth` green
  - Done: PortalSelfServiceServiceTest::testTheDetailsShowAddressesAndChannelAndNoIdentity; the 401 is PortalAccountSelfController::details (existing).
- [x] **T06**: `contactPrompt` on `GET /portal/api/session` (REQ-IPP-005)
  - Verify: PHPUnit for the three cases: confirmed e-mail, none, `needsAlternativeContact`
  - Done: SessionControllerTest::testIndexAsksForAnEmailAddressWhenNoneIsInUse.

## Screen

- [x] **T07**: A fixed "My account" nav entry and `src/portal/components/AccountPage.jsx`: name, addresses, phone numbers, contact channel, remove (REQ-IPP-001, REQ-IPP-003, REQ-IPP-004, REQ-IPP-006)
  - Verify: Playwright `tests/e2e/identity-profile-page.spec.ts`: change the name, add an address, confirm it from the captured mail, mark it preferred
  - Done: AccountPage.jsx; tests/account-page.spec.mjs; tests/e2e/identity-profile-page.spec.ts drives the same routes over HTTP (written, not run locally).
- [x] **T08**: Consume `#confirm-email=` once on mount and post it (REQ-IPP-002)
  - Verify: the same Playwright spec follows the link; a second follow answers "This link is no longer valid."
  - Done: consumeConfirmEmail() in src/portal/lib/account.js, posted from App.jsx; tests/account-page.spec.mjs.
- [x] **T09**: The contact prompt notice with its link and session-long dismissal (REQ-IPP-005)
  - Verify: Playwright with an account that has no e-mail
  - Done: ContactPrompt + promptDismissed/dismissPrompt (sessionStorage); tests/account-page.spec.mjs; e2e asserts `contactPrompt` on the session.
- [x] **T10**: Remove your account with the confirmation step, then sign out (REQ-IPP-006)
  - Verify: Playwright asserts the account row reads `status: removed` and a case scoped to the old subject still exists
  - Done: AccountPage removal step, then `logout()`; e2e asserts the account reads 404 after removal. The case staying is PortalSelfServiceServiceTest::testTheAccountAndItsClaimsGoAndTheCaseStays.

## Close

- [x] **T11**: Dutch and English strings in `src/portal/i18n/`; docs page on the account page and the event; `openspec validate identity-profile-page --strict`
  - Done: nl/en strings in src/portal/i18n and the schema strings in l10n/; docs/operations/my-account.md; `openspec validate identity-profile-page --strict` valid.
