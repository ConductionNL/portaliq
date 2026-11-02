# Tasks: operate-portals-per-organisation

## The census

- [x] **T01**: `lib/Service/Tenancy/SchemaTenancy.php` and `tests/Unit/Tenancy/SchemaTenancyCensusTest.php` (REQ-OPO-001). Verification: the test fails on a deliberately undeclared schema, then passes.
- [x] **T02**: Decide the 23 schemas without `organisation` or `portal`: add the field and stamp it in `PortalObjectWriter`, declare the parent path, or declare `global` with a reason; register version bump (REQ-OPO-001). Verification: census green; the list with reasons in the PR body. The 23 schemas without `organisation` or `portal` are declared in `SchemaTenancy::MAP`: `subject` (5), `parent` (8) or `global` with a reason (10). No schema got a new field and `PortalObjectWriter` stamps nothing new; the global ones that hold school data (`schoolEvent`, `messageThread`, `activityOffer`, `newsletter`) are marked as waiting for a portal field. The register version is unchanged.
## Fail closed

- [ ] **T03**: On a live instance, count rows of organisation-scoped schemas with an empty `organisation`; add a repair step that stamps them from their portal or reports them (REQ-OPO-002). Verification: counts before and after in the PR body. — not run: needs a live instance. The repair step is not written either; count the empty-organisation rows before this reaches an existing instance.
- [x] **T04**: `organisationMatches()` and `verifyScope()` read the declared scope and drop rows and subjects without a tenant value (REQ-OPO-002). Verification: `PortalObjectReaderTest::testRowWithoutOrganisationIsDropped`, `::testSubjectWithoutOrganisationGetsNothing`, `::testSubjectScopedSchemaUnchanged`. `PortalObjectReaderTest::testRowWithoutOrganisationIsDropped`, `::testSubjectWithoutOrganisationGetsNothing`, `::testSubjectScopedSchemaUnchanged`. Three existing tests that read an organisation-scoped schema with no organisation now name one.
## The proof

- [ ] **T05**: `tests/e2e/operate-portals-per-organisation.spec.ts` with the two-tenant fixture and the route table; it fails for an unlisted `/portal/api/` route (REQ-OPO-003). Verification: the spec runs green, and red when one route is made to ignore the organisation. — not run: needs a live instance with two organisations.
## Provisioning

- [x] **T06**: `lib/Service/Tenancy/PortalProvisioningService.php` and `occ portaliq:portal:provision` registered in `appinfo/info.xml` (REQ-OPO-004). Verification: `PortalProvisioningServiceTest::testSlugTakenIsRefused`, `::testHostTakenIsRefused`, `::testProvisionedPortalIsDraft`. The service reuses `ExampleSiteStore`; the command is registered in `appinfo/info.xml`.
- [ ] **T07**: "New portal" action and dialog on the Portals admin page (REQ-OPO-004). Verification: the Playwright spec provisions a portal and is refused on a taken host. — not run: the "New portal" dialog on the Portals admin page is not built; `occ portaliq:portal:provision` is the door that exists.
## Docs, strings and validation

- [x] **T08**: English and Dutch strings ("New portal", "This web address is already used by another portal."); a docs page for hosting parties: provisioning, DNS verification, what is and is not isolated. Verification: `npm run lint`, `test:l10n`. The command speaks English; the docs page is `docs/operations/portals-per-organisation.md`.
- [ ] **T09**: `openspec validate operate-portals-per-organisation --strict`. — not run: the openspec CLI is not installed here.

## Each organisation looks after its own portal

- [ ] **T10**: `lib/Settings/portaliq_register.json`: `portal.adminGroups` (array of group ids, title and description); register version bump; import and grep the log for `PARTIAL IMPORT` (REQ-OPO-005).
- [ ] **T11**: `lib/Service/Tenancy/PortalAdminScope.php`: `mayAdminister(IUser, portal)` (instance admin, or member of one of the portal's `adminGroups`); the portal settings, domain, theme and sign-in controllers and `PageEditorService` ask it per portal, and answer 404 for a portal the user may not administer. A change to `adminGroups` stays instance-admin only. Verification: `PortalAdminScopeTest` (own portal, other portal 404, `adminGroups` change 403, instance admin everywhere) (REQ-OPO-005).
- [ ] **T12**: The Portals admin page lists only the portals the user may administer; an "Administrators" group picker (`NcSelect` with `inputLabel`) shows on a portal for instance administrators only. Board PtBeheerdersrechten (REQ-OPO-005).
- [ ] **T13**: Docs page `docs/operations/portals-per-organisation.md`: what the hosting party runs (Nextcloud, upgrades, provisioning, `adminGroups`) and what each organisation runs (its portal), in English and Dutch strings for the new labels.
- [ ] **T14**: The two-tenant Playwright spec gains a member of each organisation's group (REQ-OPO-005).
