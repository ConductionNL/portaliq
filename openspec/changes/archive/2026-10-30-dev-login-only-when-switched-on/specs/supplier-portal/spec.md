## ADDED Requirements

### Requirement: The test sign-in opens only on its own switch

`SessionController::devLogin()` MUST answer only when the app config
`portaliq`/`dev_login_enabled` is `yes`, and MUST issue trust `low`. System
`debug` MUST NOT open it. Every refusal MUST be a 404 marked for the bruteforce
throttler. The site MUST offer the test sign-in button only while the switch is
`yes`. It is unaffected by the OIDC edge.

#### Scenario: Debug mode does not open it
@e2e exclude PHPUnit: tests/Unit/Controller/SessionControllerTest.php and tests/Unit/Service/PortalRuntimeConfigResolverTest.php
- **GIVEN** an instance with `debug` on and no `dev_login_enabled`
- **WHEN** `POST /portal/api/session/dev-login` is called and the sign-in page is served
- **THEN** the call answers 404 and the page offers no test sign-in

#### Scenario: The switch opens it
@e2e exclude PHPUnit: tests/Unit/Controller/SessionControllerTest.php
- **GIVEN** `dev_login_enabled` is `yes`
- **WHEN** `POST /portal/api/session/dev-login` is called
- **THEN** a session at trust `low` is issued

## REMOVED Requirements

### Requirement: Dev-login stays debug-gated at trust low
**Reason**: `debug` opened a passwordless sign-in on a public page without anybody switching it on for portaliq.
**Migration**: "The test sign-in opens only on its own switch". An instance that relied on `debug` sets `occ config:app:set portaliq dev_login_enabled --value=yes`.
