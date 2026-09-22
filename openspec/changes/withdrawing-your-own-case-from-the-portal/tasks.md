# Tasks: withdrawing-your-own-case-from-the-portal

## The declaration

- [x] **T01**: Read the withdrawal declaration from the contribution: whether, until when, and onto which status (REQ-WOC-001)
- [x] **T02**: Offer the action only while the window is open, and render the reason when it has closed (REQ-WOC-001)

## The act

- [x] **T03**: Bind the action to the identity that may act on the case, and refuse every other identity (REQ-WOC-002)
- [x] **T04**: Confirm before withdrawing, with an optional free-text reason (REQ-WOC-003)
- [x] **T05**: Apply the case app's target status server-side and ignore any status in the client body (REQ-WOC-004)

## The record and the event

- [x] **T06**: Record the withdrawal on the case with the identity, the mandate, the time and the reason; leave the request readable (REQ-WOC-005)
- [x] **T07**: Raise `portal.withdraw.client` with the case, the identity, the mandate and the reason (REQ-WOC-006)
- [x] **T08**: Throttle the action per identity and per case (ADR-082)

## Quality

- [x] **T09**: PHPUnit: a closed window refuses, a foreign identity refuses, a client-supplied status is overwritten, a second withdrawal is refused
  - 🔴 `CitizenWriteRecorder::announceWithdrawal()` HAD ZERO TEST REFERENCES,
    measured 2026-09-18. Its whole reason for existing is in its own docblock:
    a withdrawal is raised as its OWN event, never as a write, so a rule bound
    to a citizen withdrawing their case does not also fire when a handler sets
    the same status internally. That method is the path that tells the two
    apart, and nothing executed it.
  - Had it dispatched the ordinary write event instead, every rule bound to a
    write would have fired on a withdrawal and every rule bound to a withdrawal
    would have stopped, while the case still withdrew, so nothing on screen
    would look wrong.
  - Now covered: the event's identity, that the reason and the landing status
    travel with it, and that the withdrawal is audited against the citizen with
    their `jti`. Both mutations redden their assertions.
  - Found by measuring PER METHOD across the whole tests tree. Every other
    public method on the classes this change and `partner-tasks-in-the-portal`
    name is covered somewhere.
- [x] **T10**: Playwright `tests/e2e/withdrawing-your-own-case-from-the-portal.spec.ts`: withdraw an open request, then reopen the page and find it withdrawn and read-only
- [x] **T11**: Dutch and English strings; docs; `openspec validate withdrawing-your-own-case-from-the-portal --type change --strict`

## Where it lives

`portalCaseType.portalWithdrawal` carries whether, until when, onto which
status and in whose words.
`CitizenWritableSetResolver::withdrawal()` resolves it per case and is what the
page reads, so the action is offered only while the window is open and the
reason is shown when it is not.
`CitizenCaseController::withdraw()` applies the declared status server-side,
never the body's, appends the record beside the answers and raises
`PortalClientWithdrawalEvent` through `CitizenWriteRecorder::announceWithdrawal()`.
The confirmation step itself is the portal SPA's, over `withdrawal.confirmText`.

