# Tasks: withdrawing-your-own-case-from-the-portal

## The declaration

- [ ] **T01**: Read the withdrawal declaration from the contribution: whether, until when, and onto which status (REQ-WOC-001)
- [ ] **T02**: Offer the action only while the window is open, and render the reason when it has closed (REQ-WOC-001)

## The act

- [ ] **T03**: Bind the action to the identity that may act on the case, and refuse every other identity (REQ-WOC-002)
- [ ] **T04**: Confirm before withdrawing, with an optional free-text reason (REQ-WOC-003)
- [ ] **T05**: Apply the case app's target status server-side and ignore any status in the client body (REQ-WOC-004)

## The record and the event

- [ ] **T06**: Record the withdrawal on the case with the identity, the mandate, the time and the reason; leave the request readable (REQ-WOC-005)
- [ ] **T07**: Raise `portal.withdraw.client` with the case, the identity, the mandate and the reason (REQ-WOC-006)
- [ ] **T08**: Throttle the action per identity and per case (ADR-082)

## Quality

- [ ] **T09**: PHPUnit: a closed window refuses, a foreign identity refuses, a client-supplied status is overwritten, a second withdrawal is refused
- [ ] **T10**: Playwright `tests/e2e/withdrawing-your-own-case-from-the-portal.spec.ts`: withdraw an open request, then reopen the page and find it withdrawn and read-only
- [ ] **T11**: Dutch and English strings; docs; `openspec validate withdrawing-your-own-case-from-the-portal --type change --strict`
