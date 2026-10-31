## ADDED Requirements

### Requirement: Only staff may open portaliq's Nextcloud app and read its register

portaliq's register and every schema in it SHALL grant read, create, update and delete only to the staff group `portaliq-medewerkers` (administrators bypass OpenRegister's checks), except the public site content (`page` while published, `menu`, `glossaryTerm`), which stays anonymously readable. No authorization rule SHALL name `authenticated`. Staff SHALL be an administrator, a member of `portaliq-medewerkers`, a member of a configured editor group, or a member of a group the action matrix grants an action. The configured editor groups SHALL also read the schemas they edit. `/apps/portaliq/` SHALL answer a signed-in account that is not staff with a 403, after sending a resident to their portal. Every OpenRegister call on a portal path SHALL pass `_rbac: false`, so the portal keeps working while the register is closed.

#### Scenario: A signed-in account outside the staff reads nothing through OpenRegister
- **GIVEN** a Nextcloud account in no group, or only in the resident group
- **WHEN** it lists `/apps/openregister/api/objects/portaliq/portalAccessRequest` or `portalSubmission`
- **THEN** OpenRegister returns no objects
- @e2e exclude enforced by OpenRegister from the shipped register; PHPUnit `RegisterAccessDeclaredTest` sweeps the register, and the live probe in the PR body checks the instance

#### Scenario: Public site content stays public
- **GIVEN** an anonymous visitor
- **WHEN** they read published pages, menus and glossary terms
- **THEN** they get them, and no other portaliq schema is anonymously readable
- @e2e exclude a property of the shipped register; PHPUnit `RegisterAccessDeclaredTest::testOnlyThePublicSiteContentIsPublic`

#### Scenario: A signed-in account that is not staff is refused the app
- **GIVEN** a Nextcloud account that is not a resident, not an administrator, not in the staff group, not an editor and holds no action
- **WHEN** it opens `/apps/portaliq/`
- **THEN** it gets a 403 and no access flags
- @e2e exclude controller branch; PHPUnit `DashboardControllerTest::testAnAccountThatIsNotStaffIsRefused`

#### Scenario: A resident is still sent to their portal
- **GIVEN** a member of a portal's resident group
- **WHEN** they open `/apps/portaliq/`
- **THEN** they are redirected to that portal's account route
- @e2e exclude controller branch; PHPUnit `DashboardControllerTest::testAResidentIsStillSentToTheirPortal`

#### Scenario: An editor outside the staff group still reads the drafts they edit
- **GIVEN** an editor group configured in the page editor setting
- **WHEN** the setting is saved or the register is re-imported
- **THEN** the group is in the `read` rule of page, media, menu, portalNotice and sharedBlock, and a group removed from the setting leaves it
- @e2e exclude schema write; PHPUnit `PageEditorServiceTest::testARemovedEditorGroupLosesTheRead`

#### Scenario: Portal paths keep working
- **GIVEN** the closed register
- **WHEN** a resident uses the portal with a portal session
- **THEN** every OpenRegister call on that path passes `_rbac: false`
- @e2e exclude source scan; PHPUnit `RegisterAccessDeclaredTest::testEveryPortalPathReadsWithTheChecksOff`

### Requirement: The staff group exists and keeps everybody who already worked in portaliq

A repair step SHALL create `portaliq-medewerkers` on install and upgrade and, once, add the members of the configured editor groups and of every group the action matrix names, never the members of `admin` or of the registration app's group.

#### Scenario: The upgrade seeds the staff group once
- **GIVEN** an editor group `redactie` and an action granted to `leerkrachten`
- **WHEN** the upgrade runs the repair step
- **THEN** their members are in `portaliq-medewerkers`, administrators and residents are not, and a later upgrade adds nobody
- @e2e exclude repair step; PHPUnit `SeedStaffGroupTest`
