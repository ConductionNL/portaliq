# Tasks: embedded-intake-form

## The form and its origins

- [ ] **T01**: `portalForm.allowedOrigins[]` on the portal page, edited in the CMS admin, with an empty list meaning the form serves to nobody (REQ-EIF-001)
- [ ] **T02**: The snippet, shown on the page beside the origin list, with the origins named in plain language (REQ-EIF-001)

## The frame

- [ ] **T03**: The frame route, running the public boot mode of the shared runtime, with `frame-ancestors` built from that form's origin list and no cookie set or read (REQ-EIF-002, REQ-EIF-005)
- [ ] **T04**: Refuse a disallowed origin before any schema is read, rendering a plain message (REQ-EIF-002)
- [ ] **T05**: Height negotiation over `postMessage` with a declared minimum when no message arrives (D2, D6)

## The submission

- [ ] **T06**: Submit through the existing anonymous contribution create; record the origin on the submission (REQ-EIF-003)
- [ ] **T07**: The confirmation: the case reference and a one-time follow link, no account (REQ-EIF-004)
- [ ] **T08**: Throttle the frame and the submit route per origin and per address under ADR-082, measured on the frame route

## Quality

- [ ] **T09**: PHPUnit: the origin guard, the fail-closed ordering, the throttle, the no-cookie assertion
- [ ] **T10**: Playwright `tests/e2e/embedded-intake-form.spec.ts`: embed on a test origin, submit, see the case, then the same page from a disallowed origin
- [ ] **T11**: Dutch and English strings; docs with screenshots; `openspec validate embedded-intake-form --strict`
