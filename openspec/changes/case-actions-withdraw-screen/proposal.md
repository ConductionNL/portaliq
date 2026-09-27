# Proposal: case-actions-withdraw-screen

## Why

A resident can withdraw their own request through portaliq's API, but not through the portal. The backend is complete; no screen calls it.

Portaliq matrix, row `act-withdraw-case`, "Withdraw your own case from the portal.", own rating `no`, `built.state` `built`. Its `built.evidence`:

> lib/Controller/CitizenCaseController.php:304-331 withdraw(), fully implemented (guardWrite, writableSet.withdrawal, applyWithdrawal, PortalClientWithdrawalEvent); routes.php:287 POST /portal/api/citizen/cases/{register}/{schema}/{id}/withdraw

its `reachedOn`: "nothing reaches it: src/portal/lib/portalApi.js has no withdraw method, and src/portal/components/CitizenCase.jsx has no withdraw button; grep 'withdraw' across all of src/ finds only an unrelated traffic-consent comment", and its `defectCandidate`: "src/portal/components/CitizenCase.jsx has no withdraw control; the working backend at lib/Controller/CitizenCaseController.php:304 is unreachable from the portal SPA (code reading, needs a live check)".

Row `sib-dossiq-2-47`, "Applicant withdraws their own case from the portal.", carries the same finding; its `built.note`: "dossiq matrix rated this 'partial' with no evidence given; from portaliq's code the honest rating is no, since the whole path is unreached (see the defect on act-withdraw-case)."

Re-read at development `eeda3fa` for this change: the route now sits at `appinfo/routes.php:384`, `withdraw()` at `CitizenCaseController.php:304`, and `grep -rn withdraw src/` still finds no caller of it. The only withdraw controls in `src/` withdraw a proposal (`PageView.jsx:175`).

The e2e test the open change counts as done drives the API, not a page. `tests/e2e/withdrawing-your-own-case-from-the-portal.spec.ts` posts to `${casePath}/withdraw` with Playwright's `request` fixture (line 187), while that change's task T10 reads "withdraw an open request, then reopen the page and find it withdrawn and read-only". The test is green and no resident can do what it describes.

No demand row and no competitor cell rated `yes` is attached to either row. The lane built it under its stranded-backend rule, recorded in its change map: "Where an open change has every task checked but no screen reaches its endpoints (withdrawing-your-own-case-from-the-portal, ...), the lane treated it as the brief's archived case: shipped without the capability, so a new change builds the missing half. The new change names the original change and does NOT redo its backend."

## What changes

- **The case screen offers the withdrawal.** When the case type allows it and the window is open, the citizen case screen shows "Withdraw this request".
- **A confirmation step with an optional reason.** It shows what withdrawing means, in the case app's words when it supplies them, and a box for the reason.
- **A closed window says why.** When the case type allows withdrawal but the window has closed, the screen shows the reason instead of a button.
- **Afterwards the request stays readable.** The screen shows when it was withdrawn and the reason given, and offers no undo.
- **A browser test that uses the screen.** A Playwright test that presses the button, next to the API-level test that exists.

## Rows this closes

| Matrix | Row id | Row name | Own rating | What is missing |
|---|---|---|---|---|
| portaliq | `act-withdraw-case` | Withdraw your own case from the portal. | no | The screen: the button, the confirmation, the closed-window text and the withdrawn state. |
| portaliq | `sib-dossiq-2-47` | Applicant withdraws their own case from the portal. | no | The same screen. Dossiq's half is named below. |

## Existing work it builds on

- `openspec/changes/withdrawing-your-own-case-from-the-portal` (open, every task checked) shipped the backend: `portalCaseType.portalWithdrawal`, `CitizenWritableSetResolver::withdrawal()`, `CitizenCaseController::withdraw()`, the server-fixed target status, the record beside the answers, and `PortalClientWithdrawalEvent`. This change adds only the missing half, the screen. Its requirements REQ-WOC-001 through REQ-WOC-005 are the ones the screen makes reachable.
- `openspec/changes/what-the-citizen-may-write-on-their-own-case` (open): the citizen case screen `CitizenCase.jsx` this change extends.

## Out of scope

- Any change to the withdrawal backend, its event or its throttle.
- Undoing a withdrawal. The original change rules it out (REQ-WOC-005).
- Withdrawing on a case screen other than the `citizenCase` block.

## Sibling halves

- **ConductionNL/dossiq owes** a `citizenWrite` update action on its case collection and `portalCaseType` records with `portalWithdrawal` for the case types a resident may withdraw. Without the action, `CitizenCaseController::context()` refuses a dossiq case with "This case cannot be changed from the portal." (`CitizenCaseController.php:453`), and without `portalWithdrawal` no case type offers withdrawal. Dossiq's citizen contribution declares only create actions today (`createKlacht`, `createBezwaar`, `replyToMessage`); its one update action, `submitChecklistRun`, belongs to the inspector audience.
