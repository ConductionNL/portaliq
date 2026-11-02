# Tasks: action-forward-survives-a-slow-answer

- [x] 1.1 `PortalActionForwarder`: timeout 30 s; relay a well-formed `Idempotency-Key`; tell a timeout from another transport failure; `failureResponse()` answers 504 `forward_timeout` or 502 `forward_failed`. Verify: `PortalActionForwarderTest::testTheSubmissionKeyIsRelayedAndAMalformedOneIsDropped`, `::testATimeoutIsToldApartFromAFailure`.
- [x] 1.2 `ContributionController::action()`, `PortalRowActionController`, `GuestActionController` answer with `failureResponse()`. Verify: `ContributionControllerTest::testActionThatTimesOutIs504ForwardTimeout`, `PortalRowActionControllerTest::testATransportFailureIs502`.
- [x] 2.1 `src/shared/submissionKey.js`; `portalApi.forwardAction()`, `portalApi.forwardRowAction()` and `residentActions.forwardApi()` send the key; `SchemaForm` makes one per opened form, resends it, renews it after success. Verify: `tests/submission-key.spec.mjs`, `tests/schema-form.spec.mjs`.
- [x] 2.2 `SchemaForm` shows the "may still be processing" sentence with a link to Mijn zaken (`addressOfRoute`); en and nl strings in `pages/c/strings.js` and `shared/i18n`.
- [ ] 3.1 Live (decision 139): with dossiq's `portal-submission-is-made-once`, send a Woo request from a dossier twice with the same key and see one case.
