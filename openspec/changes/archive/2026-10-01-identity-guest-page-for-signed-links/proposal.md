---
kind: code
depends_on: []
---

# Proposal: identity-guest-page-for-signed-links

> Retargeted 2026-10-01 (`site-reaches-portal-parity`): new frontend work in this change lands in the Vue site `src/site/`, not in the React portal `src/portal/`, which is being retired.

## Why

Some acts belong to a person who has no portal account and should not need
one. A consumer who booked a haircut through a shop's booking widget has the
legal right to withdraw from it in the first 14 days, from where they booked.
A customer who got an invoice by mail wants to pay it online. Both hold a link
the organisation mailed them. Today that link has nowhere to go: every portal
action needs a signed-in subject (`ContributionController::action()` answers
401 without one), and the only link-based entry, the case reference link, is
bound to one case and to portaliq's own secret.

Two merged shillinq changes ask portaliq for this page and name it as
portaliq's half.

**`sales-cancellation`** (ConductionNL/shillinq, open, from shillinq matrix
row `sal-withdrawal-button`, demand
<https://www.moneybird.nl/changelog/herroepingsknop-voor-online-verkopen/>).
Article 11a of Directive 2011/83/EU, applicable from 19 June 2026, requires a
withdrawal function on the online interface where a distance contract was
concluded. Its design D4, verbatim:

> A widget booker usually has no portal account. The booking confirmation mail and the widget's confirmation step carry a link signed for one appointment (HMAC over appointment id and expiry, expiring with the withdrawal period), to portaliq's guest page for the `withdraw` action. shillinq verifies the signature on the forward.

Its cross-project dependency: "If portaliq has no guest subject for a signed
link, that is portaliq's change; shillinq issues and verifies the signed
link."

**`receivables-payment-links`** (ConductionNL/shillinq, open, from shillinq
matrix rows `sal-pay-link` and `rec-payment-request` and portaliq row
`cmp-cas-pay`). Its requirement REQ-RPL-006: "The initiation endpoint SHALL
accept, besides a portal subject, a pay token signed for one invoice that
portaliq forwards from its guest page". Its cross-project line, verbatim:
"portaliq: a guest page that forwards the `pay` action for a signed link, the
same guest mechanism `sales-cancellation` asks portaliq for." Its design
leaves the shape open: "Where portaliq serves the guest page for a signed link
(a route of its own, or a generic signed-subject page) is portaliq's call."

Decision `build` in the owner-moves pass of 2026-09-28: a half that two
merged sibling changes depend on.

## What changes

- **One guest page for any contributing app.** A link of the form
  `/apps/portaliq/portal#guest/<app>/<action>/<token>` opens the portal on a
  page for that one action. The token stays in the fragment, so it never
  reaches a server log or a referrer.
- **The app decides what the token means.** A contribution declares a guest
  action: its endpoint, its label and, when it has one, a preview endpoint.
  Portaliq forwards the token and verifies nothing about it; the app that
  signed it checks it.
- **The page shows what the link is for.** With a preview endpoint the page
  shows the app's summary (the appointment, the invoice) and whether the act
  is still possible, with the app's reason when it is not.
- **Then one confirmed act.** The button carries the app's label, a
  confirmation step comes first, and the answer is shown. A payment answer
  that carries a checkout address sends the visitor there.
- **No account and no session.** Nothing is created or signed in. The
  forward carries a guest assertion in the frozen wire format, so a receiver
  can tell a guest from a resident.

## Halves this closes

| Requesting repo | Requesting change | Half |
|---|---|---|
| ConductionNL/shillinq | `sales-cancellation` | a guest page for a signed withdrawal link, so a consumer without a portal account can withdraw |
| ConductionNL/shillinq | `receivables-payment-links` | a guest page that forwards the `pay` action for a signed pay link |

## Existing work it builds on

- `portal-contribution-contract` (spec): contributions, endpoint actions, the
  forwarder and the frozen assertion format.
- `identity-ways-in-screens` (open): fragment-carried secrets consumed once by
  the SPA on mount, the pattern this page follows.
- `case-actions-row-inputs-and-conditions` (this pass): the dialog parts
  (declared fields, confirmation text, the answer shown), reused here.

## Out of scope

- Minting or verifying the signed link. The contributing app does both.
- A guest session that can do more than the one act.
- Guest acts that need a trust level above `low`.

## Sibling halves

- **ConductionNL/shillinq**: declare the two guest actions (`withdraw`, `pay`)
  with a `tokenField`, and, for `withdraw`, a preview endpoint that answers the
  appointment's summary and whether it can still be withdrawn with the reason
  (`WithdrawalService::isWithdrawable()` already carries the reason). Its
  links use the URL form above. Not written here.
