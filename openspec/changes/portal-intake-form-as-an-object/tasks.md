# Tasks: portal-intake-form-as-an-object

## The binding

- [x] **T01**: A portal form page stores a form binding: type tuple, audience, optional form name, `intakeKind` (REQ-PIFO-001, REQ-PIFO-002)
- [x] **T02**: Resolve the binding against the `buildiq-registration-form` leaf at render time, applying `presets[]` and the form's order (REQ-PIFO-001)
- [ ] **T03**: The admin surface names the form the binding resolves to today, and says so when it resolves to none (D2, risks)

## The intake settings

- [x] **T04**: Address lookup, prefill from earlier cases, challenge and confirmation text carried with the binding and honoured on render (REQ-PIFO-001)
- [x] **T05**: An external binding renders a start card naming the destination, and records the case type's intake as external (REQ-PIFO-002)

## The citizen

- [x] **T06**: Prefill the applicant block from the signed-in portal identity's own claims only, empty for an anonymous visitor (REQ-PIFO-003)
- [x] **T07**: Validate the submission against the form's schema before any create, returning per-field errors (REQ-PIFO-004)
- [x] **T08**: Accept, queue and acknowledge with a reference; the reference page reads the real state, including a failed create (REQ-PIFO-005)

## The entry point

- [ ] **T09**: Pages, topics and layouts for the entry point as `portaliq-cms` content, arranged by an editor (REQ-PIFO-006)
- [x] **T10**: List opencatalogi's published catalogue entries and start the form behind an entry (REQ-PIFO-006)

## Quality

- [x] **T11**: PHPUnit: binding resolution, audience filtering, schema refusal before create, anonymous prefill returns nothing
- [x] **T12**: Playwright `tests/e2e/portal-intake-form-as-an-object.spec.ts`: find a request in the catalogue, submit it, read the reference; then the same page signed in, with the applicant block filled
- [x] **T13**: Dutch and English strings; docs; `openspec validate portal-intake-form-as-an-object --strict`

## What is backend and what is still SPA work

Shipped and covered: the binding and its resolution at render time
(`lib/Service/Intake/PortalFormBindingResolver.php`), the intake settings on
the binding, the external start card's destination, applicant prefill from the
signed-in identity only, per-field validation before any create
(`PortalFormValidator`), the queue that acknowledges a reference at once and
the job that creates the case afterwards, and the entry point over
opencatalogi's published catalogue (`PortalCatalogueReader`).

Left open, and marked so: **T03** and **T09**. T03 is the CMS admin surface
that prints which form a binding resolves to today; the resolver already
answers `resolvesToNoForm` with its reason, so the admin screen is the
remaining piece. T09 arranges the entry point's pages and layouts as
portaliq-cms content, which is editor-facing work over the existing CMS.

