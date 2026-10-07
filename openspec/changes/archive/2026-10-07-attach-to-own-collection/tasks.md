# Tasks: attach-to-own-collection

- [x] **T01**: `AttachedActionResolver`: `attachTo.collection`, own-app targets, `rowWhen` in the listing (REQ-ATO-001, REQ-ATO-002). Verification: PHPUnit `AttachedActionResolverTest`.
- [x] **T02**: `PortalRowActionController`: `?actionApp=` always takes the attached path (REQ-ATO-001). Verification: PHPUnit `PortalRowActionControllerTest`.
- [x] **T03**: `attachedActionsOf(collection, row)` and the React detail card (REQ-ATO-002). Verification: `tests/attached-actions.spec.mjs`.
- [x] **T04**: The site's `AttachedActions.vue` passes the row (REQ-ATO-002). Verification: `tests/attached-actions.spec.mjs` ("the site offers an attached action only on the records its rowWhen names").
- [x] **T05**: `openspec validate attach-to-own-collection --strict`.
