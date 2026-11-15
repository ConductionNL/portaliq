---
kind: code
---

# Proposal: retire-frontend-data-stores

## Why

`src/store/store.js` and `src/store/modules/{object,settings}.js` were
scaffold inherited from `nextcloud-app-template` and had no importer:
`src/main.js` mounts `App.vue`, which renders `<CnAppRoot>` from
`@conduction/nextcloud-vue`, and the manifest-driven SPA reaches OpenRegister
through the library's own store plane (ADR-022). The `frontend-data-stores`
capability (an `example: true` spec) described those modules, so its nine
scenarios could never be exercised: no browser session runs code the bundle
never reaches
([ConductionNL/portaliq#92](https://github.com/ConductionNL/portaliq/issues/92)).

## What Changes

- Delete `src/store/store.js` and `src/store/modules/`. `src/store/traffic.js`
  stays: the Traffic widgets import it.
- Remove the `frontend-data-stores` capability (REQ-STORE-001 to
  REQ-STORE-005) from `openspec/specs/`.

## Impact

None at runtime: the deleted modules were never on the bundle's module graph.
The settings screen keeps reading and writing `/api/settings` from
`src/views/AdminRoot.vue`.
