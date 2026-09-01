# Tasks: portal-task-delivery

> Portaliq's half of the resident task leg (openregister#3282 seam): the
> "Mijn taken" surface, the assertion-minting proxy, and the delivery worker.
> Checkbox budget: 5 tasks × 2 = 10 unindented `- [ ]` lines (cap 20).

## Implementation Tasks

### Task 1: Task gateway — server-to-server seam client
- **spec_ref**: `openspec/changes/portal-task-delivery/specs/portal-task-delivery/spec.md#requirement-the-task-proxy-is-the-only-path-and-the-assertion-never-reaches-the-browser`
- **files**: `lib/Service/PortalTaskGateway.php`, `tests/Unit/Service/PortalTaskGatewayTest.php`
- **acceptance_criteria**:
  - `list/get/complete` forward to `/apps/openregister/api/portal-tasks[...]` with `X-Portal-Subject` minted via `PortalSessionService::issueAssertion()` per request; the client bearer is never forwarded; `allow_local_address` + `http_errors: false`, the PortalActionForwarder posture
  - Transport failure returns null (proxy answers 502); an unconfigured signing secret surfaces as unavailable, not a throw to the resident
  - `isAvailable()` is true only when openregister is installed and the signing secret is configured; drives the contributions announcement

- [x] T1 implemented
- [x] T1 tests green

### Task 2: Proxy controller + routes + contributions announcement
- **spec_ref**: `openspec/changes/portal-task-delivery/specs/portal-task-delivery/spec.md#requirement-the-task-proxy-is-the-only-path-and-the-assertion-never-reaches-the-browser`
- **files**: `lib/Controller/PortalTaskProxyController.php`, `lib/Controller/ContributionController.php`, `appinfo/routes.php`, `tests/Unit/Controller/PortalTaskProxyControllerTest.php`
- **acceptance_criteria**:
  - Three `PortalProtected` routes before the `/portal/{path}` catch-all; no bearer = 401 with zero forwards (mutation check)
  - Refusal mapping per design D-3: 404/400/409 pass through with their codes; openregister 401 becomes 503 `task-service-unavailable`; transport null becomes 502 `task-service-unreachable`
  - `GET /portal/api/contributions` carries `tasks: {enabled: bool}` for authenticated subjects only; the anonymous aggregate never announces it

- [x] T2 implemented
- [x] T2 tests green

### Task 3: Delivery worker
- **spec_ref**: `openspec/changes/portal-task-delivery/specs/portal-task-delivery/spec.md#requirement-the-delivery-worker-settles-every-ledger-row-idempotently-and-in-isolation`
- **files**: `lib/BackgroundJob/PortalTaskDeliveryJob.php`, `appinfo/info.xml`, `lib/Settings/portaliq_register.json`, `tests/Unit/BackgroundJob/PortalTaskDeliveryJobTest.php`
- **acceptance_criteria**:
  - TimedJob (5 min) resolves openregister's `PortalTaskDeliveryService` by name; absent openregister = debug log, no-op
  - `portal-inbox` row → one `portalMessage` (subject/body B1 NL-first, `taskUuid`, `deliveryUuid`) then `markDelivered`; existing `deliveryUuid` match = settle without duplicate; null write = `markFailed`
  - `mail` row → portalAccount lookup by stripped `party:` reference, privacy-minimal bilingual mail via IMailer; missing/invalid address or send failure = `markFailed(reason)`; per-row try/catch so no row blocks another and nothing escapes to cron
  - `portalMessage` schema declares `taskUuid` + `deliveryUuid` (undeclared properties are dropped on save)

- [x] T3 implemented
- [x] T3 tests green

### Task 4: "Mijn taken" SPA surface + inbox deep link
- **spec_ref**: `openspec/changes/portal-task-delivery/specs/portal-task-delivery/spec.md#requirement-mijn-taken-lists-details-and-completes-the-partys-open-tasks`
- **files**: `src/portal/components/TasksPage.jsx`, `src/portal/components/InboxPage.jsx`, `src/portal/App.jsx`, `src/portal/lib/portalApi.js`, `src/portal/i18n/nl.json`, `src/portal/i18n/en.json`
- **acceptance_criteria**:
  - Nav entry "Mijn taken" appears only when the contributions response announces `tasks.enabled`; list shows title + due date (overdue marked), detail shows title/description/due/upload rules
  - Completion form: comment + file input honouring `metadata.upload` (required/maxFiles/types/size) with client-side refusals in Dutch B1; multipart POST through the proxy; refusal codes map to the D-3 messages; success confirms and reloads the list
  - Inbox rows with `taskUuid` render "Bekijk taak", handing the uuid to the shell which opens the task detail

- [x] T4 implemented
- [x] T4 lint + build green

### Task 5: Quality gates + l10n
- **spec_ref**: `openspec/changes/portal-task-delivery/specs/portal-task-delivery/spec.md#requirement-the-inbox-message-deep-links-to-the-task`
- **files**: `l10n/nl.json`, `l10n/en.json`, `l10n/*.js`
- **acceptance_criteria**:
  - Server-side message/mail strings translated nl + en (English source keys); SPA strings in `src/portal/i18n`
  - phpcs, phpmd, psalm, phpstan, phpunit, npm lint + build, hydra gates (diff-scoped) all green

- [x] T5 implemented
- [x] T5 checks green
