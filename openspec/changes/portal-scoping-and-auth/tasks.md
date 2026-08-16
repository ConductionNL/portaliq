# Tasks: portal-scoping-and-auth

> Portal resolution, domain verification, per-portal presentation, auth and CSP
> (ADR-032 `kind: mixed`). Checkbox budget: 5 tasks × 2 = 10 unindented
> `- [ ]` lines (cap 20).

## Implementation Tasks

### Task 1: Host-based portal resolution with no fallback
- **spec_ref**: `openspec/changes/portal-scoping-and-auth/specs/portal-scoping-and-auth/spec.md#requirement-a-request-must-resolve-to-exactly-one-portal-or-to-none`
- **files**: `lib/Middleware/PortalResolutionMiddleware.php`, `lib/Service/PortalResolver.php`, `tests/Unit/Service/PortalResolverTest.php`
- **acceptance_criteria**:
  - An unresolved host returns 404 and leaks no portal name, theme, logo or content — asserted on the whole response, not the status code
  - The host comes from the trusted proxy configuration; a forged `Host` header does not select a portal
  - There is NO default/first/fallback portal — asserted by a test with exactly one portal configured and a request for a different host
  - Two portals sharing a route stay separate under every request parameter
- [ ] Implement
- [ ] Test

### Task 2: Domain binding and DNS verification
- **spec_ref**: `openspec/changes/portal-scoping-and-auth/specs/portal-scoping-and-auth/spec.md#requirement-a-custom-domain-must-be-verified-before-it-serves`
- **files**: `lib/Service/DomainVerificationService.php`, `lib/BackgroundJob/DomainReverificationJob.php`, `tests/Unit/Service/DomainVerificationServiceTest.php`
- **acceptance_criteria**:
  - An unverified domain behaves exactly as an unknown host (404), even when DNS already points at Portaliq
  - BOTH directions are tested: verification SUCCEEDS with the record present and FAILS without it — a verifier that always fails looks identical to one that works if only the failure case is exercised
  - A per-portal nonce is used, so a record published for portal A does not verify portal B
  - Re-verification runs periodically; a removed record eventually unbinds
- [ ] Implement
- [ ] Test

### Task 3: Per-portal presentation resolved at runtime
- **spec_ref**: `openspec/changes/portal-scoping-and-auth/specs/portal-scoping-and-auth/spec.md#requirement-presentation-must-resolve-per-portal-at-runtime`
- **files**: `lib/Controller/PortalPageController.php`, `templates/portal.php`, `tests/Unit/Controller/PortalPageControllerTest.php`
- **acceptance_criteria**:
  - `index()` resolves presentation from the PORTAL rather than the Organisation, keeping every field `PortalOrganisationConfigService` already resolves (name, logo, theme, locales, feature flags, embed origins)
  - An Organisation with exactly one portal renders byte-identically to before — asserted against a recorded pre-change response, because "still works" is the claim most easily assumed
  - Two portals of the SAME Organisation produce two different responses; this is the capability that does not exist today
  - An unresolvable theme reference names the missing theme rather than rendering unthemed
- [ ] Implement
- [ ] Test

### Task 4: Per-portal authentication and scoped sessions
- **spec_ref**: `openspec/changes/portal-scoping-and-auth/specs/portal-scoping-and-auth/spec.md#requirement-each-portal-must-declare-its-authentication-failing-closed`
- **files**: `lib/Auth/PortalAuthConfig.php`, `lib/Auth/PortalProtected.php`, `lib/Controller/SessionController.php`, `tests/Unit/Auth/*Test.php`
- **acceptance_criteria**:
  - The mode set is closed; `oidc` covers Google/Microsoft/Keycloak through one integration
  - Malformed configuration issues NO session and permits NO write, while anonymous read of published content still works — the fail-closed default is `public` read-only, not "authenticated" and not public read-write
  - A session minted for portal A is refused by portal B, and the refusal is indistinguishable from an invalid session
  - `minTrust` is enforced for the government modes
- [ ] Implement
- [ ] Test

### Task 5: Per-portal CSP
- **spec_ref**: `openspec/changes/portal-scoping-and-auth/specs/portal-scoping-and-auth/spec.md#requirement-frame-ancestors-must-be-derived-per-portal`
- **files**: `lib/Controller/PortalPageController.php`, `tests/Unit/Controller/PortalCspTest.php`
- **acceptance_criteria**:
  - `frame-ancestors` is built from the resolved PORTAL's origins instead of the Organisation's; the existing deny-by-default (clearing the `'self'` default first) is preserved, not re-derived
  - Two portals of one Organisation can permit different embedders — the capability this task adds
  - A test asserts no served response ever emits `frame-ancestors: *`. The wildcard is already gone from `lib/`; the test exists so it cannot come back unnoticed, which is a different job from removing it
- [ ] Implement
- [ ] Test
