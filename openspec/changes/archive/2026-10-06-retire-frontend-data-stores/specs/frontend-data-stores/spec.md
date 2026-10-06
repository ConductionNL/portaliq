# Frontend Data Stores — delta

## REMOVED Requirements

### Requirement: REQ-STORE-001: Configure the object store with OpenRegister URLs

**Reason**: `src/store/modules/object.js` had no importer; the SPA reaches OpenRegister through `@conduction/nextcloud-vue`'s store plane (ADR-022).
**Migration**: None. Nothing called it.

### Requirement: REQ-STORE-002: Register named object types

**Reason**: As REQ-STORE-001.
**Migration**: None.

### Requirement: REQ-STORE-003: Fetch a collection of objects

**Reason**: As REQ-STORE-001.
**Migration**: None.

### Requirement: REQ-STORE-004: Read and write app settings from the SPA

**Reason**: `src/store/modules/settings.js` had no importer.
**Migration**: None. `src/views/AdminRoot.vue` reads and writes `/api/settings` (settings-management, REQ-CFG-001 / REQ-CFG-002).

### Requirement: REQ-STORE-005: Initialise the stores before the SPA renders

**Reason**: `src/store/store.js` (`initializeStores()`) had no importer; `src/main.js` never called it.
**Migration**: None.
