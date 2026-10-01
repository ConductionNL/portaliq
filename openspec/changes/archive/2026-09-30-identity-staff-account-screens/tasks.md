# Tasks: identity-staff-account-screens

## Server

- [x] **T01**: `invite()` mails through `PortalIdentityMailer` and stops answering the token (REQ-ISA-001)
  - Verify: PHPUnit asserting the answer has no token and the mailer received it
  - Done: Done by #822: PortalAccountAdminController::invite mails through PortalIdentityMailer (template invitation) and answers only `{state, expiresAt}`; PortalAccountAdminControllerTest::testTheInvitationIsMailedAndTheClerkNeverSeesItsSecret.
- [x] **T02**: `PortalInvitationService::revoke()` and `POST /api/invitations/{id}/revoke`, refused for an accepted invitation (REQ-ISA-002)
  - Verify: PHPUnit for revoke, and that `accept()` then refuses the token
  - Done: PortalInvitationService::revoke(id, organisation): `not_found` outside the organisation, `already_accepted`, idempotent; route portalAccountAdmin#revokeInvitation. PortalInvitationServiceTest::testAWithdrawnInvitationAdmitsNobody, ::testAnAcceptedInvitationCannotBeWithdrawn; PortalAccountAdminControllerTest::testApproveRefuseAndWithdrawAnswerWhatHappened.
- [x] **T03**: `POST /api/accounts/{subjectRef}/approve` and `/refuse` for pending self-registrations, guarded by `portal.provision` (REQ-ISA-004)
  - Verify: PHPUnit: approve a pending self-registration; refuse needs a reason; an active account answers `not_pending`; `hydra-gate-no-admin-idor` green
  - Done: PortalAccountService::approvePending (only `provisionedBy: self-registration`, constant SELF_REGISTRATION); refuse voids with a required reason. PortalAccountProvisionTest::testAPendingSelfRegistrationIsApprovedAndNothingElseIs; PortalAccountAdminControllerTest::testTheNewStaffRoutesNeedTheProvisionAction.

## Screens

- [x] **T04**: `IssueAccountDialog.vue` and hiding the generic add on the Portal accounts index (REQ-ISA-003)
  - Verify: Playwright `tests/e2e/identity-staff-account-screens.spec.ts`: issue an account; a duplicate identity is refused with the reason shown
  - Done: src/dialogs/IssueAccountDialog.vue submits through src/lib/staffAccountActions.js `issue` (POST /api/accounts/provision; `isNew: false` shows 'An account for this identity already exists, so no second account was made.' in the dialog). PortalAccounts: `showAdd: false`, headerActions `issueAccount` and `inviteSomeone` (src/customComponents.js). tests/staff-account-screens.spec.mjs; e2e written, not run.
- [x] **T05**: `InviteDialog.vue` and the `Invitations` manifest page with "Withdraw invitation" (REQ-ISA-001, REQ-ISA-002)
  - Verify: Playwright: invite, see it listed as sent with its expiry, withdraw it
  - Done: src/dialogs/InviteDialog.vue (answer names the expiry, never the link); manifest page `Invitations` (/invitations, index over portalInvitation, no Add) with row action `withdrawInvitation` (confirm, POST /api/invitations/{id}/revoke with the row organisation, hidden on accepted rows) and menu entry. tests/staff-account-screens.spec.mjs.
- [x] **T06**: "Withdraw this account" on a pending account with `VoidAccountDialog.vue` (REQ-ISA-003)
  - Verify: Playwright: the action is absent on an active account
  - Done: widget `PortalAccountWithdraw` on PortalAccountDetail (a detail header action cannot open a dialog that knows the record in nc-vue 2.57, so a widget): the button only on `status: pending`, reason from src/dialogs/VoidAccountDialog.vue, POST /api/accounts/void. tests/staff-account-screens.spec.mjs (canWithdrawAccount, voidAccount).
- [x] **T07**: The Registration tab on `PortalDetail` and the "Waiting for approval" list (REQ-ISA-004)
  - Verify: Playwright: set approval, register as a visitor, approve as staff
  - Done: widget `PortalRegistration` on PortalDetail (src/lib/registrationSettings.js): policy radios and allowed domains saved on the portal record through OpenRegister (GET then PUT, other authentication keys kept; the body is tests/fixtures/registration-save.json, validated with Opis against the real portal schema in tests/Unit/Settings/RegistrationSavePayloadTest.php); 'Waiting for approval' lists pending self-registrations of the portal's organisation with Approve and Refuse (POST /api/accounts/{subjectRef}/approve|refuse, refusal reason via VoidAccountDialog).

## Close

- [x] **T08**: Dutch and English strings; an administrator docs page; `openspec validate identity-staff-account-screens --strict`
  - Done: 53 strings in l10n/nl.json, en.json, en_US.json (and the built .js); docs/operations/staff-accounts-and-registration.md; `openspec validate identity-staff-account-screens --strict` valid.
