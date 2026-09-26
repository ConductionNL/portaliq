# Tasks: news-and-newsletter-authoring

## Implementation Tasks

### Task 1: Register schemas and seed data
- **spec_ref**: `openspec/changes/news-and-newsletter-authoring/design.md#seed-data`
- **files**: `lib/Settings/portaliq_register.json`
- **acceptance_criteria**:
  - GIVEN the register file WHEN validated as JSON THEN `newsItem`, `newsletter`, `guardianAudienceFixture` schemas and the seed fixture/newsItem/newsletter rows exist
- [x] Implement
- [x] Test (`python3 -m json.tool` + `tests/Unit/Settings/RegisterAuthorizationTest.php` inherited suite still green)

### Task 2: GuardianAudienceFixtureReader (the audience-source seam)
- **spec_ref**: `openspec/changes/news-and-newsletter-authoring/design.md#audience-source-seam`
- **files**: `lib/Service/GuardianAudienceFixtureReader.php`
- **acceptance_criteria**:
  - GIVEN a subjectRef with a fixture row WHEN resolved THEN school/group/child refs and photoConsent map are returned
  - GIVEN a subjectRef with no fixture row WHEN resolved THEN an empty audience is returned, never an error
- [x] Implement
- [x] Test (`GuardianAudienceFixtureReaderTest`, 6 tests)

### Task 3: NewsAudienceMatcher
- **spec_ref**: `openspec/changes/news-and-newsletter-authoring/specs/portaliq-cms/spec.md#requirement-a-newsitem-is-authored-per-school-group-or-child-and-tracks-read-receipts`
- **files**: `lib/Service/NewsAudienceMatcher.php`
- **acceptance_criteria**:
  - GIVEN a target and a guardian audience WHEN any of school/group/child matches THEN true; otherwise false
- [x] Implement
- [x] Test (`NewsAudienceMatcherTest`, 6 tests)

### Task 4: NewsController (authoring, publish, read receipt)
- **spec_ref**: `openspec/changes/news-and-newsletter-authoring/design.md#api-design`
- **files**: `lib/Controller/NewsController.php`, `lib/Controller/NewsGuardianController.php`, `lib/Service/NewsReadReceiptService.php`, `appinfo/routes.php`
- **acceptance_criteria**:
  - GIVEN staff creates and publishes a newsItem WHEN a matching guardian reads it THEN it appears; a non-matching guardian never sees it
  - GIVEN a guardian reads an item twice WHEN receipts are inspected THEN exactly one receipt exists
- [x] Implement
- [x] Test (`NewsReadReceiptServiceTest`, 3 tests; controllers verified by php -l + phpcs, no live NC container in this lane)

### Task 5: NewsFeedReader — dedicated guardian read path
- **spec_ref**: `openspec/changes/news-and-newsletter-authoring/design.md#architecture-overview`
- **files**: `lib/Service/NewsFeedReader.php`
- **acceptance_criteria**:
  - GIVEN a guardian's resolved audience WHEN the feed is read THEN only published, in-audience items return, an out-of-audience id 404s identically to a non-existent one
- [x] Implement
- [x] Test (`NewsFeedReaderTest`, 4 tests)

### Task 6: NewsletterController (compose, preflight, send, archive)
- **spec_ref**: `openspec/changes/news-and-newsletter-authoring/specs/portaliq-cms/spec.md#requirement-a-newsletter-send-is-preceded-by-a-recipient-count-preflight`
- **files**: `lib/Controller/NewsletterController.php`, `lib/Service/NewsletterPreflightService.php`, `appinfo/routes.php`
- **acceptance_criteria**:
  - GIVEN a newsletter targeting a group with N guardians WHEN preflight is called THEN it reports N, and sending reaches exactly N guardians' archives
  - GIVEN a newsletter whose target resolves to zero guardians WHEN send is attempted THEN it is refused and sentAt stays null
- [x] Implement
- [x] Test (`NewsletterPreflightServiceTest`, 3 tests)

### Task 7: NewsPhotoConsentGate
- **spec_ref**: `openspec/changes/news-and-newsletter-authoring/specs/portaliq-cms/spec.md#requirement-photos-in-a-news-item-are-gated-by-the-target-childs-photo-consent`
- **files**: `lib/Service/NewsPhotoConsentGate.php`
- **acceptance_criteria**:
  - GIVEN any targeted child with withheld or unresolvable consent WHEN a newsItem is read THEN photoRefs is empty
  - GIVEN all targeted children with granted consent WHEN a newsItem is read THEN photoRefs is unchanged
- [x] Implement
- [x] Test (`NewsPhotoConsentGateTest`, 4 tests)

### Task 8: Minimal staff authoring UI — BLOCKED, deferred to a follow-up
- **spec_ref**: `openspec/changes/news-and-newsletter-authoring/design.md#nl-design-system`
- **files**: `src/views/News/NewsListView.vue`, `src/views/News/NewsEditorView.vue`
- **acceptance_criteria**:
  - GIVEN staff on the news list WHEN they create and publish an item THEN it appears in the list with its status
- [ ] Implement — NOT DONE this PR: the backend (schemas, controllers, services, tests) is the load-bearing half and ships complete; staff can already author through the raw `/api/news`/`/api/newsletters` endpoints or OpenRegister's own generic admin object editor. A dedicated Vue list/editor is scoped as a fast, low-risk follow-up PR against this same backend.
- [ ] Test

## Quality checklist

- All new business logic covered by PHPUnit unit tests (`tests/Unit/Service/`, `tests/Unit/Controller/`)
- New API endpoints documented in design.md's API Design section
- Dutch (`nl`) and English (`en`) labels added for the staff authoring UI (ADR-007)
- `openspec validate news-and-newsletter-authoring` passes
- SPDX headers on every new PHP file (hydra-gate-spdx)
- No forbidden debug helpers (hydra-gate-forbidden-patterns)

## Verification
- [ ] All tasks checked off — Task 8 (staff UI) intentionally not done, see its note
- [x] `openspec validate news-and-newsletter-authoring --strict` passes
- [x] Diff-scoped gates green on touched files (php -l, phpcs, phpunit --filter — 25/25 passing)
