---
kind: mixed
---

# Proposal: contribution-landing-page-action

## Summary

Adds the phase 0 platform prerequisite from the pipelinq marketing programme
(`pipelinq/docs/Technical/marketing-architecture.md`, phase 0): a same-instance
cross-app command that lets a contributing app (pipelinq first) ask Portaliq
to provision a draft CMS landing page with a bound lead-capture form, plus the
return path — Portaliq notifying the contributing app when a visitor submits
that form, carrying UTM first/last-touch attribution. Publishing stays an
editor's own action in the CMS; this change only ever creates `draft` pages.

## Motivation

Rule 5 of the marketing architecture is explicit: "pages stay in portaliq...
Pipelinq... creates landing pages through the portal contribution contract. It
does not... render public pages itself." Every later marketing phase (2
content hub, 4 campaigns and attribution) depends on this seam existing.
Without it, phase 4's exit criterion — "a lead created from a landing-page
form shows the mailing as first touch, a post as last touch" — has no page to
attribute to. This change is a pure enabler: it ships no pipelinq-side UI, no
campaign object, no attribution model — those are phase 4's own openspec
change (`marketing-landing-pages-via-portaliq`, in pipelinq's repo).

## Affected Projects

- [x] Project: `portaliq` — new cross-app command (create landing page),
  anonymous submission provisioning, UTM capture, new OpenRegister schemas,
  a public `form` site widget.

pipelinq is a **consumer** of the contract this change ships, not touched by
it — pipelinq's own event class and delegation service are explicitly out of
scope here (see below) and land in pipelinq's phase 4 change.

## Scope

### In Scope

- A same-instance ADR-041 typed cross-app command
  (`OCA\Portaliq\Event\LandingPageRequestedEvent`) a contributing app
  dispatches to ask Portaliq to create a `page` (status `draft`) with a bound
  `form` object, validating portal existence and route uniqueness within the
  portal, returning `{pageId, route, publicUrl, formId}` (or a machine
  error code) via the event's result slot.
- A public `form` site widget (`src/site/components/FormBlock.vue`) rendering
  the bound form on the landing page and submitting through Portaliq's
  existing anonymous contribution-create path — no new public HTTP route.
- Portaliq's built-in `PortalContributionProvider` reading active `form`
  objects and synthesising them into the existing anonymous aggregate
  (mirroring the `portal-page-provisioning` pattern for `portalPage`
  objects), so the submission is authorised, whitelisted and throttled by
  the exact machinery `ContributionController::createAnonymous()` already
  enforces.
- A new `landingPageSubmission` schema recording each submission, with
  server-stamped `submittedAt`/`nonce` and client-observed UTM first/last
  touch + referrer (advisory attribution data, not authorization-relevant).
- A first-party, portal-scoped `sessionStorage` UTM capture util on the site
  renderer (`src/site/lib/campaignTracking.js`) — no third-party script.
- A second, producer-side cross-app event
  (`OCA\Portaliq\Event\LandingPageFormSubmittedEvent`) Portaliq dispatches
  after a `landingPageSubmission` write, resolved against a `sourceApp`-derived
  candidate consumer class and skipped (logged, not failed) when that class
  does not exist yet — see Open Questions.
- `heroImage` added to the `page` schema (additive, no `format`).
- PHPUnit coverage for validation, the returned shape, and the dispatch
  payload; a Playwright spec for the visitor-submission flow (lint/list-only
  if no live instance is reachable in this environment — see design.md).

### Out of Scope

- pipelinq's own consumer-side event class, delegation service, `lead`/
  `touchpoint` writes, and campaign UI — phase 4's own change
  (`marketing-landing-pages-via-portaliq`), tracked in
  `pipelinq/docs/Technical/marketing-architecture.md`.
- A dedicated internal "submissions" admin view — none exists today for any
  Portaliq schema; out of scope here (see design.md Open Questions).
- General route-uniqueness enforcement across every `page` write path — the
  known portaliq-cms gap is only closed for THIS action's own writes.
- A canonical "primary domain" concept on `portal.domains[]` — `publicUrl`
  degrades to `null` when no domain is configured (documented limitation).
- Cross-instance / federated delivery of the submission event (a webhook
  fallback) — the task brief permits it only if the fleet already has one for
  contributions; it does not, and same-instance ADR-041 dispatch is the
  canonical, tested mechanism, so no webhook path is built.

## Approach

Two same-instance ADR-041 typed events, not the ADR-046 portal-contribution
endpoint-action-forward pattern (that forwards a **visitor's** action out to a
domain app — the opposite direction from a domain app asking Portaliq to
create content) and not a new HTTP route (ADR-108: public/citizen surfaces
belong in Portaliq, and gate-27 bans phantom cross-app RPC over HTTP). See
design.md for the full rationale and the rejected alternatives.

## New Dependencies

None.

## Impact

- `lib/Settings/portaliq_register.json` — new schemas `form`,
  `landingPageSubmission`; `page.heroImage` (additive).
- `lib/Event/LandingPageRequestedEvent.php`,
  `lib/Event/LandingPageFormSubmittedEvent.php` — new.
- `lib/Listener/LandingPageRequestedEventListener.php`,
  `lib/Listener/LandingPageSubmissionDispatchListener.php` — new.
- `lib/Service/LandingPageProvisioningService.php` — new.
- `lib/Portal/PortalContributionProvider.php` — extended (active `form`
  objects folded into the anonymous aggregate).
- `lib/AppInfo/Application.php` — register the two new listeners.
- `src/site/components/FormBlock.vue`,
  `src/site/lib/campaignTracking.js` — new.
- `src/site/components/WidgetGrid.vue` — one new `PUBLIC_WIDGETS` entry.
- `docs/` — a new page documenting the contract for contributing apps.

## Cross-Project Dependencies

pipelinq's phase 4 change depends on this one (the contract this change
ships is what pipelinq's `marketing-landing-pages-via-portaliq` change will
consume). Nothing in pipelinq is touched here.

### Risk 1: The consumer half does not exist yet, so the submission event has nothing to be received by

**Severity:** Medium — **Mitigation:** the dispatch listener resolves the
consumer class with `class_exists()` and logs-and-skips (never throws, never
blocks the visitor's response) when absent — exactly the same fail-safe shape
`NotificationDispatchService` already uses for a no-match. The submission is
durably recorded in `landingPageSubmission` regardless, so no data is lost
while phase 4 is unbuilt; a later reconciliation job could resubmit before
this write path exists.

### Risk 2: Route uniqueness and `publicUrl` derivation are gaps the wider CMS spec already flags

**Severity:** Low — **Mitigation:** both are closed narrowly, inside this
action's own write path only (documented in Out of Scope); the wider gaps in
`portaliq-cms` remain and are not claimed as fixed by this change.

## Rollback Strategy

Schema additions are additive (no destructive migration); reverting the PR
removes the two listeners, the provisioning service, and the schema
additions. Any `form`/`landingPageSubmission` objects already created remain
as inert OpenRegister rows (never served, since the built-in provider that
reads them is removed with the revert) — no data-loss cleanup is required to
roll back.

## Open Questions

None outstanding — decisions made under uncertainty during this change are
recorded as DEFERRED_QUESTIONS in the implementation report, not here, since
`AskUserQuestion` was unavailable while building this change.
