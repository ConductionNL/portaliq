# Design: case-actions-withdraw-screen

Read at portaliq development `eeda3fa`. The backend is not touched; everything below is in the portal SPA.

## What is there today

- `appinfo/routes.php:384` `citizenCase#withdraw`, `POST /portal/api/citizen/cases/{register}/{schema}/{id}/withdraw`.
- `lib/Controller/CitizenCaseController.php:304` `withdraw()`: `context()`, `guardWrite()`, then `CitizenWritableSetResolver::withdrawal()`; a closed window is refused with 409 and the window's sentence (`withdrawal-not-open`). `applyWithdrawal()` reads only `reason` from the request, writes the declared target status, `withdrawnAt`, `withdrawalReason` and a record in the case's write log, raises `PortalClientWithdrawalEvent`, and answers `{case, withdrawal}`. The throttle is 10 per minute (`#[AnonRateLimit(limit: 10, period: 60)]`).
- `CitizenCaseController.php:140` `show()` already returns `withdrawal` next to `case`, `writableSet` and `documents`.
- `lib/Service/CitizenWritableSetResolver.php:164` `withdrawal()` answers `{declared, open, reason, targetStatus, confirmText}`. Undeclared: `declared: false`, `open: false`, reason "This request cannot be withdrawn from the portal.". Already withdrawn: `declared: true`, `open: false`, reason "This request has already been withdrawn.".
- `src/portal/components/CitizenCase.jsx:63` renders status, fields, save and documents. It never reads `state.data.withdrawal`. Its field list is every key of the case except `@self` and `_files` (line 96), so after a withdrawal `withdrawnAt` and `withdrawalReason` would show as bare fields.
- `src/portal/lib/portalApi.js:373` `fetchCitizenCase()`, `:390` `amendCitizenCase()`, `:425` `addCitizenDocument()`. There is no withdraw method.

## D1. The screen follows the declaration, and adds nothing to it

- `withdrawal.declared` false: nothing is shown. REQ-WOC-001 says a type that declares nothing offers nothing, and a sentence about a feature the case type never had is noise.
- `declared` and `open`: a "Withdraw this request" button (Dutch: "Deze aanvraag intrekken").
- `declared` and not `open`: the `reason` sentence, and no button. That is the case app's `closedReason`, or "This request has already been withdrawn."

The screen never decides whether a request can be withdrawn. It renders the server's answer.

## D2. A confirmation step, in its own component

Pressing the button replaces it with `src/portal/components/WithdrawCaseConfirm.jsx`, in place on the case screen:

- Heading: "Withdraw this request?" (Dutch: "Deze aanvraag intrekken?"). Focus moves to it.
- Text: the case app's `confirmText` when given, else "If you withdraw, we stop handling your request. You cannot undo this." (Dutch: "Als u intrekt, stoppen wij met de behandeling. U kunt dit niet terugdraaien.")
- A labelled text area: "Why are you withdrawing? (optional)" (Dutch: "Waarom trekt u de aanvraag in? (niet verplicht)").
- "Withdraw request" (Dutch: "Aanvraag intrekken") and "Keep my request" (Dutch: "Aanvraag houden"). The second closes the step and sends nothing.

Nothing is sent before "Withdraw request". The step is a component of its own so `CitizenCase.jsx` does not carry the form inline.

## D3. Sending

A new `portalApi.withdrawCitizenCase(collection, id, reason)` posts `{reason}` to the withdraw route with the bearer, and returns `{ok, case, withdrawal}` or `{ok: false, status, message}`, the same shape `amendCitizenCase()` returns. The button is disabled while the request runs.

On success the screen shows "Your request has been withdrawn." (Dutch: "Uw aanvraag is ingetrokken.") and reloads the case. On a refusal it shows the server's sentence, as the save and the upload already do.

## D4. The withdrawn state is shown as such

`withdrawnAt` and `withdrawalReason` leave the generic field list. When `withdrawnAt` is set, the case screen shows "Withdrawn on {date}." (Dutch: "Ingetrokken op {date}.") and, when a reason was given, "Your reason: {reason}" (Dutch: "Uw reden: {reason}"). The answers stay readable. No control undoes it.

## D5. A browser test that presses the button

`tests/e2e/case-actions-withdraw-screen.spec.ts` uses a `page`, not only `request`: it seeds a case type with `portalWithdrawal`, signs in with dev-login, opens the case, presses the button, types a reason, confirms, and reads the withdrawn state after a reload. The existing API-level spec stays; the task list of the original change is not edited.

## Risks

- **Dossiq cannot use it yet.** Its citizen contribution has no `citizenWrite` update action, so its cases are refused by `context()` before the withdrawal is even resolved. The sibling half names it.
- **Wording collision.** A case app's `confirmText` may already say "you cannot undo this". The default text is shown only when the case app gives none.

## What this change does not do

- It does not change `CitizenCaseController`, the resolver, the event or the throttle.
- It does not add undo, delete or reopen.
