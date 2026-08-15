---
title: Portal parity — the Vue site renderer against the React portal
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

## What each renderer can do

| Capability | React portal | Vue renderer |
| --- | --- | --- |
| 12-column manifest grid | ✗ | ✓ |
| Markdown pages | ✗ | ✓ (shared `cnRenderMarkdown`) |
| Communal widget catalog | ✗ | partial — allow-list stand-in until the registry carries `public` |
| Multi-site by host | ✗ | ✓ |
| Per-site theme | ✗ (per Organisation, via `?org=`) | **reference only — see below** |
| Glossary | ✗ | ✓ |
| Subject-scoped collections | ✓ | ✗ — not this renderer's job |
| Inbox, actions, file upload | ✓ | ✗ — not yet ported |
| Boots without Nextcloud globals | ✗ | ✓ (asserted, S11) |

The last two rows are the reason `/portal` has **not** been deleted. The
supplier-facing capabilities have no replacement yet, and a comparison against
a portal that has already been removed is not a comparison.

## Requests on a first visit

The Vue renderer makes four content calls on first paint (`site`, `menus`,
`pages`, `glossary`) plus one per page navigation. All are public, cacheable
GETs; the anonymous variants carry `public, max-age=300, must-revalidate`.

The React portal makes two (`session`, `contributions`), but neither is
cacheable — both are per-subject.

## What was verified, and how

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

## Known gaps

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
