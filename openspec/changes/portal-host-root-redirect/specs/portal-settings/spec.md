## ADDED Requirements

### Requirement: The bare address of a portal host must lead to its site (REQ-HRR-001)

When a request for the bare address of the instance (`/`, `/index.php` or `/index.php/`) arrives as a page (GET or HEAD, through `index.php`, not asking for JSON or XML) on a host that matches a verified domain of a published portal, the app MUST answer 302 with a `Location` on the same host: the long address of the site on the domain's `landingRoute` when it is a valid plain path, else the long address of the site's home. This holds for a guest and for a signed-in user. The address MUST be built from the route table and the stored domain entry, never from request input. It MUST be the long address also when the domain declares `servedAtRoot`.

#### Scenario: The main host of a portal
@e2e exclude Needs a second host name on the test instance; covered by PHPUnit tests/Unit/AppInfo/HostRootRedirectBootTest.php through Application::boot(), live check is task T5
- GIVEN a portal with the verified domain `www.gemeente.example` and no landing route
- WHEN a visitor opens `https://www.gemeente.example/`
- THEN the answer is 302 to `/index.php/apps/portaliq/site`

#### Scenario: The host of the signed-in area
@e2e exclude Needs a second host name on the test instance; covered by PHPUnit tests/Unit/AppInfo/HostRootRedirectBootTest.php, live check is task T5
- GIVEN the same portal with the verified domain `mijn.gemeente.example` and `landingRoute: /mijn`
- WHEN a visitor opens `https://mijn.gemeente.example/`
- THEN the answer is 302 to `/index.php/apps/portaliq/site/mijn`, which asks a signed-out resident to sign in

#### Scenario: A landing route that leads elsewhere
@e2e exclude PHPUnit tests/Unit/AppInfo/HostRootRedirectBootTest.php
- GIVEN a verified domain whose `landingRoute` is `//evil.example`
- WHEN a visitor opens its bare address
- THEN the answer is 302 to the long address of the site's home

#### Scenario: A domain that declares root serving without the proxy rule
@e2e exclude PHPUnit tests/Unit/AppInfo/HostRootRedirectBootTest.php
- GIVEN a verified domain with `servedAtRoot: true`
- WHEN its bare address reaches Nextcloud
- THEN the answer is 302 to the long address, never to `/`

### Requirement: Any other host must keep Nextcloud's own root (REQ-HRR-002)

A request on a host that matches no verified domain of a published portal MUST get no redirect from the app, so Nextcloud answers it as it would without the app. An unverified domain MUST be treated as an unknown host, whatever it declares. The host MUST be the one Nextcloud resolved for the request; a `Host` or `X-Forwarded-Host` header from an untrusted client MUST NOT select a portal. When the portals cannot be read, there MUST be no redirect.

#### Scenario: The workplace host
@e2e exclude The test instance has one host; covered by PHPUnit tests/Unit/AppInfo/HostRootRedirectBootTest.php, live check is task T5
- GIVEN no portal lists `cloud.gemeente.example`
- WHEN a guest opens `https://cloud.gemeente.example/`
- THEN Nextcloud sends the guest to its login page, as without the app

#### Scenario: An unverified domain
@e2e exclude PHPUnit tests/Unit/AppInfo/HostRootRedirectBootTest.php
- GIVEN a portal lists `claimed.example` unverified, with `landingRoute: /mijn`
- WHEN a visitor opens `https://claimed.example/`
- THEN the app sends no redirect

#### Scenario: A forged host header
@e2e exclude PHPUnit tests/Unit/AppInfo/HostRootRedirectBootTest.php
- GIVEN Nextcloud resolves the request host as the workplace host
- WHEN the request carries `Host: www.gemeente.example` from an untrusted client
- THEN the app sends no redirect

#### Scenario: The portals cannot be read
@e2e exclude PHPUnit tests/Unit/AppInfo/HostRootRedirectBootTest.php
- GIVEN reading the published portals fails
- WHEN a visitor opens the bare address of a portal host
- THEN the app sends no redirect

### Requirement: An employee must still reach the sign-in of Nextcloud on a portal host (REQ-HRR-003)

The app MUST NOT redirect any request but the bare address as a page. `/login`, every other path, a POST, another entry script (`cron.php`, `status.php`, `remote.php`, the console) and a JSON or XML client MUST be left to Nextcloud on every host.

#### Scenario: Sign-in on a portal host
@e2e exclude Needs a second host name on the test instance; covered by PHPUnit tests/Unit/AppInfo/HostRootRedirectBootTest.php, live check is task T5
- GIVEN a portal with the verified domain `www.gemeente.example`
- WHEN an employee opens `https://www.gemeente.example/login`
- THEN Nextcloud's login page is shown

#### Scenario: Other requests on a portal host
@e2e exclude PHPUnit tests/Unit/AppInfo/HostRootRedirectBootTest.php
- GIVEN a portal with the verified domain `www.gemeente.example`
- WHEN a request on that host asks for `/apps/files`, posts to `/`, runs `cron.php` or asks for `/` as JSON
- THEN the app sends no redirect
