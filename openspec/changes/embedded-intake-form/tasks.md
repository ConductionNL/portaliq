# Tasks: embedded-intake-form

## The form and its origins

- [x] **T01**: `portalForm.allowedOrigins[]` on the portal page, edited in the CMS admin, with an empty list meaning the form serves to nobody (REQ-EIF-001)
- [ ] **T02**: The snippet, shown on the page beside the origin list, with the origins named in plain language (REQ-EIF-001)

## The frame

- [x] **T03**: The frame route, running the public boot mode of the shared runtime, with `frame-ancestors` built from that form's origin list and no cookie set or read (REQ-EIF-002, REQ-EIF-005)
- [x] **T04**: Refuse a disallowed origin before any schema is read, rendering a plain message (REQ-EIF-002)
- [ ] **T05**: Height negotiation over `postMessage` with a declared minimum when no message arrives (D2, D6)

## The submission

- [x] **T06**: Submit through the existing anonymous contribution create; record the origin on the submission (REQ-EIF-003)
- [x] **T07**: The confirmation: the case reference and a one-time follow link, no account (REQ-EIF-004)
- [x] **T08**: Throttle the frame and the submit route per origin and per address under ADR-082, measured on the frame route

## Quality

- [x] **T09**: PHPUnit: the origin guard, the fail-closed ordering, the throttle, the no-cookie assertion
- [x] **T10**: Playwright `tests/e2e/embedded-intake-form.spec.ts`: embed on a test origin, submit, see the case, then the same page from a disallowed origin
- [x] **T11**: Dutch and English strings; docs with screenshots; `openspec validate embedded-intake-form --strict`

## What is here and what is SPA work

Shipped and covered: `allowedOrigins[]` on the form binding with an empty list
serving nobody, the frame route with `frame-ancestors` built from that form's
own list and no cookie set or read, the refusal that happens before the form is
resolved, the submission down the ordinary anonymous intake path with the
origin recorded, the reference and its follow link, and the per-origin and
per-address throttle.

Left open, and marked so: **T02**, the snippet shown in the CMS admin beside
the origin list. `PortalEmbedGuard::snippetFor()` produces the snippet and the
plain-language origin list, and withholds both for a form that is not
embeddable; placing that on the admin screen is the remaining work. **T05**,
height negotiation over `postMessage`, is frame-side script: the minimum height
is declared and carried on the frame's own element
(`PortalEmbedGuard::MINIMUM_HEIGHT`, `templates/embed.php`).

