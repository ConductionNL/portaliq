# Tasks: identity-access-requests

## The owner's side

- [x] **T01**: Seed `portal.answer-access-request` in `lib/actions.seed.json`, `['admin']` (REQ-IAR-002)
  - Verify: repair step `InitializeActions` run on a clean instance lists it
- [x] **T02**: `AccessRequestAdminController` with list, grant and refuse, each guarded by `requireAction()`; routes in `appinfo/routes.php` (REQ-IAR-002)
  - Verify: PHPUnit for 403 without the action and 404 for another organisation's id; `hydra-gate-no-admin-idor` and `hydra-gate-route-auth` green
- [x] **T03**: `PortalAccessRequestService::grant()` writes the `portalMandate` and rolls the request back to pending when that write fails; `refuse()` stores `decisionReason` (REQ-IAR-003)
  - Verify: PHPUnit with the mandate write failing; the request reads pending afterwards

## The asker's side

- [x] **T04**: `AccessRequests.jsx`: the form and the list of your own requests, with state and refusal reason (REQ-IAR-001)
  - Verify: Playwright `tests/e2e/identity-access-requests.spec.ts`: ask, see pending
- [x] **T05**: Place it on "My cases" (`cases-my-cases-page`), falling back to "My account" (REQ-IAR-001)
  - Verify: the same spec finds the link on "My cases"

## The staff screen

- [x] **T06**: `AccessRequests` manifest page and the grant and refuse dialogs in `src/dialogs/` (REQ-IAR-002)
  - Verify: Playwright: staff grant a request; the asker's "My cases" then lists the party's cases (REQ-IAR-003)

## Close

- [x] **T07**: Dutch and English strings; docs page for administrators on granting access; `openspec validate identity-access-requests --strict`

## Where it landed

- T01, T02, T03 and T06 shipped in PR 835 (Fixes #797): `lib/actions.seed.json`,
  `lib/Controller/AccessRequestAdminController.php`,
  `PortalAccessRequestService::grant()` and `refuse()`, and the Access requests
  index page with the Grant and Refuse row actions
  (`src/lib/accessRequestActions.js`, `tests/access-requests.spec.mjs`). The
  staff list reads the register directly, so OpenRegister's access control
  decides what an owner sees, and a row action reloads the page.
- T04 and T05 shipped on 2026-09-28: `src/portal/components/AccessRequestsPage.jsx`
  over `requestAccess()` and `fetchMyAccessRequests()` in
  `src/portal/lib/portalApi.js`, verified by `tests/access-request-asker.spec.mjs`.
  Design change: neither "My cases" (`cases-my-cases-page`) nor "My account"
  exists in the portal SPA yet, so the page is its own navigation entry,
  **Access to cases**, offered to every signed-in user once the contributions
  have loaded. When "My cases" lands it should link here.
- T07: portal strings in `src/portal/i18n/en.json` and `nl.json`; the
  administrator page is `docs/operations/access-requests.md`.

