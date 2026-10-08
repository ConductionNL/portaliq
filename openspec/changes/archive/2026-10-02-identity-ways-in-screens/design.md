# Design: identity-ways-in-screens

Read at portaliq `development` `eeda3fa`.

## Where it sits today

- `lib/Controller/PortalIdentityController.php`: `challenge(surface)` (:101),
  `requestReferenceLink(register, schema, caseType, caseReference, email)`
  (:126), `redeemReferenceLink(token)` (:182), `register(email, displayName,
  nonce, solution, expiresAt, signature)` and `acceptInvitation(token)`. All
  `#[PublicPage]` with `AnonRateLimit`, routed at `appinfo/routes.php:273-277`.
- `PortalChallengeService` issues `{ nonce, expiresAt, signature, difficulty,
  algorithm: 'sha256-leading-zero-bits' }` and checks a honeypot first.
- `PortalRegistrationPolicyService::policy()` reads
  `portal.authentication.registration.policy` (`off`, `approval`,
  `activation`) and `allowedDomains`; `register()` provisions a `pending`
  account with `verifiedEmail: false` and answers `{ status, awaiting }`.
- `PortalReferenceLinkService::issue()` stores the SHA-256 of a 48-character
  secret and answers the plain secret to its caller; `redeem()` spends it and
  answers `{ caseReference, organisation }`.
- `PortalInvitationService::accept()` provisions the account and marks the
  invitation `accepted`.
- The sign-in screen is the `!state.session` branch of `src/portal/App.jsx`
  (:357-378): a welcome text, one button per `config.oidcProviders`, and the
  dev login.

## D1. Every secret goes out by mail, in the fragment

`PortalIdentityMailer` (see `identity-profile-page` D1) gets three templates:
`reference-link`, `invitation` and `registration-activation`. The controllers
call it where they now drop the secret. Links are `/portal/#reference=<secret>`,
`/portal/#invitation=<secret>` and `/portal/#activate=<secret>`; the SPA
consumes each fragment once on mount, like `consumeOidcCallbackFragment()`
(`src/portal/lib/portalApi.js:716`).

## D2. A reference link opens one case, read only

`redeemReferenceLink()` mints a portal session through
`PortalSessionService` with `audience: client`, `trust: low`, a subjectRef of
the form `reference:<sha256 of the link id>` and a `caseReference` claim, and a
lifetime of 30 minutes with no refresh. The case read path accepts such a
session only for a collection whose case type admits the `reference` kind
(`portalCaseType.portalIdentityKind`) and that declares `referenceField`; it
reads exactly the row whose `referenceField` equals the claim. Every write
route refuses a reference session. The SPA opens that case on the case screen
(`CitizenCase.jsx`) without the write controls.

## D3. The doors appear only when they lead somewhere

The runtime config the SPA receives (`PortalRuntimeConfigResolver`) gains
`waysIn: { register: bool, reference: bool }`:

- `register` is true when the registration policy is not `off` and the portal
  has an OIDC provider whose claims include a verified `email`.
- `reference` is true when at least one of the portal's published form bindings
  names a case type admitting `reference`.

The case type choice in the reference form lists exactly those case types, by
their public label.

## D4. Activation is a link, approval is staff work

Under `activation`, `register()` mails the activation link and the account
stays `pending`. Following it sets `verifiedEmail: true` and `status: active`
through a new `PortalAccountService::activate(token)`. Under `approval`,
registration answers "We will let you know when your account is ready." and the
staff screen of `identity-staff-account-screens` activates it.

## D5. After any door, sign in with the e-mail provider

An account made by registration or invitation has a verified e-mail and no
identity reference. `PortalAccountService::findOrCreate()` already matches a
pending account on a verified `email` claim (`portal-identity-space`). The
success screen of each door says which button to use next: "Sign in with
{provider}".

## Risks

- A reference session is an anonymous-grade key to one case. It is short,
  never refreshed, read only and bound to one row.
- Registration invites bots. The existing challenge and honeypot stay in front,
  and `AnonRateLimit` on `register()` (10 per minute) stays.

## What it deliberately does not do

- It adds no password login.
- It does not show the case list to a reference session.

## As built, server half (2026-10-01)

- D4's `PortalAccountService::activate(token)` is its own class,
  `PortalAccountActivationService` (`issue(subjectRef)`, `activate(token)`):
  the account service already has a private `activate()` for the sign-in
  that matches a pending account, and the link's hash and expiry live on two
  new `portalAccount` fields, `activationTokenHash` and `activationExpiresAt`
  (register 0.55.0, `portalAccount` 0.14.0). Only a pending
  self-registration gets a link; a staff-provisioned or invited account never
  does. Following the link empties the hash and leaves the expiry, because an
  empty string is no `date-time`. The link works two days.
- D3's "an OIDC provider whose claims include a verified `email`" is the
  `generic` provider: DigiD, eHerkenning and eIDAS sign in on a BSN, a KVK
  number or a foreign identity. `PortalWaysInResolver` decides `waysIn` and
  also answers `emailSignIn` (the label the success screen names) and
  `referenceCaseTypes` (the reference form's choice).
- The identity routes resolve the portal the SPA names (`portal` in the query
  or the body), the way `/site` does; on a shared host the host alone names
  none. The challenge answer names the portal's `honeypotField`.
- Found on the way: `PortalAccountService::provision()` wrote `identityType:
  ''` for an address-only account, which the enum refuses, so every
  self-registration and invitation would have been refused by the register.
  It now leaves the identity fields out until a sign-in fills them.
- The screens (T02, T03's `#activate=`, T04, T06, T07's sign-in screen) are built on the Vue site in `src/site/` (portaliq#1021). A React version was written on portaliq#1022 and not merged.
