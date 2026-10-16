# Tasks: portal-host-root-redirect

One PR, built on `feat/site-on-portal-host-root` (#1542).

- [x] **T1**: `SiteAddressing::hostRoot()` names where the bare address of a verified domain leads: the long site address on the cleaned `landingRoute`, or the home page; null for any other host (REQ-HRR-001, REQ-HRR-002, design D5).
- [x] **T2**: `HostRootRedirect` decides whether a request is the bare address as a page and sends the redirect; `HostRootRedirectSend` ends the request with it (REQ-HRR-001, REQ-HRR-003, design D3, D4).
- [x] **T3**: `Application::boot()` runs it for every request (REQ-HRR-001, design D2).
  - PHPUnit `tests/Unit/AppInfo/HostRootRedirectBootTest.php`, through `Application::boot()` over the real decision, addressing and resolver, changing only the request.
- [x] **T4**: `docs/operations/custom-domains.md` says the bare address needs no proxy rule any more, and gives the nginx alternative.
- [ ] **T5** (platform lane): live check on the demo hosts: `/` on the main host, the second host and the workplace host, as a guest and signed in; `/login` on the main host. Not run: needs the deployment.
