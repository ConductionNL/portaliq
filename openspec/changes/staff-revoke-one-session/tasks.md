# Tasks: staff-revoke-one-session

- [x] **T01**: `PortalSessionService::revokeByAdmin()` and `SessionAdminController::revokeSession()`, route `sessionAdmin#revokeSession`; PHPUnit for revoke, unknown id, empty id, audit (REQ-ISA-010)
- [x] **T02**: `src/lib/sessionActions.js` handlers `revokeSession` and `openSessionAccount`, wired in `src/customComponents.js`; row actions on page PortalSessions; `tests/session-row-actions.spec.mjs` (REQ-ISA-010)
- [ ] **T03**: Live check on the Sessions list against the PtSessies board (not run: needs a live instance)
