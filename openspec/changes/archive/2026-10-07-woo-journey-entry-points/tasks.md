# Tasks: woo-journey-entry-points

- [x] **T01**: `signedIn` from `App.vue` through `WidgetGrid.propsFor()` to the search and publication blocks (REQ-WJE-001). Verification: `tests/woo-entry-points.spec.mjs` on `propsFor`.
- [x] **T02**: `residentActions.js` and "Bewaar in mijn dossier" on the publication page and per document (REQ-WJE-002). Verification: `tests/woo-entry-points.spec.mjs`.
- [x] **T03**: "Bewaar deze zoekopdracht" on the search block (REQ-WJE-003). Verification: `tests/woo-entry-points.spec.mjs`.
- [x] **T04**: `AttachedActionResolver` and the cross-app row-action forward (REQ-WJE-004). Verification: PHPUnit `AttachedActionResolverTest`, `PortalRowActionControllerTest`.
- [x] **T05**: Attached action buttons and dialog in the portal detail card (REQ-WJE-004). Verification: `tests/attached-actions.spec.mjs`.
- [x] **T07**: `portalMessage.ruleKey`, schema and register bump, and the listener dispatch (REQ-WJE-006). Verification: PHPUnit `PortalRecordChangeListenerTest`, register pins.
- [x] **T08**: `openspec validate woo-journey-entry-points --strict`.
