## ADDED Requirements

### Requirement: An eHerkenning sign-in is a business sign-in (REQ-SEE-001)

A session minted from an eHerkenning sign-in SHALL carry the identity type `eherkenning` and the audience `supplier`, unless the portal account it joins names another audience. This SHALL hold on the `oidc` route and on the `broker` route alike. When the organisation names a branch claim in its claim map, a twelve-digit branch number in that claim SHALL be signed into the session; a malformed branch number SHALL be dropped and the sign-in SHALL go on. The session's trust SHALL be the organisation's mapping of the eHerkenning level.

#### Scenario: A business user signs in through the broker
- **GIVEN** an organisation whose eHerkenning provider is routed to the broker
- **WHEN** a business user completes the eHerkenning sign-in
- **THEN** the session's identity type is `eherkenning` and its audience is `supplier`
- @e2e exclude Session claims; pinned by BrokerLoginTest and OidcClaimMapperServiceTest

#### Scenario: A malformed branch number does not stop the sign-in
- **GIVEN** an organisation whose claim map names the branch claim `vestigingsnummer`
- **WHEN** the broker sends `vestigingsnummer` as `12AB`
- **THEN** the session is minted without a branch
- @e2e exclude Claim mapping; pinned by OidcClaimMapperServiceTest::testAMalformedBranchIsDroppedAndTheLoginGoesOn

### Requirement: An eIDAS sign-in is a visitor from another EU country (REQ-SEE-002)

A session minted from an eIDAS sign-in SHALL carry the identity type `eidas` and the audience `client`. Its subject reference SHALL be derived by portaliq from the identity reference, unless the organisation maps a claim for it. It MUST NOT be taken from a citizen service number claim. The session's trust SHALL be the organisation's mapping of the eIDAS level of assurance, and an unmapped level SHALL map to `low`.

#### Scenario: An EU visitor signs in
- **GIVEN** an organisation offering eIDAS on the broker route
- **WHEN** a visitor completes an eIDAS sign-in with level `substantial` mapped to `substantial`
- **THEN** the session's identity type is `eidas`, its audience is `client` and its trust is `substantial`
- @e2e exclude Session claims; pinned by BrokerLoginTest and OidcClaimMapperServiceTest

#### Scenario: An unmapped level stays low
- **GIVEN** an eIDAS sign-in whose level the organisation's map does not name
- **WHEN** the session is minted
- **THEN** its trust is `low`
- @e2e exclude Trust mapping; pinned by OidcClaimMapperServiceTest::testAnUnmappedLoaMapsToLowNeverHigher

### Requirement: The bearer reaches the browser in the fragment and leaves the address (REQ-SEE-003)

Every route that ends a sign-in with a session (the OIDC callback, the broker callback, the Nextcloud way in and the example resident sign-in) SHALL redirect with the bearer in the URL fragment as `#token=`, never in a query string. The site renderer and the shared portal client SHALL read that fragment once on load, keep the bearer in the tab's own storage, and replace the address with one without the fragment through `history.replaceState`. A load without a `#token=` fragment SHALL adopt nothing from the address.

#### Scenario: Back on the site, signed in
- **GIVEN** a resident who completed the DigiD sign-in
- **WHEN** the browser lands on `/apps/portaliq/site?portal=zuiddrecht#token=abc`
- **THEN** the site is signed in and the address reads `/apps/portaliq/site?portal=zuiddrecht`
- @e2e exclude Browser history; pinned by tests/site-auth.spec.mjs

#### Scenario: No token in a query string
- **WHEN** any sign-in route redirects after success
- **THEN** the location carries the bearer after `#token=` and no query parameter holds it
- @e2e exclude Redirect shape; pinned by SessionControllerTest and BrokerSessionControllerTest
