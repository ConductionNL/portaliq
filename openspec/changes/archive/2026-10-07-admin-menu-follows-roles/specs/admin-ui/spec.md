## ADDED Requirements

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
