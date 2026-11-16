# Tasks: portal-acts-for-its-organisation

- [x] **T01**: `lib/Service/Tenancy/PortalTenantContext.php` and `OrganisationScopedObjectService.php`: the object service for an organisation slug or uuid, every call run inside `runForOrganisation()` (REQ-PAO-001, REQ-PAO-002). Verification: `PortalTenantContextTest`.
- [x] **T02**: `PortalObjectReader::readCollection()`, `readScopedPage()`, `readObject()` and `resolveScopeValue()` read through it, including the claim and via reads inside them (REQ-PAO-001). Verification: `PortalReadsActForTheOrganisationTest`.
- [x] **T03**: `PortalObjectWriter::createObject()`, `updateObject()` and `deleteObject()` write through it (REQ-PAO-001). Verification: `PortalWritesActForTheOrganisationTest`.
- [x] **T04**: `PortalAccountsByClaim::find()` lists acting for the record's organisation; the change listener passes it (REQ-PAO-003). Verification: `PortalAccountsByClaimOrganisationTest`.
- [x] **T05**: `occ portaliq:tenancy:restamp`, dry run by default, `--apply` to write (REQ-PAO-004). Verification: `PortalOrganisationRestampTest`, `TenancyRestampTest`.
- [x] **T06**: Run the dry run and the apply on a live instance with two organisations and record the counts.
  - Live pass 2026-11-17 (fresh NC 34.0.6, openregister main, organisations `dev-org` and `e2e-org`): the dry run reported dev-org 5 already / 6 to restamp, e2e-org 6 / 0, 0 failed, 6 rows whose organisation does not resolve (two `portal` and three `article` rows with none, one seeded `portalAccount` naming `EXAMPLE_ORG_PLACEHOLDER`), 0 stored in another organisation; `--apply` restamped the 6; a second dry run read dev-org 11 / 0 and left the 6 unresolved rows as they were.
