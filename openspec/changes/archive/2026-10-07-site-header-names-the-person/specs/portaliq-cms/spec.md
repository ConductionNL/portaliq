## ADDED Requirements

### Requirement: The header must name the signed-in person, never their reference

The session endpoint MUST answer the portal account's display name as `displayName`, and MUST answer `''` when the account has none or when the value equals the subject reference. The site header MUST show "Logged in as {name}" with that name, and "Logged in" when no name is known. The header MUST NOT show the subject reference.

#### Scenario: A parent with a name on her account
- GIVEN Fatima Hulstkamp's portal account carries the display name "Fatima Hulstkamp"
- WHEN she signs in on the Dutch parent portal
- THEN the header reads "Ingelogd als Fatima Hulstkamp"
- @e2e exclude pinned by `tests/site-signed-in-shell.spec.mjs` and `SessionControllerTest::testIndexNamesThePersonNeverTheReference`; live-checked on the primary-school instance

#### Scenario: An account without a name
- GIVEN a portal account without a display name
- WHEN its holder signs in
- THEN the header reads "Ingelogd" and shows no reference
- @e2e exclude pinned by `tests/site-signed-in-shell.spec.mjs`
