---
status: proposed
---

# Spec: supplier-portal (contribution endpoint actions)

## ADDED Requirements

### Requirement: A contribution MUST be able to declare an endpoint-forwarding action

A `PortalContributionProvider` SHALL be able to declare an endpoint action `{id, label, endpoint, method?, minTrust?, fields?}` in addition to a create action. `endpoint` is an instance-local absolute path (it starts with `/`, carries no scheme and no protocol-relative `//`). The contributing app is the contribution's own `app` key, which is the `{appId}` segment of the forwarding route `POST /portal/api/actions/{appId}/{actionId}`, not a field on the action. Portaliq SHALL authorise an invocation the same way it authorises a create action: the action MUST appear in the subject's own aggregated contributions, its method MUST be allowed and its `minTrust` MUST be met. When the action declares `fields`, Portaliq SHALL rebuild the forwarded body server-side from only those fields; an action without `fields` relays the request body unchanged. Portaliq SHALL send a short-lived signed assertion (`X-Portal-Subject`, `use: "assertion"`) of the subject's server-derived `subjectRef` and `organisation` with the forward, and SHALL NOT forward any client-supplied `subjectRef` or `organisation`. The public site SHALL run the action through `src/site/lib/residentActions.js` (`postAction`, `forwardApi`) and show the answer of the contributing app, or follow its redirect.

#### Scenario: A supplier invokes a declared endpoint action

- **GIVEN** a contribution of app `procest` declares `{ id: "requestRenewal", label: "Verlenging aanvragen", endpoint: "/apps/procest/api/leverancier-portaal/renewals", method: "POST", fields: ["contractId", "note"] }` for the authenticated subject
- **WHEN** the subject invokes `requestRenewal` with a `contractId`, a `note` and a smuggled `subjectRef`
- **THEN** Portaliq forwards only `contractId` and `note`, with its own server-derived `subjectRef` and `organisation` in the signed assertion (never the client value), to the declared endpoint, and the domain app's own bearer-guarded logic decides whether to act on it
- **AND** the site shows the domain app's answer in a status region, or follows its redirect

#### Scenario: A subject cannot invoke an action outside their own contributions

- **GIVEN** a subject whose aggregated contributions do not declare an action with that `{appId}` and `{actionId}`
- **WHEN** they call `POST /portal/api/actions/{appId}/{actionId}` for that pair anyway
- **THEN** the request is rejected with 403 and nothing is forwarded

#### Scenario: The domain app is unreachable or refuses the forward

- **GIVEN** an authorised endpoint action whose target cannot be reached
- **WHEN** Portaliq forwards the invocation
- **THEN** the caller receives a `502` with `{error: "forward_failed"}` and no transport detail
- **AND** a non-2xx answer of a reachable app is relayed with its own status, so the site can show it

## Notes

- Rewritten at archive (15 Oct 2026) to the shape as built: `endpoint` instead of the proposal's `appId` + `path` pair, and the Vue site instead of the removed React portal (`src/portal/App.jsx`).
- **@e2e**: a `requestRenewal`-shaped invocation end to end once a real contributing app opts in on its own side (`supplier-portal` T10, out of this change's scope).
