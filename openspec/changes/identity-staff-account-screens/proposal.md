# Proposal: identity-staff-account-screens

## Why

Staff cannot do any of the account work the portal was built for. The
purpose-built actions exist and are guarded: `PortalAccountAdminController`
`provision()`, `void()`, `invite()` and `invitations()` behind the ADR-023
action `portal.provision` (`appinfo/routes.php:50-58`), from
`portal-identity-space` (open, 8 of 8) and
`portal-identity-and-the-organisations-cases` (open, 15 of 15). Read at
`eeda3fa`, no admin page calls them. The only working path is the generic
OpenRegister object form on the Portal accounts page, which skips the
identity de-duplication and the authorisation those actions carry. The
invitation secret is handed back to the clerk to mail by hand
(`PortalAccountAdminController::invite()` answers `$invited`, the token), no
page lists invitations (`src/manifest.json` has no `portalInvitation` page), and
a registration waiting for approval has nobody who can approve it.

Four rows in the portaliq parity matrix (`openspec/parity/capabilities.json`,
compared 2026-09-26) name it; the OpenSpec pass of 2026-09-27 decided `build`
for all four.

**`id-staff-invite`**, "Let staff invite an e-mail address into the portal,
with the invitation's state visible and an expiry." Portaliq `no`, built.state
`built`: "No manifest page exists for portalInvitation at all [...] so staff
cannot even browse invitations generically, let alone send one." Core area
(identity). xxllnc Zaken PIP is `yes`: "backend/perl-api/lib/Zaaksysteem/Controller/Betrokkene.pm:332
send_activation_link; backend/perl-api/lib/Zaaksysteem/Backend/Sysin/Auth/Alternative.pm:778
link expires after 72 hours; [...] is_active shown [reached on staff contact
view, 'Alternatieve authenticatie']".

**`id-staff-provision-desk`**, "Let staff issue a portal account at the desk
for someone who cannot use a national login." Portaliq `partial`, `built`:
"The dedicated, validated provision() action (identity de-duplication, ADR-023
authorization) is unreached; the only working path is the generic OR
object-create form, which bypasses that validation." Three competitors `yes`:

- Open Inwoner Platform: "src/open_inwoner/accounts/admin.py:102 _UserAdmin
  with src/open_inwoner/accounts/admin.py:76 _UserCreationForm (e-mail and
  password account, login_type default)".
- xxllnc Zaken PIP: "backend/perl-api/lib/Zaaksysteem/Controller/Betrokkene.pm:244
  generate_alternative_authentication [...] e-mail/password plus SMS account for
  people without DigiD".
- Liferay DXP: "https://learn.liferay.com/w/dxp/security-and-administration/users-and-permissions/users/adding-and-managing-users:
  administrators add users in Control Panel".

**`id-staff-void`**, "Let staff withdraw a portal account that was provisioned
by mistake." Portaliq `partial`, `built`: "a purpose-built, audited action
exists but is unreached, while a cruder generic path stands in for it." Three
competitors `yes`: Open Inwoner Platform ("src/open_inwoner/accounts/models.py:276
is_active; admin delete"), xxllnc Zaken PIP ("alternative_authentication.tt:101
'Accountstatus' Actief/Inactief (staff only)") and Liferay DXP ("'Deactivate:
Disable the user's account'").

**`id-registration-policy`**, "Turn self-registration off, or require approval
or activation, per organisation, with an allowed e-mail domain list." Portaliq
`no`, `built`: "A real policy engine gating a dead endpoint; no admin UI to set
the policy either." Core area (identity). Open Inwoner Platform, xxllnc Zaken
PIP and Liferay DXP are `partial`; Liferay offers "'Allow strangers to create
accounts?', 'Allow strangers to create accounts with a company email address?'
and 'Require strangers to verify their email address?'".

## What changes

- On the admin app's Portal accounts page: "Issue an account" and "Invite
  someone", each a dialog over the guarded action. Portaliq mails the
  invitation; the clerk never sees the secret.
- An Invitations page listing what was sent, its state and its expiry, with
  "Withdraw invitation".
- On a pending account: "Withdraw this account", with a required reason.
- On a portal: a Registration section setting the policy and the allowed
  e-mail domains, and a "Waiting for approval" list with "Approve" and
  "Refuse".

## Rows this closes

| matrix | row | name | own rating | what is missing |
| --- | --- | --- | --- | --- |
| portaliq | `id-staff-invite` | Invite an e-mail address, with state and expiry visible | no | the dialog, the mail, the list |
| portaliq | `id-staff-provision-desk` | Issue a portal account at the desk | partial | the dialog over the validated action |
| portaliq | `id-staff-void` | Withdraw an account provisioned by mistake | partial | the action on the account |
| portaliq | `id-registration-policy` | Set the self-registration policy and allowed domains | no | the settings and the approval list |

## Existing work it builds on

- `portal-identity-space` shipped `provision()` and `void()`;
  `portal-identity-and-the-organisations-cases` shipped `invite()`,
  `invitations()` and `PortalRegistrationPolicyService`. This change adds the
  screens, the invitation mail, revoking an invitation and approving a
  registration. It does not change what `provision()` validates.
- `identity-profile-page` introduces `PortalIdentityMailer`;
  `identity-ways-in-screens` adds the invitation template this change sends.

## Out of scope

- Changing who may act. All four screens use the existing action
  `portal.provision`; granting it to a group is
  `operate-roles-for-content-and-actions`.
- Suspending an active account. `void()` is for pending accounts; an active
  account is removed by its owner or suspended on the record.
