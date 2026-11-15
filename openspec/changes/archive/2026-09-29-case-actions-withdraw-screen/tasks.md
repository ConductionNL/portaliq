# Tasks: case-actions-withdraw-screen

## The screen

- [x] **T01**: `portalApi.withdrawCitizenCase(collection, id, reason)` posting to the existing withdraw route (REQ-WDS-002)
  - Node script `tests/case-withdraw-screen.spec.mjs` (named for the whole screen), run like `tests/site-auth.spec.mjs` (this app has no JS test runner): the request carries the bearer and only `reason`
- [x] **T02**: `CitizenCase.jsx` renders the withdrawal section from `state.data.withdrawal`: nothing when undeclared, the button when open, the reason when closed (REQ-WDS-001)
  - Playwright `tests/e2e/case-actions-withdraw-screen.spec.ts`: an open window shows the button; a type without `portalWithdrawal` shows nothing; a decided case shows the closed reason
- [x] **T03**: `WithdrawCaseConfirm.jsx`: heading with focus, the case app's text or the default, the optional reason, confirm and cancel (REQ-WDS-002)
  - Playwright `tests/e2e/case-actions-withdraw-screen.spec.ts`: cancelling sends nothing and the request is still running after a reload
- [x] **T04**: Success notice, reload, and the server's sentence on a refusal (REQ-WDS-002)
  - Playwright `tests/e2e/case-actions-withdraw-screen.spec.ts`: withdraw with a reason, reload, find the request withdrawn
- [x] **T05**: Take `withdrawnAt` and `withdrawalReason` out of the generic field list and show the withdrawn state, with no undo (REQ-WDS-003)
  - Playwright `tests/e2e/case-actions-withdraw-screen.spec.ts`: after a reload the screen shows "Withdrawn on" with the date and the reason, and no withdraw or undo control

## Docs and strings

- [x] **T06**: Dutch and English strings for the button, the confirmation, the notice and the withdrawn state; a docs page section on withdrawing from the case screen, with a screenshot
- [x] **T07**: `openspec validate case-actions-withdraw-screen --strict`

## Notes from the build (2026-09-29)

- The node test covers T01 to T05 without a browser: the API call, `withdrawalView()` and `caseFieldNames()` in `src/portal/lib/withdrawal.js`, the rendered confirmation step, and the wiring in `CitizenCase.jsx`. The Playwright file is written, not run in the build session.
- T06: the docs page is `docs/operations/withdrawing-from-the-case-screen.md`. It has no screenshot: the build session had no browser.
