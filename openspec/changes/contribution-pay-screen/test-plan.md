# Test Plan: contribution-pay-screen

## Test Cases

### TC-1: Row action resolution
- **spec_ref**: `openspec/changes/contribution-pay-screen/specs/portal-contribution-contract/spec.md#requirement-server-enforced-status-transitions`
- **type**: regression
- **preconditions**: a contribution with an update action, an endpoint action with and without `rowField`, a create action
- **steps**: normalise collections declaring `rowAction`, `rowActions` strings and objects, and ghosts
- **expected result**: update and well-formed endpoint row actions resolve; `rowAction` is folded in and removed; the old `[close, createTicket, ghost]` case still yields `[close]`
- **test command**: `vendor/bin/phpunit --filter 'RowActionResolverTest|PortalManifestNormaliserTest'`

### TC-2: The row-scoped forward
- **spec_ref**: `openspec/changes/contribution-pay-screen/specs/portal-contribution-contract/spec.md#requirement-a-row-scoped-forward-must-prove-the-row-before-it-forwards`
- **type**: security
- **preconditions**: doubles for registry, session, reader, forwarder, auditor
- **steps**: call `forward()` without subject, with an action not offered, below trust, with a foreign row, with a client body, with a transport failure
- **expected result**: 401, 403 (no read), 403, 404 (no forward), body is exactly the stamp, 502
- **test command**: `vendor/bin/phpunit --filter PortalRowActionControllerTest`

### TC-3: rowWhen
- **spec_ref**: `openspec/changes/contribution-pay-screen/specs/portal-contribution-contract/spec.md#requirement-an-endpoint-row-action-must-be-offered-only-on-the-rows-its-rowwhen-names`
- **type**: functional
- **preconditions**: `pay` with `rowWhen` on `state`
- **steps**: render the table with an issued and a paid row; forward the paid row
- **expected result**: one button; the forward answers 409 without a call
- **test command**: `npm run check:row-action`, `vendor/bin/phpunit --filter PortalRowActionControllerTest`

### TC-4: The notice
- **spec_ref**: `openspec/changes/contribution-pay-screen/specs/portal-contribution-contract/spec.md#requirement-a-collection-must-be-able-to-name-a-notice-field`
- **type**: accessibility
- **persona**: Henk (elderly guardian): the voluntary sentence must be readable before paying
- **preconditions**: a voluntary and a non-voluntary row
- **steps**: render the confirm step for both
- **expected result**: the sentence appears once for the voluntary row, nothing for the other
- **test command**: `npm run check:row-action`

### TC-5: The redirect and the outcome
- **spec_ref**: `openspec/changes/contribution-pay-screen/specs/portal-contribution-contract/spec.md#requirement-the-portal-must-let-a-guardian-pay-a-school-contribution-from-its-row`
- **type**: security
- **preconditions**: answers 200 https, 200 http, 200 javascript:, 200 without URL, 403, 409, 503, network error
- **steps**: `redirectTarget()` and `outcomeKey()` on each
- **expected result**: only the https URL is followed; each other answer maps to one message
- **test command**: `npm run check:row-action`

## Coverage Summary

All five requirements have a unit or node test. The full browser flow (pay
button to checkout) waits for shillinq's `rowField` follow-up and a bound
payment provider; it is not covered by an e2e test in this change.

## Out of Scope

Shillinq's receiver, the payment request and the provider: shillinq's own
tests cover them.
