# Tasks: staff-resend-an-invitation

- [x] **T01**: `PortalInvitationService::resend()`; PHPUnit: new secret, old link dead, window restarts, accepted/withdrawn/other organisation refused (REQ-ISA-011)
- [x] **T02**: `PortalAccountAdminController::resendInvitation()`, route `portalAccountAdmin#resendInvitation`; PHPUnit: refused without the action before the service is asked, mail without the secret, refusals told apart (REQ-ISA-011)
- [x] **T03**: `resendInvitation` in `src/lib/staffAccountActions.js` (action + row handler), row action on page Invitations; `tests/invitation-resend.spec.mjs` (REQ-ISA-011)
- [ ] **T04**: Live check against the PtUitnodigingen board (not run: needs a live instance)
