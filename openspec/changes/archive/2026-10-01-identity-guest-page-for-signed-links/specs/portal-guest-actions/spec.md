---
status: proposed
---

# Spec: portal-guest-actions

## Purpose

A person without a portal account does one act from a link a contributing app
signed and mailed them: withdraw from a booking, pay an invoice. Portaliq
serves the page and forwards the act; the app that signed the link checks it.
Requested by shillinq `sales-cancellation` and `receivables-payment-links`.

## ADDED Requirements

### Requirement: A contribution declares its guest actions (REQ-GST-001)

Portaliq SHALL accept an endpoint action for the `guest` audience only when
it is marked `guest: true`, names a `tokenField`, targets an instance-local
endpoint and asks for no trust above `low`, and SHALL drop every other guest
declaration.

#### Scenario: A guest action asking for substantial trust is dropped
- **GIVEN** a contribution declaring a guest action `withdraw` with `minTrust: substantial`
- **WHEN** the manifest is normalised
- **THEN** no guest action `withdraw` exists and its link opens the "This link cannot be used." page
- @e2e exclude normaliser contract; pinned by PortalManifestNormaliserTest

### Requirement: A signed link opens a page for its one act, without an account (REQ-GST-002)

A link `/apps/portaliq/portal#guest/<app>/<action>/<token>` SHALL open a page
for that declared guest action without signing anyone in. The SPA SHALL read
the fragment once and remove it from the address bar. Portaliq SHALL forward
the token under the action's `tokenField`, over any client value, with an
assertion whose audience is `guest`, and SHALL NOT create an account or a
session.

#### Scenario: A guest finds the withdrawal button from the confirmation mail
- **GIVEN** M. Visser, who booked "Knippen en kleuren" through the widget of Kapsalon Knip and has no portal account, and shillinq's declared guest action `withdraw` with a preview endpoint
- **WHEN** she opens the withdrawal link in her confirmation mail
- **THEN** the page shows her appointment as shillinq describes it and the button "Withdraw from contract here", and no portal session exists afterwards

#### Scenario: The token cannot be swapped
- **GIVEN** a guest page opened with token `A`
- **WHEN** a hand-crafted post to the guest route carries `token: A` and the token field set to `B`
- **THEN** shillinq receives `A` under the token field
- @e2e exclude tamper assertion; pinned by GuestActionControllerTest

### Requirement: The guest route reveals nothing about what exists (REQ-GST-003)

The guest routes SHALL be rate limited per client, SHALL answer an unknown
app, an unknown action and an action without a preview with the same 404, and
SHALL record each forward with a hash of the token, never the token.

#### Scenario: A probe for an unknown action
- **GIVEN** no contribution declares a guest action `refund`
- **WHEN** a visitor posts to `/portal/api/guest/shillinq/refund` with any token
- **THEN** the answer is the same 404 as for an app that does not exist, and nothing is forwarded
- @e2e exclude refusal at the server seam; pinned by GuestActionControllerTest

### Requirement: The page shows the app's answer (REQ-GST-004)

The page SHALL show the preview's summary and, when the preview says the act
is not available, the reason and no button. After a confirmed act it SHALL
follow an `https` `redirectUrl` in the answer, and otherwise show the answer's
message; on a refusal it SHALL show the app's message or "This link cannot be
used."

#### Scenario: A customer pays from the invoice mail
- **GIVEN** a customer without a portal account who received invoice 2026-0412 by mail with a pay link, and shillinq's guest action `pay`
- **WHEN** they open the link and confirm
- **THEN** the page says it is taking them to the payment page and opens the checkout address shillinq answered

#### Scenario: A booking that can no longer be withdrawn
- **GIVEN** a consumer with a withdrawal link for "Workshop bloemschikken 12 oktober", which shillinq marks exempt
- **WHEN** they open the link
- **THEN** the page shows shillinq's reason and no withdraw button
