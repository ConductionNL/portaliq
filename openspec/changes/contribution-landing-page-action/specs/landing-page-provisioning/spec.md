# landing-page-provisioning Specification

**Status**: in-progress
**Scope**: portaliq
**OpenSpec changes**:

- [contribution-landing-page-action](../../changes/contribution-landing-page-action/)

## Purpose

Defines the same-instance cross-app command a contributing app uses to ask
Portaliq to provision a draft CMS landing page with a bound lead-capture
form, and the return path — Portaliq notifying the contributing app when a
visitor submits that form, carrying UTM first/last-touch attribution. This is
the phase 0 platform prerequisite for the pipelinq marketing programme
(`pipelinq/docs/Technical/marketing-architecture.md`, phase 0). Related:
ADR-041 (cross-app commands via events, same-instance, typed, result-slot),
ADR-046 (portal contribution contract — the visitor-facing contract this is
deliberately NOT built on), ADR-085 (forms), ADR-086 (headless CMS),
ADR-005 (fail-closed security), ADR-108 (public surface placement).

## ADDED Requirements

### Requirement: A contributing app requests a landing page via a typed event

A contributing app MUST dispatch
`OCA\Portaliq\Event\LandingPageRequestedEvent` (via
`IEventDispatcher::dispatchTyped()`) to ask Portaliq to provision a landing
page. Portaliq's listener MUST handle the event synchronously and write the
outcome onto the SAME event instance's result slot
(`pageId`/`route`/`publicUrl`/`formId`/`error`/`handled`), so the producer
reads the result immediately after `dispatchTyped()` returns, with no
network hop and no new HTTP route.

#### Scenario: A valid request creates a draft page and a form

- GIVEN a `LandingPageRequestedEvent` naming an existing, published `portal`, a route not already used within that portal, a non-empty `article.summary`/`article.body`, and at least one well-formed `form.fields` entry
- WHEN the event is dispatched
- THEN the listener creates a `page` object with `status: draft` and a `form` object, and writes `pageId`, `route`, `formId`, `publicUrl` (or `null` if the portal has no domain), `error: null`, and `handled: true` onto the event
- @e2e exclude backend cross-app command contract — covered by `LandingPageProvisioningServiceTest`; no distinct portal UI flow (the caller is another app, not a browser)

#### Scenario: The created page is always draft, never published

- GIVEN any valid `LandingPageRequestedEvent`
- WHEN the page is created
- THEN its `status` is `draft` and the listener never writes `published` — publishing stays an editor's own action against the CMS designer, unaffected by this change
- @e2e exclude invariant pinned by `LandingPageProvisioningServiceTest::testCreatedPageIsAlwaysDraft`; not independently observable in a UI flow this change ships

### Requirement: Requests fail closed with a machine-readable error and no partial write plan

The listener MUST validate the full request BEFORE writing anything, and
MUST set `error` (one of `unknown_portal`, `duplicate_route`,
`invalid_article`, `invalid_form`) and `handled: true` with `pageId`/`formId`
left `null` when validation fails. It MUST NOT create a `form` or a `page`
object for a request that fails validation.

#### Scenario: An unknown or unpublished portal is rejected

- GIVEN a `LandingPageRequestedEvent` naming a `portal` slug that does not exist, or whose `status` is not `published`
- WHEN the event is dispatched
- THEN `error` is `unknown_portal`, `pageId`/`formId` are `null`, and no OpenRegister write occurs
- @e2e exclude fail-closed validation — covered by PHPUnit; no UI surface (the caller is another app)

#### Scenario: A duplicate route within the same portal is rejected

- GIVEN an existing `page` in portal `open-tilburg` at route `/campagne/x`
- AND a `LandingPageRequestedEvent` for the same portal and the same route (case-insensitive)
- WHEN the event is dispatched
- THEN `error` is `duplicate_route` and no new `page` or `form` is created
- AND a request for the SAME route in a DIFFERENT portal succeeds
- @e2e exclude fail-closed validation — covered by PHPUnit; no UI surface

#### Scenario: A malformed article or form is rejected

- GIVEN a `LandingPageRequestedEvent` whose `article.summary`/`article.body` is empty, OR whose `form.fields` is empty or contains an entry missing `id`/`label`/`type`, OR whose `form.submitLabel` is empty
- WHEN the event is dispatched
- THEN `error` is `invalid_article` or `invalid_form` respectively, and no OpenRegister write occurs
- @e2e exclude fail-closed validation — covered by PHPUnit; no UI surface

### Requirement: A landing page's form is submittable with no portal session

An active `form` object (created by this action) MUST be reachable through
Portaliq's existing anonymous contribution-create path
(`ContributionController::createAnonymous()`,
`POST /portal/api/collections/{register}/{schema}`) with NO new HTTP route.
Portaliq's built-in `PortalContributionProvider` MUST synthesise one
`anonymous: true`, `type: create` action per active `form` object into the
existing anonymous aggregate, whitelisting the form's own declared field ids
plus the fixed tracking fields `utmFirstTouch`, `utmLastTouch`, `referrer`,
and stamping `formId`/`pageId`/`pageRoute`/`portal`/`sourceApp`/
`externalReference` as server-side `defaults` a client can never override.

#### Scenario: A visitor submits the landing page's form anonymously

- GIVEN an active `form` object created by a prior `LandingPageRequestedEvent`
- WHEN an anonymous visitor `POST`s their field values (plus observed UTM/referrer) to the anonymous create endpoint for `portaliq`/`landingPageSubmission`
- THEN a `landingPageSubmission` object is created carrying the whitelisted values, the stamped `defaults`, a server-generated `submittedAt` and `nonce`, and the client-observed UTM/referrer fields unchanged
- AND the response never exposes `formId`/`pageId` as client-writable — they are always the stamped defaults, never the request body's values
- @e2e site-form-submission.spec.ts — "a visitor submits the landing page form and it is recorded"

#### Scenario: A field not declared on the form is dropped, never persisted

- GIVEN an active `form` object declaring fields `name` and `email` only
- WHEN a visitor's submission body also includes `isAdmin: true`
- THEN the created `landingPageSubmission.values` contains only `name` and `email` — `isAdmin` never reaches the write
- @e2e exclude whitelist invariant — covered by PHPUnit (`ContributionController`'s existing whitelist path, reused unchanged); the load-bearing half is a negative on the wire, not decidable by a browser assertion

### Requirement: A submission is relayed to the contributing app as a fail-safe, not a fail-closed, cross-app event

After a `landingPageSubmission` write succeeds, Portaliq MUST attempt to
notify the contributing app named by the submission's `sourceApp` by
resolving and dispatching `OCA\{StudlyApp}\Event\LandingPageFormSubmittedEvent`
via `class_exists()`. When that class does not exist (the consumer app is not
installed, or has not shipped its own event class yet), Portaliq MUST log and
continue — it MUST NOT throw, MUST NOT fail the visitor's request, and MUST
NOT retry synchronously. The visitor-facing write's success is never
contingent on this notification.

#### Scenario: The consumer app is installed and defines the event class

- GIVEN a `landingPageSubmission` whose `sourceApp` is `pipelinq`, and `\OCA\Pipelinq\Event\LandingPageFormSubmittedEvent` exists
- WHEN the submission write succeeds
- THEN Portaliq dispatches that event carrying the submission's values, UTM first/last touch, referrer, `submittedAt`, and `nonce`
- @e2e exclude cross-app dispatch contract — covered by `LandingPageSubmissionDispatchListenerTest` using a fixture consumer class; no distinct UI flow

#### Scenario: The consumer app's event class does not exist yet

- GIVEN a `landingPageSubmission` whose `sourceApp` names an app with no `LandingPageFormSubmittedEvent` class
- WHEN the submission write succeeds
- THEN the visitor's request still returns success, a warning is logged, and no exception propagates
- @e2e exclude fail-safe invariant — covered by PHPUnit; deliberately not a UI-observable difference (the visitor sees the same success either way)

### Requirement: UTM capture is first-party, portal-scoped, and honest about being advisory

The site renderer MUST capture `utm_source`/`utm_medium`/`utm_campaign`/
`utm_term`/`utm_content` from the landing URL's query string into a first-
party, portal-scoped `sessionStorage` entry — first touch written once per
session, last touch overwritten on every landing with UTM parameters present
— and MUST NOT load any third-party tracking script. These values MUST be
treated as advisory attribution data, never as an authorization or identity
input, consistent with `submittedAt`/`nonce` remaining server-stamped on the
same submission.

#### Scenario: First touch is captured once, last touch is overwritten

- GIVEN a visitor lands on a page with `?utm_campaign=a&utm_source=x`, then later returns via a different link carrying `?utm_campaign=a&utm_source=y` in the same browser session
- WHEN the visitor submits the form
- THEN the submission's `utmFirstTouch.source` is `x` and `utmLastTouch.source` is `y`
- @e2e site-form-submission.spec.ts — "a visitor submits the landing page form and it is recorded"
