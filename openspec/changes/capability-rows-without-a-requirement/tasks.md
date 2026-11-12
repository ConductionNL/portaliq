# Tasks: capability-rows-without-a-requirement

- [x] **T01**: Record `built.requirement` on the 241 rows whose cited spec already says the row (`openspec/parity/capabilities.json`)
- [x] **T02**: Write the requirements of this change and record them on their rows (REQ-CRG-001 to REQ-CRG-011 and REQ-CRG-013)
- [ ] **T03**: Check REQ-CRG-001 to REQ-CRG-004 and REQ-CRG-006 to REQ-CRG-011 against the code the rows cite, and tag the tests that already cover them with `@e2e` or `@e2e exclude`
- [ ] **T04**: Build the PDF copy of a receipt (REQ-CRG-005): `lib/Service/SubmissionReceiptService.php` renders the receipt's data copy through OpenRegister's PDF rendering, and the receipt message offers it
- [ ] **T05**: Build Do Not Track (REQ-CRG-013) in `src/traffic/client.js`, with a node test
