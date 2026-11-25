# Tasks: the-owning-apps-refusal-reads-as-no-longer-possible

- [x] **T01**: `PortalObjectWriter::lastRefusedByOwner()`: true when the last create or update failed on OpenRegister's `HookStoppedException`, reset at the start of each write (REQ-ORR-001)
  - PHPUnit `PortalObjectWriterOwnerRefusalTest`
- [x] **T02**: `ContributionController::create()`: an owner's refusal answers 409 `{error: "refused"}`, before the field refusal and the 502 (REQ-ORR-001)
  - PHPUnit `ContributionControllerOwnerRefusalTest`
- [ ] **T03**: Live: decidiq's `tests/e2e/portal-citizen-contribution.spec.ts` "a reaction on a closed consultation is refused" answers 409 and nothing is created
