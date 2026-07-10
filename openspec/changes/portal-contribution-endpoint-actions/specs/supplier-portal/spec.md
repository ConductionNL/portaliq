---
status: proposed
---

# Spec: supplier-portal (contribution endpoint actions)

## ADDED Requirements

### Requirement: A contribution MUST be able to declare an endpoint-forwarding action

A `PortalContributionProvider` SHALL be able to declare an action of
`type: "endpoint"` (`id`, `label`, `appId`, `path`, `method`, `fields`) in
addition to `type: "create"`. Portaliq SHALL authorise an endpoint action
invocation the same way it authorises a create action — the action MUST
appear in the subject's own aggregated contributions — and SHALL forward only
the action's whitelisted fields, together with a Portaliq-minted, short-lived
internal assertion of the subject's server-derived `subjectRef` and
`organisation`, to the declared app/path/method. Portaliq SHALL NOT forward
any client-supplied `subjectRef`/`organisation`.

#### Scenario: A supplier invokes a declared endpoint action

- **GIVEN** a contribution declares `{ id: "requestRenewal", type: "endpoint",
  appId: "procest", path: "/api/leverancier-portaal/renewals", method: "POST",
  fields: ["contractId", "note"] }` for the authenticated subject
- **WHEN** the subject invokes `requestRenewal` with a `contractId` and `note`
- **THEN** Portaliq forwards those two fields plus its own server-derived
  `subjectRef`/`organisation` (never a client value) to procest's declared
  endpoint, and the domain app's own bearer-guarded logic decides whether to
  act on it

#### Scenario: A subject cannot invoke an action outside their own contributions

- **GIVEN** a subject whose aggregated contributions do not declare an action
  `id`/`appId` pair
- **WHEN** they call `POST /portal/api/actions/{appId}/{actionId}` for that
  pair anyway
- **THEN** the request is rejected with 403 and nothing is forwarded

#### Scenario: The domain app is unreachable or refuses the forward

- **GIVEN** an authorised endpoint action whose target app returns a non-2xx
  response or cannot be reached
- **WHEN** Portaliq forwards the invocation
- **THEN** the caller receives a `502` and no partial state is implied

## Notes

- This closes the gap between the manifest contract's documented `endpoint`
  action shape (`IPortalContributionProvider`) and the fact that only
  `type: "create"` was ever implemented in `ContributionController`.
- **@e2e**: covers `requestRenewal`-shaped invocation end-to-end once a real
  contributing app (procest) opts in on its own side (`supplier-portal` T10,
  out of this change's scope).
