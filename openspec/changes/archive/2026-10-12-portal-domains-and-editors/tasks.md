# Tasks: portal-domains-and-editors

- [x] **T01**: Spec change written and validated (`openspec validate portal-domains-and-editors --strict`)
- [x] **T02**: `lib/Settings/portaliq_register.json`: `portal.domains[].certificateValidUntil`, `certificateCheckedAt`, `portal.editors`; portal minor version up (REQ-PDE-002, REQ-PDE-003)
- [x] **T03**: `PortalDomainVerifier`: certificate probe after a verified run, injectable; `records` returns `certificateValidUntil` and `certificateCheckedAt`; PHPUnit (REQ-PDE-002)
- [x] **T04**: `PortalDomains.vue` card on the Instellingen tab (plus the side card certificate row): list, status, certificate, TXT record, Opnieuw controleren, Domein toevoegen; node test for the hostname check (REQ-PDE-001)
- [x] **T05**: `PortalEditors.vue` card and the side card "Beheerders" (a PortalSummaryCard kind) (REQ-PDE-003)
  - Implementation note (11 Oct 2026): the Domeinen and Beheerders cards are now nextcloud-vue `field-list` widgets in `src/manifest.json` (page PortalDetail, widgets `portal-domains` and `portal-editors`), inside the Instellingen tab's `sections` widget (`portal-settings`). `PortalDomains.vue`, `PortalEditors.vue` and `PortalSettings.vue` are gone; `tests/portal-domains.spec.mjs` checks the manifest configuration. REQ-PDE-001 to 003 still hold. `POST /api/cms/domains/verify` answers a translated `message` that the list shows after "Check again".
- [x] **T06**: `PortalDetailsForm.vue` and `src/lib/portalDetails.js`; node test for the validation (REQ-PDE-004)

## Not part of this change

Both are named as out of scope in proposal.md. They are not built, and nothing here claims them:

- A background job that checks domains and certificates again. A check runs when a beheerder asks for it.
- A per-portal right to change pages from `editors`. The list grants nothing (REQ-PDE-003).

Archived 12 Oct 2026: T01 to T06 checked against the code. The spec is `openspec/specs/portal-settings/spec.md`.
