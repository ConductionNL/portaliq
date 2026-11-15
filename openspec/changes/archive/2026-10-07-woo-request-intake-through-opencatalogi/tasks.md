# Tasks: woo-request-intake-through-opencatalogi

- [x] **T1**: `portalFormBinding.deliverTo` and `portalIntakeSubmission.externalReference`, `dueAt` (register 0.59.0)
  - `PortaliqRegisterConfigTest`
- [x] **T2**: `PortalWooRequestDelivery` reaches opencatalogi's provider, guarded by `isInstalled`, and trusts a date only on `armed`
  - `PortalWooRequestDeliveryTest`
- [x] **T3**: `PortalIntakeDeliveryJob` routes a `wooRequest` binding there and registers only an armed term
  - `PortalIntakeDeliveryJobTest`
- [x] **T4**: the reference page quotes the Woo reference and the due date, only when a term runs
  - `PortalIntakeQueueTest`, `node --test tests/intake-entry.spec.mjs`
