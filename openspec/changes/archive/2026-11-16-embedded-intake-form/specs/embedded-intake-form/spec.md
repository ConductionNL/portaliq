---
status: proposed
---

# Spec: embedded-intake-form

**Status:** proposed
**Scope:** portaliq (owner); the case app declares the intake it already declares
**Depends on:** `portal-contribution-contract` (anonymous create, scoping);
`portaliq-cms` (portal, theme, locales); `portal-shared-runtime` (public boot mode)

## Purpose

A municipality embeds a portal intake form on its own website, a visitor
submits without an account, and the submission arrives as a case through
the contribution contract. Requested by the dossiq competitor analysis,
register row Q1.16.

## ADDED Requirements

### Requirement: A portal form is publishable as an embed (REQ-EIF-001)

A portal page of type form SHALL carry `allowedOrigins[]` and SHALL offer
an embed snippet naming that form. The snippet SHALL mount the form in an
iframe served from the portal's own origin. A form whose `allowedOrigins`
is empty SHALL serve to no origin, and the admin surface SHALL say so
beside the snippet.

#### Scenario: An admin copies a snippet for one form
- **GIVEN** a portal form with `allowedOrigins: ["https://www.gemeente.nl"]`
- **WHEN** the admin opens the page in the CMS admin
- **THEN** the snippet is shown with that origin named beside it
- e2e: `tests/e2e/embedded-intake-form.spec.ts`

#### Scenario: A form with no allowed origins serves nobody
- **GIVEN** a portal form with an empty `allowedOrigins`
- **WHEN** any page frames it
- **THEN** the frame renders a message and no form
- **AND** the admin surface states that the form is not embeddable yet
- e2e: `tests/e2e/embedded-intake-form.spec.ts`

### Requirement: The origin list is the boundary, and it fails closed (REQ-EIF-002)

The frame route SHALL set `Content-Security-Policy: frame-ancestors` from
the allowed origins of the form being served, never a wildcard. A request
whose framing origin is not on that list SHALL be refused before any
schema is read, and SHALL render a plain message rather than a form.

#### Scenario: A disallowed origin gets no form
- **GIVEN** a form allowing `https://www.gemeente.nl` only
- **WHEN** a page on another origin frames it
- **THEN** the frame renders a message and no form field is present
- e2e: `tests/e2e/embedded-intake-form.spec.ts`

#### Scenario: The refusal reads no schema
- **GIVEN** the same disallowed origin
- **WHEN** the frame route runs
- **THEN** no schema is read and no contribution provider is consulted
- @e2e exclude Fail-closed ordering, observable only at the seam; covered by PHPUnit

### Requirement: A submission from an embed is an ordinary contribution create (REQ-EIF-003)

A submission from the frame SHALL go through the same anonymous
contribution create the portal's own form uses, with the same schema
validation, the same throttle and the same audit entry. It SHALL record
the origin it came from. The case app SHALL need no change for a
submission to arrive.

#### Scenario: A submission arrives as a case
- **GIVEN** a form embedded on an allowed origin and a case app declaring that intake
- **WHEN** a visitor submits it
- **THEN** a case is created through the contribution contract
- **AND** the submission records the origin it came from
- e2e: `tests/e2e/embedded-intake-form.spec.ts`

#### Scenario: Validation is the schema's, not the frame's
- **GIVEN** a form whose schema requires a postcode
- **WHEN** a visitor submits without one
- **THEN** the submission is refused with the schema's message and no case is created

#### Scenario: A burst from one origin is throttled
- **GIVEN** an embedded form under a burst of submissions from one origin
- **WHEN** the configured rate is passed
- **THEN** further submissions are refused and no case is created
- @e2e exclude Throttling per ADR-082; covered by PHPUnit

### Requirement: The visitor needs no account and gets something to come back with (REQ-EIF-004)

After a submission the frame SHALL show the case reference and a one-time
follow link. The frame SHALL NOT host a login. A portal that requires an
identified submission SHALL link the visitor to its own page instead of
authenticating inside the frame.

#### Scenario: A visitor submits without an account
- **GIVEN** an anonymous visitor on the municipality's website
- **WHEN** they complete the embedded form
- **THEN** they see the case reference and a follow link
- **AND** at no point are they asked to sign in
- e2e: `tests/e2e/embedded-intake-form.spec.ts`

#### Scenario: An identified intake redirects rather than logs in
- **GIVEN** a form whose portal requires an identified submission
- **WHEN** a visitor opens the embed
- **THEN** the frame offers a link to the portal's own page and renders no login field
- e2e: `tests/e2e/embedded-intake-form.spec.ts`

### Requirement: Nothing in the frame carries a session (REQ-EIF-005)

The frame route SHALL set no cookie and SHALL read none, and the form
inside it SHALL read no data belonging to a signed-in visitor. A visitor
signed in to the portal in another tab SHALL be anonymous inside the
frame.

#### Scenario: A signed-in visitor is anonymous in the frame
- **GIVEN** a visitor with an active portal session in another tab
- **WHEN** they open a page carrying the embed
- **THEN** the frame shows an empty form and no personal data
- e2e: `tests/e2e/embedded-intake-form.spec.ts`

#### Scenario: No cookie crosses the frame
- **GIVEN** a request to the frame route
- **WHEN** the response is inspected
- **THEN** it sets no cookie
- @e2e exclude Header assertion; covered by PHPUnit
