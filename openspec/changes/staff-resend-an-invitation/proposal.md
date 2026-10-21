# Proposal: staff-resend-an-invitation

## Why

The Invitations board ([portaliq/PtUitnodigingen](https://identity.conduction.nl/screens/board?id=portaliq/PtUitnodigingen)) gives every invitation row "Bekijken", "Opnieuw versturen" and "Uitnodiging intrekken". Portaliq can send and withdraw an invitation, not send it again: when the mail landed in a spam folder or the week ran out, the clerk has to withdraw it and invite the address anew.

## What Changes

- `POST /api/invitations/{id}/resend` with `organisation`, behind the same `portal.provision` action as invite and withdraw, checked before the invitation is read.
- `PortalInvitationService::resend()` mints a new secret, replaces the stored hash (the old link admits nobody), sets the state back to `sent` and starts a new window. An accepted or withdrawn invitation is refused.
- The new link goes to the invited address only; the answer carries the state and expiry, never the secret.
- Row action "Opnieuw versturen" on the Invitations list for an invitation that is sent, opened or expired.

## Impact

- `lib/Controller/PortalAccountAdminController.php`, `lib/Service/Identity/PortalInvitationService.php`, `appinfo/routes.php`
- `src/lib/staffAccountActions.js`, `src/manifest.json` (page Invitations), l10n
- No schema change.
