# Tasks: portal-domains-and-editors

- [x] **T01**: Spec change written and validated (`openspec validate portal-domains-and-editors --strict`)
- [x] **T02**: `lib/Settings/portaliq_register.json`: `portal.domains[].certificateValidUntil`, `certificateCheckedAt`, `portal.editors`; portal minor version up (REQ-PDE-002, REQ-PDE-003)
- [x] **T03**: `PortalDomainVerifier`: certificate probe after a verified run, injectable; `records` returns `certificateValidUntil` and `certificateCheckedAt`; PHPUnit (REQ-PDE-002)
- [x] **T04**: `PortalDomains.vue` card on the Instellingen tab (plus the side card certificate row): list, status, certificate, TXT record, Opnieuw controleren, Domein toevoegen; node test for the hostname check (REQ-PDE-001)
- [x] **T05**: `PortalEditors.vue` card and the side card "Beheerders" (a PortalSummaryCard kind) (REQ-PDE-003)
- [x] **T06**: `PortalDetailsForm.vue` and `src/lib/portalDetails.js`; node test for the validation (REQ-PDE-004)
- [ ] **T07**: Re-check job for domains and certificates (decided out of scope here)
- [ ] **T08**: Per-portal permission from `editors` (a separate change)
