# Tasks: retire-frontend-data-stores

- [x] **T01**: Delete `src/store/store.js`, `src/store/modules/object.js` and `src/store/modules/settings.js`, and their `eslint-suppressions.json` entries. Verification: `grep -rn "store/store\|store/modules" src/` returns nothing; `npm run lint` and `npm run build` pass.
- [x] **T02**: Remove `openspec/specs/frontend-data-stores/` (this change's REMOVED delta).
