# landing-page-provisioning Delta: submit-creates-the-case-directly

**Status**: draft
**Scope**: landing-page forms submit into the contributing app's object (decision 179).

## MODIFIED Requirements

### Requirement: A landing page's form is submittable with no portal session

An active landing-page form SHALL declare the contributing app's destination (for pipelinq: `lead`). An anonymous visitor's submit SHALL create that object through OpenRegister's submit service in the same request, with `formId`, `pageId`, `pageRoute`, `portal`, `sourceApp` and the tracking fields stamped as server-side values a client cannot override.

#### Scenario: A visitor submits the landing page's form anonymously
- GIVEN an active landing-page form into pipelinq `lead`
- WHEN an anonymous visitor posts their values with observed UTM and referrer
- THEN a pipelinq lead exists before the response returns, carrying the values and the stamped fields
- AND no object is written in portaliq
- @e2e site-form-submission.spec.ts

#### Scenario: A field not declared on the form is dropped, never persisted
- GIVEN a form declaring fields `name` and `email` only
- WHEN a visitor's body also includes `isAdmin: true`
- THEN the created lead holds no `isAdmin`
- @e2e exclude whitelist invariant, covered by PHPUnit

## REMOVED Requirements

### Requirement: A submission is relayed to the contributing app as a fail-safe, not a fail-closed, cross-app event

**Reason**: the relay exists because the answers were stored in portaliq first. With the destination created directly, there is nothing to relay, and a missing contributing app now refuses the form at publish instead of losing the answers silently.
**Migration**: `occ portaliq:intake:drain` submits stored `landingPageSubmission` objects into their source app's destination or reports them; the schema and `LandingPageSubmissionDispatchListener` are removed at zero pending.
