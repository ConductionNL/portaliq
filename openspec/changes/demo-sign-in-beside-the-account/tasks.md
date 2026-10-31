# Tasks: demo-sign-in-beside-the-account

- [x] **T01**: `ExampleSetResidents::residentsOf()` reads `exampleResident` as an object or a list and vouches for each resident by the install record. PHPUnit `ExampleSetResidentsTest`.
- [x] **T02**: `ExampleResidentSignIn::offeredResidents()` and `userFor(resident:)`; `hiddenWayIn()` and `exampleResidentWayIn` retire. `ExampleResidentController::signIn()` takes `resident`. PHPUnit `ExampleResidentSignInTest`, `ExampleResidentControllerTest`, `PortalRuntimeConfigResolverTest`.
- [x] **T03**: `signInRoutes()` keeps the `nextcloud` way as the account route with its reset and registration links, and adds one demo card per resident after the portal's ways in, each with its own `key`. Node `tests/site-auth.spec.mjs`, `tests/nextcloud-registration-link.spec.mjs`.
- [x] **T04**: Zuiddrecht fragment: four residents in `exampleResident`, the group `inwoners`, the account card and `registration.residentGroup` in the change `inloggen-met-account`. Node `tests/example-resident.spec.mjs`.
- [x] **T05**: Portal schema declares `authentication.registration.residentGroup` and `residentAudience`, with catalogue entries.
- [ ] **T06**: Live: install the set on a demo instance with the switch on, sign in as Linda with one click and open Henk's case; sign in with a registered account through the account card. Not run in this change: needs a tenant with the registration app and a reinstall of the set.
