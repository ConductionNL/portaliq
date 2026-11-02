## ADDED Requirements

### Requirement: A verified domain may serve the site at its root (REQ-SRH-001)

A portal domain SHALL accept `servedAtRoot`, a boolean, default false. The site SHALL answer a page at `/apps/portaliq/site/{path}` as it answers `/apps/portaliq/site?route=/{path}`, so a proxy can rewrite the short address of a host to it. When the request host matches a verified domain that declares `servedAtRoot: true`, and the request names no other portal, the site MUST write its links, its history entries and its canonical address as short addresses on that host. Anywhere else it MUST keep the long addresses. A page opened on the long address MUST keep long addresses.

#### Scenario: A page on the short address
@e2e exclude Needs the proxy rule in front of Nextcloud; covered by PHPUnit tests/Unit/Controller/PortalPageControllerHostRootTest.php and tests/site-address.spec.mjs, live check is task T7
- GIVEN a portal with the verified domain `www.gemeente.example`, `servedAtRoot: true`
- AND a proxy that rewrites `/afval` on that host to `/index.php/apps/portaliq/site/afval`
- WHEN a visitor opens `https://www.gemeente.example/afval`
- THEN the page `/afval` of that portal is shown
- AND its canonical address is `https://www.gemeente.example/afval`
- AND a link to `/contact` on it reads `https://www.gemeente.example/contact`

#### Scenario: A domain without the declaration
@e2e exclude PHPUnit tests/Unit/Controller/PortalPageControllerHostRootTest.php and tests/site-address.spec.mjs
- GIVEN a verified domain without `servedAtRoot`
- WHEN a visitor opens the site on that host
- THEN every link is a long address with `?route=`, as before

#### Scenario: The long address on a declaring host
@e2e exclude Unit test in node: tests/site-address.spec.mjs
- GIVEN the domain `www.gemeente.example` declares `servedAtRoot: true`
- WHEN a visitor opens `https://www.gemeente.example/index.php/apps/portaliq/site?route=/afval`
- THEN links on that page are long addresses

### Requirement: A domain may open on a page of its own (REQ-SRH-002)

A portal domain SHALL accept `landingRoute`, a site route with a leading slash. When the request host matches that verified domain and the request names no page, the site MUST open that route. A named page MUST win over it. A value that is not a plain path (a scheme, `//`, a backslash, a query, a fragment, whitespace or a control character) MUST be ignored. One portal MAY carry several domains with different landing routes.

#### Scenario: The host of the signed-in area
@e2e exclude Needs the proxy rule; covered by PHPUnit tests/Unit/Controller/PortalPageControllerHostRootTest.php
- GIVEN a portal with the verified domains `www.gemeente.example` and `mijn.gemeente.example`, the second with `landingRoute: /mijn`
- WHEN a visitor opens `https://mijn.gemeente.example/`
- THEN the page `/mijn` of that portal is shown, which asks a signed-out visitor to sign in
- AND `https://www.gemeente.example/` shows the home page

#### Scenario: A landing route that leads elsewhere
@e2e exclude PHPUnit tests/Unit/Service/SiteAddressingTest.php
- GIVEN a domain whose `landingRoute` is `//evil.example`
- WHEN a visitor opens the bare address of that host
- THEN the home page is shown

### Requirement: A host that resolves no portal must get no site (REQ-SRH-003)

A request on a host that matches no verified domain, and that names no published portal, MUST resolve to no portal. The path route MUST answer it with 404 and a shell without a portal's title, theme, notices, addressing or landing page. An unverified domain MUST behave as an unknown host, whatever it declares. The host MUST be the one Nextcloud resolved for the request; a `Host` or `X-Forwarded-Host` header from an untrusted client MUST NOT select a portal. A request that names another portal by slug MUST NOT get the domain settings of the host it came in on.

#### Scenario: An unknown host
@e2e exclude PHPUnit tests/Unit/Controller/PortalPageControllerHostRootTest.php
- GIVEN no portal lists `nobody.example`
- WHEN a request for `/index.php/apps/portaliq/site/afval` arrives on that host
- THEN the answer is 404 with no portal's title or theme

#### Scenario: An unverified domain that declares root serving
@e2e exclude PHPUnit tests/Unit/Controller/PortalPageControllerHostRootTest.php and tests/Unit/Service/PortalResolverTest.php
- GIVEN a portal lists `claimed.example` unverified, with `servedAtRoot: true` and `landingRoute: /mijn`
- WHEN a request arrives on that host
- THEN the answer is 404 and no landing page is opened

#### Scenario: A forged host header
@e2e exclude PHPUnit tests/Unit/Controller/PortalPageControllerHostRootTest.php and tests/Unit/Service/PortalResolverTest.php
- GIVEN Nextcloud resolves the request host as the workplace host
- WHEN the request carries `Host: www.gemeente.example` from an untrusted client
- THEN no portal is selected

### Requirement: The renderer must read and write the addresses of its host (REQ-SRH-004)

The renderer SHALL read the page from `?route=` first, then from the path (the whole path on a short address, the part after the site route on a long one), then from the landing route, then `/`. It SHALL write links and history entries through one helper, keeping only `?portal=` and `?lang=` on a link. An address it writes MUST read back as the route it was written for.

#### Scenario: Back and forward on short addresses
@e2e exclude Unit test in node: tests/site-address.spec.mjs; browser history needs the proxy rule, live check is task T7
- GIVEN a visitor on `https://www.gemeente.example/afval` follows a link to `/contact`
- WHEN the visitor goes back
- THEN the address is `https://www.gemeente.example/afval` and the page `/afval` is shown
