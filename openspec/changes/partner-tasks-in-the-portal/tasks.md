# Tasks: partner-tasks-in-the-portal

## Surface

- [ ] **T01**: Register the "Mijn taken" route for every discovered audience; keep the subject-scoped proxy unchanged (REQ-PTP-001)

## The ask

- [ ] **T02**: Add the `ask-partner` internal endpoint action to the contribution runtime with the five fields and the write check on the case (REQ-PTP-002)
- [ ] **T03**: Resolve the partner: existing `portalAccount` by id, or `PortalAccountService::provision()` from KvK number and email (REQ-PTP-002; depends on `portal-identity-space` T01)
- [ ] **T04**: Create the engine task server-to-server as the handler with frozen due date and upload rules; confirm the ledger rows appear (REQ-PTP-002)

## The answer

- [ ] **T05**: Record the completing account on the task and verify the completion path stores on the case object unchanged (REQ-PTP-003)

## Quality

- [ ] **T06**: PHPUnit: audience filter, 403 without write, provisioning branch, ledger rows
- [ ] **T07**: Playwright `tests/e2e/partner-tasks.spec.ts`: raise, see as partner, complete
- [ ] **T08**: Dutch and English strings; docs with screenshots; tell dossiq the action id so it declares it and retires `ExternalConsultationResponse`
