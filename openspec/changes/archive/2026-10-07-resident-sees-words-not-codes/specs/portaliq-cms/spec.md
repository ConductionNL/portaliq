## ADDED Requirements

### Requirement: The header must never name a person by a number

The site header MUST name a signed-in resident by their portal account's display name. A display name equal to the subject reference or to the account's identity number, or made of digits only (a BSN, a KvK number), MUST NOT be served by the session endpoint nor shown by the site: the line then reads "Ingelogd" ("Logged in"). Which name an account carries is set by provisioning or the broker, not by this rule.

#### Scenario: A BSN stored as the display name
- GIVEN a portal account whose `displayName` is `999993653`, its identity number
- WHEN the resident signs in on the site
- THEN the session's `displayName` is empty and the header reads "Ingelogd"
- @e2e exclude pinned by `SessionControllerTest::testIndexNamesThePersonNeverTheReference` and `tests/site-signed-in-shell.spec.mjs` ("the header says who is signed in")
