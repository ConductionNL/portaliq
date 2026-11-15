# Tasks: nextcloud-account-registration

- [x] **T01**: `NextcloudRegistrationDoor` (`lib/Service/Identity/`): resident group, resident audience, registration address, membership, the resident's portal (REQ-NCL-003, REQ-NCL-005, REQ-NCL-006). PHPUnit `NextcloudAccountRegistrationTest`.
- [x] **T02**: `ContentController::site()` serves `registerUrl`; `signInRoutes()` puts it on the account route; `chrome/SignInPage.vue` and `AccountArea.vue` draw "Account aanmaken" (REQ-NCL-005). PHPUnit `ContentControllerTest::testARegistrationAddressIsServedForAPortalWithResidents`, `::testAnEmailLinkPortalIsServedNoRegistrationAddress`, `::testAnAccountRouteWithoutAResidentGroupIsServedNoRegistrationAddress`; node `tests/nextcloud-registration-link.spec.mjs`.
- [x] **T03**: `SessionController::nextcloud()` writes a resident's first account through `NextcloudAccountProvisioner` (REQ-NCL-003). PHPUnit through the real controller and provisioner.
- [x] **T04**: `DashboardController::page()` sends a resident to their portal's account route (REQ-NCL-006). PHPUnit.
- [ ] **T05**: Live round trip on the Zuiddrecht tenant: register, activate by mail, land signed in on Mijn Zuiddrecht. Not run: needs the tenant with the registration app, a mail server and the `occ` settings in the PR.
