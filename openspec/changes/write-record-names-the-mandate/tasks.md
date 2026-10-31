# Tasks: write-record-names-the-mandate

- [x] 1.1 `CitizenCaseController::actingAs()` puts the described mandate's `label` on the subject as `actingForLabel`; `CitizenWriteRecorder::mandate()` copies it into the `mandate` block when it is not empty.
  - unit: `CitizenWriteRecorderTest::testAWriteForAnEntityBelowTheMandateRecordsBoth` asserts the label; `::testAnOrdinaryWriteNamesNoEntityAndNoMandate` asserts it is absent.
- [ ] 1.2 (live pass) A write made while acting for a represented person carries the label in `portalWrites` on the case and on `PortalClientWriteEvent::getMandate()`.
