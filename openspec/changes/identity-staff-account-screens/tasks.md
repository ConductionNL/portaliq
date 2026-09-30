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

- [ ] **T04**: `IssueAccountDialog.vue` and hiding the generic add on the Portal accounts index (REQ-ISA-003)
  - Verify: Playwright `tests/e2e/identity-staff-account-screens.spec.ts`: issue an account; a duplicate identity is refused with the reason shown
- [ ] **T05**: `InviteDialog.vue` and the `Invitations` manifest page with "Withdraw invitation" (REQ-ISA-001, REQ-ISA-002)
  - Verify: Playwright: invite, see it listed as sent with its expiry, withdraw it
- [ ] **T06**: "Withdraw this account" on a pending account with `VoidAccountDialog.vue` (REQ-ISA-003)
  - Verify: Playwright: the action is absent on an active account
- [ ] **T07**: The Registration tab on `PortalDetail` and the "Waiting for approval" list (REQ-ISA-004)
  - Verify: Playwright: set approval, register as a visitor, approve as staff

## Close

- [ ] **T08**: Dutch and English strings; an administrator docs page; `openspec validate identity-staff-account-screens --strict`
