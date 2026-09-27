# Tasks: identity-access-requests

## The owner's side

- [ ] **T01**: Seed `portal.answer-access-request` in `lib/actions.seed.json`, `['admin']` (REQ-IAR-002)
  - Verify: repair step `InitializeActions` run on a clean instance lists it
- [ ] **T02**: `AccessRequestAdminController` with list, grant and refuse, each guarded by `requireAction()`; routes in `appinfo/routes.php` (REQ-IAR-002)
  - Verify: PHPUnit for 403 without the action and 404 for another organisation's id; `hydra-gate-no-admin-idor` and `hydra-gate-route-auth` green
- [ ] **T03**: `PortalAccessRequestService::grant()` writes the `portalMandate` and rolls the request back to pending when that write fails; `refuse()` stores `decisionReason` (REQ-IAR-003)
  - Verify: PHPUnit with the mandate write failing; the request reads pending afterwards

## The asker's side

- [ ] **T04**: `AccessRequests.jsx`: the form and the list of your own requests, with state and refusal reason (REQ-IAR-001)
  - Verify: Playwright `tests/e2e/identity-access-requests.spec.ts`: ask, see pending
- [ ] **T05**: Place it on "My cases" (`cases-my-cases-page`), falling back to "My account" (REQ-IAR-001)
  - Verify: the same spec finds the link on "My cases"

## The staff screen

- [ ] **T06**: `AccessRequests` manifest page and the grant and refuse dialogs in `src/dialogs/` (REQ-IAR-002)
  - Verify: Playwright: staff grant a request; the asker's "My cases" then lists the party's cases (REQ-IAR-003)

## Close

- [ ] **T07**: Dutch and English strings; docs page for administrators on granting access; `openspec validate identity-access-requests --strict`
