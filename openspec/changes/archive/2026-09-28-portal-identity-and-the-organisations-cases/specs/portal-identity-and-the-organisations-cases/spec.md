---
status: proposed
---

# Spec: portal-identity-and-the-organisations-cases

**Status:** proposed
**Scope:** portaliq (owner); the case app declares the identity kind and reads the claim
**Depends on:** `portal-identity-space` (the `portalAccount` and its provisioning); `portal-contribution-contract` (scoping); `supplier-portal` (the organisation record); `portal-auth-edge-session-hardening` (the session)

## Purpose

A citizen or a company reaches their cases with an identity of their own,
of a kind the case type chooses, and a portal user who belongs to an
organisation sees that organisation's cases. Requested by the dossiq
competitor analysis, round 4 cluster 7, decision D8.

## ADDED Requirements

### Requirement: The case type chooses the identity kind (REQ-PIOC-001)

A case type's portal declaration SHALL carry an `identityKind` of
`account`, `reference`, or both. `reference` SHALL admit a citizen on a
case number and a verified e-mail address through a one-time link,
without an account. `account` SHALL require a portal session. The portal
SHALL offer only the kinds the case type declares.

#### Scenario: A melding needs no account
- **GIVEN** a case type declaring `reference`
- **WHEN** a citizen follows a one-time link with their case number and verified address
- **THEN** the case is shown and no account is created
- e2e: `tests/e2e/portal-identity-and-the-organisations-cases.spec.ts`

#### Scenario: A vergunning refuses the reference route
- **GIVEN** a case type declaring `account` only
- **WHEN** a citizen asks for a reference link
- **THEN** the route is not offered and no link is sent
- e2e: `tests/e2e/portal-identity-and-the-organisations-cases.spec.ts`

#### Scenario: A portal only answers for the case types it declares
- **GIVEN** a request naming a register, schema and case type the portal has published no form binding for
- **WHEN** a citizen asks for a reference link
- **THEN** the case type is never read and the answer is the same refusal an `account` only case type gets
- @e2e exclude the refusal is a server-side scope decision with no portal page behind it; asserted in `tests/Unit/Controller/PortalIdentityControllerTest.php::testACaseTypeThePortalNeverDeclaredIsNeverRead` and `tests/Unit/Service/Intake/PortalFormBindingResolverTest.php::testOnlyTheExactDeclaredTripleIsInScope`

#### Scenario: A reference link works once
- **GIVEN** a reference link already used
- **WHEN** it is followed again
- **THEN** access is refused and a new link must be requested

### Requirement: A portal user sees the cases of the organisation they are mandated for (REQ-PIOC-002)

A portal identity SHALL see the cases its mandates cover, not only the
cases it filed. The scope SHALL be built from the mandates the identity
holds, and an identity with no mandate recorded for an organisation SHALL
see none of that organisation's cases. Each case in an organisation view
SHALL name the mandate that grants it.

#### Scenario: Two employees, one company, both cases
- **GIVEN** two portal identities mandated for the same organisation
- **AND** each has filed one case for it
- **WHEN** either opens their case list
- **THEN** both cases are listed
- e2e: `tests/e2e/portal-identity-and-the-organisations-cases.spec.ts`

#### Scenario: A colleague with no mandate sees nothing
- **GIVEN** a third identity at the same organisation with no mandate recorded
- **WHEN** they open their case list
- **THEN** neither case is listed
- e2e: `tests/e2e/portal-identity-and-the-organisations-cases.spec.ts`

#### Scenario: A mandate narrower than the organisation
- **GIVEN** an identity mandated for one case type only
- **WHEN** they open their case list
- **THEN** only cases of that type are listed
- e2e: `tests/e2e/portal-identity-and-the-organisations-cases.spec.ts`

#### Scenario: The view says why
- **GIVEN** a case visible through an organisation mandate
- **WHEN** it is listed
- **THEN** the mandate that grants it is named beside it
- e2e: `tests/e2e/portal-identity-and-the-organisations-cases.spec.ts`

### Requirement: A portal account can be issued without a national login (REQ-PIOC-003)

A staff user with the provisioning action SHALL be able to issue a portal
account at the desk, and to invite an e-mail address into the portal. The
state of an invitation SHALL be visible to the person who sent it, as
sent, opened, accepted or expired. An invitation SHALL expire.

#### Scenario: The desk issues an account
- **GIVEN** a citizen who cannot use a national login
- **WHEN** a clerk issues them a portal account
- **THEN** the account exists in a pending state and the citizen is told how to reach it
- e2e: `tests/e2e/portal-identity-and-the-organisations-cases.spec.ts`

#### Scenario: The sender sees what happened to the invitation
- **GIVEN** an invitation sent to an address
- **WHEN** the sender opens the invitation list
- **THEN** its state is shown
- e2e: `tests/e2e/portal-identity-and-the-organisations-cases.spec.ts`

#### Scenario: An expired invitation admits nobody
- **GIVEN** an invitation past its expiry
- **WHEN** it is opened
- **THEN** access is refused and the state reads expired

### Requirement: Self-registration is governed by a policy (REQ-PIOC-004)

The portal SHALL carry a registration policy of `off`, `approval` or
`activation`, and an allowed e-mail domain list. With `off` no account is
created by a stranger. With `approval` a new account waits for a decision.
With `activation` it waits for a mail the registrant confirms. A domain
list that is not empty SHALL refuse an address outside it.

#### Scenario: Registration switched off
- **GIVEN** the policy `off`
- **WHEN** a stranger opens the registration page
- **THEN** registration is not offered and no account is created
- e2e: `tests/e2e/portal-identity-and-the-organisations-cases.spec.ts`

#### Scenario: An account waits for approval
- **GIVEN** the policy `approval`
- **WHEN** a stranger registers
- **THEN** the account is pending and cannot sign in until it is approved
- e2e: `tests/e2e/portal-identity-and-the-organisations-cases.spec.ts`

#### Scenario: An address outside the list is refused
- **GIVEN** an allowed domain list holding one domain
- **WHEN** a registration arrives from another domain
- **THEN** it is refused and no account is created
- e2e: `tests/e2e/portal-identity-and-the-organisations-cases.spec.ts`

### Requirement: The challenge runs here, not at a vendor (REQ-PIOC-005)

A public form and the self-registration page SHALL be protected by a
challenge the portal runs itself: proof of work, or a honeypot. No
request SHALL be made to a third-party challenge service, and the work
factor SHALL be configurable per surface.

The nonce SHALL be signed by the instance, over the nonce, the surface it
was issued for, and the moment it stops counting. A submission SHALL be
refused unless that signature verifies, the surface matches, and the
expiry has not passed. Without this the challenge binds nothing: nothing
is stored, so a caller may invent a nonce, do the work over it once, and
send the same pair indefinitely.

#### Scenario: A nonce the instance never issued is refused
- **GIVEN** proof of work enabled on a public form
- **WHEN** a submission arrives with a nonce the caller made up, correctly solved
- **THEN** it is refused, because the work was done over a nonce nothing signed
- @e2e exclude a forged credential has no portal page behind it; asserted in `tests/Unit/Service/Identity/PortalChallengeServiceTest.php::testANonceThisInstanceNeverIssuedIsRefusedHoweverWellItIsSolved`

#### Scenario: A solved nonce stops counting at its expiry
- **GIVEN** a nonce this instance issued and the visitor solved
- **WHEN** it is sent again after its expiry
- **THEN** it is refused and the work has to be done again on a fresh nonce
- @e2e exclude a clock-dependent refusal with no page behind it; asserted in `tests/Unit/Service/Identity/PortalChallengeServiceTest.php::testASolvedNonceStopsCountingAtItsExpiry`

#### Scenario: A submission without a solved challenge is refused
- **GIVEN** proof of work enabled on a public form
- **WHEN** a submission arrives without a valid solution
- **THEN** it is refused and no case is created
- e2e: `tests/e2e/portal-identity-and-the-organisations-cases.spec.ts`

#### Scenario: Nothing is sent to a third party
- **GIVEN** the challenge enabled on either surface
- **WHEN** the page is rendered and solved
- **THEN** no request leaves for a challenge vendor
- @e2e exclude Absence of an outbound call; covered by PHPUnit and the network policy test

### Requirement: A citizen manages their own portal account (REQ-PIOC-006)

A signed-in portal identity SHALL be able to change its own details, with
a new e-mail address used only after it is confirmed through a link. It
SHALL be able to ask for the account to be removed. The product SHALL
carry that out: the account and its claims go, and the cases remain.

#### Scenario: A new address is confirmed before it is used
- **GIVEN** a citizen who changes their e-mail address
- **WHEN** the change is saved
- **THEN** the old address stays in use until the new one is confirmed by the link
- e2e: `tests/e2e/portal-identity-and-the-organisations-cases.spec.ts`

#### Scenario: The account goes and the case stays
- **GIVEN** a citizen with one case who asks for removal
- **WHEN** the request is carried out
- **THEN** the account and its claims are gone
- **AND** the case still exists for the municipality
- e2e: `tests/e2e/portal-identity-and-the-organisations-cases.spec.ts`

### Requirement: Asking for access is recorded, not mailed (REQ-PIOC-007)

A person who cannot see something they believe they should SHALL be able
to ask for access from the portal. The request SHALL reach the owner in
the product, SHALL carry who asked and what for, and SHALL be answerable
with a grant or a refusal that the asker sees.

#### Scenario: The owner receives the request
- **GIVEN** a person asking for access to an organisation's cases
- **WHEN** they submit the request
- **THEN** the owner sees it in the product with the asker and the reason
- e2e: `tests/e2e/portal-identity-and-the-organisations-cases.spec.ts`

#### Scenario: A refusal reaches the asker
- **GIVEN** a pending access request
- **WHEN** the owner refuses it
- **THEN** the asker is told, and nothing new is visible to them
- e2e: `tests/e2e/portal-identity-and-the-organisations-cases.spec.ts`

### Requirement: A person switches the organisation they act under, inside the session (REQ-PIOC-008)

An identity holding more than one mandate SHALL be able to switch which
one it acts under without signing out. The portal SHALL show which
mandate is active, and SHALL record it on every write made under it.

#### Scenario: Switching changes what is listed
- **GIVEN** an identity mandated for two organisations
- **WHEN** they switch to the second
- **THEN** the case list shows that organisation's cases and not the first's
- e2e: `tests/e2e/portal-identity-and-the-organisations-cases.spec.ts`

#### Scenario: The write records the mandate
- **GIVEN** an identity acting under one of two mandates
- **WHEN** they write on a case
- **THEN** the write records the mandate it was made under
- e2e: `tests/e2e/portal-identity-and-the-organisations-cases.spec.ts`
