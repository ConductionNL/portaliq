---
title: Portal parity, the Vue site against the React portal
sidebar_label: Portal parity
---

# Portal parity

Measured 2026-08-15 on a disposable Nextcloud 34 instance
(`portaliq-p2-rig`, `:8321`) running portaliq, openregister and nldesign from
the working tree. Both bundles built with `NODE_ENV=production`.

This page exists because ADR-084 says parity must be **measured, not
asserted**, and because the honest answer is more interesting than the one I
expected.

## What is actually being compared

The two renderers are not two versions of one screen. They render different
content models against different data:

| | React portal (`/portal`) | Vue site renderer (`/site`) |
| --- | --- | --- |
| Audience | An authenticated supplier/citizen | Anonymous public visitor |
| Auth | Bearer session (`portaliq_token`) | None |
| Content | Subject-scoped OR collections, actions, inbox | Portal CMS: menus, pages, glossary |
| Source | `/portal/api/contributions` | `/api/content/*` |
| Layout | Linear stack of typed blocks | 12-column manifest grid, or markdown |

So a pixel diff between them would be a large number that means nothing. The
screenshots in `tests/e2e/visual/` are for human review; what is compared
numerically is below.

## Size — the result I did not expect

| Bundle | Raw | Gzipped |
| --- | ---: | ---: |
| `portaliq-portal.js` (React) | 181,188 B | 56,099 B |
| `portaliq-site.js` (Vue) | 162,907 B | **56,316 B** |

**Uncompressed the Vue bundle is ~10% smaller. Compressed it is 217 bytes
LARGER.** Gzip is the number a visitor pays, so the honest summary is: the two
are the same size, and any claim that moving to Vue made the public bundle
smaller would be wrong.

That is worth stating plainly because the raw figure was the one I reached for
first, and it flattered the change. The compressed figures differ by 0.4% —
noise.

What the Vue bundle buys is not bytes. It is that the grid, the markdown
renderer and (once nc-vue chain links 1–2 land) the whole communal widget
catalog come from the shared library instead of being reimplemented per
front-end.

### Re-measured 2026-08-20 — the figures above were stale by 84%

The table above is kept as written because it is what was true in August and
because the conclusion it draws still holds. The numbers are not current.
Re-measured on `development` at 59486c3, `NODE_ENV=production`:

| `portaliq-site.js` | Raw | Gzipped |
| --- | ---: | ---: |
| recorded 2026-08-15 | 162,907 B | 56,316 B |
| measured 2026-08-20, before this change | 358,538 B | 103,209 B |
| measured 2026-08-20, with `federatedSearch` | 386,945 B | **111,254 B** |

**The baseline grew 83% gzipped in five days, and no entry in this file
recorded it.** That is the failure this page exists to prevent: a parity
document whose numbers are not re-measured asserts parity rather than
measuring it, and it does so in the confident voice of something that once
was checked.

The `federatedSearch` block accounts for **+8,045 B gzipped (+7.8%)** of the
current figure. That delta was measured by building the same tree twice with
only the block's registration in `WidgetGrid.vue` reverted — not by
subtracting an estimate, and not against the stale August baseline, which
would have attributed the whole 55 KB of intervening growth to this change.

Anyone touching this file: re-measure both bundles in the same run. A row here
is worth exactly as much as the date beside it.

## Parity checklist, measured 2026-10-02

This replaces the "what each renderer can do" table of 2026-08-15. That table
compared a public renderer with a signed-in one. Since then every signed-in
screen of the React portal was ported to the site (`site-reaches-portal-parity`,
slices a to f), and slice g ported what was still missing and retired `/portal`.

One row per capability in `openspec/changes/site-reaches-portal-parity`
(inventory rows a1 to g6, requirement REQ-SRP-051). The checklist of the
validation programme (`parity-checklist.md`, rows A1 to E) maps onto these rows
in the last column. Status:

- **ported**: slices a to f built it; the file and the node test are named.
- **newly ported**: slice g built it, because it was still missing.
- **dropped**: deliberately not ported; the reason is given.

Every node test named here runs in `npm run check:specs`.

### Shell: session, sign-in, navigation, branding

| # | Capability | Vue port | Evidence | Status | Checklist |
| --- | --- | --- | --- | --- | --- |
| a1 | Boot from runtime config | `src/site/main.js`, `lib/contentApi.js`, `templates/site.php` | `site-head.spec.mjs` | ported | A1 |
| a2 | Bearer from `#token=`, one store per tab, session read | `lib/authApi.js` `adoptSessionToken` | `site-auth.spec.mjs` | ported | A9 |
| a2 | The React portal's localStorage `portaliq_token` taken once | `lib/authApi.js` `adoptLegacyToken` | `site-auth.spec.mjs` (adoptLegacyToken) | newly ported | A9 |
| a3 | Sign-in screen, provider buttons, failed sign-in, "no login method", dev login and its refusal, 401 `authentication_required` | `components/AccountArea.vue`, `BrandHeader.vue`, `App.vue` (`signInNeeded`, `devLogin`) | `site-signed-in-shell.spec.mjs`, `broker-login.spec.mjs`, `site-auth.spec.mjs` | ported | A5, A6, A7, A8 |
| a3 | A provider the organisation routes to the broker | The site links to `/session/oidc/start`; `SessionController::oidcStart` sends a broker-routed provider on to `/session/broker/start` | PHPUnit `SessionControllerTest` (broker start) | ported (on the server, not in the button as the React portal did) | A6 |
| a4 | Silent sign-in once per browser session | `App.vue` with `shared/idleSession.js` `silentSignInUrl` | `idle-session.spec.mjs` | ported | A10 |
| a5 | Idle window: refresh on activity, warning, hard expiry, sign-out with reason | `lib/idleTracker.js`, `components/IdleWarningDialog.vue` | `idle-session.spec.mjs` | ported | A11, A12, A13, A14 |
| a5 | Cross-tab sync of the idle window | none | n/a | dropped: the site keeps its bearer per tab (sessionStorage), so no other tab shares the session to follow | A14 |
| a6 | Sign-out at the edge and the broker | `App.vue` `signOut`, `shared/idleSession.js` `logoutTarget` | `idle-session.spec.mjs` | ported | A15 |
| a7 | Navigation from contributions, fixed entries, unread badge, own address per entry | `shared/portalNav.js`, `lib/shellData.js`, `components/SiteMenu.vue` | `site-signed-in-shell.spec.mjs` | ported | A25, A26 |
| a8 | Tab title and header carry the portal's name | `templates/site.php`, `BrandHeader.vue`, `App.vue` `sessionLabel` | `site-head.spec.mjs`, `site-shell-blocks.spec.mjs` | ported | A2, A3, A4 |
| a9 | One translator, nl and en | `src/shared/i18n/` | `npm run check:l10n-js`, key checks in each page spec | ported | A18 |
| a10 | Notices above every page, closable for the visit | `components/SiteNotices.vue`, `shared/notices.js` | `notices.spec.mjs` | ported | A19 |
| a10 | Notices for signed-in residents (surface `portal`) | `PortalPageController::sitePortalNotices`, `shared/notices.js` `noticesFor` | `notices.spec.mjs`, PHPUnit `PortalPageControllerTest` | newly ported | A19 |
| a11 | eHerkenning branch: switcher, branch in effect, refusal | `components/BranchSwitcher.vue`, `shared/branch.js` | `branch-choice.spec.mjs`, `branch-in-effect.spec.mjs` | newly ported | C19, C20 |
| a12 | Loading in a polite status region | `role="status"` on every loading line, the shell's included | `portal-live-regions.spec.mjs` | newly ported (shell line) | A16 |
| a13 | Tokens only, no `theme.css` | `css/site-theme.css`, NL Design classes | `npm run stylelint` | ported | |

### Contribution pages, forms and actions

| # | Capability | Vue port | Evidence | Status | Checklist |
| --- | --- | --- | --- | --- | --- |
| b1 | Page blocks in order | `pages/collections/ContributionPage.vue`, `pageBlocks.js` | `site-collections.spec.mjs` | ported | B1 |
| b2 | Collection table: columns, formatters, keyboard rows, row buttons | `components/collections/CollectionTable.vue`, `cells.js` | `collection-table-keyboard.spec.mjs`, `array-cells.spec.mjs` | ported | B3 |
| b3 | Load and reload collections, unread refresh | `pages/collections/collectionLoader.js` | `collection-loader.spec.mjs` | ported | B18 |
| b4 | Detail card with file upload and download | `components/collections/DetailCard.vue` | `my-dossiers.spec.mjs` | ported | B9, B10 |
| b5 | Rich text, no raw HTML | `components/collections/RichTextBlock.vue` | `rich-text.spec.mjs` | ported | B2 |
| b6 | Record timeline | `components/collections/TimelineList.vue` | `case-timeline.spec.mjs` | ported | B14 |
| b7 | Item list with remove | `components/collections/ItemList.vue` | `my-dossiers.spec.mjs` | ported | B12 |
| b8 | `#open=` record links, kept across sign-in, "not in your list" | `shared/openRecord.js`, `App.vue` `keepOpenTarget`, `pages/collections` `openRecordEntry` | `open-record.spec.mjs` | ported | A27, A28, A29 |
| b9 | `crossRefs` | server only | PHPUnit | n/a: a server guard | |
| b10 | `groupBy` | not in the React portal | n/a | n/a | |
| c1 | Schema form | `components/c/SchemaForm.vue`, `SchemaField.vue` | `schema-form.spec.mjs` | ported | B15, B16 |
| c2 | File fields, create then upload, retry | `shared/fileFieldSubmit.js` | `schema-form-file-field.spec.mjs` | ported | B17 |
| c3 | Inline action fields | folded into `SchemaForm.vue` | `schema-form.spec.mjs` | ported | B19 |
| c4 | Propose a change, proposal queue | `components/c/ProposeChangeForm.vue`, `ProposalQueue.vue` | `propose-change.spec.mjs`, `proposal-queue-leaf.spec.mjs` | ported | B11 |
| c5 | Row status transition | `ContributionPage.vue` | `row-action.spec.mjs` | ported | B4 |
| c6 | Endpoint row action: confirm, notice, answer link | `modals/c/RowActionConfirm.vue`, `shared/rowAction.js` | `row-action.spec.mjs` | ported | B5, B6, B7 |
| c7 | Endpoint and cta actions | `components/c/ActionBlock.vue`, `ActionButton.vue` | `row-action.spec.mjs` | ported | B8 |
| c8 | Attached actions | `components/c/AttachedActions.vue` | `attached-actions.spec.mjs` | ported | B13 |
| c9 | Sign and decline a document | `modals/c/SigningDialog.vue`, `DeclineDialog.vue` | `signing-dialog.spec.mjs` | ported | C21, C22 |

### Fixed screens

| # | Capability | Vue port | Evidence | Status | Checklist |
| --- | --- | --- | --- | --- | --- |
| d1 | Inbox | `pages/inbox/InboxPage.vue`, `shared/messageBox.js` | `site-inbox-pages.spec.mjs`, `message-box-channel.spec.mjs` | ported | C8 |
| d2 | Notification settings | `components/inbox/NotificationSettings.vue` | `message-box-channel.spec.mjs` | ported | C9 |
| d3 | Messages with a language picker | `pages/inbox/MessagesPage.vue`, `MessageLanguagePicker.vue` | `site-inbox-pages.spec.mjs`, `news-item-translation.spec.mjs` | ported | C11 |
| d4 | News and newsletter archive | `pages/inbox/NewsPage.vue`, `NewsItem.vue`, `NewsletterArchive.vue` | `news-title-and-newsletter-translation.spec.mjs`, `newsletter-title-translation.spec.mjs` | ported | C12 |
| d5 | Machine translation notice | `components/inbox/TranslatedText.vue` | `translated-message-notice.spec.mjs` | ported | C13 |
| d6 | My tasks, from the inbox | `pages/inbox/TasksPage.vue` | `tasks-page.spec.mjs` | ported | C10 |
| d7 | Timed tasks | `components/timed-task/TimedTaskView.vue`, `TimedTaskItem.vue` | `timed-task.spec.mjs` | ported | C23, C24 |
| e1 | My account, contact prompt, `#confirm-email=` | `pages/e/AccountPage.vue`, `components/e/ContactPrompt.vue`, `AddressList.vue` | `account-page.spec.mjs` | ported | A23, A24, C14, C15 |
| e2 | My details (BRP, KvK) | `pages/e/RegisteredDetailsPage.vue` | `registered-details.spec.mjs` | ported | C16, C17 |
| e3 | Access to cases | `pages/e/AccessRequestsPage.vue` | `access-request-asker.spec.mjs` | ported | C18 |
| e4 | My cases | `pages/e/MyCasesPage.vue`, `shared/myCases.js` | `my-cases-page.spec.mjs` | ported | C1, C2 |
| e5 | Acting for | `components/e/ActingForSwitcher.vue` | `my-cases-acting-for.spec.mjs` | ported | C3 |
| e6 | Citizen case and its documents | `components/e/CitizenCase.vue`, `CaseField.vue` | `case-documents-screen.spec.mjs`, `case-type-portal-header.spec.mjs` | ported | C4, C5, C7 |
| e7 | Withdraw a request | `modals/e/WithdrawCaseConfirm.vue` | `case-withdraw-screen.spec.mjs` | ported | C6 |

### Installability and embed

| # | Capability | Vue port | Evidence | Status | Checklist |
| --- | --- | --- | --- | --- | --- |
| f1 | Web app manifest, `start_url` on `/site` | `PortalManifestController`, `templates/site.php` | PHPUnit `PortalManifestControllerTest` | ported | A22 |
| f2 | Service worker | `src/shared/serviceWorker.js`, `lib/pwa.js` | `service-worker.spec.mjs` | ported | A21 |
| f3 | Install offer | `components/f/InstallBanner.vue` | `install-banner.spec.mjs` | ported | A20 |
| f4 | Embed frame, refusals, height | `src/embed/` (`EmbedFrame.vue`, `EmbedForm.vue`) | `embed-frame.spec.mjs`, `embed-copy.spec.mjs`, `embed-height.spec.mjs` | ported | D1 to D4 |

### Retirement

| # | Item | Where | Evidence | Status |
| --- | --- | --- | --- | --- |
| g1 | `/portal` and `/portal/{path}` answer 302 to `/site` with the query string; the browser keeps the fragment | `PortalPageController::index`, `catchAll` | PHPUnit `PortalPageControllerTest`; headless Chrome kept `#token=`, `#open=`, `#signin=failed` and `#confirm-email=` across the 302 | newly ported |
| g2 | Server links: OIDC callback and its default, broker callback and every failure, broker logout return, mail links | `SessionController`, `BrokerSessionController`, `PortalDeepLinkBuilder` | PHPUnit `SessionControllerTest`, `BrokerSessionControllerTest`, `PortalDeepLinkBuilderTest`, `NotificationDispatchJobTest`, `PortalTaskDeliveryJobTest` | newly ported |
| g3 | `/site` reads `?org=` | `PortalPageController::requestedPortalSlug` | PHPUnit `PortalPageControllerTest` | newly ported |
| g4 | React portal deleted | `src/portal/`, `webpack.portal.js`, `templates/portal.php`, React dependencies | `npm ls react` empty, `npm run build` emits no `portaliq-portal.js` | done |
| g5 | Tests off React; Playwright specs on `/site` | `tests/*.spec.mjs`, `tests/e2e/` | no test compiles JSX | done |

### What this does not prove

The node tests render the Vue components and drive their methods. They do not
run a browser against a live instance. Line 4 of REQ-SRP-051 (each screen as
the guardian on the integration instance, with a screenshot) was checked per
slice by the coordinator on `:8090`. It is not repeated here, because this
change was not deployed there.

## Requests on a first visit (2026-08-15)

The Vue renderer makes four content calls on first paint (`site`, `menus`,
`pages`, `glossary`) plus one per page navigation. All are public, cacheable
GETs; the anonymous variants carry `public, max-age=300, must-revalidate`.

The React portal makes two (`session`, `contributions`), but neither is
cacheable — both are per-subject.

## What was verified, and how (2026-08-15)

Twelve scenarios in `tests/e2e/scenarios/portal-phase-two.md`, run as 16 tests
across four spec files. All green.

Four of them were **mutation-tested** — the implementation was deliberately
broken and the test had to fail:

| Mutation | Test | Result |
| --- | --- | --- |
| Add `files` to the public widget allow-list | S10 | **passed — the test was blind** |
| Same, after fixing the gate | S10 | fails, as required |
| Render markdown without the shared sanitiser | S9 | fails, as required |
| Drop the `status: published` query filter | S4 | fails, as required |
| Disable cache invalidation on write | S8b | fails, as required |

The first row is the finding worth keeping. `WidgetGrid.vue` held a
`PUBLIC_WIDGET_KEYS` set *and* a hard-coded `widgetKey === 'markdown'` check,
so the allow-list decided nothing — adding a widget to it changed no
behaviour. The gate looked present and was not, and the e2e test could not
tell. The map is now the gate, and the same mutation fails.

## Correction: per-site theming does not yet render

An earlier version of this table claimed the Vue renderer does per-site
theming. It does not. Measured 2026-08-15: `open-tilburg` and `open-venray`
carry different theme classes (`vng-theme`, `venray-theme`), `--pq-heading-color`
is UNSET on both, both compute `rgb(26,26,26)` for the heading, and zero
elements carry NL Design classes. **The two sites render identically.**

The theme is a class with nothing behind it — the same defect phase one hit on
a Tilburg deployment. It is tracked as gap 2.1 in the programme's gap analysis
and specified in `openspec/changes/portal-theme-application`.

The test that should have caught it (`S6b`) asserted only that the API returns
different theme STRINGS, and its name and my description of it implied more.
Both are corrected.

## Known gaps (2026-08-15)

- Per-site theming renders nothing (above). Until `portal-theme-application`
  lands, no visual comparison here supports a parity claim against a themed
  Tilburg deployment.
- Per-portal authentication is declared in the schema and enforced nowhere.
  Every site currently behaves as `public` read-only, which happens to match
  the specified fail-closed default — a coincidence, not an implementation.
- Domain verification enforces the `verified` flag but nothing performs the DNS
  TXT lookup that sets it; in this rig it was set by hand.
- There is no editorial surface — content is created by `curl`. `cms-handover`
  removes OpenCatalogi's UI, so `portal-cms-admin-ui` must land first.
- **1 of 40** widget types renders. That is the correct default-closed interim
  posture, but "widgets work" would overstate it.
- The widget allow-list is a local stand-in. It becomes a filter over the
  shared registry's `public` flag when nextcloud-vue chain link 2 lands, and
  nothing already allowed may change behaviour then.
- `CnPageRenderer` / `CnAppRoot` are not yet used. The installed
  `@conduction/nextcloud-vue` 2.2.0-vue3.16 exports both, but booting them
  outside Nextcloud is chain link 1 (`host: 'public'`); until it exists this
  renderer composes library helpers rather than the manifest runtime.
- Supplier-facing capability (collections, inbox, actions, uploads) is not
  ported. `/portal` stays until it is.

## Structural comparison against :8306, run 2026-08-15

⚠️ **Read what :8306 actually serves before comparing to it.** The container is
`twu-themes2` — the tilburg-woo-ui codebase — but it is serving
**Softwarecatalogus** content ("342 gemeenten", "336 leveranciers"), not a
Tilburg WOO deployment. So CONTENT is not comparable and any claim of the form
"the new portal matches :8306" would be comparing two different products.
Structure and chrome are comparable; that is what was measured.

| Surface | :8306 (tilburg-woo-ui) | new renderer | verdict |
| --- | --- | --- | --- |
| Skip link | yes | yes | parity |
| Header + site name | yes | yes | parity |
| Two-level nav | yes | yes | parity |
| Footer | yes | yes | parity |
| Glossary | yes | yes | parity |
| **Browser tab title** | the site's own name | **"Nextcloud"** | **DEFECT — fixed** |
| Search | yes | no | gap, not started |
| Sign-in affordance | yes | yes, when declared | parity |

**The tab title was a real defect and is fixed.** A white-label portal whose
entire purpose is that a visitor never learns what it runs on was filing itself
in every bookmark, history entry, window-switcher and search result under the
name of the hosting platform. It appears in no screenshot, which is why it
survived a visual review. Now `Page - Portal`, asserted by S22.

**Search is a genuine gap and is NOT started.** The old portal searches its
publications; the new one has no search of any kind. It is listed here rather
than in a spec because no change owns it yet.
