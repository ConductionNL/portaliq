# Design: identity-staff-account-screens

Read at portaliq `development` `eeda3fa`.

## Where it sits today

- `lib/Controller/PortalAccountAdminController.php`: `provision(audience,
  organisation, identityType, identityRef, email, verifiedEmail, displayName)`
  (:95), `void(subjectRef, reason)` (:143, refuses `not_pending` and an empty
  reason), `invite(email, organisation, audience)` (:179, answers the token and
  expiry), `invitations(organisation)` (:214, the sender's own). Every method
  `#[NoAdminRequired]` plus `ActionAuthService::requireAction($user,
  'portal.provision')`.
- `PortalInvitationService`: `invite()` (:90), `sentBy()` (:149), `open()`,
  `accept()` (:223); the state `revoked` exists in the schema enum and is
  honoured by `accept()` (:291) but nothing sets it.
- `portal.authentication.registration` in `lib/Settings/portaliq_register.json`
  declares `policy` (`off`, `approval`, `activation`) and `allowedDomains`.
- `src/manifest.json` pages: `PortalAccounts` (`/accounts`, index over
  `portalAccount`), `PortalAccountDetail`, `Portals`, `PortalDetail`. No page
  for `portalInvitation`. Dialogs live in `src/dialogs/`, modals in
  `src/modals/` (ADR-004 modal isolation).

## D1. Dialogs over the guarded actions, not over the object form

"Issue an account" (`src/dialogs/IssueAccountDialog.vue`) posts to
`/api/accounts/provision`: audience, organisation, identity type and reference
when known, else a verified e-mail. The generic "Add" on the Portal accounts
index is hidden for `portalAccount` (index page `actions` config), so the
validated path is the only one staff meet.

"Invite someone" (`src/dialogs/InviteDialog.vue`) posts to `/api/invitations`.

## D2. Portaliq mails the invitation; the clerk never sees the secret

`invite()` calls `PortalIdentityMailer` with the `invitation` template and
answers `{ state: 'sent', expiresAt }` without the token. A secret shown to a
clerk is a secret a clerk could use, and the mail is what proves the address.

## D3. Invitations are listed and can be withdrawn

A manifest page `Invitations` (`/invitations`) is an index over
`portalInvitation` scoped to the organisation, showing address, state, sent,
expires and sender. `POST /api/invitations/{id}/revoke` (new, same guard)
sets `revoked` through a new `PortalInvitationService::revoke()`; an accepted
invitation cannot be revoked.

## D4. Withdraw a pending account where it is shown

`PortalAccountDetail` gains a "Withdraw this account" action, shown only for
`status: pending`, opening `src/dialogs/VoidAccountDialog.vue` with a required
reason, posting to `/api/accounts/void`.

## D5. Registration settings and the approval list

`PortalDetail` gains a Registration tab: the policy as a choice of three and
the allowed domains as a list, saved on the portal record. Beside it,
"Waiting for approval" lists `portalAccount` rows with `status: pending` and
`provisionedBy: self-registration` for that portal's organisation, with
"Approve" and "Refuse". Two new routes, same guard:
`POST /api/accounts/{subjectRef}/approve` sets `status: active` and
`verifiedEmail` stays as the registrant left it; `/refuse` voids the account
with the reason given.

## Risks

- Approving a self-registration activates an account on an unverified address
  under the approval policy. The dialog says the address is not verified, and
  sign-in still needs the e-mail provider's own verified claim to match.

## What it deliberately does not do

- It does not add a new ADR-023 action; `portal.provision` covers all of it.

## As built (2026-09-30)

- D4 is a widget, not a header action: `PortalAccountWithdraw` on
  `PortalAccountDetail`. In nc-vue 2.57 a detail page's `headerActions`
  handler resolves against `manifest.actions`, not the handler map the index
  pages read, and `open-modal` passes static props, so no header action can
  open a dialog that knows the record. The widget shows the button only on
  `status: pending` and says why otherwise.
- D5 is a widget too, `PortalRegistration` on `PortalDetail`, not a tab. The
  policy is saved per portal (the `portal.authentication.registration` block
  `PortalRegistrationPolicyService` reads), so the matrix row's "per
  organisation" is met per portal.
- "Withdraw invitation" is hidden on an accepted row (`visibleWhen`), and the
  server refuses it anyway (`already_accepted`).
- The invitee's "This invitation is no longer valid." is the acceptance
  screen of identity-ways-in-screens T06; here the revoked state is what
  `accept()` refuses.
