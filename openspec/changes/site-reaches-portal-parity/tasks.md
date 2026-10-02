# Tasks: site-reaches-portal-parity

Each slice is one lane and one or more PRs on `development`. Every task names
its requirement and the check that proves it. A task that ports a screen also
needs one live check on the integration instance as the guardian, with a
screenshot. `design.md` has the file-by-file map.

## Slice 0: shared libraries first

- [x] **T00**: `git mv` every "move" library from `src/portal/lib/`, `src/portal/i18n/`, `embedCopy.js`, `embedHeight.js` and `serviceWorker.js` to `src/shared/`; point `src/portal`, `src/site` and `tests/*.spec.mjs` at the new paths in the same commit; wire `embed-copy` and `embed-height` into `check:specs`; give `portalApi.js` an injected token getter (REQ-SRP-052, REQ-SRP-002). Verification: `npm run check:specs`, `npm run build`, `/portal` still renders.
  - Done across slices a to g: every framework-free library lives in `src/shared/` (the last two, `branch.js` and `notices.js`, moved in slice g); `git grep -n "portal/lib\|portal/i18n" src/site src/shared` finds nothing.

## Slice a: shell

- [x] **T01**: Session: one bearer store for site and shared API; adopt the old `portaliq_token` once (REQ-SRP-001, REQ-SRP-002). Verification: `tests/site-auth.spec.mjs`.
  - Slice g added the one-time adoption of localStorage `portaliq_token` (`adoptLegacyToken` in `src/site/lib/authApi.js`, `tests/site-auth.spec.mjs`).
- [x] **T02**: Sign-in screen, including the 401 `authentication_required` path, the "no login method" hint and dev-login (REQ-SRP-003). Verification: `tests/broker-login.spec.mjs`, `tests/site-auth.spec.mjs`; live: signed out on `wilgenboom`.
- [x] **T03**: Silent sign-in once per browser session (REQ-SRP-004). Verification: `tests/idle-session.spec.mjs`.
- [x] **T04**: Idle window and sign-out stay on the shared `idleSession.js` (REQ-SRP-005, REQ-SRP-006). Verification: `tests/idle-session.spec.mjs`.
- [x] **T05**: `src/site/lib/residentNav.js` (port of `buildNav`), reserved routes and the signed-in menu with the unread count (REQ-SRP-007). Verification: new `tests/resident-nav.spec.mjs`; live as Fatima.
  - Built as `src/shared/portalNav.js` and `src/site/lib/shellData.js` rather than `residentNav.js`; tested in `tests/site-signed-in-shell.spec.mjs`.
- [x] **T06**: Title and branding unchanged; translator from `src/shared/i18n/` provided by `App.vue`, shell literals translated (REQ-SRP-008, REQ-SRP-009). Verification: `tests/site-head.spec.mjs`, `npm run check:l10n-js`.
- [x] **T07**: Notices, branch switcher, loading status region; tokens-only styling (REQ-SRP-010, REQ-SRP-011, REQ-SRP-012, REQ-SRP-013). Verification: `tests/notices.spec.mjs`, `tests/branch-choice.spec.mjs`, `tests/branch-in-effect.spec.mjs`, `tests/portal-live-regions.spec.mjs`, `npm run stylelint`.
  - Slice g ported the branch switcher (`src/site/components/BranchSwitcher.vue`), made the shell's loading line a status region, and rewrote `notices`, `branch-choice`, `branch-in-effect` and `portal-live-regions` against the site.

## Slice b: collections

- [x] **T08**: `ContributionPage.vue` on `/diensten/{app}/{page}` (builds on `portal-theme-blocks-and-contributed-pages` task 9) with the block switch and the collection loader (REQ-SRP-014, REQ-SRP-016). Verification: new `tests/collection-loader.spec.mjs`; `tests/attached-actions.spec.mjs` and `tests/my-dossiers.spec.mjs` read the Vue page.
- [x] **T09**: `CollectionTable.vue`, `DetailCard.vue`, `TimelineList.vue`, `ItemList.vue`; rich text through `MarkdownBlock.vue` once its sanitising is checked (REQ-SRP-015, REQ-SRP-017, REQ-SRP-018, REQ-SRP-019, REQ-SRP-020). Verification: `tests/collection-table-keyboard.spec.mjs`, `tests/case-timeline.spec.mjs`, `tests/my-dossiers.spec.mjs`, new `tests/rich-text.spec.mjs`.
  - Done on `feat/site-collections`: the page is exported as `pages.contribution` from `src/site/pages/collections/index.js`; its route comes from the shell's registry (slice a). Rich text is a text-only `RichTextBlock.vue`: `MarkdownBlock.vue` was checked and not reused, because `cnRenderMarkdown` keeps safe raw HTML (`<b>`, `<img>`) and REQ-SRP-018 allows none.
- [x] **T10**: `#open=` record links across sign-in (REQ-SRP-021). Verification: `tests/open-record.spec.mjs`; live: a notification link as Fatima.
  - Page half done on `feat/site-collections` (select from own rows, "not in your list" notice, `openRecordEntry(nav)` for the shell). Open until the shell calls `openRecordEntry(nav)` on boot and routes to the entry it returns (App.vue, slice a).
  - Shell half done: `App.vue` keeps the target at boot (`keepOpenTarget`) and calls `openRecordEntry(this.nav)` once the navigation has loaded; `tests/open-record.spec.mjs` checks both.

## Slice c: forms and actions

- [x] **T11**: `SchemaForm.vue` (absorbs `ActionFieldsForm`) with file fields through the shared `fileFieldSubmit.js` (REQ-SRP-022, REQ-SRP-023). Verification: new `tests/schema-form.spec.mjs`, `tests/schema-form-file-field.spec.mjs`.
- [x] **T12**: `ProposeChangeForm.vue`, status transitions, endpoint and cta actions (REQ-SRP-024, REQ-SRP-025, REQ-SRP-027). Verification: new `tests/propose-change.spec.mjs`, `tests/row-action.spec.mjs`.
- [x] **T13**: `RowActionConfirm.vue`, `AttachedActions.vue`, `SigningDialog.vue`, `DeclineDialog.vue` (REQ-SRP-026, REQ-SRP-028, REQ-SRP-029). Verification: `tests/row-action.spec.mjs`, `tests/attached-actions.spec.mjs`, `tests/signing-dialog.spec.mjs`.

## Slice d: inbox, messages, news, notification settings, tasks, timed tasks

- [x] **T14**: `InboxPage.vue` and `NotificationSettings.vue` (REQ-SRP-030, REQ-SRP-031). Verification: `tests/message-box-channel.spec.mjs`, `tests/translated-message-notice.spec.mjs`.
- [x] **T15**: `MessagesPage.vue`, `NewsPage.vue`, `TranslatedText.vue` (REQ-SRP-032, REQ-SRP-033, REQ-SRP-034). Verification: the three news and translation specs; live as Fatima.
- [x] **T16**: `TasksPage.vue` with the inbox deep link (REQ-SRP-035). Verification: new `tests/tasks-page.spec.mjs`.
- [x] **T17**: `TimedTaskView.vue` and `TimedTaskItem.vue` on the shared `timedTask.js` (REQ-SRP-036). Verification: `tests/timed-task.spec.mjs`.

## Slice e: account, registered details, access requests, my cases, citizen case

- [x] **T18**: `AccountPage.vue` with the contact prompt and `#confirm-email=` (REQ-SRP-037). Verification: `tests/account-page.spec.mjs`.
- [x] **T19**: `RegisteredDetailsPage.vue` and `AccessRequestsPage.vue` (REQ-SRP-038, REQ-SRP-039). Verification: `tests/registered-details.spec.mjs`, `tests/access-request-asker.spec.mjs`.
- [x] **T20**: `MyCasesPage.vue` and `ActingForSwitcher.vue` (REQ-SRP-040, REQ-SRP-041). Verification: `tests/my-cases-page.spec.mjs`, `tests/my-cases-acting-for.spec.mjs`.
- [x] **T21**: `CitizenCase.vue` and `WithdrawCaseConfirm.vue` (REQ-SRP-042, REQ-SRP-043). Verification: `tests/case-documents-screen.spec.mjs`, `tests/case-withdraw-screen.spec.mjs`, `tests/case-type-portal-header.spec.mjs`.

## Slice f: PWA manifest, service worker, embed

- [x] **T22**: `site.php` links the manifest; `start_url` points at `/site`; the service worker is served from `src/shared/` and registered by the site; the install control (REQ-SRP-044, REQ-SRP-045, REQ-SRP-046). Verification: PHPUnit `PortalManifestControllerTest`, new `tests/service-worker.spec.mjs`, new `tests/install-banner.spec.mjs`.
- [x] **T23**: A `portaliq-embed` entry with `EmbeddedForm.vue`; `templates/embed.php` loads it (REQ-SRP-047). Verification: `tests/embed-copy.spec.mjs`, `tests/embed-height.spec.mjs`, `tests/e2e/embedded-intake-form.spec.ts`.

## Slice g: retirement

- [x] **T24**: Server links point at `portalPage.site`: `SessionController::portalReturnTo()`, `BrokerSessionController::portalPath()`, `PortalDeepLinkBuilder`, `PortalManifestController::startUrl()`; `/site` resolves `?org=` (REQ-SRP-049). Verification: PHPUnit, `COMPOSER_PROCESS_TIMEOUT=0 composer check:strict`.
  - Done on `feat/site-retires-the-react-portal`: `PortalPageController::requestedPortalSlug()` reads `?org=`; every return, failure and mail link uses `portalPage.site`.
- [x] **T25**: Every Playwright spec that opens `/apps/portaliq/portal` opens `/site`; learniq `tests/e2e/po-parent-flows.spec.ts:393` likewise (REQ-SRP-051). Verification: `git grep` finds no other caller.
  - portaliq e2e moved on the same branch (one fixme: activity in another tab, because the site keeps a session per tab); learniq's guardian e2e already opens `/site` on its development branch.
- [x] **T26**: Measure the parity checklist and record it in `docs/portal-parity.md` (REQ-SRP-051). Verification: the dated checklist in that page, every line true.
  - Recorded 2026-10-02 in `docs/portal-parity.md`. Line 4 (live screenshots as Fatima) was measured per slice by the coordinator on `:8090`, not on this branch.
- [x] **T27**: `/portal` answers 302 to `/site` with its query string; delete `src/portal/`, `webpack.portal.js`, `templates/portal.php`, the portal build scripts, `react`, `react-dom`, `@utrecht/component-library-react`, the React-only tests, and update `README.md` and `docs/` (REQ-SRP-048, REQ-SRP-050). Verification: PHPUnit `PortalPageControllerTest`, `npm ls react` empty, `npm run build`.
  - `/portal` and `/portal/{path}` answer 302 to `/site` keeping the query; the browser keeps the fragment (checked in headless Chrome). `npm ls react` is empty and `npm run build` emits no portal bundle.
- [x] **T28**: `openspec validate site-reaches-portal-parity --strict`.
