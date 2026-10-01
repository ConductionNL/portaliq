# Tasks: site-reaches-portal-parity

Each slice is one lane and one or more PRs on `development`. Every task names
its requirement and the check that proves it. A task that ports a screen also
needs one live check on the integration instance as the guardian, with a
screenshot. `design.md` has the file-by-file map.

## Slice 0: shared libraries first

- [ ] **T00**: `git mv` every "move" library from `src/portal/lib/`, `src/portal/i18n/`, `embedCopy.js`, `embedHeight.js` and `serviceWorker.js` to `src/shared/`; point `src/portal`, `src/site` and `tests/*.spec.mjs` at the new paths in the same commit; wire `embed-copy` and `embed-height` into `check:specs`; give `portalApi.js` an injected token getter (REQ-SRP-052, REQ-SRP-002). Verification: `npm run check:specs`, `npm run build`, `/portal` still renders.

## Slice a: shell

- [ ] **T01**: Session: one bearer store for site and shared API; adopt the old `portaliq_token` once (REQ-SRP-001, REQ-SRP-002). Verification: `tests/site-auth.spec.mjs`.
- [ ] **T02**: Sign-in screen, including the 401 `authentication_required` path, the "no login method" hint and dev-login (REQ-SRP-003). Verification: `tests/broker-login.spec.mjs`, `tests/site-auth.spec.mjs`; live: signed out on `wilgenboom`.
- [ ] **T03**: Silent sign-in once per browser session (REQ-SRP-004). Verification: `tests/idle-session.spec.mjs`.
- [ ] **T04**: Idle window and sign-out stay on the shared `idleSession.js` (REQ-SRP-005, REQ-SRP-006). Verification: `tests/idle-session.spec.mjs`.
- [ ] **T05**: `src/site/lib/residentNav.js` (port of `buildNav`), reserved routes and the signed-in menu with the unread count (REQ-SRP-007). Verification: new `tests/resident-nav.spec.mjs`; live as Fatima.
- [ ] **T06**: Title and branding unchanged; translator from `src/shared/i18n/` provided by `App.vue`, shell literals translated (REQ-SRP-008, REQ-SRP-009). Verification: `tests/site-head.spec.mjs`, `npm run check:l10n-js`.
- [ ] **T07**: Notices, branch switcher, loading status region; tokens-only styling (REQ-SRP-010, REQ-SRP-011, REQ-SRP-012, REQ-SRP-013). Verification: `tests/notices.spec.mjs`, `tests/branch-choice.spec.mjs`, `tests/branch-in-effect.spec.mjs`, `tests/portal-live-regions.spec.mjs`, `npm run stylelint`.

## Slice b: collections

- [ ] **T08**: `ContributionPage.vue` on `/diensten/{app}/{page}` (builds on `portal-theme-blocks-and-contributed-pages` task 9) with the block switch and the collection loader (REQ-SRP-014, REQ-SRP-016). Verification: new `tests/collection-loader.spec.mjs`; `tests/attached-actions.spec.mjs` and `tests/my-dossiers.spec.mjs` read the Vue page.
- [ ] **T09**: `CollectionTable.vue`, `DetailCard.vue`, `TimelineList.vue`, `ItemList.vue`; rich text through `MarkdownBlock.vue` once its sanitising is checked (REQ-SRP-015, REQ-SRP-017, REQ-SRP-018, REQ-SRP-019, REQ-SRP-020). Verification: `tests/collection-table-keyboard.spec.mjs`, `tests/case-timeline.spec.mjs`, `tests/my-dossiers.spec.mjs`, new `tests/rich-text.spec.mjs`.
- [ ] **T10**: `#open=` record links across sign-in (REQ-SRP-021). Verification: `tests/open-record.spec.mjs`; live: a notification link as Fatima.

## Slice c: forms and actions

- [ ] **T11**: `SchemaForm.vue` (absorbs `ActionFieldsForm`) with file fields through the shared `fileFieldSubmit.js` (REQ-SRP-022, REQ-SRP-023). Verification: new `tests/schema-form.spec.mjs`, `tests/schema-form-file-field.spec.mjs`.
- [ ] **T12**: `ProposeChangeForm.vue`, status transitions, endpoint and cta actions (REQ-SRP-024, REQ-SRP-025, REQ-SRP-027). Verification: new `tests/propose-change.spec.mjs`, `tests/row-action.spec.mjs`.
- [ ] **T13**: `RowActionConfirm.vue`, `AttachedActions.vue`, `SigningDialog.vue`, `DeclineDialog.vue` (REQ-SRP-026, REQ-SRP-028, REQ-SRP-029). Verification: `tests/row-action.spec.mjs`, `tests/attached-actions.spec.mjs`, `tests/signing-dialog.spec.mjs`.

## Slice d: inbox, messages, news, notification settings, tasks, timed tasks

- [ ] **T14**: `InboxPage.vue` and `NotificationSettings.vue` (REQ-SRP-030, REQ-SRP-031). Verification: `tests/message-box-channel.spec.mjs`, `tests/translated-message-notice.spec.mjs`.
- [ ] **T15**: `MessagesPage.vue`, `NewsPage.vue`, `TranslatedText.vue` (REQ-SRP-032, REQ-SRP-033, REQ-SRP-034). Verification: the three news and translation specs; live as Fatima.
- [ ] **T16**: `TasksPage.vue` with the inbox deep link (REQ-SRP-035). Verification: new `tests/tasks-page.spec.mjs`.
- [ ] **T17**: `TimedTaskView.vue` and `TimedTaskItem.vue` on the shared `timedTask.js` (REQ-SRP-036). Verification: `tests/timed-task.spec.mjs`.

## Slice e: account, registered details, access requests, my cases, citizen case

- [x] **T18**: `AccountPage.vue` with the contact prompt and `#confirm-email=` (REQ-SRP-037). Verification: `tests/account-page.spec.mjs`.
- [x] **T19**: `RegisteredDetailsPage.vue` and `AccessRequestsPage.vue` (REQ-SRP-038, REQ-SRP-039). Verification: `tests/registered-details.spec.mjs`, `tests/access-request-asker.spec.mjs`.
- [x] **T20**: `MyCasesPage.vue` and `ActingForSwitcher.vue` (REQ-SRP-040, REQ-SRP-041). Verification: `tests/my-cases-page.spec.mjs`, `tests/my-cases-acting-for.spec.mjs`.
- [x] **T21**: `CitizenCase.vue` and `WithdrawCaseConfirm.vue` (REQ-SRP-042, REQ-SRP-043). Verification: `tests/case-documents-screen.spec.mjs`, `tests/case-withdraw-screen.spec.mjs`, `tests/case-type-portal-header.spec.mjs`.

## Slice f: PWA manifest, service worker, embed

- [ ] **T22**: `site.php` links the manifest; `start_url` points at `/site`; the service worker is served from `src/shared/` and registered by the site; the install control (REQ-SRP-044, REQ-SRP-045, REQ-SRP-046). Verification: PHPUnit `PortalManifestControllerTest`, new `tests/service-worker.spec.mjs`, new `tests/install-banner.spec.mjs`.
- [ ] **T23**: A `portaliq-embed` entry with `EmbeddedForm.vue`; `templates/embed.php` loads it (REQ-SRP-047). Verification: `tests/embed-copy.spec.mjs`, `tests/embed-height.spec.mjs`, `tests/e2e/embedded-intake-form.spec.ts`.

## Slice g: retirement

- [ ] **T24**: Server links point at `portalPage.site`: `SessionController::portalReturnTo()`, `BrokerSessionController::portalPath()`, `PortalDeepLinkBuilder`, `PortalManifestController::startUrl()`; `/site` resolves `?org=` (REQ-SRP-049). Verification: PHPUnit, `COMPOSER_PROCESS_TIMEOUT=0 composer check:strict`.
- [ ] **T25**: Every Playwright spec that opens `/apps/portaliq/portal` opens `/site`; learniq `tests/e2e/po-parent-flows.spec.ts:393` likewise (REQ-SRP-051). Verification: `git grep` finds no other caller.
- [ ] **T26**: Measure the parity checklist and record it in `docs/portal-parity.md` (REQ-SRP-051). Verification: the dated checklist in that page, every line true.
- [ ] **T27**: `/portal` answers 302 to `/site` with its query string; delete `src/portal/`, `webpack.portal.js`, `templates/portal.php`, the portal build scripts, `react`, `react-dom`, `@utrecht/component-library-react`, the React-only tests, and update `README.md` and `docs/` (REQ-SRP-048, REQ-SRP-050). Verification: PHPUnit `PortalPageControllerTest`, `npm ls react` empty, `npm run build`.
- [ ] **T28**: `openspec validate site-reaches-portal-parity --strict`.
