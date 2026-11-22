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
- [x] Implement
- [x] Test
- **built** (under portaliq-cms): `lib/Service/PortalResolver.php` resolves the verified host (taken from `IRequest::getServerHost()`, which honours forwarded hosts only from trusted proxies) with no default portal; controllers turn a miss into the shared 404. There is no separate `PortalResolutionMiddleware`: each served route calls the resolver. Tests: `PortalResolverTest` (`testASingleConfiguredSiteIsStillNotADefault`, `testEachHostResolvesToItsOwnSite`, `testAForgedHostHeaderSelectsNoDomainAndNoPortal`, `testANamedPortalOnAnotherPortalsHostGetsNoDomain`, `testAnUnknownSlugDoesNotFallThroughToTheHost`).

### Task 2: Domain binding and DNS verification
- **spec_ref**: `openspec/changes/portal-scoping-and-auth/specs/portal-scoping-and-auth/spec.md#requirement-a-custom-domain-must-be-verified-before-it-serves`
- **files**: `lib/Service/DomainVerificationService.php`, `lib/BackgroundJob/DomainReverificationJob.php`, `tests/Unit/Service/DomainVerificationServiceTest.php`
- **acceptance_criteria**:
  - An unverified domain behaves exactly as an unknown host (404), even when DNS already points at Portaliq
  - BOTH directions are tested: verification SUCCEEDS with the record present and FAILS without it — a verifier that always fails looks identical to one that works if only the failure case is exercised
  - A per-portal nonce is used, so a record published for portal A does not verify portal B
  - Re-verification runs periodically; a removed record eventually unbinds
- [x] Implement
- [x] Test
- **built**: the TXT check with a per-portal token is `lib/Service/Cms/PortalDomainVerifier.php` (portal-cms-admin-ui), tested in both directions (`PortalDomainVerifierTest::testAnotherPortalsTokenDoesNotVerify`, `testNoPropagationYetIsPendingAndCanBeRunAgain`, `testAVerifiedDomainStoresTheEndOfItsCertificate`). This change adds the periodic re-check: `PortalDomainVerifier::reverify()` and `lib/BackgroundJob/DomainReverificationJob.php` (every 12 hours) unbind a verified domain whose record is gone three re-checks in a row; a failed lookup counts for nothing, a found record resets the count. Domain fields `reverifyMisses` and `unboundAt` (register 0.124.0, portal 0.36.0). Tests: `tests/Unit/Service/Cms/PortalDomainReverifyTest.php`, `tests/Unit/BackgroundJob/DomainReverificationJobTest.php`.

### Task 3: Per-portal presentation resolved at runtime
- **spec_ref**: `openspec/changes/portal-scoping-and-auth/specs/portal-scoping-and-auth/spec.md#requirement-presentation-must-resolve-per-portal-at-runtime`
- **files**: `lib/Controller/PortalPageController.php`, `templates/portal.php`, `tests/Unit/Controller/PortalPageControllerTest.php`
- **acceptance_criteria**:
  - `index()` resolves presentation from the PORTAL rather than the Organisation, keeping every field `PortalOrganisationConfigService` already resolves (name, logo, theme, locales, feature flags, embed origins)
  - An Organisation with exactly one portal renders byte-identically to before — asserted against a recorded pre-change response, because "still works" is the claim most easily assumed
  - Two portals of the SAME Organisation produce two different responses; this is the capability that does not exist today
  - An unresolvable theme reference names the missing theme rather than rendering unthemed
- [x] Implement
- [x] Test
- **built** (under portaliq-cms): `lib/Service/PortalRuntimeConfigResolver.php` resolves name, logo, theme, locales, feature flags and embed origins from the serving portal (`PortalRuntimeConfigResolverTest::testAResolvedPortalSuppliesItsOwnName`, `testAResolvableThemeIsReported`). One criterion was decided differently in the canonical spec (`openspec/specs/portaliq-cms/spec.md`, "A portal's theme MUST change what a visitor sees"): an unresolvable theme renders unstyled rather than naming the missing theme (`testAnUnresolvableThemeIsNotReportedAsResolved`). The byte-identical single-portal comparison applied to the moment of migration, which has passed.

### Task 4: Per-portal authentication and scoped sessions
- **spec_ref**: `openspec/changes/portal-scoping-and-auth/specs/portal-scoping-and-auth/spec.md#requirement-each-portal-must-declare-its-authentication-failing-closed`
- **files**: `lib/Auth/PortalAuthConfig.php`, `lib/Auth/PortalProtected.php`, `lib/Controller/SessionController.php`, `tests/Unit/Auth/*Test.php`
- **acceptance_criteria**:
  - The mode set is closed; `oidc` covers Google/Microsoft/Keycloak through one integration
  - Malformed configuration issues NO session and permits NO write, while anonymous read of published content still works — the fail-closed default is `public` read-only, not "authenticated" and not public read-write
  - A session minted for portal A is refused by portal B, and the refusal is indistinguishable from an invalid session
  - `minTrust` is enforced for the government modes
- [x] Implement
- [x] Test
- **done (22 Nov)**: the content reads now take the same reading as the ways in. `PortalAuthConfig::readsPublicly()` is true for a portal without modes, one offering `public`, and a malformed block, so a malformed block fails closed to public read-only. `PublicContentGate` (content, news and catalogue reads), `MediaFile` (media streaming) and `NextcloudRegistrationDoor` (the registration group) read the block through `PortalAuthConfig` and no longer parse `modes` themselves. Before this, `modes: ['digid', 7]` asked for sign-in on the content reads while every way in stayed shut. Tests: `PortalAuthConfigTest` (readsPublicly on well-formed, absent and every malformed block), `PublicContentGateGoldenTest` (a `malformed` caller reads publicly), `MediaFileTest::testAPortalWithAMalformedBlockStillServesItsPublishedItem`, `NextcloudAccountRegistrationTest::testTheDoorStaysShutWhenItWouldLeadNowhere` (malformed block). The content golden fixtures now use a well-formed sign-in portal.
- **progress** (this change): a session minted on a portal is bound to it. `PortalJwtService` signs a `portal` claim, `PortalSessionService::issueSession(portal:)` takes it and every refresh or rebranch carries it, and `BearerSessionResolver` refuses a portal-bound session through `lib/Auth/PortalSessionScope.php` when the request is served by another portal (verified host first, a named `portal` only when the host resolves none), exactly as an invalid bearer. The Nextcloud way in (`SessionController::nextcloudSession`) and the desk session (`DeskSessionController`) bind their sessions to their portal. Already enforced elsewhere: content reads per portal mode and `minTrust` (`PublicContentGate`), and each way in refuses a portal that does not offer its mode. Tests: `tests/Unit/Auth/PortalSessionScopeTest.php`, `BearerSessionResolverTest`, `PortalSessionServiceTest::testAPortalSessionStaysOnItsPortalThroughARefresh`, `SessionClaimsTest::testThePortalClaimReadsBackOnlyAsASlug`.
- **progress (17 Nov, continued)**: every way in now binds its session to its portal. The login state row keeps the portal slug (`portalOidcState` 0.4.0, register 0.125.0): `SessionController::oidcStart` and `BrokerSessionController::start` store the resolved portal's slug, and `oidcCallback` / `BrokerLogin::complete` mint with it; the e-mail link mints with the portal it was asked on, the example resident with the portal it was found on. `lib/Auth/PortalAuthConfig.php` reads `authentication` against the closed mode set (the schema's enum, asserted equal) and a malformed block (not an object, modes not a list, a mode outside the set, a `minTrust` outside low/substantial/high) offers no way in, so no session is issued and nothing can be written through it; the e-mail link, Nextcloud sign-in, Nextcloud account provisioning, example-resident sign-in and the lost-password link ask it. Tests: `PortalAuthConfigTest`, `OidcStateStoreServiceTest::testARowKeepsThePortalTheLoginStartedOn`, `SessionControllerTest::testOidcCallbackBindsTheSessionToThePortalTheLoginStartedOn`, `BrokerLoginTest::testABrokerSessionIsBoundToThePortalTheLoginStartedOn`, `EmailLinkSettingTest::testAMalformedAuthenticationBlockOffersNoLink`, and the tightened start/session expectations in `BrokerSessionControllerTest`, `EmailLinkControllerTest`, `ExampleResidentControllerTest`.
- **progress (17 Nov, per-portal OIDC provider)**: a portal names the one OIDC provider it accepts in `authentication.oidc` (`issuer`, optionally `clientId`; register 0.126.0, portal 0.37.0). The provider's settings and secret stay with the organisation; `PortalAuthConfig::acceptsOidcProvider()` compares them with what the portal names (issuer without a trailing slash, client id exactly), and an `oidc` value that is not an object or an empty/non-string `issuer`/`clientId` is malformed. `SessionController::oidcStart` refuses a provider the serving portal does not name before any state is stored, and `oidcCallback` looks the start portal up by the slug its state row kept and refuses, before the code is exchanged, a provider that portal does not name or a portal that no longer resolves; both with the identical generic error and no session. A portal that names no provider accepts its organisation's, as before. Tests: `PortalAuthConfigTest::testAPortalThatNamesItsProviderAcceptsOnlyThatProvider`, `testAnIssuerAloneDoesNotPinTheClient`, `testAPortalThatNamesNoProviderAcceptsTheOrganisations` (+ malformed `oidc` rows), `SessionControllerTest::testOidcCallbackAcceptsOnlyTheProviderTheStartPortalNames`, `testOidcCallbackRefusesWhenTheStartPortalIsGone`, `testOidcStartRefusesAProviderThePortalDoesNotName`.
- **progress (21 Nov, admin fields)**: the portal's Instellingen tab has an "OIDC provider" section (`portal-settings-oidc` in `src/manifest.json`, a `settings-form`) that writes `authentication.oidc.issuer` (an https address or empty) and `authentication.oidc.clientId`. The form merges the dot-paths into the stored `authentication` block, so the modes are kept, and a cleared field is sent as null, which names no provider rather than a malformed one. Test: `tests/portal-oidc-provider-settings.spec.mjs` (`check:portal-oidc-provider-settings`, in `check:specs`).
- **progress (21 Nov, trust floor)**: the serving portal's `authentication.minTrust` is a floor every presented session meets. `PortalSessionScope::admits(portal, trust)` reads it through `PortalAuthConfig` and refuses a lower session there exactly as a session from another portal, bound or not; a session at `high` meets every floor and is admitted without resolving the portal, and an unbound lower one is refused when the serving portal cannot be resolved. `BearerSessionResolver` asks the scope for every session with its trust. A portal that declares no floor, or `low` (both example sites), is unchanged. Tests: `PortalSessionScopeTest::testASessionBelowTheServingPortalsFloorIsRefused`, `testWithoutAFloorEverySessionMeetsIt`, `BearerSessionResolverTest::testASessionBelowTheServingPortalsFloorResolvesToNoSubject`, `testAPortalBoundSessionWithoutAScopeIsRefusedAndAnUnboundOneIsAskedWithItsTrust`.
- **left**: (1) done above; the integriq broker route keeps its own provider check. (2) The content read gate (`PublicContentGate`) still reads `modes` itself: moving it onto `PortalAuthConfig` would make a malformed block on a restricted portal publicly readable, as the spec asks ("anonymous read of published content still works"), but that opens content that is gated today, so it is left for a reviewed decision rather than done in a build pass. `devLogin` stays unbound (debug only).

### Task 5: Per-portal CSP
- **spec_ref**: `openspec/changes/portal-scoping-and-auth/specs/portal-scoping-and-auth/spec.md#requirement-frame-ancestors-must-be-derived-per-portal`
- **files**: `lib/Controller/PortalPageController.php`, `tests/Unit/Controller/PortalCspTest.php`
- **acceptance_criteria**:
  - `frame-ancestors` is built from the resolved PORTAL's origins instead of the Organisation's; the existing deny-by-default (clearing the `'self'` default first) is preserved, not re-derived
  - Two portals of one Organisation can permit different embedders — the capability this task adds
  - A test asserts no served response ever emits `frame-ancestors: *`. The wildcard is already gone from `lib/`; the test exists so it cannot come back unnoticed, which is a different job from removing it
- [x] Implement
- [x] Test
- **built**: the site page is never framed (`frame-ancestors 'none'`, `PortalPageControllerTest::testOnlyTheMeasurementMayFrameTheSiteAndOnlyFromItsOwnOrigin`, which also asserts no wildcard); embedding is per form binding through `PortalEmbedGuard` (`PortalEmbedGuardTest::testOnlyTheDeclaredOriginMayFrameTheForm`, `testFrameAncestorsIsNeverAWildcard`), so two portals of one organisation permit different embedders.

