# 0002. Apps contribute portal surfaces as manifest data; enforcement stays in portaliq code

- Status: accepted
- Source: [`openspec/changes/archive/2026-09-07-contract-v2/design.md`](../../openspec/changes/archive/2026-09-07-contract-v2/design.md)
  (sections "Architecture Overview" and "Declarative-vs-imperative decision"),
  README section "Contribution contract v2"

## Context

Portaliq is one portal for many Nextcloud apps. Each app needs to show its own
collections and actions to portal visitors, and portaliq has to guard that
boundary for subjects who do not exist as Nextcloud users.

## Decision

A contributing app ships one plain class, `OCA\{App}\Portal\PortalContributionProvider`,
duck-typed so the app has no dependency on portaliq. Everything a contributor
says (audiences, `minTrust`, `scopeClaim`, `via`, endpoint actions) is manifest
data, and every new field is optional with a default equivalent to the earlier
contract. `PortalContributionRegistry` aggregates the manifests per subject and
filters by trust inside aggregation, so every data path authorises against one
source of truth. The enforcement itself (trust comparison, claim resolution,
join verification, assertion signing) stays imperative, unit-tested service
code in portaliq, because it is the security kernel of the portal boundary
rather than domain business logic.

## Consequences

- No contributor writes portal code; adding a portal surface is a manifest change
  in the contributing app.
- Older providers and manifests keep working unchanged.
- The boundary runs with OpenRegister RBAC and multitenancy switched off for its
  reads, so its scoping checks must stay in portaliq and stay tested.
