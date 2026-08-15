# Tasks: portal-headless-content-api

> Public content API + caching + invalidation + throttling (ADR-032 `kind: code`).
> Checkbox budget: 4 tasks × 2 = 8 unindented `- [ ]` lines (cap 20).

## Implementation Tasks

### Task 1: Public content endpoints
- **spec_ref**: `openspec/changes/portal-headless-content-api/specs/portal-headless-content-api/spec.md#requirement-the-api-must-expose-a-sites-navigation-pages-glossary-and-media`
- **files**: `lib/Controller/ContentController.php`, `lib/Service/ContentService.php`, `appinfo/routes.php`, `tests/Unit/Controller/ContentControllerTest.php`
- **acceptance_criteria**:
  - Site record, menu tree, page-by-route, page listing, glossary and media, every response scoped to the resolved website
  - Every route declares its auth posture explicitly; public routes use the attribute form the fleet's public-endpoint sweep actually counts
  - An unpublished page is indistinguishable from a non-existent route — no existence oracle
  - A markdown body is returned as source, byte-identical; a grid body returns canonical `$defs.widgetEntry` shapes
- [ ] Implement
- [ ] Test

### Task 2: Response cache keyed by audience
- **spec_ref**: `openspec/changes/portal-headless-content-api/specs/portal-headless-content-api/spec.md#requirement-responses-must-be-cached-with-the-audience-in-the-key`
- **files**: `lib/Service/ContentCache.php`, `tests/Unit/Service/ContentCacheTest.php`
- **acceptance_criteria**:
  - Key is website + route + locale + audience; anonymous and authenticated requests for one route never share an entry
  - The audience component is proven load-bearing: with it removed the separation test FAILS, and that failure is observed before the key is trusted — a cache that ignores audience is a data leak, not a slow path
  - Headers are stated ONCE per response: `private, no-store` for per-visitor, publicly cacheable for anonymous published content, with no contradictory second directive
  - Cache hits are measured, so a cache that never hits is distinguishable from no cache at all
- [ ] Implement
- [ ] Test

### Task 3: Event-driven invalidation
- **spec_ref**: `openspec/changes/portal-headless-content-api/specs/portal-headless-content-api/spec.md#requirement-a-write-must-invalidate-the-affected-cache-entries`
- **files**: `lib/Listener/ContentCacheInvalidationListener.php`, `tests/Unit/Listener/ContentCacheInvalidationListenerTest.php`
- **acceptance_criteria**:
  - A page update is visible on the next request without waiting for expiry
  - A menu update invalidates the pages that render it
  - Invalidation is website-scoped; another site's entries are untouched
  - The listener does its work off the write path per ADR-078, or carries a reason-bearing inline annotation
- [ ] Implement
- [ ] Test

### Task 4: Throttling and headless conformance
- **spec_ref**: `openspec/changes/portal-headless-content-api/specs/portal-headless-content-api/spec.md#requirement-the-anonymous-read-path-must-be-throttled`
- **files**: `lib/Controller/ContentController.php`, `tests/Integration/HeadlessConformanceTest.php`
- **acceptance_criteria**:
  - Anonymous reads are rate-limited; a cache hit is NOT an exemption
  - The throttle is proven by two independent discriminators — an absent success is not evidence it fired
  - A conformance test enumerates the built-in portal's capabilities and fails when one is unreachable through the public API, naming it
  - The conformance test reaches into no Portaliq internal, or it is not testing headlessness
- [ ] Implement
- [ ] Test
