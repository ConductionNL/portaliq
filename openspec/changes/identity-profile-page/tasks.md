# Tasks: identity-profile-page

## Mail and model

- [ ] **T01**: `PortalIdentityMailer` with the `email-confirmation` template; `PortalAccountSelfController::updateDetails()` mails the token instead of dropping it (REQ-IPP-002)
  - Verify: PHPUnit `PortalIdentityMailerTest` with `IMailer` mocked, asserting the link carries the secret in the fragment and the secret is not logged
- [ ] **T02**: `contactAddresses` and `contactChannel` on `portalAccount` in `lib/Settings/portaliq_register.json`, with a repair-safe default (REQ-IPP-003, REQ-IPP-004)
  - Verify: register import on a clean instance; existing accounts read with `contactChannel` `portal`
- [ ] **T03**: `PortalSelfServiceService` rules of design D2: add, confirm, mark preferred, remove; preferred confirmed e-mail copied into `email`; five pending confirmations per account at most (REQ-IPP-003)
  - Verify: PHPUnit per rule, including "an unconfirmed address cannot be preferred"
- [ ] **T04**: Setting `contactChannel` raises `PortalContactDetailsChangedEvent` (REQ-IPP-004)
  - Verify: PHPUnit asserting the dispatcher receives the event once per change and not on an unchanged save

## Endpoints

- [ ] **T05**: `GET /portal/api/identity/details`, own account only, the fields of design D5 (REQ-IPP-001)
  - Verify: PHPUnit for 401 without a bearer and for the absence of `identityRef` and `claims`; `hydra-gate-route-auth` green
- [ ] **T06**: `contactPrompt` on `GET /portal/api/session` (REQ-IPP-005)
  - Verify: PHPUnit for the three cases: confirmed e-mail, none, `needsAlternativeContact`

## Screen

- [ ] **T07**: A fixed "My account" nav entry and `src/portal/components/AccountPage.jsx`: name, addresses, phone numbers, contact channel, remove (REQ-IPP-001, REQ-IPP-003, REQ-IPP-004, REQ-IPP-006)
  - Verify: Playwright `tests/e2e/identity-profile-page.spec.ts`: change the name, add an address, confirm it from the captured mail, mark it preferred
- [ ] **T08**: Consume `#confirm-email=` once on mount and post it (REQ-IPP-002)
  - Verify: the same Playwright spec follows the link; a second follow answers "This link is no longer valid."
- [ ] **T09**: The contact prompt notice with its link and session-long dismissal (REQ-IPP-005)
  - Verify: Playwright with an account that has no e-mail
- [ ] **T10**: Remove your account with the confirmation step, then sign out (REQ-IPP-006)
  - Verify: Playwright asserts the account row reads `status: removed` and a case scoped to the old subject still exists

## Close

- [ ] **T11**: Dutch and English strings in `src/portal/i18n/`; docs page on the account page and the event; `openspec validate identity-profile-page --strict`
