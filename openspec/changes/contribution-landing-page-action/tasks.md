## Implementation Tasks

### Task 1: Schemas, the request event, and the provisioning service
- **spec_ref**: `openspec/specs/landing-page-provisioning/spec.md#requirement-a-contributing-app-requests-a-landing-page-via-a-typed-event`, `#requirement-requests-fail-closed-with-a-machine-readable-error-and-no-partial-write-plan`
- **files**: `lib/Settings/portaliq_register.json`, `lib/Event/LandingPageRequestedEvent.php`, `lib/Listener/LandingPageRequestedEventListener.php`, `lib/Service/LandingPageProvisioningService.php`, `lib/AppInfo/Application.php`
- **acceptance_criteria**:
  - GIVEN a well-formed `LandingPageRequestedEvent` WHEN dispatched THEN a draft `page` + a `form` object are created and `pageId`/`route`/`publicUrl`/`formId` are written to the event
  - GIVEN an unknown portal, a duplicate route, or a malformed article/form WHEN dispatched THEN the matching `error` code is set, `handled: true`, and no object is written
  - GIVEN any valid request WHEN the page is created THEN its `status` is always `draft`
- [x] Implement
- [x] Test

### Task 2: Anonymous submission plumbing, the submission schema, and the dispatch listener
- **spec_ref**: `openspec/specs/landing-page-provisioning/spec.md#requirement-a-landing-pages-form-is-submittable-with-no-portal-session`, `#requirement-a-submission-is-relayed-to-the-contributing-app-as-a-fail-safe-not-a-fail-closed-cross-app-event`
- **files**: `lib/Settings/portaliq_register.json`, `lib/Portal/PortalContributionProvider.php`, `lib/Event/LandingPageFormSubmittedEvent.php`, `lib/Listener/LandingPageSubmissionDispatchListener.php`, `lib/AppInfo/Application.php`
- **acceptance_criteria**:
  - GIVEN an active `form` object WHEN the anonymous aggregate is built THEN a synthesised `anonymous: true` create action exists whitelisting the form's fields plus `utmFirstTouch`/`utmLastTouch`/`referrer`, with `formId`/`pageId`/`pageRoute`/`portal`/`sourceApp`/`externalReference` stamped as server defaults
  - GIVEN a successful anonymous submission WHEN `sourceApp`'s consumer event class exists THEN it is dispatched with the submission payload; WHEN it does not exist THEN the write still succeeds and a warning is logged, never an exception
- [x] Implement
- [x] Test

### Task 3: UTM capture and the public form widget
- **spec_ref**: `openspec/specs/landing-page-provisioning/spec.md#requirement-utm-capture-is-first-party-portal-scoped-and-honest-about-being-advisory`, `openspec/specs/portaliq-cms/spec.md#requirement-a-public-page-may-embed-a-lead-capture-form-widget`, `#requirement-a-page-may-carry-a-hero-image-reference`
- **files**: `src/site/lib/campaignTracking.js`, `src/site/components/FormBlock.vue`, `src/site/components/WidgetGrid.vue`
- **acceptance_criteria**:
  - GIVEN a landing with `utm_*` query params WHEN a later visit in the same session carries different `utm_*` params THEN first touch is preserved and last touch is overwritten
  - GIVEN a `form`-keyed widget on a page WHEN rendered THEN the form's fields/submitLabel/consentText render and submitting posts to the anonymous create endpoint with the captured UTM/referrer
- [x] Implement
- [x] Test

### Task 4: Tests, docs, and the Playwright spec
- **spec_ref**: `openspec/specs/landing-page-provisioning/spec.md`, `openspec/specs/portaliq-cms/spec.md`
- **files**: `tests/Unit/Service/LandingPageProvisioningServiceTest.php`, `tests/Unit/Listener/LandingPageSubmissionDispatchListenerTest.php`, `tests/e2e/site-form-submission.spec.ts`, `tests/e2e/fixtures/seed-cms.sh`, `docs/`
- **acceptance_criteria**:
  - PHPUnit covers unknown-portal, duplicate-route, missing-form-fields, the returned result-slot shape, the always-draft invariant, the whitelist-drops-unknown-fields invariant, and the fail-safe skip-when-consumer-absent path
  - The Playwright spec drives a visitor filling and submitting the widget and asserts the resulting `landingPageSubmission` object exists (via the OpenRegister object API — no dedicated admin submissions view ships in this change, see design.md)
  - `docs/` documents the two-event contract for a contributing app, with placeholder-safe example values
- [x] Implement
- [x] Test

## Verification
- [x] All tasks checked off
- [x] `openspec validate` passes
- [x] Manual testing against acceptance criteria (static: phpcs/phpmd/psalm/phpstan/eslint/prettier/stylelint all green; PHPUnit written and syntax-verified but NOT executed — this worktree's bootstrap needs a live Nextcloud server tree it is not nested under, see the PR description)
- [x] Code review against spec requirements

## Tests (company-wide ADR-009)
- [x] PHPUnit unit tests for new/changed business logic (`tests/Unit/`) — written, `php -l` clean, not executed in this environment (see Verification)
- [x] Newman/Postman tests for new/changed API endpoints — N/A, this change adds no new HTTP endpoint (same-instance event dispatch + the existing anonymous-create route)
- [x] Browser tests (Playwright MCP) for UI changes — `tests/e2e/site-form-submission.spec.ts` written, verified with `--list` only (no live instance reachable in this environment)
- [ ] All tests pass (`composer test`, `npm run test:unit`) — NOT confirmed; `npm run test:unit` does not exist in this repo (its real per-file JS checks pass, see PR description), and `composer test:unit`/PHPUnit could not run here

## Documentation (company-wide ADR-010)
- [x] Feature documentation updated in `docs/` (`docs/operations/landing-page-contribution.md`)
- [x] Screenshot captured and committed to `docs/images/` — N/A, this change's user-facing surface is a form on a dynamically-created landing page with no fixed screenshot subject; the docs page shows the request/response contract instead

## i18n (company-wide ADR-005)
- [x] Dutch (`nl_NL`) and English (`en_US`) translation strings added — N/A, no new translatable strings were added to any `l10n/` catalogue; `FormBlock.vue`'s default labels are Dutch (matching this app's existing site-renderer convention of author-supplied, not catalogue-driven, public copy) and are always overridable by the authored `form` object
