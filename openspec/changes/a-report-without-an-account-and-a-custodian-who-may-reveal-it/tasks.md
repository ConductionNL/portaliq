# Tasks: a-report-without-an-account-and-a-custodian-who-may-reveal-it

## The door

- [ ] **T01**: Add the `receipt` identity kind: a report accepted with no account and no address (REQ-RWA-001)
- [ ] **T02**: Issue the code from a cryptographic source, show it once, store only its hash (REQ-RWA-002)
- [ ] **T03**: Open a thread on a valid code; register every failed attempt with the throttler (REQ-RWA-002, REQ-RWA-003)

## The thread

- [ ] **T04**: Two-way messages against the code, readable by the reporter and by the handler (REQ-RWA-003)
- [ ] **T05**: Render the acknowledgement and feedback terms from the declaration, with where they stand (REQ-RWA-006)

## The identity and the reveal

- [ ] **T06**: Store contact details apart from the report body, joined by reference and never returned with it (REQ-RWA-004)
- [ ] **T07**: A motivated reveal request, answered by the custodian the declaration names (REQ-RWA-005)
- [ ] **T08**: Record every reveal: asker, motivation, custodian, time, what was revealed; raise the reveal event (REQ-RWA-005)

## The hardening

- [ ] **T09**: Exclude the reporting surface from visitor analytics by declaration, and record no client address against a report (REQ-RWA-007)
- [ ] **T10**: Proof of work in front of the form, run here, with no call to a challenge vendor (REQ-RWA-007)

## Quality

- [ ] **T11**: PHPUnit: a wrong code is throttled, the identity is absent from list, search and export, a non-custodian reveal is refused, a reveal without a motivation is refused
- [ ] **T12**: Playwright `tests/e2e/a-report-without-an-account-and-a-custodian-who-may-reveal-it.spec.ts`: file a report with no account, keep the code, return with it, read the answer
- [ ] **T13**: Dutch and English strings; docs; `openspec validate a-report-without-an-account-and-a-custodian-who-may-reveal-it --type change --strict`
