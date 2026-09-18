---
status: proposed
---

# Spec: portal-identity-space

**Status:** proposed
**Scope:** portaliq (owner); any app with a contribution provisions and claims
**Depends on:** `portal-contribution-contract` (server-managed claims, `scopeClaim`); `supplier-portal` (login find-or-create)

## Purpose

A citizen or a company is a portal identity before their first login, so a
case filed at the desk belongs to someone and is waiting under their name
when they log in. Requested by the dossiq competitor analysis, register
row Q1.14.

## ADDED Requirements

### Requirement: An account can be provisioned before any login (REQ-PIS-001)

`portalAccount.status` SHALL gain `pending`. `PortalAccountService::provision()`
SHALL create a `pending` account from an identity reference or a verified
email, SHALL return the existing account when one matches
`(identityType, identityRef, organisation)`, and SHALL refuse a call with
neither reference nor email. A `pending` account SHALL have no session and
SHALL be unreachable from the portal. Identity references SHALL be stored
through OpenRegister's formats.

#### Scenario: A clerk provisions a citizen at the desk
- **GIVEN** a staff user with the `portal.provision` action
- **WHEN** the clerk provisions a `client` for the organisation with a BSN
- **THEN** one `pending` `portalAccount` exists with `identityType = digid`, the reference stored through `BsnFormat`, and `provisionedBy` naming the clerk
- e2e: `tests/e2e/portal-identity-space.spec.ts`

#### Scenario: A pending account cannot be used
- **GIVEN** a `pending` account
- **WHEN** any portal request names its `subjectRef`
- **THEN** the response is 401 and nothing about the account is revealed
- @e2e exclude fail-closed session contract; covered by PHPUnit on `PortalSessionService` and the proxy controllers

### Requirement: First login matches the pending account (REQ-PIS-002)

The OIDC callback SHALL, before creating an account, match a `pending`
account on `(identityType, identityRef, organisation)` and activate it,
reusing its `subjectRef`. When no identity match exists and the envelope
carries a verified email, it SHALL match a `pending` account with that
email and `verifiedEmail = true`. Any other pending account SHALL stay
pending.

#### Scenario: The provisioned citizen logs in for the first time
- **GIVEN** a `pending` account for BSN X and a DigiD login yielding an envelope for BSN X
- **WHEN** the callback completes
- **THEN** that account is `active`, its `subjectRef` is the session's subject, and no second account exists
- @e2e exclude the broker round trip is stubbed; covered by PHPUnit on the callback matching with a stub envelope

#### Scenario: A different person does not inherit the account
- **GIVEN** a `pending` account for BSN X
- **WHEN** a login for BSN Y completes
- **THEN** a new account for Y exists and X's account stays `pending`
- @e2e exclude covered by the same PHPUnit suite

### Requirement: The owning app writes its claim through a typed event (REQ-PIS-003)

Portaliq SHALL handle `PortalAccountClaimRequestedEvent` by writing
`claims.<appId>.<claimName>` on the named account server-side, taking
`appId` from the dispatching app's context, and SHALL answer the result
slot. Client input SHALL never reach `claims` (contract rule). Portaliq
SHALL handle `PortalAccountProvisionRequestedEvent` by calling
`provision()` and answering the `subjectRef`.

#### Scenario: dossiq links a case's requester to the account
- **GIVEN** a `pending` account and a case whose requester it is
- **WHEN** dossiq dispatches the claim event with `linkedRequesterId`
- **THEN** the account carries `claims.dossiq.linkedRequesterId` and the result slot reads `ok`
- @e2e exclude cross-app typed event; covered by PHPUnit with a stub dispatcher

### Requirement: "My cases" lists what the claim scopes (REQ-PIS-004)

Portaliq SHALL render a "My cases" page for the `client` audience over the
collections the contributions mark `kind: cases`, each scoped by its
`scopeClaim`, so a case attached before the first login is listed on the
first login. The link page SHALL keep working and SHALL offer login
UNCONDITIONALLY, to every reader, whether or not the subject holds an
account.

The conditional version of this sentence, "offer login when the case's
subject has an account", is REFUSED and must not be restored. The page is
`#[PublicPage]` and its reader is whoever holds the link, which can be
forwarded. Varying the offer on whether a named person holds an account
tells that holder something about that person, and comparing two links
tells them which subjects have accounts. That is account enumeration, and
it is the same failure as a login form that says whether an email is
registered.

The unconditional offer costs nothing: a reader who has no account follows
it and is told how to get one, which is the same page they need anyway. The
scenario below is unchanged, because it only ever asked that a login link be
offered.

#### Scenario: The case filed at the desk is there on first login
- **GIVEN** a case attached to a `pending` account by claim, and that person's first login
- **WHEN** they open "My cases"
- **THEN** the case is listed
- e2e: `tests/e2e/portal-identity-space.spec.ts`

#### Scenario: The token still works
- **GIVEN** a case shared by token to the same person
- **WHEN** the token page is opened without a session
- **THEN** the case status renders as today and a login link is offered
- e2e: `tests/e2e/portal-identity-space.spec.ts`
