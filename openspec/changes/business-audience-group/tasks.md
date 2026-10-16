# Tasks: business-audience-group

- [x] **T01**: `NextcloudRegistrationDoor` reads `businessGroup` and `businessAudience`; `portalGroups()` and `audienceOf()` replace the resident-only membership test in `admitResident()` and `residentPortalOf()` (REQ-NCL-008). PHPUnit `tests/Unit/Service/Identity/NextcloudAccountRegistrationTest.php`.
- [x] **T02**: `ResidentLandingListener` reacts to the business group as to the resident group. PHPUnit `tests/Unit/Listener/ResidentLandingListenerTest.php`.
- [x] **T03**: Portal schema declares `businessGroup` and `businessAudience`; register 0.98.0, portal schema 0.26.0.
- [x] **T04**: Zuiddrecht fragment: group `ondernemers` with Jan-Willem, Mark and Karin; change `ondernemers-op-het-portaal`. Node `tests/example-resident.spec.mjs`.
- [ ] **T05**: Live: Karin signs in to Nextcloud directly on the Zuiddrecht tenant and lands on Mijn Zuiddrecht. Not run: needs the tenant with the set installed.
