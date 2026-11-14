# Tasks: page-public-on-purpose

## 1. Publish on purpose

- [x] 1.1 The Publish control in `src/views/PageLayoutDesigner.vue` and `src/editor/SiteEditMode.vue` opens a confirmation (its own dialog under `src/dialogs/`) that says anyone can read the page without signing in and names its public address (`src/lib/pageSiteUrl.js`); cancel leaves the page a draft. Verify: component tests for confirm and cancel.
- [x] 1.2 The page list marks each page public or draft, in words, and offers taking a published page back to draft. Verify: component test on the list.
- [ ] 1.3 e2e: publish a draft page, read it signed out, take it back to draft, and read the same 404 as an unknown route. Verify: `tests/e2e/page-public-on-purpose.spec.ts`.
