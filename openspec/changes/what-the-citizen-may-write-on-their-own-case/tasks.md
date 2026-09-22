# Tasks: what-the-citizen-may-write-on-their-own-case

## The writable set

- [x] **T01**: Render the writable set from the contribution, per audience, per case, per status; keep no list in the portal (REQ-CWOC-001)
- [x] **T02**: Show a closed field as read-only with the reason, never as a button that fails (REQ-CWOC-001, REQ-CWOC-002)

## The three acts

- [x] **T03**: Amend a submitted request inside the declared window, recorded as the citizen's change (REQ-CWOC-002)
- [x] **T04**: Add a document to a running case through the file surface the case app declares (REQ-CWOC-003)
- [x] **T05**: Deliver a task to the `client` audience and return its answer to the process that raised it (REQ-CWOC-004)

## The event

- [x] **T06**: Raise `portal.write.client` with the case, the identity, the act and the fields; never raise it for a staff write (REQ-CWOC-005)
- [x] **T07**: Record the identity and the mandate on every citizen write, and throttle the surface per identity and per case (D7)

## What the citizen reads

- [x] **T08**: Render the public status label the contribution supplies, with no portal-side vocabulary (REQ-CWOC-006)

## Quality

- [x] **T09**: PHPUnit: the writable set is refused outside the flags, the window guard, the event is not raised for a staff write, the throttle
- [x] **T10**: Playwright `tests/e2e/what-the-citizen-may-write-on-their-own-case.spec.ts`: amend a request, add a document, answer a task, then the same case with the window closed
- [x] **T11**: Dutch and English strings; docs; `openspec validate what-the-citizen-may-write-on-their-own-case --strict`
