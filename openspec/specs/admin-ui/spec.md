---
example: true
capability: admin-ui
status: example
built_by: openspec/changes/example-change
---

# Admin UI Registration Specification

> ⚠️ **EXAMPLE SPEC** — This spec lives in the `portaliq` repository
> as a demonstration of the OpenSpec format. It describes the Nextcloud admin-
> panel registration in `lib/Settings/AdminSettings.php` + `lib/Sections/SettingsSection.php`.
> Apps built from this template will typically keep this capability almost
> unchanged; the substitutions are the section ID string, display name, icon
> path, and priority numbers.

## Purpose

Registers the app's configuration panel inside Nextcloud's admin settings UI.
Two pieces work together:

- A **section** (IIconSection implementation) — gives the panel a unique id,
  display name, order priority, and icon. Shows up as an entry in the admin
  navigation.
- A **settings form** (ISettings implementation) — renders the actual form
  template within that section.

Both pieces MUST agree on the section id string; Nextcloud uses the id as the
join key.

## Requirements

### Requirement: Register the admin section (IIconSection) (REQ-UI-001)

The system MUST register a section in Nextcloud's admin settings panel so
that the app has a dedicated place to render its configuration form.

#### Scenario: Section appears in admin navigation

- GIVEN the app is installed and enabled
- WHEN Nextcloud enumerates admin-setting sections
- THEN `SettingsSection::getID()` MUST return a stable identifier (`portaliq` in the template; apps MUST substitute their own id)
- AND `SettingsSection::getName()` MUST return a localised display name (via `IL10N::t()`)
- AND `SettingsSection::getPriority()` MUST return an integer controlling ordering (template uses `75`)
- AND `SettingsSection::getIcon()` MUST return an image path produced by `IURLGenerator::imagePath()` (template uses `portaliq/app-dark.svg`)

### Requirement: Render the admin settings form (ISettings) (REQ-UI-002)

The system MUST render the admin form template when Nextcloud opens the app's
admin section, and it MUST pass the running app version into the template so
the admin UI can display it.

#### Scenario: Admin opens the app's settings section

- GIVEN an admin user opens the app section registered by REQ-UI-001
- WHEN Nextcloud invokes `AdminSettings::getForm()`
- THEN the system MUST return a `TemplateResponse` rendering the `settings/admin` template
- AND the response parameters MUST include the current app version (fetched via `IAppManager::getAppVersion()`)

#### Scenario: Section join key

- WHEN Nextcloud resolves which section the form belongs to
- THEN `AdminSettings::getSection()` MUST return the same string that `SettingsSection::getID()` returns
- AND `AdminSettings::getPriority()` MUST return an integer controlling the form's position within the section

### Requirement: The app menu must show a user only the pages their role may use

Portaliq's Nextcloud app MUST show each menu entry only to a user whose role may use it: News, the dashboard and the documentation to every signed-in user; Portals, Media and Notices to page editors and administrators; Accounts and Invitations to users granted `portal.provision`; Access requests to users granted `portal.answer-access-request`; every other administration entry to Nextcloud administrators only. Opening a hidden entry's page by its address MUST open the dashboard instead. When the server does not answer the flags, the app MUST show only the entries every signed-in user may use.

#### Scenario: A teacher sees News and no administration
- GIVEN `po-leerkracht-09`, a member of `instructors` and of no editor group or action grant
- WHEN the teacher opens portaliq
- THEN the menu shows Dashboard, News and Documentation, and no Portals, Accounts, Invitations or Themes
- @e2e exclude pinned by `tests/admin-menu-access.spec.mjs` (CnAppNav's own predicate evaluator on the real manifest) and `AdminMenuAccessTest`; live-checked on the primary-school instance

#### Scenario: A typed address of a hidden page
- GIVEN the same teacher
- WHEN the teacher opens `/apps/portaliq/themes`
- THEN the dashboard opens
- @e2e exclude pinned by `tests/admin-menu-access.spec.mjs` ("a page hidden from the menu does not open by its address")

### Requirement: Saving from a list must keep the list where it was

After staff save, publish or take back a news item from the News page, the list MUST show the changed rows on the same page, with the same sort, filters and search, without reloading the browser page.

#### Scenario: Publishing on page 2
- GIVEN staff are on page 2 of the News list, sorted by title
- WHEN they publish an item on that page
- THEN the list still shows page 2, sorted by title, with the item published
- @e2e exclude pinned by `tests/news-list-refresh.spec.mjs` (the fetch is replayed with the same page, sort and filters); live-checked on the primary-school instance

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
