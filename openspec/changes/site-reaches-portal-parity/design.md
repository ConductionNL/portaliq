# Design: site-reaches-portal-parity

Read on `development` at 9a160863 (2026-10-01). Line numbers are from that
commit.

Legend for the "lib" column:

- **move**: framework-free JS. It moves to `src/shared/` unchanged, both
  bundles import it from there, and its node test follows it.
- **rewrite**: React (a hook or JSX). The behaviour is rebuilt in Vue.
- **drop**: nothing to keep after retirement.

Legend for the "site today" column: **exists** means a Vue piece already does
it, **partial** means it does part of it, **build** means nothing does yet.

## Capability map

### Slice a. Shell: session, sign-in, idle, navigation, branding, i18n

| # | Capability | React source | Site today | Vue target | lib | Node test today |
|---|---|---|---|---|---|---|
| a1 | Boot and runtime config | `main.jsx` (loadState `runtimeConfig`) | exists: `src/site/main.js`, `lib/contentApi.js` `runtimeConfig()` (JSON block from `templates/site.php`) | keep | drop | `site-head.spec.mjs` (site.php) |
| a2 | Bearer adoption and session read | `lib/portalApi.js` `getToken/setToken` (localStorage `portaliq_token`), `consumeOidcCallbackFragment()`, `getSession()` | exists: `lib/authApi.js` `adoptSessionToken/fetchSession/refreshSession/clearSessionToken` (sessionStorage `portaliq.session.token`) | keep, unify the token store | `portalApi.js`: move (1,352 lines, no imports) | `site-auth.spec.mjs` |
| a3 | Sign-in screen: one button per provider, failed-login message, "no login method" hint, dev-login | `App.jsx` 602-632, `lib/signinRoute.js` | partial: `BrandHeader.vue` sign-in routes from `authApi.signInRoutes()` and `takeSigninFailed()`. No dev-login, no "no login method" hint. **Measured: an auth-gated portal (`wilgenboom`) answers 401 on `/api/content/*` and `/site` shows "Er ging iets mis" instead of a sign-in screen.** | build `pages/SignInPage.vue`, handle 401 `authentication_required` | `signinRoute.js`: move | `broker-login.spec.mjs`, `portal-signin-settings.spec.mjs` (src/lib) |
| a4 | Silent sign-in once per browser session | `App.jsx` 289-302, `idleSession.silentSignInUrl()` | build | shell | `idleSession.js`: move | `idle-session.spec.mjs` |
| a5 | Idle window: refresh on activity, warning, sign-out with reason | `lib/useIdleSession.js`, `lib/idleSession.js`, `IdleWarningDialog.jsx` | exists: `lib/idleTracker.js`, `IdleWarningDialog.vue` (both already import `../portal/lib/idleSession.js`) | keep | `useIdleSession.js`: drop; `idleSession.js`: move | `idle-session.spec.mjs` |
| a6 | Sign-out, incl. the broker's own sign-out | `App.jsx` `logout()`, `idleSession.logoutTarget()` | exists: `App.vue` `signOut()` | keep | move (with a5) | `idle-session.spec.mjs` |
| a7 | Navigation built from the pages contributions declare, plus fixed entries (My cases first; tasks, messages, news, inbox, access, details, account), unread badge on inbox, default to first content page | `App.jsx` `buildNav()` 102-152, 356-378, 555-576 | build. The site has CMS menus (`SiteMenu.vue`, `BrandHeader.vue`) and the `contributions` block index only. `/diensten/{app}/{page}` is specified but not built (`portal-theme-blocks-and-contributed-pages` task 9) | `lib/residentNav.js` (framework-free port of `buildNav`) + signed-in menu in `BrandHeader.vue` + reserved routes | new | none (App.jsx has no unit test) |
| a8 | Portal title and branding | `main.jsx` `document.title`, `App.jsx` header org name, `theme-${theme}` class | exists: server `<title>`, `applyDocumentTitle()`, `BrandHeader.vue`, `themeClass` | keep | drop | `site-head.spec.mjs`, `site-shell-blocks.spec.mjs` |
| a9 | i18n and locale (nl, en; 283 keys; `{var}` interpolation) | `i18n/index.js` `createTranslator()`, `i18n/en.json`, `i18n/nl.json` | partial: no general translator. Dutch literals in `App.vue` ("Bezig met laden…", "Pagina niet gevonden", "Ingelogd"). `IdleWarningDialog.vue` and `App.vue` import `../portal/i18n/*.json`; `SiteNotices.vue` keeps its own map | `src/shared/i18n/` + a `provide('t')` in `App.vue` | `index.js` + json: move | key presence read by `message-box-channel.spec.mjs`, `open-record.spec.mjs` |
| a10 | Maintenance and warning notices | `PortalNotices.jsx`, `lib/notices.js` | exists: `SiteNotices.vue` (imports `../../portal/lib/notices.js`) | keep | move | `notices.spec.mjs` |
| a11 | eHerkenning branch: switcher, branch in effect, refusal | `BranchSwitcher.jsx`, `lib/branch.js`, api `fetchBranches/chooseBranch` | build | `components/BranchSwitcher.vue` in the header | move | `branch-choice.spec.mjs`, `branch-in-effect.spec.mjs` |
| a12 | Loading indicator in a polite status region | `Loading.jsx` | partial: `<p data-testid="site-loading">Bezig met laden…</p>`, no `role="status"`, Dutch only | `components/LoadingStatus.vue` | drop | `portal-live-regions.spec.mjs` |
| a13 | Shell stylesheet | `theme.css` (513 lines, `portaliq-*` classes) | exists: vendored NLDS CSS + `css/site-theme.css` | restate any class a ported page needs in `site-theme.css`, tokens only | drop | none |
| a14 | Ways in: create an account (challenge, honeypot, policy outcome), activate from `#activate=`, follow a case with its number, accept an invitation from `#invitation=`, only the doors `waysIn` turns on | `components/WaysIn.jsx`, `lib/waysIn.js`, api `challenge/registerAccount/activateAccount/requestReferenceLink/redeemReferenceLink/referenceCase/acceptInvitation` (landed by #1022 after this inventory was read) | build | `components/WaysIn.vue` on `pages/SignInPage.vue`; `waysIn` from the site config | `waysIn.js`: move | `ways-in-screens.spec.mjs` |

### Slice b. Collections

| # | Capability | React source | Site today | Vue target | lib | Node test today |
|---|---|---|---|---|---|---|
| b1 | Page view: one contribution page's ordered blocks (`richText`, `collection`, `detail`, `citizenCase`, `action`, `cta`, timed task) | `PageView.jsx` 324-533 | build (spec'd as `ContributionPage.vue` in `portal-theme-blocks-and-contributed-pages` task 9) | `pages/ContributionPage.vue` | rewrite | `attached-actions.spec.mjs`, `my-dossiers.spec.mjs` (both read PageView.jsx) |
| b2 | Collection table: columns from manifest `columns` else row keys, `render` cell formatters, keyboard row selection, row buttons, busy row | `CollectionTable.jsx` | build | `components/CollectionTable.vue` | rewrite | `collection-table-keyboard.spec.mjs` |
| b3 | Load collections a page references; reload every collection reading a schema after a create; refresh unread count | `App.jsx` 399-450 | build | `lib/collectionLoader.js` (framework-free) + `ContributionPage.vue` | new | none |
| b4 | Detail card: fields, file list with upload and download, proposals | `PageView.jsx` `DetailCard` 238-322 | build | `components/DetailCard.vue` | rewrite | `my-dossiers.spec.mjs` |
| b5 | Rich text block: headings and paragraphs, no raw HTML | `RichText.jsx` | exists: `MarkdownBlock.vue` (`cnRenderMarkdown`). Check its sanitising matches "no raw HTML" before reuse | reuse `MarkdownBlock.vue` | drop | none |
| b6 | Timeline of one object | `TimelineList.jsx` | build | `components/TimelineList.vue` | rewrite | `case-timeline.spec.mjs` |
| b7 | Item list with remove | `ItemList.jsx`, `lib/itemList.js` | build | `components/ItemList.vue` | `itemList.js`: move | `my-dossiers.spec.mjs` |
| b8 | Open a record from a link (`#open=<app>/<collection>/<id>`), kept across sign-in, "not in your list" notice | `lib/openRecord.js`, `App.jsx` 170-173, 382-397 | build | shell hook + `ContributionPage.vue` | move | `open-record.spec.mjs` |
| b9 | `crossRefs` | none in `src/portal`. It is a server guard: `lib/Contribution/CrossRefConfigNormaliser.php`, `lib/Service/PortalCrossRefGuard.php` | n/a | nothing to port. The Vue form posts the same body | n/a | PHPUnit only |
| b10 | `groupBy` | **not found** in `src/portal`, `lib/` or any open change | n/a | nothing to port. If the coordinator meant a specific feature, it needs a name | n/a | n/a |

### Slice c. Forms and actions

| # | Capability | React source | Site today | Vue target | lib | Node test today |
|---|---|---|---|---|---|---|
| c1 | Schema form: whitelisted `fields`, `fieldConfigs`, `optionsProviders` (static or collection-backed via `fetchOptions`) | `SchemaForm.jsx` | partial: `FormBlock.vue` (CMS form) and `IntakeFormBlock.vue` (intake) use other contracts | `components/SchemaForm.vue` | rewrite | none for the component |
| c2 | File fields: create first, then upload each file | `lib/fileFieldSubmit.js` | build | used by `SchemaForm.vue` | move | `schema-form-file-field.spec.mjs` |
| c3 | Inline action fields | `ActionFieldsForm.jsx` | build | fold into `SchemaForm.vue` (as `case-actions-row-inputs-and-conditions` T03 plans) | drop | none |
| c4 | Propose a change | `ProposeChangeForm.jsx` | build | `components/ProposeChangeForm.vue` | rewrite | none |
| c5 | Row status transitions (`type: update`, no field data) | `App.jsx` `onRowAction` 456-465 | build | `ContributionPage.vue` | new | none |
| c6 | Endpoint row actions: confirm step, notice, outcome, answer link | `RowActionConfirm.jsx`, `lib/rowAction.js` | build | `components/RowActionConfirm.vue` | move | `row-action.spec.mjs`, `my-dossiers.spec.mjs` |
| c7 | Endpoint and cta actions: forward, follow a checked redirect, else show the answer | `App.jsx` `onAction` 470-482, `PageView.jsx` 494-525 | partial: `lib/residentActions.js` forwards the Woo entry-point actions | `ContributionPage.vue` using `rowAction.runAction` | move (with c6) | `row-action.spec.mjs` |
| c8 | Attached actions (another app's actions on a record) | `AttachedActions.jsx`, `lib/attachedActions.js` | partial: `SaveToDossier.vue`, `SaveSearch.vue` for Woo | `components/AttachedActions.vue` | move | `attached-actions.spec.mjs` |
| c9 | Signing and declining a document | `SigningDialog.jsx`, `DeclineDialog.jsx`, `lib/signing.js` | build | `modals/SigningDialog.vue`, `modals/DeclineDialog.vue` | move | `signing-dialog.spec.mjs` |

### Slice d. Inbox, messages, news, notification settings, tasks, timed tasks

| # | Capability | React source | Site today | Vue target | lib | Node test today |
|---|---|---|---|---|---|---|
| d1 | Unified inbox: merged, unread, mark read, open record, view task, message-box deliveries | `InboxPage.jsx`, `lib/messageBox.js` | build | `pages/InboxPage.vue` | `messageBox.js`: move | `message-box-channel.spec.mjs`, `translated-message-notice.spec.mjs`, `open-record.spec.mjs` |
| d2 | Notification settings (per kind: e-mail, push) | `NotificationSettings.jsx` | build | `components/NotificationSettings.vue` | rewrite | `message-box-channel.spec.mjs`, `translated-message-notice.spec.mjs` |
| d3 | Messages (threads, language picker) | `MessagesPage.jsx` | build | `pages/MessagesPage.vue` | rewrite | `news-item-translation.spec.mjs`, `news-title-and-newsletter-translation.spec.mjs`, `newsletter-title-translation.spec.mjs` |
| d4 | News and newsletter archive, `hasNews` | `NewsPage.jsx` | build | `pages/NewsPage.vue` | rewrite | same three news specs |
| d5 | AI translation notice | `TranslatedText.jsx` | build | `components/TranslatedText.vue` | rewrite | `translated-message-notice.spec.mjs` |
| d6 | My tasks: list, complete with comment and files, deep link from the inbox | `TasksPage.jsx` | build | `pages/TasksPage.vue` | rewrite | **none** |
| d7 | Timed tasks (tests with a countdown) | `TimedTaskView.jsx`, `TimedTaskItem.jsx`, `lib/timedTask.js` | build | `components/TimedTaskView.vue`, `TimedTaskItem.vue` | move | `timed-task.spec.mjs` |

### Slice e. Account, registered details, access requests, my cases, citizen case

| # | Capability | React source | Site today | Vue target | lib | Node test today |
|---|---|---|---|---|---|---|
| e1 | My account: name, contact addresses, preferred channel, removal; contact prompt; `#confirm-email=` | `AccountPage.jsx` (+ `ContactPrompt`), `lib/account.js`, `App.jsx` 184-188, 255-264, 580-589 | build | `pages/AccountPage.vue`, shell hook for the fragment | move | `account-page.spec.mjs` |
| e2 | My details (BRP, KvK) | `RegisteredDetailsPage.jsx` | build | `pages/RegisteredDetailsPage.vue` | rewrite | `registered-details.spec.mjs` |
| e3 | Access requests, asker's side | `AccessRequestsPage.jsx` | build | `pages/AccessRequestsPage.vue` | rewrite | `access-request-asker.spec.mjs` |
| e4 | My cases across apps, closed marker, open a case | `MyCasesPage.jsx`, `lib/myCases.js` | build | `pages/MyCasesPage.vue` | move | `my-cases-page.spec.mjs` |
| e5 | Acting for (mandates), kept for the session | `ActingForSwitcher.jsx`, `myCases.js` `readActingFor/keepActingFor/actingForHeld` | build | `components/ActingForSwitcher.vue` | move | `my-cases-acting-for.spec.mjs` |
| e6 | Citizen case: writable set, amend, add document, documents grouped, download | `CitizenCase.jsx`, `lib/caseDocuments.js` | build | `components/CitizenCase.vue` | move | `case-documents-screen.spec.mjs`, `case-type-portal-header.spec.mjs` |
| e7 | Withdraw a request | `WithdrawCaseConfirm.jsx`, `lib/withdrawal.js` | build | `modals/WithdrawCaseConfirm.vue` | move | `case-withdraw-screen.spec.mjs` |

### Slice f. PWA and embed

| # | Capability | React source | Site today | Vue target | lib | Node test today |
|---|---|---|---|---|---|---|
| f1 | Web app manifest | `PortalManifestController::manifest()` at `/portal/manifest.webmanifest`; `start_url` built from `portalPage.index`; linked only by `templates/portal.php` | build: `templates/site.php` links no manifest | link it from `site.php`; `start_url` to `portalPage.site` | n/a (PHP) | PHPUnit `PortalManifestControllerTest` |
| f2 | Service worker | `serviceWorker.js`, served from disk by `PortalManifestController::serviceWorkerSourcePath()` = `src/portal/serviceWorker.js`; registered in `main.jsx` | build | move the file out of `src/portal/` first, register from `src/site/main.js`, cache the site bundle | move (then edit the cached names) | none |
| f3 | Install offer (`beforeinstallprompt`) | `App.jsx` 200-241, 547-553 | build | `components/InstallBanner.vue` | rewrite | none |
| f4 | Embed frame: form, refusal copy, height reporting | `templates/embed.php` (loads `portaliq-portal`), `main.jsx` 110-138, `EmbeddedForm.jsx`, `embedCopy.js`, `embedHeight.js` | build | a small own entry `portaliq-embed` (`src/embed/main.js` + `EmbeddedForm.vue`), so the frame does not pull the site bundle | `embedCopy.js`, `embedHeight.js`: move | `embed-copy.spec.mjs`, `embed-height.spec.mjs` (**neither is wired into `package.json`, so `check:specs` never runs them**) |

### Slice g. Retirement

| # | Item | Where |
|---|---|---|
| g1 | `/portal` answers 302 to `/site`, keeping the query string (`?portal=`, `?org=`) | `PortalPageController::index()`; the fragment (`#token=`, `#open=`, `#signin=failed`, `#confirm-email=`) survives a 302 in the browser when the Location has none |
| g2 | Server redirects that land on `portalPage.index` today | `SessionController::portalReturnTo()` (line 109), `BrokerSessionController::portalPath()` (169), `PortalDeepLinkBuilder::PORTAL_ROUTE` (51, used by notification and task mails), `PortalManifestController::startUrl()` (205). Point them at `portalPage.site` so nobody depends on the redirect |
| g3 | `?org=` on `/site` | `/portal` resolves a tenant from `?org=`; `/site` reads `?portal=` and host only. Mails built with `forOrganisation()` carry `?org=`. Either `/site` honours `?org=` or the redirect maps it |
| g4 | Delete | `src/portal/` (after the moves above), `webpack.portal.js`, `templates/portal.php`, `build:portal`/`watch:portal` scripts, `react`, `react-dom`, `@utrecht/component-library-react`, the JSX babel preset if nothing else uses it, the `@portal` alias, REUSE/eslint entries |
| g5 | Tests | React-only node specs rewritten against the Vue port or deleted with their component; ~44 Playwright specs under `tests/e2e/` that open `/apps/portaliq/portal` move to `/site`; `tests/e2e/ci-seed.sh` |
| g6 | Docs | `docs/portal-parity.md` (rewritten from the checklist below), `README.md`, `docs/operations/row-actions-and-payments.md`, `docs/operations/staying-signed-in-and-single-sign-on.md`, `openspec/specs/parent-pwa-installability/spec.md`, `openspec/specs/supplier-portal/spec.md` |

### Rough size per slice (React lines to replace)

| Slice | React lines | Notes |
|---|---:|---|
| a. shell | 4,145 (1,710 without `portalApi.js`, i18n json and `theme.css`, which move or drop) | biggest risk: token store and nav model |
| b. collections | 1,109 | depends on the contributed-page route |
| c. forms and actions | 1,494 | |
| d. inbox, messages, news, tasks | 2,090 | `TasksPage` has no node test today |
| e. account, details, cases | 1,608 | |
| f. PWA, embed | 448 (+ 16 `embed.php`) | |
| g. retirement | 135 (`portal.php`, `webpack.portal.js`, `embed.php`) + PHP redirects + ~44 e2e files | |

---

## Callers of `/portal` in other apps

Searched on fresh shallow clones of `development` on 2026-10-01 (thirteen
repos; the full table is in the site-parity programme ledger). One live
caller: learniq `tests/e2e/po-parent-flows.spec.ts:393` opens
`/apps/portaliq/portal?portal=…`. dossiq and hydra cite `src/portal/` files in
designs and ADRs; opencatalogi and openregister already use `/site`.

## Callers of `/portal` inside portaliq

PHP that lands on `/portal`: `SessionController.php:109`, `BrokerSessionController.php:169`, `PortalDeepLinkBuilder.php:51` (all notification and task mails), `PortalManifestController.php:205`, `PortalManifestController.php:164` (serves `src/portal/serviceWorker.js` from disk). Templates: `templates/portal.php`, `templates/embed.php:14` (loads the portal bundle). Tests: ~44 Playwright specs, `tests/e2e/ci-seed.sh`, PHPUnit `PortalManifestControllerTest`, `SessionControllerTest`.

---

## Theme application on `/site` today

**The code does; the integration instance does not, and NL Design classes render only partly.**

What the code does. `PortalPageController::site()` resolves the serving portal and asks `PortalThemeResolver::stylesheetFor($portal['theme'])`. That returns `tokens/<theme>` only when all hold: the slug is safe, a theme app (`nldesign` or `thematiq`) is installed, its `token-sets.json` catalogue lists the set, `css/tokens/<theme>.css` exists, and a custom set passes the theme app's validator. `templates/site.php` then links that file from the theme app **last**, after the vendored NLDS sheets, so the theme's `:root` tokens win. It also emits `--nldesign-logo-url` inline and links the theme app's font stylesheet when it has one. `nldsStylesheetFor()` always returns null now; portaliq ships no tokens of its own. The root div also gets a `<theme>-theme` class.

Measured live on :8090 (read-only, curl and browser-1):

- No theme app is enabled on :8090 (`occ`-free check: `ocs/v2.php/cloud/apps?filter=enabled` lists learniq, openregister, portaliq; no nldesign or thematiq).
- `wilgenboom` has no `theme` (content API answers `"theme":""`). `/site?portal=wilgenboom` links seven portaliq sheets (`nlds-components`, `nlds-vendor-a`, `nlds-vendor-b`, `nlds-app`, `nlds-controls`, `site-theme`, `nlds-fonts`) and **no token stylesheet**. Root class `pq-site ac-app-container`, no theme class.
- `demo` has `theme: opencatalogi`. Same seven sheets, still **no token stylesheet** (no theme app to resolve it from). Root class gains `opencatalogi-theme`; `:root` declares **0** `--utrecht-*` properties; the `h1` computes `rgb(0, 0, 0)`.
- NL Design classes: on `demo`, 6 elements carry `utrecht-*` classes (10 distinct), the rest is the vendored `ac-*` skeleton. On `wilgenboom` only the skip link does, because the page body is an error.
- **Extra finding for slice a:** anonymous `/site?portal=wilgenboom` shows "Er ging iets mis / De inhoud kon niet worden geladen." The content API answers `401 {"error":"authentication_required","authentication":{"modes":["digid"]}}` for this portal, and `App.vue` `loadSite()` treats any rejection as a failure. The React portal shows its sign-in screen here.

So for the themes lane: once thematiq is installed on :8090 and a portal's `theme` names a catalogued set, `site.php` will link it. Nothing in `src/site` needs to change for the link. Whether every surface then repaints depends on `css/public-bridge.css` (thematiq#355), which `site.php` does **not** link yet (`portal-theme-blocks-and-contributed-pages` task 2).

---

## Architecture of the site, for the slice builders

**Boot.** `PortalPageController::site()` renders `templates/site.php` with `RENDER_AS_BLANK`: the template owns the whole document, links the stylesheets, writes a JSON config block `#portaliq-site-config` (`portal`, `apiBase`, `resolvedPortal`, `title`) and a nonce'd script tag. `src/site/main.js` mounts `App.vue` on `#portaliq-site` with `portalSlug`. No `OC`, `OCA`, `requesttoken` or `generateUrl`: the bundle must run at a public origin, and e2e asserts that.

**Routing.** No vue-router. The in-site route is the `?route=/x` query parameter (`App.vue` `routeFromLocation()`), `go(link)` does `history.pushState`, `popstate` reloads. Each route is a CMS page fetched from `/api/content/pages`. A 404 falls back one level to the parent and hands the last segment over as `routeParam` (`/publicatie/<id>`). Signed-in screens need reserved routes (proposal: `/mijn/zaken`, `/mijn/inbox`, `/mijn/taken`, `/mijn/berichten`, `/mijn/nieuws`, `/mijn/account`, `/mijn/gegevens`, `/mijn/toegang`, and `/diensten/{app}/{page}` for contribution pages as already specified) that the shell renders without a CMS page.

**Session and bearer.** `lib/authApi.js`: `adoptSessionToken()` takes `#token=` from the fragment into sessionStorage `portaliq.session.token` and strips it; `fetchSession(authBase)` reads `/portal/api/session`; `refreshSession()` rotates; `clearSessionToken()`. `authBaseFrom(resolveApiBase())` maps the content API base to `.../portal/api`. `lib/residentSession.js` wraps it: `residentAuthBase()`, `residentToken()`, `residentManifest()` (contributions, fetched once per page). `lib/residentActions.js` `getJson(url, token)` is the plain bearer GET. **Two decisions for slice a:** (1) `portalApi.js` reads localStorage `portaliq_token`, the site sessionStorage `portaliq.session.token`; after the move `createPortalApi()` must read the site's store, and a resident with only the old key is signed out once. (2) `portalApi.js` is the right data seam for every ported page (every method is a plain `fetch`); move it to `src/shared/portalApi.js` and give it a token getter instead of its own storage.

**Blocks.** `src/site/components/WidgetGrid.vue`: `PUBLIC_WIDGETS` (line 174) maps `widgetKey` to a component, heavy ones through `defineAsyncComponent`; `propsFor(widget)` (≈line 380) hands each its data; the shared `siteBlockRegistry` from `@conduction/nextcloud-vue/public` supplies the rest. The page designer's palette reads `src/lib/pageWidgetCatalogue.js`. A ported screen that an author may place (for example "My cases" on a landing page) is registered here as well as on its reserved route.

**i18n.** None in the site beyond two components borrowing `src/portal/i18n/*.json`. The locale is `site.locale` from `/api/content/site` and the server-set `<html lang>`. Move `src/portal/i18n/` to `src/shared/i18n/`, keep `createTranslator(locale)`, `provide` one `t` from `App.vue`, and replace the Dutch literals in `App.vue` as each slice touches them. Every new string goes into both json files.

**Versions.** `vue` ^3.5.43 (Options API in `App.vue`), `@conduction/nextcloud-vue` ^2.57.3 (only the `/public` entry: `CnSiteSearch`, `CnSiteIcon`, `siteBlockRegistry`, `siteBlockIsBand`; and `cnRenderMarkdown` from the main entry). No `@nextcloud/vue`, no Pinia, no router in the site bundle. Styling is Utrecht component CSS plus the vendored `ac-*` skeleton; no Utrecht React components.

**Budget.** `webpack.site.js` fails the build at 412 KiB entry and asset size; the entry sat 182 B under it. Every ported page must be a `defineAsyncComponent` chunk loaded on its route, and `portalApi.js` should be imported only by those chunks.

**Recommended layout.**

```
src/shared/           framework-free, imported by both bundles until retirement
  portalApi.js        (from src/portal/lib, token getter injected)
  i18n/               (index.js, en.json, nl.json)
  idleSession.js signinRoute.js notices.js branch.js openRecord.js itemList.js
  rowAction.js attachedActions.js signing.js fileFieldSubmit.js messageBox.js
  timedTask.js account.js myCases.js caseDocuments.js withdrawal.js
  embedCopy.js embedHeight.js serviceWorker.js
src/site/pages/       one async component per reserved route
src/site/components/  CollectionTable, DetailCard, SchemaForm, ... 
src/site/modals/      SigningDialog, DeclineDialog, WithdrawCaseConfirm, RowActionConfirm
src/embed/main.js     small own entry for the embed frame
```

Move a lib with `git mv`, then point the React import at the new path in the same commit, so `/portal` keeps working until g. Node specs follow the lib (`src/portal/lib/x.js` path strings in `tests/*.spec.mjs`).

**Coordination.** The slice-a lane (`feat/site-signed-in-shell`) and every later slice touch `App.vue`; land the `src/shared/` moves early and in one PR so later slices do not each move the same files.

---
