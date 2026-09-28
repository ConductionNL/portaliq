# Design: identity-guest-page-for-signed-links

Read at portaliq `development` `4f460b3`, and shillinq `development` for the
declaring side (`sales-cancellation` D3 and D4, `receivables-payment-links`
D4).

## Where it sits today

- `lib/Controller/ContributionController.php:1437` `action()` resolves the
  subject and answers 401 without one; it then finds the endpoint action in
  the subject's aggregate, rebuilds the body from the `fields` whitelist,
  forwards, relays status and body, and writes a `forward` audit entry.
- `lib/Service/PortalActionForwarder.php:89` `forward(action, subject,
  whitelisted)` always sends `X-Portal-Subject` from
  `PortalSessionService::issueAssertion($subject)` (:767), which mints the
  frozen claim set `sub`, `audience`, `organisation`, `trust`, `jti`, `use`,
  `iat`, `exp`, `iss`.
- `lib/Middleware/PortalAuthMiddleware.php:61` guards only controllers that
  implement `PortalProtected`; `PortalIdentityController` is public with
  `AnonRateLimit` on every method.
- `lib/Contribution/PortalContributionRegistry.php:185`
  `aggregateAnonymous()` asks each provider, per audience it serves, for its
  anonymous entries; the audience vocabulary is open (ADR-046).
- `src/portal/lib/portalApi.js:716` `consumeOidcCallbackFragment()` reads a
  secret from the URL fragment once and clears it; `identity-ways-in-screens`
  D1 uses the same pattern for `#reference=`, `#invitation=` and
  `#activate=`.

## D1. A guest action is declared for the `guest` audience

A provider that lists `guest` among its audiences returns, for that audience,
endpoint actions marked `guest: true` with a `tokenField` (the body key the
token is sent under), a `label`, an optional `confirmText`, an optional
`fields` whitelist, and an optional `previewEndpoint`. The normaliser keeps a
guest action only with an instance-local endpoint (the same SSRF rule as every
endpoint action), a `tokenField`, and no `minTrust` above `low`; everything
else is dropped. A new `PortalContributionRegistry::guestAction(appId,
actionId)` returns one such action or null.

## D2. The link and the page

The link is `<portaliq>/apps/portaliq/portal#guest/<app>/<action>/<token>`,
built by the contributing app with the URL generator for
`portaliq.portalPage.index`. The SPA reads the fragment once on mount, clears
it with `history.replaceState`, and renders `GuestActionPage.jsx` instead of
the sign-in screen: the organisation's portal chrome, the preview when there
is one, the declared fields, the button with the declared label, and a
confirmation step.

## D3. Two public routes, no session

`GuestActionController`, not `PortalProtected`, every method `#[PublicPage]`,
`#[NoCSRFRequired]`, `#[AnonRateLimit(limit: 20, period: 60)]`:

- `POST /portal/api/guest/{appId}/{actionId}/preview` with `{ token }`
  forwards to the action's `previewEndpoint` and relays the answer. 404 when
  the action is unknown or has no preview.
- `POST /portal/api/guest/{appId}/{actionId}` with `{ token, ...fields }`
  rebuilds the body from the `fields` whitelist, stamps the token under
  `tokenField` over any client value, forwards, relays status and body, and
  records a `forward` audit entry whose subject is the hash of the token.

Both forward with a guest subject: `subjectRef` `guest:<sha256 of the
token>`, `audience` `guest`, `organisation` of the serving portal
(`PortalResolver::resolve()`, empty when none), `trust` `low` and a fresh
`jti`. `issueAssertion()` mints the frozen nine claims from it, so the wire
format is unchanged and a receiver can tell a guest by its audience.

Alternative considered: a short guest session like the reference link of
`identity-ways-in-screens`. Rejected: the token is the contributing app's,
portaliq cannot verify it, and a session would let the visitor reach other
routes on a credential portaliq never checked.

## D4. The answer

- 2xx with a string `redirectUrl` that is `https`: the page shows "Taking you
  to the payment page" and navigates there. This is how `pay` reaches the
  checkout (`receivables-payment-links` D4).
- 2xx otherwise: the page shows `message`, or the action's `successText`, and
  no button.
- A preview answer with `available: false`: the page shows `reason` and no
  button, which is how a withdrawal after the period, or of an exempt
  booking, reads (shillinq REQ-SCX-005).
- Any refusal: the app's `message` or "This link cannot be used.", the same
  sentence for an unknown app or action, so the page is no oracle.

## Declarative-vs-imperative decision (ADR-031)

| Behaviour | Path | Rationale |
|---|---|---|
| Which acts a guest can do | Declarative, the contribution's `guest` audience | The contributing app decides. |
| Forwarding with a guest assertion | Imperative, `GuestActionController` | A public route with a stamped body. |

## Risks

- **A public forward to leaf apps.** Only declared guest actions are
  reachable, only to instance-local endpoints, rate limited, and the leaf
  verifies the token before anything changes (shillinq risk 3 in
  `sales-cancellation`).
- **An open redirect.** Only an `https` `redirectUrl` in the answer of the
  instance-local endpoint the action declares is followed; the browser never
  names it.
- **Tokens in logs.** The token travels in the fragment and in a POST body,
  and the audit keeps only its hash.

## What it deliberately does not do

- It does not create an account or a session.
- It does not check the token.
