# Tasks: partner-tasks-in-the-portal

## Surface

- [x] **T01**: Register the "Mijn taken" route for every discovered audience; keep the subject-scoped proxy unchanged (REQ-PTP-001)

## The ask

- [x] **T02**: Add the `ask-partner` internal endpoint action to the contribution runtime with the five fields and the write check on the case (REQ-PTP-002)
- [x] **T03**: Resolve the partner: existing `portalAccount` by id, or `PortalAccountService::provision()` from KvK number and email (REQ-PTP-002; depends on `portal-identity-space` T01)
- [x] **T04**: Create the engine task server-to-server as the handler with frozen due date and upload rules; confirm the ledger rows appear (REQ-PTP-002)

## The answer

- [x] **T05**: Record the completing account on the task and verify the completion path stores on the case object unchanged (REQ-PTP-003)

## Quality

- [x] **T06**: PHPUnit: audience filter, 403 without write, provisioning branch, ledger rows
- [x] **T07**: Playwright `tests/e2e/partner-tasks.spec.ts`: raise, see as partner, complete
- [x] **T08**: Dutch and English strings; docs with screenshots; tell dossiq the action id so it declares it and retires `ExternalConsultationResponse`

## Where it lives, and what dossiq needs to know

`POST /apps/portaliq/api/partner-tasks/ask` is the handler's endpoint. It is
gated by `PortalCaseAccessGuard`: the ADR-023 action `portal.ask-partner` plus a
read of the case with RBAC and multitenancy ON, which is the one read portaliq
makes as the user rather than as itself. `PartnerAskService` resolves the
partner, pre-provisioning a `pending` account from a KvK number and address when
there is none, and raises the task through `PortalTaskGateway::createTask()`
with the due date and upload rules frozen onto it.

For dossiq: the action id is **`ask-partner`**, the endpoint above, and the
partner account carries audience **`partner`**. Once dossiq declares it,
`ExternalConsultationResponse` can be retired.

