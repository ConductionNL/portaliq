---
kind: code
---

# Proposal: portal-identity-space

Competitor gap register, row Q1.14 "can an external party file and follow
a case with an identity that is not a staff account"
(`procest/_gaps/gap-register.md` in ConductionNL/market-intelligence,
2026-09-13, read from `_round4/compare/proposed-rows-batch8.md`). Rated
partial, owner portaliq, size M. Opened by the small-owner lane of the
OpenSpec phase.

## Summary

A citizen or a company is a record in the portal before they ever log in.
A clerk at the desk, an intake feeder or a case app can create that
record, attach cases to it, and the person finds them under their own
name on first login. Following a case by a token stays as the anonymous
fallback; it stops being the only way.

## Why

dossiq scores `yes` on row 13.13 by a token share
(`lib/Service/Sharing/CaseTokenShareService.php`, `#PublicStatus`): a
citizen follows a case by a link and files nothing. No citizen identity
exists; the platform's guest accounts are staff accounts with fewer groups
(register note). The best competitor in the register: OTOBO 11.0,
`customer_user` is a first-class record without a login, driven in batch
5 (`_round4/compare/proposed-rows-batch8.md`).

Portaliq already has the identity: `portalAccount` (`lib/Settings/portaliq_register.json`)
with `identityType`, `identityRef`, `subjectRef`, `organisation`, `claims`
and a `status`. `PortalAccountService` finds or creates it, but only from
a validated OIDC login (`supplier-portal`, the callback scenario). So the
record exists exactly from the moment a person first logs in, never
before. A case filed at the counter has nobody to belong to until then,
and the clerk falls back to the token.

## Scope

- Pre-provisioning: `PortalAccountService::provision()` creates a
  `portalAccount` in a new `status: pending` from an identity reference
  (a BSN through OpenRegister's `BsnFormat`, a KvK number) or, failing
  that, from a verified contact (email), with `identityType` set and
  `identityRef` set or empty. Callable by a staff user with the
  `portal.provision` action, by an app through a typed event
  (ADR-041), and by an intake journey that writes a subject.
- Matching on first login: the OIDC callback's find-or-create SHALL match
  a `pending` account on `(identityType, identityRef)` before creating,
  and activate it. An email-only pending account is matched on the
  verified email claim when the broker supplies one, and otherwise stays
  pending and unreachable.
- Claims by the owning app: an app writes its claim on the account
  (`claims.<appId>.<claimName>`) server-side through a typed event with a
  result slot; the contract already forbids client-supplied claims.
- A "My cases" surface for the `client` audience over the case app's
  contribution, scoped by `scopeClaim`, so a case attached to a pending
  account is there on first login.
- The token share stays. A case with both a claim and a token is reachable
  both ways; the token page says so and offers login.

## How dossiq consumes it

The register's dossiq half: "contribute the case to that identity through
the portal provider; the token share stays the anonymous fallback". dossiq's
portal provider (archived `move-portals-to-portaliq`) scopes its `cases`
collection by a `linkedRequesterId` claim, and its intake, at the desk and
from the journey, dispatches the provision and claim events for the
requester. One task in dossiq's umbrella `competitor-parity-2026-09`.

## ADRs

- ADR-046: the identity space is the portal's; the app contributes.
- ADR-108: citizen-facing surfaces belong to portaliq.
- ADR-041: provisioning and claims are typed events with a result slot,
  never a call into portaliq's controllers.
- ADR-005: fail closed; a pending account has no session and no reach.
- ADR-064: identity references are stored through OpenRegister's formats,
  never raw.

## Existing specs it extends

`portal-contribution-contract` (server-managed claims, `scopeClaim`) and
`supplier-portal` (the find-or-create at login).

## Out of scope

- The IdP conversation. DigiD and eHerkenning stay integriq's
  `digid-eherkenning-auth-adapter`; this change matches on what the
  envelope carries.
- Merging two accounts that turn out to be one person. A later change,
  over OpenRegister's `mdm-merge`.
