# Test scenarios — Portaliq phase two

Covers the headless CMS (ADR-086) and the built-in Vue site renderer
(ADR-084). Each scenario names the spec requirement it exercises and the
Playwright spec that runs it.

**A note on how these are written.** Several of these scenarios exist
specifically to fail if a check stops working, not only to pass when it does.
Where a rule is "X must not happen", there is a paired scenario proving the
same machinery *can* say yes — a domain verifier that always refuses, a
`published` filter that hides everything, or a sanitiser that strips
everything, would each pass a one-sided test while being completely broken.

Fixtures: `tests/e2e/fixtures/seed-cms.sh` (idempotent; two websites, one
menu, a grid page, two markdown pages, a draft, two glossary terms).

---

## S1 — The site renders from the content API

| | |
| --- | --- |
| **Spec** | `portal-shared-runtime` — the portal must boot the shared runtime |
| **Spec** | `portal-headless-content-api` — the API must expose navigation, pages, glossary |
| **Runs in** | `site-content.spec.ts` |

- **GIVEN** the seeded `open-tilburg` website
- **WHEN** a visitor with no session opens `/site`
- **THEN** the header shows the site's own title from the API — not a
  hard-coded product name
- **AND** the menu renders with its two levels in stored order
- **AND** the home page's three grid widgets render in their declared cells

## S2 — Markdown is served and rendered as markdown

| | |
| --- | --- |
| **Spec** | `portaliq-cms` — a page body must be either a widget grid or markdown |
| **Runs in** | `site-content.spec.ts` |

- **GIVEN** `/over-ons`, whose body is markdown containing a code fence and a
  table
- **WHEN** the API is read directly
- **THEN** the response carries markdown SOURCE — the fence and the pipes are
  present and no HTML tag has been introduced
- **AND WHEN** the same page is opened in the renderer
- **THEN** the fence renders as a `<pre>` and the table as a `<table>`, so the
  fidelity survived the round trip a consumer would otherwise have to undo

## S3 — Navigation without a full page load

| | |
| --- | --- |
| **Spec** | `portal-shared-runtime` — a portal page must be a manifest-v2 page |
| **Runs in** | `site-content.spec.ts` |

- **GIVEN** the site is open at `/`
- **WHEN** the visitor activates the "Over ons" menu item
- **THEN** the page content changes and the menu item is marked
  `aria-current="page"`
- **AND** no full document navigation occurred

## S4 — An unpublished page is indistinguishable from one that never existed

| | |
| --- | --- |
| **Spec** | `portal-headless-content-api` — an unpublished page is not served |
| **Runs in** | `site-security.spec.ts` |

- **GIVEN** the draft page at `/concept` and a route `/does-not-exist`
- **WHEN** both are requested from the API
- **THEN** both return 404 with byte-identical bodies
- **AND** the published `/over-ons` returns 200 — the positive control, without
  which a `status` filter that hides *everything* would pass this scenario

## S5 — Content does not cross websites

| | |
| --- | --- |
| **Spec** | `portaliq-cms` — all content must be scoped to a website |
| **Runs in** | `site-multisite.spec.ts` |

- **GIVEN** two websites that each publish a page at `/over-ons`
- **WHEN** `/over-ons` is requested on the Tilburg host
- **THEN** the Tilburg page is returned
- **AND WHEN** it is requested on the Venray host
- **THEN** the Venray page is returned
- **AND** neither is reachable from the other's host by any request parameter

## S6 — A domain serves only once verified, and verification can succeed

| | |
| --- | --- |
| **Spec** | `portal-website-scoping-and-auth` — a custom domain must be verified before it serves |
| **Runs in** | `site-multisite.spec.ts` |

- **GIVEN** one website carrying a verified domain and an unverified one
- **WHEN** the unverified host is requested
- **THEN** it returns 404, identical to an unknown host
- **AND WHEN** the verified host is requested
- **THEN** the site is served
- **NOTE** both directions run in one scenario deliberately. A verifier that
  always refuses looks exactly like a working one when only the refusal is
  tested.

## S7 — An unknown host resolves to no site at all

| | |
| --- | --- |
| **Spec** | `portal-website-scoping-and-auth` — a request must resolve to exactly one website, or to none |
| **Runs in** | `site-multisite.spec.ts` |

- **GIVEN** a host bound to no website
- **WHEN** any content endpoint is requested
- **THEN** it returns 404
- **AND** the response body contains no site's title, theme or slug — a
  fallback to "the first website" would render perfectly and be invisible

## S8 — Caching separates anonymous from authenticated

| | |
| --- | --- |
| **Spec** | `portal-headless-content-api` — responses must be cached with the audience in the key |
| **Runs in** | `site-security.spec.ts` |

- **WHEN** a content endpoint is requested anonymously
- **THEN** the response is marked publicly cacheable
- **AND WHEN** the same endpoint is requested with an `Authorization` header
- **THEN** the response is marked `private, no-store`
- **NOTE** the key itself is unit-tested in `CmsReaderTest`, where removing the
  audience component makes the test fail. A header assertion alone cannot show
  that the two responses occupy different cache slots.

## S9 — Markdown from the CMS cannot execute at a public origin

| | |
| --- | --- |
| **Spec** | `widget-registry-public-flag` — markdown is sanitised at a public origin |
| **Runs in** | `site-security.spec.ts` |

- **GIVEN** a page whose markdown contains a `<script>` tag and a
  `javascript:` link
- **WHEN** it renders
- **THEN** no script executed and no `javascript:` href survives in the DOM
- **AND** the surrounding prose still rendered — a sanitiser that strips the
  whole document would otherwise pass

## S10 — A non-public widget degrades, it does not blank the page

| | |
| --- | --- |
| **Spec** | `widget-registry-public-flag` — a public host must render only public widgets, and must degrade |
| **Runs in** | `site-security.spec.ts` |

- **GIVEN** a grid page placing one widget key that is not public alongside two
  that are
- **WHEN** the page renders
- **THEN** a placeholder appears for the non-public one
- **AND** both public widgets render normally

## S11 — The renderer touches no Nextcloud global

| | |
| --- | --- |
| **Spec** | `public-manifest-runtime` — a manifest must render with no Nextcloud globals present |
| **Runs in** | `site-security.spec.ts` |

- **WHEN** the site page is loaded with `OC`, `OCA` and `OCP` deleted before
  the bundle runs
- **THEN** the site still renders its title, menu and page
- **NOTE** this is the closest a Nextcloud-hosted test can get to booting at a
  public origin. Without deleting the globals, "does not depend on Nextcloud"
  and "happens to work because Nextcloud is there" are the same observation.

## S12 — Visual comparison: the Vue renderer against the React portal

| | |
| --- | --- |
| **Spec** | `portal-shared-runtime` — parity must be measured, not asserted |
| **Runs in** | `visual/portal-comparison.spec.ts` |

- **GIVEN** both renderers served from the same instance
- **WHEN** each is captured at the same viewport
- **THEN** both screenshots are written for side-by-side review
- **NOTE** the two are NOT pixel-compared. They render different content models
  against different fixtures — the React portal shows subject-scoped
  collections from a bearer session, the Vue renderer shows public CMS pages.
  A pixel diff between them would be noise dressed as a metric. What is
  compared is stated in `docs/portal-parity.md`: what each renders, what each
  costs, and what the Vue one does that the React one cannot.

---

## S13 — No serious or critical accessibility violations

| | |
| --- | --- |
| **Spec** | ADR-010 / WCAG 2.2 AA; `portaliq-cms` |
| **Runs in** | `site-accessibility.spec.ts` |

- **GIVEN** a grid page, a markdown page and the not-found state
- **WHEN** axe-core runs against the renderer's root at WCAG 2.2 AA
- **THEN** no violation is serious or critical
- **NOTE** scoped to `[data-testid="site-root"]`, not the document: served
  inside Nextcloud here, and this suite must not report Nextcloud's markup as a
  Portaliq defect. The not-found state is included because error states are
  where accessibility is usually skipped and where a confused visitor needs it
  most.
- **CONTROL** a button with no accessible name was injected into the page and
  axe reported `button-name`. Without that, "axe found nothing" is
  indistinguishable from axe not running.

## S14 — Keyboard-only navigation

| | |
| --- | --- |
| **Spec** | WCAG 2.2 AA SC 2.1.1 |
| **Runs in** | `site-accessibility.spec.ts` |

- **GIVEN** the site at its root
- **WHEN** a visitor tabs to the skip link and then to a menu item and presses
  Enter
- **THEN** the skip link targets the renderer's main region, and the menu item
  navigates
- **NOTE** deliberately `keyboard.press('Enter')`, not `click()` — a control
  that only works with a mouse passes a click-based test and fails a real
  visitor. The skip link's *position* is not asserted: inside Nextcloud's
  chrome, Nextcloud's own skip link comes first, and asserting position would
  test the host page.

## S15 — Visible focus indicator

| | |
| --- | --- |
| **Spec** | WCAG 2.2 AA SC 2.4.7 / 2.4.11 |
| **Runs in** | `site-accessibility.spec.ts` |

- **WHEN** a menu link is focused
- **THEN** an outline or box-shadow renders
- **NOTE** `outline: none` with no replacement is the usual way a design review
  removes the only cue a keyboard user has.

## S16 — A phone viewport does not scroll horizontally

| | |
| --- | --- |
| **Spec** | WCAG 2.2 AA SC 1.4.10 (Reflow) |
| **Runs in** | `site-accessibility.spec.ts` |

- **GIVEN** a 360×740 viewport
- **THEN** the document does not scroll horizontally
- **AND** the two half-width grid widgets stack rather than squeeze — a
  6-column cell on a 360px screen is unreadable, so the renderer drops the grid
  rather than scaling it
- **NOTE** a municipal public site is majority mobile; this is the default
  case, not an edge one.

## S17 — Author content cannot break the layout

| | |
| --- | --- |
| **Spec** | `portaliq-cms` — a page body must be either a widget grid or markdown |
| **Runs in** | `site-accessibility.spec.ts` |

- **GIVEN** a markdown page containing a wide table, on a phone viewport
- **THEN** the table scrolls inside its own container and the document does not
- **NOTE** content an editor controls must not be able to break the page for
  every visitor.
