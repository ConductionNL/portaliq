# Tasks: site-at-the-root-of-a-portal-host

One PR. Built from `origin/development`.

- [x] **T1**: `PortalDomainMatch` holds the verified host match; `PortalResolver::resolveByHost()` and the new `servingDomain()` both use it (REQ-SRH-001, REQ-SRH-003, design D3).
  - PHPUnit `PortalResolverTest::testAVerifiedHostIsServedOnItsOwnDomainEntry`, `::testAnUnknownOrUnverifiedHostHasNoServingDomain`, `::testANamedPortalOnAnotherPortalsHostGetsNoDomain`, `::testAForgedHostHeaderSelectsNoDomainAndNoPortal`
- [x] **T2**: `SiteAddressing` gives the shell `addressing`, the requested route and the canonical address; `landingRoute` accepts plain paths only (REQ-SRH-001, REQ-SRH-002).
  - PHPUnit `SiteAddressingTest`
- [x] **T3**: route `portalPage#sitePath` (`/site/{path}`), 404 when no portal resolves; `site()` and `sitePath()` share one shell (REQ-SRH-001, REQ-SRH-003).
  - PHPUnit `PortalPageControllerHostRootTest`, over the real resolver, changing only the resolved host
- [x] **T4**: `servedAtRoot` and `landingRoute` on the `portal.domains[]` items of the register (REQ-SRH-001, REQ-SRH-002).
- [x] **T5**: the renderer reads and writes addresses through `src/site/lib/siteAddress.js` (REQ-SRH-004).
  - `tests/site-address.spec.mjs` (`npm run check:site-address`, in `check:specs`), including the wiring in `App.vue`
- [x] **T6**: `docs/operations/custom-domains.md` gives the proxy rule (Envoy Gateway, nginx) and the session consequence (design D5, D6).
- [ ] **T7** (platform lane): the gateway rule on the demo hosts, then a live check: `/`, `/afval`, an asset, a content API call, a reload on a deep page, the second host, the workplace host. Not run: needs the gateway.
- [ ] **T8** (follow-up): the two settings in the Domeinen field-list; short return addresses after sign-in.
