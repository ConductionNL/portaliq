# Tasks: embed-frame-reads-the-referer-origin

- [x] **T1**: `PortalEmbedController::framingOrigin()`: `Origin`, else the origin of `Referer`; the frame route and the submit both judge it with the existing guard
  - Files: lib/Controller/PortalEmbedController.php
  - Test: PHPUnit `PortalEmbedControllerTest::testAFrameWithoutOriginIsJudgedByItsReferersOrigin`, `testARefererOnAnotherOriginIsRefusedBeforeTheFormIsRead`, `testAFrameWithNeitherOriginNorRefererServesNobody`, `testAnOriginHeaderWinsOverTheReferer`, `testASubmissionWithoutOriginRecordsTheReferersOriginOnly`
- [x] **T2**: e2e: the render test frames with an allowed Referer; new tests for a Referer on another origin, neither header, Origin winning over Referer, and the recorded origin of a Referer-only submission (destination schema gains `origin`)
  - Files: tests/e2e/embedded-intake-form.spec.ts; written, runs in nightly
- [ ] **T3**: Live: a page on an allowed origin frames the form in a real browser and the visitor sees the fields; a page on another origin sees the refusal (live lane)
