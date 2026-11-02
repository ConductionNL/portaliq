---
kind: code
---

# Proposal: citizen-case-write-limit

## Why

A resident can correct an answer, add a document and withdraw her case from the portal. Each of those writes passes `CitizenCaseController::guardWrite()`, which asks `CitizenWriteThrottle::allow()`: at most 20 writes per case and 60 per identity in an hour, with a refused write counted too. Past the limit the answer is 429 with "You have made a lot of changes in a short time. Please try again later." No requirement describes this guard, so the limits, the counting of refused writes and the fail-open without a cache can change unnoticed.

## What changes

- **One requirement for the guard that already runs.** It names the three writes it covers, both limits and the window, that a refused write counts, the 429 answer with its sentence, and what happens without a counter.
- **Tests pin the requirement.** `CitizenWriteThrottleTest` and `CitizenCaseControllerTest` already cover most of it; the tasks add the withdrawal and the refused-write count.

## Rows covered

- `act-write-throttle` (built). No screen of its own: the refusal sentence shows on the case page as any other notice.
- `act-forward-action` is already described by "Endpoint bearer-forward actions" in `specs/portal-contribution-contract`; this PR records that link in capabilities.json and adds no requirement for it.

## Out of scope

- The rate limits on reads and on anonymous routes. Those are "A signed-in portal session must be rate limited per subject" and "Public portal endpoints are rate limited".
