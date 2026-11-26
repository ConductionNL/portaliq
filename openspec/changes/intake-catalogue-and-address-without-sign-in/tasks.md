# Tasks: intake-catalogue-and-address-without-sign-in

- [x] **T1**: `PortalAuthMiddleware::PUBLIC_READS` + `isPublicRead()`: the intake catalogue and address lookup pass without a session
  - Files: lib/Middleware/PortalAuthMiddleware.php
  - Test: PHPUnit `PortalAuthMiddlewareTest::testTheIntakeCatalogueAndAddressLookupAnswerWithoutASession`, `testTheOtherIntakeMethodsStillNeedASession`, `testAnotherControllersMethodOfTheSameNameStillNeedsASession`
- [x] **T2**: e2e `tests/e2e/intake-catalogue-and-address-without-sign-in.spec.ts`: signed out, the catalogue and the address lookup answer, the form route answers 401 (written, runs in nightly)
- [ ] **T3**: Live: signed out on the Zuiddrecht site, the catalogue lists its topics and an anonymous form fills in the street from the postcode (live lane; the catalogue's topics need opencatalogi's portal/topic/route back)
