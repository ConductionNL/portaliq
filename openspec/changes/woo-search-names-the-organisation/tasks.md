# Tasks: woo-search-names-the-organisation

- [x] **T1**: `PortalOrganisationConfigService::publicIdentity()`; `ContentController::site()` answers `organisation`
  - PHPUnit `ContentControllerTest::testSiteNamesItsOrganisation`, `testSiteWithoutAnOrganisationNamesNone`, `PortalOrganisationConfigServiceTest::testPublicIdentityNamesTheOrganisationOfASlug`
- [x] **T2**: `App.vue` → `WidgetGrid.vue` → `FederatedSearchBlock.vue` (`facetNames`); `labelBuckets()` takes the names
  - node `tests/publication-documents.spec.mjs` "the organisation filter reads the name the host knows, and keeps any other label", "the host hands the search block its organisation by name, after the authored props"
- [ ] **T3**: Live check on the rehearsal: the Woo search's organisation filter reads "Gemeente Zuiddrecht (13)"
