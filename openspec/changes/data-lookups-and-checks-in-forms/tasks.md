# Tasks: data-lookups-and-checks-in-forms

- [ ] **T01**: address route and `lib/Service/Intake/PortalAddressLookup.php` reading OpenRegister's BAG register; throttle; `src/site/components/forms/AddressNL.vue` per FormulierVelden; PHPUnit and node test (REQ-DIF-001). Check that the BAG register is filled on the target instance; if not, ask openregister in its tracker.
- [ ] **T02**: `lib/Service/Intake/DutchFormats.php` and `src/site/components/forms/formats.js` with shared fixtures; `PortalFormValidator` calls it; messages in nl, en, en_US; PHPUnit per format with valid and invalid fixtures, including a mutation that drops the elfproef (REQ-DIF-002)
- [ ] **T03**: reference lists: resolver in `PortalFormBindingResolver` fills options from OpenRegister, one-hour cache, validator check; PHPUnit (REQ-DIF-003)
- [ ] **T04**: family route through `BrpPersonProvider`, DigiD only, minimal fields; `FamilyMembers.vue` per FormulierGezinsleden; recheck on submit; PHPUnit for the forged reference (REQ-DIF-004)
- [ ] **T05**: fetch route and `lib/Service/Intake/PortalFormFetch.php` calling integriq; check line and read-only outputs; situation 4 inside the step; PHPUnit with a fake source (REQ-DIF-005)
- [ ] **T06**: `productVariant` field: options from the catalogue's variants; `intake-pay-on-submit`'s amount resolver prefers the variant price; PHPUnit for the ignored browser amount (REQ-DIF-006). Waits on the decision in the proposal.
- [ ] **T07**: Playwright: the address block fills in, the wrong IBAN is refused, the family cards; scenarios cite REQ-DIF-001, REQ-DIF-002, REQ-DIF-004 with `@e2e`
- [ ] **T08**: Live check against FormulierVelden, FormulierGezinsleden and FormulierNietBeschikbaar; screenshots in the build PR
