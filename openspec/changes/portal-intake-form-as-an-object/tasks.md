# Tasks: portal-intake-form-as-an-object

## The binding

- [ ] **T01**: A portal form page stores a form binding: type tuple, audience, optional form name, `intakeKind` (REQ-PIFO-001, REQ-PIFO-002)
- [ ] **T02**: Resolve the binding against the `buildiq-registration-form` leaf at render time, applying `presets[]` and the form's order (REQ-PIFO-001)
- [ ] **T03**: The admin surface names the form the binding resolves to today, and says so when it resolves to none (D2, risks)

## The intake settings

- [ ] **T04**: Address lookup, prefill from earlier cases, challenge and confirmation text carried with the binding and honoured on render (REQ-PIFO-001)
- [ ] **T05**: An external binding renders a start card naming the destination, and records the case type's intake as external (REQ-PIFO-002)

## The citizen

- [ ] **T06**: Prefill the applicant block from the signed-in portal identity's own claims only, empty for an anonymous visitor (REQ-PIFO-003)
- [ ] **T07**: Validate the submission against the form's schema before any create, returning per-field errors (REQ-PIFO-004)
- [ ] **T08**: Accept, queue and acknowledge with a reference; the reference page reads the real state, including a failed create (REQ-PIFO-005)

## The entry point

- [ ] **T09**: Pages, topics and layouts for the entry point as `portaliq-cms` content, arranged by an editor (REQ-PIFO-006)
- [ ] **T10**: List opencatalogi's published catalogue entries and start the form behind an entry (REQ-PIFO-006)

## Quality

- [ ] **T11**: PHPUnit: binding resolution, audience filtering, schema refusal before create, anonymous prefill returns nothing
- [ ] **T12**: Playwright `tests/e2e/portal-intake-form-as-an-object.spec.ts`: find a request in the catalogue, submit it, read the reference; then the same page signed in, with the applicant block filled
- [ ] **T13**: Dutch and English strings; docs; `openspec validate portal-intake-form-as-an-object --strict`
