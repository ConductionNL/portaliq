# portal-contribution-contract — delta: portal-status-transitions

**OpenSpec change**: `portal-status-transitions` (ADDED requirement; additive to
contribution-manifest-v3). Extends the update endpoint + manifest vocabulary with
server-enforced status transitions. No data-access authority changes: the action
`fields` whitelist, collection scope, projection, and ownership re-verification
remain the sole authorities.

## ADDED Requirements

### Requirement: Server-enforced status transitions

A `type: update` action MAY declare `set` — a map of WHITELISTED field → fixed
value that the SERVER applies over the client body (after whitelisting, before
the write), so a transition target (approve/reject/close/pay) is fixed by the app
and cannot be chosen by the client. A collection MAY declare `rowActions` — a
list of ids resolving to `type: update` actions in the SAME contribution,
surfaced as per-row transition buttons. The `PATCH` update endpoint accepts an
optional `?action=<id>` naming which update action to apply. Malformed `set`
(non-whitelisted key, non-scalar value, non-array) and unresolvable `rowActions`
are dropped fail-closed. Ownership re-verification and scope re-stamp still run.

#### Scenario: A transition target cannot be tampered with

- GIVEN a `type: update` action `close` with `fields: ["status"]` and `set: {status: "closed"}`
- WHEN the subject PATCHes their own row with `?action=close` and body `{"status": "reopened-by-hacker"}`
- THEN the saved `status` is `closed` (the server's `set` overrides the client), the row stays the subject's (scope re-stamped), and 200 is returned

#### Scenario: `set` may only fix whitelisted fields

- GIVEN an action with `fields: ["status"]` and `set: {status: "closed", subjectRef: "other"}`
- WHEN the manifest is normalised
- THEN `set` is `{status: "closed"}` — the non-whitelisted `subjectRef` is dropped, so a transition can never move a row out of the subject's scope

#### Scenario: rowActions resolve only to update actions in the contribution

- GIVEN a collection `rowActions: ["close", "createTicket", "ghost"]` where `close` is a `type: update` action, `createTicket` is a `type: create`, and `ghost` is undefined
- WHEN the manifest is normalised
- THEN `rowActions` is `["close"]`; a collection left with no resolvable row actions loses the key

#### Scenario: The action disambiguator selects the right transition

- GIVEN two update actions for the same schema — `updateExample` (`fields: [title]`) and `closeExample` (`fields: [status]`, `set: {status: closed}`)
- WHEN the subject PATCHes with `?action=closeExample`
- THEN the `closeExample` action is applied (status → closed), not the first-declared `updateExample`
- AND a PATCH with no `?action=` keeps the v1 first-match behaviour
