# Proposal: identity-ways-in-screens

## Why

A person who does not yet have a portal account, or who has only a case number,
cannot get in. `portal-identity-and-the-organisations-cases` (open, 15 of 15
tasks checked) built three ways in on the server: self-registration under the
portal's policy (T08, T09), the reference route of a case number plus a
verified e-mail address (T02) and invitations (T07). Read at `eeda3fa`, none of
them reaches a person:

- No screen in `src/portal`, `src/site` or `src/manifest.json` calls
  `POST /portal/api/identity/register`, `/reference-link`,
  `/reference-link/redeem` or `/invitation/accept` (`appinfo/routes.php:274-277`).
- The secrets are never mailed. `requestReferenceLink()` answers "The secret
  goes out by mail, never in this answer"
  (`lib/Controller/PortalIdentityController.php`), but no code in `lib/` mails
  it: `IMailer` is used only by `NotificationDispatchJob`,
  `PortalTaskDeliveryJob` and `TrafficReportDelivery`.
- A redeemed reference link admits to nothing. `PortalReferenceLinkService::redeem()`
  returns `caseReference` and `organisation` and mints no session, so there is
  no way to read the case it names.
- The `activation` registration policy has no activation step anywhere.

Four rows in the portaliq parity matrix (`openspec/parity/capabilities.json`,
compared 2026-09-26) name it; the OpenSpec pass of 2026-09-27 decided `build`
for all four.

**`id-self-registration-form`**, "Fill in a self-registration form to create
your own portal account." Portaliq `no`, built.state `built`: "Fully
implemented backend with PHPUnit and an 'e2e' spec, but the e2e spec is
API-only, not a browser walking a real form, so no visitor can actually reach
this." Three competitors `yes`:

- Open Inwoner Platform: "src/open_inwoner/urls.py:79 accounts/register/;
  src/open_inwoner/accounts/views/registration.py:65 CustomRegistrationView [...]
  reached on login page, 'Maak een account aan'".
- xxllnc Zaken PIP: "backend/perl-api/lib/Zaaksysteem/Controller/Plugins/Twofactor.pm:353
  register; [...] :432 process_registration (e-mail, phone, password) then SMS
  code".
- Liferay DXP: "https://learn.liferay.com/w/dxp/security-and-administration/administration/configuring-liferay/virtual-instances/user-authentication
  'Allow strangers to create accounts?'".

**`id-reference-link`**, "Claim access to one case with the case number and a
verified e-mail address, with no account." Portaliq `no`, `built`:
"Backend-complete (task T02 'the reference route' [...] marked done) but no page
in this repo offers the one-time-link form." No competitor is rated `yes`; the
row is in portaliq's core area (identity).

**`id-invitation-accept`**, "Accept an e-mailed invitation into the portal."
Portaliq `no`, `built`: "Pairs with id-staff-invite, also unreached on the
staff side, so both ends of the invitation flow are dark." Three competitors
`yes`:

- Open Inwoner Platform: "src/open_inwoner/cms/profile/urls.py:100
  invite/<key>/accept/; src/open_inwoner/accounts/views/invite.py:14
  InviteAcceptView".
- xxllnc Zaken PIP: "backend/perl-api/lib/Zaaksysteem/Controller/Plugins/Twofactor.pm:368
  activate with activation code [...] reached on
  /auth/twofactor/activate/<code> from the e-mailed link".
- Liferay DXP: "https://learn.liferay.com/w/dxp/security-and-administration/users-and-permissions/accounts/account-users
  'Inviting a New User by Email'".

**`sib-dossiq-q1-14`**, "Can an external party file and follow a case with an
identity that is not a staff account." Portaliq `partial`, `built`: "filing/following
an already-known case works end to end for a non-staff broker identity, but the
discovery step (my-cases index, or self-registration/reference-link to attach a
case before first login) is unreached". Three competitors `yes`: Open Inwoner
Platform ("src/open_inwoner/cms/cases/views/cases.py:54 OuterCaseListView"),
xxllnc Zaken PIP ("backend/perl-api/lib/Zaaksysteem/Controller/Form.pm:182
/aanvragen/<id>/<persoon|organisatie|onbekend>") and MijnOverheid. The case
list half of this row is `cases-my-cases-page`; this change is the way in.

**How the pass treated the original change.** `portal-identity-and-the-organisations-cases` is open with every task checked, and no screen calls its endpoints. The OpenSpec pass of 2026-09-27 treats that as an archived change that shipped without the capability, so this change builds the missing half and leaves the shipped backend as it is.

## What changes

- The portal's sign-in screen offers three more doors next to the login
  buttons, each only when the portal allows it: "Create an account", "Follow a
  case with its case number" and, from a mail, "Accept your invitation".
- Portaliq mails the secret for each door through one identity mailer: the
  reference link, the invitation and the activation mail.
- A redeemed reference link opens a short, read-only session for that one case,
  and the portal shows that case.
- An activation link activates a self-registered account under the
  `activation` policy.
- An account made through any of these doors signs in afterwards through the
  portal's e-mail based login provider (the `generic` OIDC preset), matched on
  the verified address. A portal without one does not offer registration or
  invitations, and says so.

## Rows this closes

| matrix | row | name | own rating | what is missing |
| --- | --- | --- | --- | --- |
| portaliq | `id-self-registration-form` | Fill in a self-registration form | no | the form, the activation mail and step |
| portaliq | `id-reference-link` | Claim access to one case with the case number and a verified e-mail | no | the form, the mail, a session for the case |
| portaliq | `id-invitation-accept` | Accept an e-mailed invitation into the portal | no | the mail and the acceptance screen |
| portaliq | `sib-dossiq-q1-14` | File and follow a case with an identity that is not a staff account | partial | the way in for a party with no account yet |

## Existing work it builds on

- `portal-identity-and-the-organisations-cases`: `PortalIdentityController`,
  `PortalRegistrationPolicyService`, `PortalChallengeService`,
  `PortalReferenceLinkService`, `PortalInvitationService`. This change adds the
  screens, the mails, the reference session and the activation step. It does
  not change the policy, the challenge or how a link is stored.
- `portal-identity-space`: the find-or-create at login that matches a pending
  account on a verified e-mail claim.
- `identity-profile-page` (this OpenSpec pass) introduces
  `PortalIdentityMailer`; whichever of the two is built first creates it.

## Sibling halves

- A case app that admits the `reference` identity kind (dossiq) declares which
  field of its case collection holds the case number, so the reference session
  can read that one case. The declaration is the case app's.

## Out of scope

- A password store in portaliq. People without a national login sign in
  through an e-mail based OIDC provider the organisation configures.
- Approving a pending registration. That is a staff screen, in
  `identity-staff-account-screens`.
