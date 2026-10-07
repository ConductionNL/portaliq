# portaliq-cms Specification

**Status**: partially implemented — see "What is implemented" below
**Scope**: portaliq
**OpenSpec changes**:

- [portal-cms-content-model](../../changes/portal-cms-content-model/)
- [portal-scoping-and-auth](../../changes/portal-scoping-and-auth/)
- [portal-headless-content-api](../../changes/portal-headless-content-api/)
- [portal-shared-runtime](../../changes/portal-shared-runtime/)
- [contribution-landing-page-action](../../changes/contribution-landing-page-action/)
- [portals-open-site-action](../../changes/archive/2026-09-10-portals-open-site-action/)
- [news-and-newsletter-authoring](../../changes/news-and-newsletter-authoring/)
- [events-and-signups](../../changes/events-and-signups/)
- [extracurricular-activity-offer](../../changes/extracurricular-activity-offer/)
- [activity-parental-consent](../../changes/activity-parental-consent/)

## Purpose

Portaliq is the fleet's headless CMS (ADR-086). Its public content API is the
contract; the built-in portal renderer (ADR-084) is one consumer of that API,
with no privileged path of its own. Content is scoped to a `portal`, which
owns its domains, theme, authentication and locales, so one Organisation can
run several branded portals.

> **Vocabulary.** The unit was called `website` in the first draft of this
> spec and in the register schema. It is `portal` everywhere now — schema id,
> property name, query parameter, service and test class. `website` in an
> older document means `portal`; there is no second concept.

This document is the CANONICAL spec for the implemented subset. Requirements
still being designed live in the change deltas listed above until they ship.

## What is implemented

Verified live on a disposable rig 2026-08-15 (`portaliq-p2-rig`, :8321), with
24 e2e tests and 21 unit tests. Every requirement below carries its evidence.
Rows added after that sweep name their own verification in the row itself —
the date, rig and test counts above are not retroactively theirs.

| Requirement | State |
| --- | --- |
| A request resolves to exactly one portal, or none | implemented |
| A custom domain is verified before it serves | **guard** implemented; the DNS TXT check that SETS the flag is not |
| All content is scoped to a portal | implemented |
| A page body is a grid or markdown | implemented |
| Unpublished content is indistinguishable from absent | implemented |
| Reads are cached, keyed by audience | implemented, with event-driven invalidation |
| Markdown does not execute at a public origin | implemented |
| Only explicitly public widgets render | implemented — via a local allow-list until nc-vue's registry carries `public` |
| The renderer does not depend on Nextcloud globals | implemented |
| The content API is sufficient without the built-in renderer | implemented — proven by a Docusaurus build |
| Editors have an admin surface for CMS content | implemented — declarative manifest pages; the publish-time validation rules are not |
| The Portals overview opens a portal's public site | implemented — verified 2026-09-10 on an isolated NC 34 rig with four seeded portals, plus 14 unit assertions and 2 e2e tests ([archived change](../../changes/archive/2026-09-10-portals-open-site-action/)) |

**Not implemented, and specified elsewhere rather than left implied:**

- Per-portal authentication is declared in the schema and ENFORCED NOWHERE.
  Every portal currently behaves as `public` read-only, which happens to match
  the specified fail-closed default. That is a coincidence, not an
  implementation. The renderer now offers the sign-in door where a portal
  declares one; nothing guards what is behind it.
- The admin surface creates and edits content, but enforces none of the
  publish-time rules `portal-cms-admin-ui` specifies: a route is not checked
  for uniqueness within its portal, a portal with no page at `/` can still be
  published, and there is no domain-verification trigger.

> Per-portal theming WAS on this list and is not any more — the token
> stylesheet is resolved server-side and consumed by the renderer, and two
> portals now compute different colours. See the theme requirement below.

**A measured consequence of the authentication gap, stated because the
contribution bridge otherwise reads as under-delivering:** twelve apps ship a
`PortalContributionProvider` and all twelve conform to ADR-046 — conventional
FQCN, `getContribution()`, and eleven of twelve correctly do NOT implement
Portaliq's interface (the twelfth is Portaliq's own). But surveyed on
2026-08-15, **only Portaliq's own provider publishes an ANONYMOUS surface**;
every leaf app publishes exclusively to `citizen`, `client`, `supplier` or
`employee`, all authenticated audiences.

So the public contributions endpoint correctly returns one contribution today.
That is the contract working, not the bridge failing — and a visitor will see
the leaf apps' services only once authentication is enforced.

Related: ADR-086 (headless CMS), ADR-084 (the built-in renderer), ADR-022 /
ADR-070 (OR-backed persistence), ADR-005 (fail-closed), ADR-082 (throttling).

## Requirements

### Requirement: A request MUST resolve to exactly one portal, or to none

The serving `portal` SHALL be resolved from an explicit portal slug, or from
the request host taken from the trusted proxy configuration. An unresolved host
SHALL produce a not-found response. There SHALL be no default, first or
fallback portal.

#### Scenario: An unknown host reveals nothing

- **GIVEN** a request whose host matches no portal
- **THEN** the response is 404
- **AND** no portal's title, theme or slug appears in the body
- @e2e `tests/e2e/site-multisite.spec.ts` (S7)

#### Scenario: A single configured portal is still not a default

- **GIVEN** exactly one portal exists
- **WHEN** a request arrives for a host it does not claim
- **THEN** nothing resolves
- @e2e exclude unit-tested — `tests/Unit/Service/PortalResolverTest.php::testASingleConfiguredSiteIsStillNotADefault`; the single-portal case is where a "just use the only one" shortcut is most tempting and would go unnoticed until a second portal existed

#### Scenario: A named portal that does not exist does not fall through to the host

- **GIVEN** an explicit portal slug that matches no portal
- **THEN** nothing resolves, and the request is not served by whichever portal
  owns the hostname it arrived on
- @e2e `tests/e2e/site-multisite.spec.ts` (S7b)

### Requirement: A custom domain MUST be verified before it serves

A domain SHALL serve only when its `verified` flag is true. An unverified
domain SHALL behave exactly as an unknown host.

#### Scenario: An unverified domain does not serve

- **GIVEN** a domain bound to a portal with `verified: false`
- **THEN** requests for it return 404
- @e2e `tests/e2e/site-multisite.spec.ts` (S6)

#### Scenario: A verified domain does serve

- **GIVEN** a verified domain on the SAME portal
- **THEN** requests for it are served
- **AND** this positive case runs alongside the refusal, because a verifier
  that refuses everything is indistinguishable from a working one when only
  the refusal is tested
- @e2e `tests/e2e/site-multisite.spec.ts` (S6)

### Requirement: All content MUST be scoped to a portal

Every menu, page and glossary term SHALL belong to exactly one portal, and
every read SHALL be filtered by it. An unscoped read SHALL return nothing
rather than everything.

#### Scenario: Two portals publishing the same route do not leak

- **GIVEN** two portals each publishing `/over-ons`
- **THEN** each host returns its own page and neither is reachable from the
  other
- @e2e `tests/e2e/site-multisite.spec.ts` (S5, S5b)

#### Scenario: An unscoped read returns nothing

- **GIVEN** a content read with no portal
- **THEN** it returns empty rather than every portal's content
- @e2e exclude unit-tested — `tests/Unit/Service/CmsReaderTest.php::testAnUnscopedReadReturnsNothing`; the failure mode is a cross-tenant leak that renders normally, so it is asserted at the seam rather than through the UI

### Requirement: A page body MUST be either a widget grid or markdown

`page.body.type` SHALL be `grid` or `markdown`. A markdown body SHALL be
stored and served as SOURCE. A grid body SHALL carry canonical manifest-v2
widget placements.

#### Scenario: Markdown is served as source

- **GIVEN** a markdown page containing a code fence and a table
- **WHEN** it is read through the API
- **THEN** the markdown source is returned, with no HTML introduced
- @e2e `tests/e2e/site-content.spec.ts` (S2)

#### Scenario: A grid page renders on the shared 12-column geometry

- **GIVEN** a grid page with two half-width widgets on one row
- **WHEN** it renders
- **THEN** they occupy the same row and different columns
- @e2e `tests/e2e/site-content.spec.ts` (S1)

### Requirement: Unpublished content MUST be indistinguishable from absent content

A draft page SHALL NOT be served, and its response SHALL be byte-identical to
that for a route that never existed.

#### Scenario: A draft and an unknown route answer identically

- **GIVEN** a draft page and a non-existent route
- **THEN** both return 404 with identical bodies
- **AND** a published route returns 200 — the control that distinguishes a
  working filter from one that hides everything
- @e2e `tests/e2e/site-security.spec.ts` (S4)

### Requirement: Public content reads MUST be cached, keyed by audience

Content responses SHALL be cached by portal, kind, selector, locale AND
audience. Per-visitor responses SHALL be marked `private, no-store`; anonymous
published content SHALL be publicly cacheable. Cached entries SHALL be
invalidated on a content write, not by expiry alone.

#### Scenario: Anonymous and authenticated responses are marked differently

- **GIVEN** the same endpoint requested with and without an `Authorization`
  header
- **THEN** the anonymous response is publicly cacheable and the authenticated
  one is `private, no-store`
- @e2e `tests/e2e/site-security.spec.ts` (S8)

#### Scenario: The audience component is load-bearing

- **GIVEN** the audience removed from the cache key
- **THEN** the key test fails
- @e2e exclude unit-tested — `tests/Unit/Service/CmsReaderTest.php::testAudienceIsPartOfTheCacheKey`, observed failing with the component removed; a header assertion cannot show that two responses occupy different cache slots

#### Scenario: Creating a page clears the cached miss for its route

- **GIVEN** a route that has been requested and 404ed
- **WHEN** a page is created at that route
- **THEN** the next request serves it, without waiting for a TTL
- @e2e `tests/e2e/site-security.spec.ts` (S8b)

### Requirement: Markdown MUST NOT execute at a public origin

Markdown rendered by the portal renderer SHALL be sanitised. No script,
`javascript:` href or event-handler attribute SHALL survive into the DOM.

#### Scenario: Hostile markdown is neutralised and the prose survives

- **GIVEN** a page whose markdown carries a script tag, a `javascript:` link
  and an `onerror` attribute
- **THEN** none executes and none remains in the DOM
- **AND** the surrounding prose still renders — the control that distinguishes
  sanitising from discarding
- @e2e `tests/e2e/site-security.spec.ts` (S9)

### Requirement: Only explicitly public widgets MUST render at a public origin

The portal renderer SHALL mount only widget keys that are explicitly public.
Anything else SHALL render an inert placeholder without preventing the rest of
the page from rendering.

#### Scenario: A non-public widget degrades and the page survives

- **GIVEN** a grid page with one non-public widget among two public ones
- **THEN** the non-public one renders a placeholder and the others render
  normally
- @e2e `tests/e2e/site-security.spec.ts` (S10)

### Requirement: The portal renderer MUST NOT depend on Nextcloud globals

The renderer SHALL boot and render with `OC`, `OCA` and `OCP` absent, reading
its configuration from the initial-state channel when present and from a
runtime global otherwise.

#### Scenario: The portal renders with the globals deleted

- **GIVEN** the Nextcloud globals removed before the bundle runs
- **THEN** the title, menu and page still render
- @e2e `tests/e2e/site-security.spec.ts` (S11)

### Requirement: A contribution MUST be scoped to the portal it targets

A leaf app's contribution SHALL appear on a portal it targets and SHALL NOT
appear on one it does not. A contribution that declares no target SHALL appear
on every portal — the ADR-046 contract is unchanged, and a contribution is a
capability descriptor rather than tenant data. A malformed target SHALL fail
closed. The public endpoint SHALL serve only the ANONYMOUS aggregate.

#### Scenario: A declared target includes and excludes in one comparison

- **GIVEN** a contribution naming one portal
- **THEN** that portal receives it and another does not
- **AND** both are asserted from one input, because a filter that kept nothing
  would satisfy the exclusion alone
- @e2e exclude unit-tested — `tests/Unit/Contribution/PortalContributionFilterTest.php`; the rig has no multi-portal contribution fixture, and the failure mode is a cross-tenant surface that renders normally, so it is asserted at the seam and mutation-tested (both fail-closed branches observed breaking the suite when flipped)

#### Scenario: An untargeted contribution still reaches every portal

- **GIVEN** a provider written before portal targeting existed
- **THEN** it appears unchanged on every portal
- @e2e exclude unit-tested — `tests/Unit/Contribution/PortalContributionFilterTest.php::testAContributionWithNoTargetAppearsOnEveryPortal`

#### Scenario: The subject-scoped aggregate is never served publicly

- **GIVEN** the public contributions endpoint
- **THEN** it consults only the anonymous aggregate, and its response is
  publicly cacheable — a per-visitor aggregate in a shared cache slot is a
  leak that happens at the edge
- @e2e `tests/e2e/site-content.spec.ts` (S19)

### Requirement: A portal MUST offer only the sign-in routes it declares

The renderer SHALL derive its sign-in affordances from the portal's declared
`authentication.modes`, read from the public content API. A portal declaring
only `public` SHALL offer NO sign-in affordance. An unknown or malformed mode
SHALL produce none.

This requirement covers the DOOR, not a guard. Per-portal authentication is
still enforced nowhere — see "Not implemented" above — and nothing in the
renderer's auth surface may be read as gating content.

#### Scenario: A public-only portal offers no way to sign in

- **GIVEN** a portal declaring `modes: ['public']`
- **THEN** no sign-in affordance renders
- **AND** a portal declaring `digid` alongside it DOES offer one — the pair is
  asserted together, because a derivation that returned nothing at all would
  satisfy the first half by itself
- @e2e exclude unit-tested — `tests/site-auth.spec.mjs`; ten assertions over
  the mode derivation, including the mixed list, an unknown mode and a
  malformed value. The rig's portals are all `public`, so a browser test could
  only ever observe the absence

#### Scenario: A signed-out visitor still gets the portal

- **GIVEN** an auth edge that cannot be reached
- **THEN** the page still renders its public content, signed out
- @e2e `tests/e2e/site-content.spec.ts` (S21)

### Requirement: A portal's theme MUST change what a visitor sees

The serving portal's `theme` SHALL resolve to a themiq token stylesheet that is
loaded before the renderer boots. Two portals referencing different themes
SHALL compute different styles. A theme that does not resolve SHALL render
UNSTYLED rather than in another portal's brand.

#### Scenario: Two portals compute different styles

- **GIVEN** two portals referencing different themiq themes
- **WHEN** each is rendered
- **THEN** their COMPUTED heading colours differ — asserted on the computed
  value, never on the theme class name, because a class with no tokens behind
  it is exactly the state this requirement replaced
- @e2e `tests/e2e/site-multisite.spec.ts` (S20)

#### Scenario: An unresolvable theme renders unstyled, not misbranded

- **GIVEN** a portal whose theme names no shipped stylesheet
- **THEN** no token stylesheet is emitted and the page renders with its own
  defaults
- @e2e exclude unit-tested — `tests/Unit/Service/PortalThemeResolverTest.php`; a portal quietly wearing another municipality's colours renders perfectly and is invisible to a screenshot, so the refusal is asserted where the decision is made

### Requirement: The content API MUST be sufficient without the built-in renderer

Every capability of the built-in renderer SHALL be reachable through the public
content API, and a consumer that is not the renderer SHALL be able to
reproduce a portal from it alone.

#### Scenario: A Docusaurus build reproduces a portal from the API alone

- **GIVEN** a portal with a menu, markdown pages and glossary terms
- **WHEN** the Docusaurus plugin builds against the public API
- **THEN** the portal is reproduced, and every request it made was a public
  content endpoint
- @e2e exclude proven by a separate consumer project rather than a browser
  test — `docusaurus-portaliq-proof` intercepts its own outbound calls and
  fails the run if any is not a `/api/content/` endpoint, which is the check a
  Playwright spec cannot make from inside the renderer

### Requirement: A page may carry a hero image reference

The `page` schema MUST carry an optional `heroImage` property — a string
reference/URL to an image, rendered by a `hero`-keyed widget when present.
This is additive: a page with no `heroImage` behaves exactly as before this
change.

#### Scenario: A page created with a hero image renders it

- GIVEN a `page` object whose `heroImage` is set
- WHEN the page is served
- THEN the hero widget's rendered props include the image reference
- @e2e exclude schema/render-plumbing contract — no distinct portaliq UI flow ships changed hero rendering in this change; covered by `LandingPageProvisioningServiceTest` asserting the field is written through unchanged

### Requirement: A public page may embed a lead-capture form widget

The public site renderer MUST support a `form`-keyed widget
(`src/site/components/FormBlock.vue`, registered locally in
`WidgetGrid.vue`'s `PUBLIC_WIDGETS` map — no change to the shared
`@conduction/nextcloud-vue` library) that renders the fields, `submitLabel`
and `consentText` of a bound `form` object and submits through the existing
anonymous contribution-create endpoint. An unrecognised `widgetKey` (an older
deployed bundle, before this change ships) MUST continue to degrade to the
existing inert placeholder — this addition changes nothing about that
fallback.

#### Scenario: A landing page renders its bound form and accepts a submission

- GIVEN a `page` whose grid body includes a `form`-keyed widget bound to an active `form` object's id
- WHEN a visitor loads the page
- THEN the form's declared fields, `submitLabel`, and `consentText` render
- AND submitting the form with valid values succeeds without a portal session
- @e2e site-form-submission.spec.ts — "a visitor submits the landing page form and it is recorded"

### Requirement: The Portals overview MUST open a portal's public site

The Portals index page in the Portaliq admin SHALL offer, per portal row, an
action that opens that portal's public site in a new browser tab. The action
SHALL address the site by the row's `slug` through the app's own site route
(`portalPage#site`) resolved with Nextcloud's URL generator, so the link holds
on instances with and without URL rewriting, and the slug SHALL be
percent-encoded and sent exactly as stored, because portal resolution compares
it verbatim. When the row carries no usable slug, the action SHALL inform the
administrator rather than report a success nobody can see.

The action SHALL NOT read `window.open`'s return value as a success signal.
The tab is opened with `noopener`, which severs the WindowProxy, so the HTML
standard returns null for the tab it DID open ("If noopener is true, then
return null"). A refused tab is therefore indistinguishable from an opened one
at this call site and is left to the browser's own blocked-popup indicator;
severing the opener is the property worth keeping, since the opened document
renders portal-authored content.

The action's LABEL is deliberately English on both locales: the shared
row-action component renders `action.label` verbatim and injects no
translator, and the library's own built-in entries (view, edit, copy, delete)
are English for the same reason. The Dutch strings ship anyway, so the label
becomes live the day the library translates them; every message the action
itself shows does go through the app's translator.

#### Scenario: An administrator opens a published portal from the overview

- GIVEN an administrator on the Portals overview with a published portal whose slug is known
- WHEN they choose "Open portal" in that row's action menu
- THEN a new tab opens on the app's site route carrying that row's slug as the `portal` parameter
- AND the site renders that portal's own title
- @e2e portals-open-site.spec.ts — "the row action opens the portal site in a new tab"

#### Scenario: A slug that needs escaping stays intact

- GIVEN a portal row whose slug contains a character that is unsafe in a query string
- WHEN the action builds the site URL
- THEN the slug is percent-encoded in the `portal` parameter, so the site resolves the portal the row names and no other
- AND the slug is sent exactly as stored, without trimming
- @e2e exclude asserted in tests/open-portal-site.spec.mjs; no seeded portal carries an escaping-relevant slug, and tagging a browser test with this scenario would certify a branch that test cannot fail on

#### Scenario: A portal without a slug reports instead of linking

- GIVEN a portal row whose `slug` is empty, absent, or only whitespace
- WHEN the administrator chooses "Open portal"
- THEN no tab is opened
- AND the administrator is told the portal has no slug yet
- @e2e exclude asserted in tests/open-portal-site.spec.mjs; the CMS seed provisions no slugless portal, and a browser test that opens no tab and reads a toast adds nothing the unit assertions do not already pin

#### Scenario: A successful open is never reported as a failure

- GIVEN a browser that severs the opener reference, so `window.open` returns null for the tab it did open
- WHEN the administrator chooses "Open portal" on a row with a usable slug
- THEN the tab opens with `noopener,noreferrer` and the action reports the address it opened
- AND no failure message is shown
- @e2e portals-open-site.spec.ts — "the row action opens the portal site in a new tab"

#### Scenario: The built-in row actions survive the addition

- GIVEN the Portals overview with the action installed
- WHEN an administrator opens a row's action menu
- THEN "Open portal" is offered alongside the built-in view, edit, copy and delete entries rather than in place of them
- @e2e portals-open-site.spec.ts — "the pre-existing row actions still work alongside it"

### Requirement: A portal without a published home page MUST be reported as a configuration error

A portal's root route `/` is a CMS page slot like any other, and an absent page
there answers not found. That answer is correct and SHALL NOT change: the
router SHALL NOT redirect a signed-in visitor from the root to the signed-in
area, because the root is content the editor owns.

What SHALL change is that a portal cannot reach that state unnoticed. The
Portaliq admin SHALL report, on the page where an administrator configures one
portal, whether that portal has a home page. A home page is a page of this
portal whose `route` is exactly `/` and whose `status` is `published`. Any
other state is a configuration error:

- no page at `/` at all, so the root has no content to serve;
- a page at `/` that is still a draft, so the root serves nothing until it is
  published.

The report SHALL name the portal, SHALL say which of the two states applies,
and SHALL say what a home page is in the terms an editor acts on: the route
`/` and the status `published`. It SHALL NOT block saving a portal, creating
one, or publishing it. A portal is routinely configured before its pages
exist, so a refusal would make the normal order of work impossible.

The read SHALL be admin-only. It answers whether a draft page exists at a
route, which is exactly the existence oracle the public content API withholds,
so it SHALL NOT be reachable without an administrator's session.

#### Scenario: A portal with no page at its root reports the error

- **GIVEN** an administrator on a portal's page, and that portal has no page whose route is `/`
- **WHEN** the page loads
- **THEN** the portal is reported as having no home page
- **AND** the report names the portal and says a home page is a published page at the route `/`
- **AND** nothing about the portal is blocked or changed
- @e2e exclude asserted in tests/portal-home-page.spec.mjs and PortalHomePageTest; both URLs of a route-less portal serve the byte-identical SPA shell, so a browser assertion on the site adds nothing, and the admin widget's own states are read from the controller this suite pins

#### Scenario: A draft at the root is reported as a draft, not as absent

- **GIVEN** a portal whose only page at `/` has status `draft`
- **WHEN** the administrator opens that portal's page
- **THEN** the report says the home page is still a draft
- **AND** it names the draft page, so the administrator can open and publish it
- @e2e exclude asserted in PortalHomePageTest::testADraftAtTheRootIsADraftNotAnAbsence; the distinction is a classification over stored rows and a browser cannot see which of the two states produced a 404

#### Scenario: A published page at the root clears the error

- **GIVEN** a portal with a published page whose route is `/`
- **WHEN** the administrator opens that portal's page
- **THEN** no configuration error is reported
- **AND** the report confirms the portal has a home page
- @e2e exclude asserted in tests/portal-home-page.spec.mjs; the cleared state is the absence of a finding, which the unit assertions pin against all three classifications at once

#### Scenario: The report is reached by the page an administrator already opens

- **GIVEN** the Portal page in the Portaliq admin manifest
- **WHEN** its widgets and layout are read
- **THEN** the home-page report is one of them, placed above the portal's own fields
- **AND** its type resolves in the component registry, and the address it reads matches the route the app declares
- @e2e exclude asserted in tests/portal-home-page.spec.mjs, which compares the widget's address against appinfo/routes.php; a widget registered but never placed is the defect this scenario exists to catch, and it is visible in the manifest, not in a browser

### Requirement: An activity MUST be able to require a guardian's consent, recorded on the sign-up

`activityOffer` SHALL carry `consentRequired` (boolean) and `consentStatement`
(the text a guardian agrees to). Staff SHALL NOT be able to open an activity
whose `consentRequired` is true and whose `consentStatement` is empty (422
`no_consent_statement`). When `consentRequired` is true, a sign-up SHALL carry
`acceptedStatement` equal to the current `consentStatement`; a missing or
different text SHALL be refused with 422 `consent_required` before anything is
written. An accepted sign-up SHALL store `consent: {statement, grantedByRef,
grantedAt}` with the statement as agreed, the signing guardian and the time.
When `consentRequired` is false, no consent record SHALL be written.

#### Scenario: A sign-up without the consent text is refused
@e2e exclude {an API refusal with no screen in this change; asserted in tests/Unit/Service/ActivitySignupServiceTest.php::testConsentIsRequiredAndRecorded}

- **GIVEN** an open activity with `consentRequired: true` and a consent text
- **WHEN** a guardian signs up their child without `acceptedStatement`, or with an older text
- **THEN** the answer SHALL be 422 `consent_required` and no sign-up SHALL exist

#### Scenario: The agreed text is kept on the sign-up
@e2e exclude {a stored-record assertion; asserted in tests/Unit/Service/ActivitySignupServiceTest.php::testConsentIsRequiredAndRecorded}

- **GIVEN** that activity
- **WHEN** the guardian sends back the exact consent text
- **THEN** the sign-up SHALL store the text, the guardian and the time of agreement

#### Scenario: An activity needing consent does not open without a text
@e2e exclude {staff API refusal; asserted in tests/Unit/Controller/ActivityControllerTest.php::testOpenRefusesConsentWithoutAStatement}

- **GIVEN** a draft with `consentRequired: true` and no `consentStatement`
- **WHEN** staff open it
- **THEN** the answer SHALL be 422 `no_consent_statement`

### Requirement: Where photos are taken, the roster MUST show each child's photo consent

`activityOffer` SHALL carry `photosTaken` (boolean). When it is true, every
entry in the staff roster and in the guardian's own `mySignups` SHALL carry
`photoConsent`, read through `GuardianAudienceFixtureReader::photoConsentGranted()`
for the signing guardian and child (purpose `news`, the channel school photos
are shared through). An absent consent entry SHALL read as false. When
`photosTaken` is false, `photoConsent` SHALL be absent.

#### Scenario: The supervisor sees who may not be photographed
@e2e exclude {needs guardian consent fixtures on a live instance; asserted in tests/Unit/Service/ActivityRosterTest.php::testTheRosterShowsPhotoConsentWherePhotosAreTaken}

- **GIVEN** an activity with `photosTaken: true` and two confirmed children, one with photo consent on file and one without
- **WHEN** staff read the roster
- **THEN** the first SHALL carry `photoConsent: true` and the second `photoConsent: false`

### Requirement: An event is authored per school, group or child, with guardian RSVP

An `event` OpenRegister schema (register `portaliq`) MUST carry `title`,
`description`, `start`/`end` (date-time), the same `target` shape as
`newsItem` (`schoolRef?`/`groupRefs[]?`/`childRefs[]?`, at least one
present), `status` (`draft`|`published`), `rsvpEnabled` (bool), and an
optional `signupRoles[]` (`{id, label, capacity}`). A guardian MUST be able
to RSVP (`yes`|`no`|`maybe`) for one of their own children to a published,
in-audience event with `rsvpEnabled: true`; a second RSVP by the same
guardian for the same child on the same event MUST update the existing
response rather than create a second one (upsert, not append).

#### Scenario: A group-targeted event reaches only that group's guardians

- GIVEN a published `event` targeting one group with `rsvpEnabled: true`
- WHEN a guardian whose audience includes that group reads the feed
- THEN the event appears
- AND a guardian outside that group, school and every targeted child never
  sees it
- @e2e exclude backend audience-scoping contract — identical matcher to
  `news-and-newsletter-authoring`'s `NewsAudienceMatcher`; covered by
  PHPUnit, no distinct portaliq UI ships a group picker in this change

#### Scenario: A second RSVP updates, it does not duplicate

- GIVEN a guardian who already RSVP'd `maybe` for their child on an event
- WHEN they RSVP `yes` for the same child on the same event
- THEN exactly one RSVP record exists for that guardian+child+event, now
  reading `yes`
- @e2e exclude idempotency invariant — pinned by
  `EventRsvpServiceTest::testASecondRsvpUpdatesRatherThanDuplicates`; no UI
  surface distinguishes a first RSVP from a change of mind

### Requirement: A sign-up role enforces its capacity server-side

A `signupRoles` entry (`{id, label, capacity}`) accepts guardian sign-ups
(activiteitenplanner/ouderhulp — findings 9.8) up to its `capacity`. A
sign-up attempt against a role already at capacity MUST be refused with a
machine-readable reason and MUST NOT be recorded — the check happens
server-side before any write, not only as a disabled button in the UI (the
gap several corpus competitors leave implicit, e.g. Kwieb's "participation
limits").

#### Scenario: A sign-up is accepted while capacity remains

- GIVEN a role with `capacity: 2` and zero current sign-ups
- WHEN a guardian signs up
- THEN the sign-up is recorded and the role shows 1 of 2 taken
- @e2e exclude backend capacity contract — covered by PHPUnit; no distinct
  UI ships a live capacity counter in this change

#### Scenario: A sign-up is refused once the role is full

- GIVEN a role with `capacity: 1` and one existing sign-up
- WHEN a second guardian attempts to sign up for the same role
- THEN the attempt is refused before any write, with a reason naming the
  role is full
- @e2e exclude fail-closed capacity invariant — pinned by
  `EventSignupServiceTest::testASignupIsRefusedOnceTheRoleIsFull`, asserting
  the write is never attempted; no UI surface

### Requirement: A term-long activity MUST be offered with places set by capacity and supervision

An `activityOffer` schema (register `portaliq`) SHALL carry `title`, `kind`
(`club`, `sport`, `culture`, `trip`, `course`, `other`), the `target` shape
`schoolEvent` uses, `termStart`, optional `termEnd` and `signupDeadline`,
`capacity` (at least 1), `waitlistEnabled`, `sessions[]` (`{id, start, end?,
location?}`), `supervisorRefs[]`, `childrenPerSupervisor`, `paymentRequested`
and `status` (`draft`, `open`, `closed`). It SHALL NOT carry an amount; a place
that costs money SHALL be paid through a shillinq payment request (D19). The
places of an activity SHALL be the lower of `capacity` and the number of
distinct supervisors times `childrenPerSupervisor` (capacity alone when
`childrenPerSupervisor` is 0 or absent). Staff SHALL NOT be able to open an
activity that has no place.

#### Scenario: Supervision caps the places
@e2e exclude {a derived number on the server; asserted in tests/Unit/Service/ActivitySignupServiceTest.php::testPlacesAreTheLowerOfCapacityAndSupervision}

- **GIVEN** an activity with `capacity: 20`, two supervisors and `childrenPerSupervisor: 8`
- **WHEN** its places are computed
- **THEN** the activity SHALL have 16 places

#### Scenario: An activity without enough supervision does not open
@e2e exclude {staff API refusal with no screen in this change; asserted in tests/Unit/Controller/ActivityControllerTest.php::testOpenRefusesAnActivityWithNoPlace}

- **GIVEN** a draft activity with `childrenPerSupervisor: 8` and no supervisors
- **WHEN** staff open it
- **THEN** the answer SHALL be 422 `no_places` and the activity SHALL stay a draft

### Requirement: A guardian MUST be able to sign up one of their own children, with a waiting list when full

A guardian SHALL see every non-draft activity in their audience, each with the
places left and their own children's sign-ups (never another guardian's). A
guardian SHALL be able to sign up one of their own children for an open
activity before its `signupDeadline`. The child SHALL get a confirmed place
while places remain, a waiting-list position when the activity is full and
`waitlistEnabled` is true, and a 422 `activity_full` otherwise. A child with a
sign-up that is not withdrawn SHALL NOT be signed up twice (409). An activity
outside the guardian's audience and a child who is not the guardian's own
SHALL both answer the same 404, with nothing written. A closed activity or a
passed deadline SHALL answer 422 `signup_closed`.

#### Scenario: The last place, then the waiting list
@e2e exclude {needs two guardian sessions against a live portal; asserted in tests/Unit/Service/ActivitySignupServiceTest.php::testAFullActivityWaitlistsInOrder}

- **GIVEN** an open activity with one place left and `waitlistEnabled: true`
- **WHEN** two guardians each sign up a child, one after the other
- **THEN** the first child SHALL be confirmed
- **AND** the second SHALL be waitlisted at position 1

#### Scenario: Another guardian's child is refused like an unknown activity
@e2e exclude {an absence of access, observable only at the seam; asserted in tests/Unit/Service/ActivitySignupServiceTest.php::testAChildWhoIsNotTheGuardiansOwnIsRefused}

- **GIVEN** an open activity in the guardian's audience
- **WHEN** the guardian signs up a child who is not in their own children
- **THEN** the refusal SHALL be the same as for an activity that does not exist
- **AND** no sign-up SHALL be written

### Requirement: A freed place MUST go to the child who waited longest

When a confirmed sign-up is withdrawn, or staff add supervisors so the places
grow, the service SHALL confirm waitlisted children in the order they signed
up until the places are full. Withdrawing a waitlisted sign-up SHALL promote
nobody. A guardian SHALL be able to withdraw only a sign-up of one of their own
children.

#### Scenario: A withdrawal promotes the first child on the list
@e2e exclude {an ordering rule on stored rows; asserted in tests/Unit/Service/ActivitySignupServiceTest.php::testAWithdrawalPromotesTheLongestWaitingChild}

- **GIVEN** a full activity with two waitlisted children, the older sign-up first
- **WHEN** a guardian withdraws a confirmed child
- **THEN** the older waitlisted child SHALL be confirmed and the other SHALL stay waitlisted

#### Scenario: More supervisors make more places
@e2e exclude {staff API with no screen in this change; asserted in tests/Unit/Service/ActivitySignupServiceTest.php::testMoreSupervisorsPromoteFromTheWaitingList}

- **GIVEN** a full activity with one supervisor, `childrenPerSupervisor: 2` and three waitlisted children
- **WHEN** staff set two supervisors
- **THEN** two waitlisted children SHALL be confirmed in sign-up order

### Requirement: Staff MUST be able to mark attendance per session

Staff SHALL be able to mark a child `present`, `absent` or `excused` for one of
the activity's declared sessions, only for a child with a confirmed place. A
second mark for the same session and child SHALL update the first. An unknown
session, a child without a confirmed place and an unknown status SHALL each be
refused with 422 and nothing written.

#### Scenario: A second mark corrects the first
@e2e exclude {an upsert invariant on stored rows; asserted in tests/Unit/Service/ActivityAttendanceServiceTest.php::testASecondMarkUpdatesTheFirst}

- **GIVEN** a child marked `absent` for session `week-3`
- **WHEN** staff mark the same child `present` for `week-3`
- **THEN** exactly one attendance row SHALL exist for that child and session, reading `present`

#### Scenario: A waitlisted child cannot be marked present
@e2e exclude {a refusal on the staff API; asserted in tests/Unit/Service/ActivityAttendanceServiceTest.php::testOnlyAConfirmedChildInADeclaredSessionIsMarked}

- **GIVEN** a waitlisted child
- **WHEN** staff mark that child present
- **THEN** the answer SHALL be 422 `not_confirmed` and nothing SHALL be written

### Requirement: Staff MUST be able to raise the contribution for an activity's confirmed places, and portaliq MUST write the reference

`POST /apps/portaliq/api/activities/{id}/contributions` SHALL need a Nextcloud
session (403 without one). It SHALL answer 404 for an unknown activity and 422
`payment_not_requested` when the activity's `paymentRequested` is not true. The
body SHALL carry `amount` (above zero), `voluntary` (boolean) and
`administrationId`, and MAY carry `description` (default: the activity title),
`invoiceDate`, `dueDate`, `revenueAccount` and `language`; a body without the
three SHALL answer 400 `invalid_charge` with no call to shillinq. Portaliq
SHALL raise through shillinq's `ContributionRaiseService::raise()` one recipient
per `confirmed` sign-up of the activity that has no `paymentRequestRef`, in
chunks of at most 200, with the chargeable `{app: portaliq, type:
activity-offer, register: portaliq, schema: activityOffer, id: <activity id>}`,
`kind: activity`, the debtor `{portalSubjectRef: guardianRef, name, email}` read
from the guardian's `portalAccount`, and the beneficiary `{type: learner, id:
childRef}`. A sign-up whose guardian account has no name or email SHALL be
reported `failed` with `no_contact_details` and SHALL NOT be sent. For every
result that is `raised` or `skipped` with a `paymentRequestId`, portaliq SHALL
write that id into the sign-up's `paymentRequestRef`. Portaliq SHALL NOT store
the amount. Without shillinq the answer SHALL be 503 `shillinq_unavailable`;
shillinq's refusal of the staff member SHALL be 403 `forbidden`; shillinq's
refusal of the charge SHALL be 400 `invalid_charge`; any other failure SHALL be
502 `raise_failed`.

#### Scenario: Staff raise the club fee and each place gets its reference
@e2e exclude {needs shillinq installed with a payment action for the staff member; asserted in tests/Unit/Service/ActivityContributionServiceTest.php::testConfirmedPlacesAreRaisedAndReferenced}

- **GIVEN** an activity with `paymentRequested: true`, two confirmed places, one waitlisted place and one confirmed place that already has a reference
- **WHEN** staff raise it with `amount: 25`, `voluntary: true` and `administrationId: adm-school-1`
- **THEN** shillinq SHALL receive two recipients, each with the activity as chargeable, the child as beneficiary and the guardian as debtor
- **AND** both sign-ups SHALL carry the returned `paymentRequestId` in `paymentRequestRef`

#### Scenario: A repeated raise bills nobody twice
@e2e exclude {shillinq's idempotency answer; asserted in tests/Unit/Service/ActivityContributionServiceTest.php::testASkippedResultStillWritesTheStandingReference}

- **GIVEN** a place shillinq already billed, whose reference write failed last time
- **WHEN** staff raise again
- **THEN** shillinq SHALL answer `skipped` with the standing request, and portaliq SHALL write that id into the sign-up

#### Scenario: A guardian without contact details is reported, the rest are raised
@e2e exclude {a data-quality branch; asserted in tests/Unit/Service/ActivityContributionServiceTest.php::testAGuardianWithoutContactDetailsIsReportedAndNotSent}

- **GIVEN** two confirmed places, one guardian account without an email
- **WHEN** staff raise
- **THEN** only the other place SHALL be sent, and the answer SHALL report the first as `failed` with `no_contact_details`

#### Scenario: No contribution is asked, or shillinq is missing
@e2e exclude {refusals before any call; asserted in tests/Unit/Controller/ActivityControllerTest.php::testContributionsMapsEachRefusal}

- **GIVEN** an activity with `paymentRequested: false`, or an instance without shillinq
- **WHEN** staff raise
- **THEN** the answer SHALL be 422 `payment_not_requested`, or 503 `shillinq_unavailable`, and no sign-up SHALL change

### Requirement: Newsletter and emergency recipients come from the school app

When the newsletter preflight, the newsletter send check or the emergency push resolve which guardians a target reaches, the result MUST include every active portal account of the `parent` audience whose audience, read from the school app through `LeafGuardianAudienceReader`, matches the target by the same rule the news feed uses. Guardians with an interim `guardianAudienceFixture` row MUST still be matched on that row, and MUST NOT be resolved a second time through the school app. Pending and void accounts MUST NOT be counted.

#### Scenario: The preflight counts a real guardian of the group
- GIVEN learniq declares `guardianAudience` and a guardian with an active portal account has a child in group 7
- AND no fixture row exists for that guardian
- WHEN staff runs the preflight for a newsletter targeted at group 7
- THEN the recipient count includes that guardian
- @e2e exclude backend enumeration contract, pinned by `GuardianAudienceFixtureReaderTest::testGuardiansMatchingAddsTheGuardiansTheSchoolAppResolves`; no staff screen shows the preflight in this change

#### Scenario: An emergency push reaches a real guardian of the school
- GIVEN the same guardian
- WHEN staff sends an emergency push targeted at the child's school
- THEN the push is delivered to that guardian and counted in `recipientCount`
- @e2e exclude the push controller only forwards `guardiansMatching()`, pinned by `EmergencyPushControllerTest`; the enumeration by `GuardianAudienceFixtureReaderTest`

#### Scenario: A fixture guardian is matched on the fixture only
- GIVEN a guardian with a fixture row in group 5 whose school app audience says group 7
- WHEN staff targets group 7
- THEN that guardian is not counted
- @e2e exclude precedence rule, pinned by `GuardianAudienceFixtureReaderTest::testAFixtureRowIsNeverResolvedAgainThroughTheSchoolApp`

### Requirement: A guardian's news audience comes from the school app

A contribution serving the `parent` audience MAY declare `guardianAudience` with `children` (a collection id whose rows are the guardian's children), `schoolField` (the child field naming the school) and `groups` (`{collection, field}` naming the children's groups). When the interim fixture holds no row for a guardian, the guardian's audience MUST be read from those collections through the subject-scoped collection reader, with the guardian's own scope claim and via join. A fixture row MUST still take precedence.

#### Scenario: A school-wide news item reaches a guardian of the school
- GIVEN learniq declares `guardianAudience` and a guardian's child belongs to school S
- AND a teacher publishes a news item targeted at school S
- WHEN the guardian opens the news page in the portal
- THEN the item is listed
- @e2e learniq `tests/e2e/po-parent-flows.spec.ts`

#### Scenario: A guardian of another school does not see it
- GIVEN a guardian whose children belong to another school
- WHEN they open the news page
- THEN the item is not listed
- @e2e exclude matching rule unchanged, covered by PHPUnit `NewsAudienceMatcherTest`; the audience source is covered by `LeafGuardianAudienceReaderTest`

### Requirement: A news item carries the moment it was published

Publishing a news item MUST set `publishedAt` to the server's current time, never to a value from the request. Taking it back MUST clear `publishedAt`. The guardian's news feed and the record page's news block MUST sort newest first on `publishedAt`, then `@self.published`, then `@self.created`. On upgrade, every published item without `publishedAt` MUST get its creation moment. The staff News list MUST show the date, and a guardian's news item MUST say "Gepubliceerd op" with the date.

#### Scenario: A draft published today is today's news
- GIVEN a draft written in August and an item published last week
- WHEN staff publish the draft today
- THEN the draft carries today's `publishedAt` and the parent's feed lists it first
- @e2e exclude pinned by `NewsControllerTest::testPublishStampsTheMomentAndTakeBackClearsIt`, `NewsFeedReaderTest::testFeedSortsOnThePublishMomentFirst` and the node test "a draft written long ago and published today is the newest news"; the live check on :8090 is in the PR

#### Scenario: Taking an item back clears the moment
- GIVEN a published item
- WHEN staff take it back
- THEN it is a draft without `publishedAt`
- @e2e exclude pinned by `NewsControllerTest::testPublishStampsTheMomentAndTakeBackClearsIt`

#### Scenario: Existing news keeps its order
- GIVEN items published before this change, without `publishedAt`
- WHEN the app upgrades
- THEN each gets its creation moment, and a second run changes nothing
- @e2e exclude pinned by `BackfillNewsPublishedAtTest`

#### Scenario: The parent sees when an item went out
- GIVEN a published item with `publishedAt` 2026-10-03
- WHEN the parent opens Nieuws
- THEN the item says "Gepubliceerd op 3-10-2026"
- @e2e exclude pinned by the node test "a news item says when it was published, and nothing while it has no date"

### Requirement: The header must never name a person by a number

The site header MUST name a signed-in resident by their portal account's display name. A display name equal to the subject reference or to the account's identity number, or made of digits only (a BSN, a KvK number), MUST NOT be served by the session endpoint nor shown by the site: the line then reads "Ingelogd" ("Logged in"). Which name an account carries is set by provisioning or the broker, not by this rule.

#### Scenario: A BSN stored as the display name
- GIVEN a portal account whose `displayName` is `999993653`, its identity number
- WHEN the resident signs in on the site
- THEN the session's `displayName` is empty and the header reads "Ingelogd"
- @e2e exclude pinned by `SessionControllerTest::testIndexNamesThePersonNeverTheReference` and `tests/site-signed-in-shell.spec.mjs` ("the header says who is signed in")

### Requirement: The header must name the signed-in person, never their reference

The session endpoint MUST answer the portal account's display name as `displayName`, and MUST answer `''` when the account has none or when the value equals the subject reference. The site header MUST show "Logged in as {name}" with that name, and "Logged in" when no name is known. The header MUST NOT show the subject reference.

#### Scenario: A parent with a name on her account
- GIVEN Fatima Hulstkamp's portal account carries the display name "Fatima Hulstkamp"
- WHEN she signs in on the Dutch parent portal
- THEN the header reads "Ingelogd als Fatima Hulstkamp"
- @e2e exclude pinned by `tests/site-signed-in-shell.spec.mjs` and `SessionControllerTest::testIndexNamesThePersonNeverTheReference`; live-checked on the primary-school instance

#### Scenario: An account without a name
- GIVEN a portal account without a display name
- WHEN its holder signs in
- THEN the header reads "Ingelogd" and shows no reference
- @e2e exclude pinned by `tests/site-signed-in-shell.spec.mjs`

### Requirement: A menu block must show the portal's navigation in groups

The site MUST offer a public `siteNavigation` block that renders one vertical list in groups: for a signed-in resident their own items, in the same groups as the menu beside `/mijn`, then each header menu under its title. The block MUST be a navigation landmark with an accessible name, MUST give each group a heading, MUST mark the current page with `aria-current="page"`, and MUST collapse its groups behind a button with `aria-expanded` at phone width. The shell MUST supply the groups; a placement MUST NOT be able to change where the links lead.

#### Scenario: A parent scans the side menu
- GIVEN the `wilgenboom` portal carries the block in its side region
- AND Fatima Hulstkamp is signed in and reads one of the school's information pages
- WHEN the page renders
- THEN the menu shows her own items in the groups of the menu beside `/mijn`, and the site's pages under the menu title
- AND the page on screen is marked as the current page
- @e2e exclude pinned by `tests/site-navigation.spec.mjs` (groups and rendered block); live-checked on the primary-school instance

#### Scenario: A phone
- GIVEN the same page at phone width
- WHEN it renders
- THEN the groups are folded behind a "Menu" button that reports whether it is expanded
- @e2e exclude pinned by `tests/site-navigation.spec.mjs` (the button and its `aria-controls`); live-checked at 390px on the primary-school instance

### Requirement: A page with a menu block must leave the header menu out

When the CMS page on screen carries a `siteNavigation` block in its side region or its main grid, the header MUST NOT render its menu, and MUST keep the logo, the language, the account controls and sign-out. When the side region holds the block, it MUST render as a column before and to the left of the content, and above the content at phone width. The portal's side region MUST apply to every CMS page that does not state its own. The signed-in area (`/mijn`) MUST keep its own resident menu and MUST NOT show the block's column as well.

#### Scenario: The header without its menu
- GIVEN a page with the block in its side region
- WHEN it renders
- THEN the header shows no menu links and still shows "Ingelogd als ..." and the sign-out button
- @e2e exclude pinned by `tests/site-navigation.spec.mjs` (header rendered with and without its menu); live-checked on the primary-school instance

### Requirement: Staff write, change and publish news on a News screen

Portaliq's Nextcloud app MUST offer a News page where a signed-in staff member sees every news item with its audience and status, writes a new one, changes one and publishes it or takes it back. The audience MUST be chosen as the whole school or one or more groups. Every write MUST go through the staff authoring routes (`POST /api/news`, `PUT /api/news/{id}`, `PUT /api/news/{id}/publish`, `PUT /api/news/{id}/unpublish`), which keep `NewsController`'s staff guard; the screen MUST NOT write news through the object API.

#### Scenario: A teacher writes news for the whole school and publishes it
- GIVEN a teacher signed in to Nextcloud at a school whose school app declares `guardianAudience`
- WHEN they open News, write a title and text, choose the whole school and save
- THEN the item is listed as a draft for the whole school
- AND when they publish it, a guardian of that school reads it in the portal
- @e2e exclude live-checked on the primary-school instance (see the PR); the screen's calls and wiring are pinned by `tests/news-authoring.spec.mjs`, the routes by `NewsControllerTest`

#### Scenario: A change keeps everything the server owns
- GIVEN a published news item with read receipts
- WHEN staff change its text and audience
- THEN the title, text and audience change and the status, author and read receipts stay as they were
- @e2e exclude backend contract, pinned by `NewsControllerTest::testUpdateChangesTheTextAndAudienceOnly`

#### Scenario: A save without an audience is refused
- GIVEN staff choose "one or more groups" and pick none
- WHEN they save
- THEN the screen names what is missing and nothing is written
- @e2e exclude pinned by `tests/news-authoring.spec.mjs` ("a form names what is missing") and `NewsControllerTest`

### Requirement: The school and group choices come from the school app

`GET /api/news/audiences` MUST return the schools and groups a staff member can choose, each `{id, label}`. For every contribution that declares `guardianAudience`, the groups MUST come from `groups.options` (`{register?, schema}`) when declared, else from the `$ref` the groups field carries in its collection's schema; the schools likewise from `schoolOptions`, else from the `$ref` of `schoolField` on the children's schema. The option objects MUST be read as the signed-in staff member with OpenRegister's access rules on. When no source resolves, the list MUST be empty and the screen MUST ask for a reference instead.

#### Scenario: The groups follow the field reference
- GIVEN learniq's `enrolment.cohortId` carries `$ref: Cohort`
- WHEN a teacher opens the News dialog
- THEN the groups they may read are offered by name
- @e2e exclude pinned by `NewsAudienceOptionsTest::testGroupsFollowTheFieldReference`

#### Scenario: No source means a reference field
- GIVEN the school app declares no school source and the school field has no `$ref`
- WHEN a teacher chooses the whole school
- THEN the dialog asks for the school's reference
- @e2e exclude pinned by `NewsAudienceOptionsTest::testGroupsFollowTheFieldReference` (empty schools) and the dialog's fallback field

## Notes

- The "A page may carry a hero image reference" and "A public page may embed
  a lead-capture form widget" requirements were added by the
  `contribution-landing-page-action` change (delta:
  `openspec/changes/contribution-landing-page-action/specs/portaliq-cms/spec.md`);
  same sync discipline until that change archives.
