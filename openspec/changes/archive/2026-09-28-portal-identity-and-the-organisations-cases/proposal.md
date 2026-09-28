---
kind: code
depends_on: [portal-identity-space]
---

# Proposal: portal-identity-and-the-organisations-cases

Round 4 discovery sweep, cluster 7 "Portal identity, registration and the
organisation's cases" (`procest/_round4/discovery/build-plan.md` in
ConductionNL/market-intelligence, 2026-09-14). Owner portaliq, size L,
decision D8, no blocking dependency in the plan. Candidates:
`C-intake-27`, `C-intake-36`, `C-access-and-privacy-2`, `-11`, `-12`,
`-13`, `-20`, `-21`, `-23`, `-32`, `-60`, `-67` (`candidates.json`, lane
lines `intake.tsv:8`, `:33` and `access-and-privacy.tsv:12`, `:14`,
`:24`, `:26`, `:43`, `:49`, `:65`, `:66`, `:73`, `:76`).

## Summary

A citizen or a company reaches their cases with an identity of their own.
Which identity, and how strong, is a choice the case type makes. A portal
user who belongs to an organisation sees that organisation's cases, not
only the ones they filed themselves.

## Why

The plan's mechanism line: "extend portaliq `portal-identity-space`;
blocked on the identity decision". That decision has been taken.

**D8, as Ruben answered it: both identities, chosen per case type, with
number plus e-mail first.** A melding openbare ruimte has no account. A
vergunningaanvraag does. The decision file says the same and names the
build order: option 2 first because it is small and unblocks eight
candidates, then option 1 through `portal-identity-space`.

The loudest part of the cluster is number 5 of the sweep's twenty-five
loudest: **portal access to the organisation's cases**, four driven
passers, dossiq `no`, and a matrix hole. The lane's clause names the case:
eHerkenning ketenmachtiging, and a company whose two employees both file.
dossiq's note is exact about why it does not pass today: "5.8
gemachtigde is a party on a case, not a portal view".

## The passers that prove it

Fifteen systems pass a member, fourteen driven and one documented.
Proving system: zammad.

| candidate | relevance | driven | evidence the lane cited |
|---|---|---|---|
| `C-access-and-privacy-23` | must, hole | otobo, request-tracker, zammad, znuny | `Organization#shared`, `app/policies/ticket_policy/base_scope.rb:33-38` (`D-zammad-13`, `D-otobo-26`, `D-request-tracker-26`) |
| `C-intake-27` | should | glpi, osticket, zammad | proof of work, `src/Glpi/Altcha/AltchaManager.php`, `ChallengeController.php` (`D-glpi-8`, `D-zammad-14`) |
| `C-access-and-privacy-20` | should | huly, taiga, tuleap | `src/common/InviteBuddy`, `/account/invitations` (`D-tuleap-1`, `D-huly-9`, `D-taiga-3`) |
| `C-access-and-privacy-67` | should | redmine, tuleap (jira-data-center documented) | `account/register`, `account/activation_email`, `account/activate` (`D-redmine-20`, `D-tuleap-7`) |
| `C-access-and-privacy-12` | should | odoo, taiga | `users/models.py:142 date_cancelled` (`D-taiga-13`, `D-odoo-15`) |
| `C-access-and-privacy-21` | should | xxllnc-zaken | Contactbeeld (`ContactBeeld-persoon.md`, `D-xxllnc-45`) |
| `C-access-and-privacy-32` | should | glpi | `ChangeEntityController.php`, `ChangeProfileController.php` (`D-glpi-17`) |
| `C-access-and-privacy-11` | should | tuleap | `/account/join-private-project-mail/` (`D-tuleap-9`) |
| `C-access-and-privacy-60` | should | plane | `license/models/instance.py:25 whitelist_emails` (`D-plane-21`) |
| `C-access-and-privacy-13` | could | tuleap | `/account/information` (`D-tuleap-3`) |
| `C-intake-36` | could | tuleap | `plugins/captcha` (`D-tuleap-20`) |
| `C-access-and-privacy-2` | could | freescout | `GET /user-setup/{hash}/{invite_sent_at}` (`D-freescout-32`) |

dossiq rates ten of the twelve `no` and two `partial`.

## What portaliq builds

- **An identity kind per case type.** `account`, `reference` (a case number
  and a verified e-mail, no account) or both. The case type chooses; the
  portal enforces.
- **The organisation's cases.** A portal identity that belongs to an
  organisation sees the cases of that organisation, with the mandate that
  grants it named on the view.
- **A portal account issued another way.** At the desk, by invitation to
  an e-mail address with the state of the invitation visible, for the
  citizen who cannot use a national login.
- **Self-registration under control.** Off, approval, or activation by
  mail, with an e-mail domain allow list and a challenge in front.
- **A challenge that sends nobody to a vendor.** Proof of work or a
  honeypot, run by us.
- **The citizen's own account.** Change your details with a new address
  confirmed by a link, and ask for the account to be removed, carried out
  by the product.
- **An access request.** Ask for access you do not have, and the owner
  gets the request instead of an e-mail nobody records.
- **Switching organisation or role inside the session**, for the person
  who acts under more than one mandate.

## How dossiq consumes it

dossiq declares the identity kind on the case type and reads the portal
identity that arrives with a submission. It does not implement a login,
an invitation or an organisation view. Its `PortalContributionProvider`
scopes its `cases` collection by the claim portaliq supplies, which is
what `portal-identity-space` already asks of it.

## Existing specs it extends

`portal-identity-space` (portaliq#535), which provisions a `portalAccount`
before first login and matches it afterwards. This change adds the second
identity kind, the organisation scope, and the registration controls
around both. It also extends `portal-contribution-contract` for the
scoping, `supplier-portal` for the organisation record, and
`portal-auth-edge-session-hardening` for the session that switches.

## ADRs

- ADR-046: portaliq owns the external auth edge. Employees stay internal.
- ADR-054 and ADR-082: a public registration surface is hardened and
  throttled.
- ADR-041: an app writes its claim through a typed event, never from the
  client.

## Recorded, not built here

`C-access-and-privacy-2`, a colleague invited by mail who sets their own
password, is a staff account. Both lanes said so: "Nextcloud's job", and
dossiq reads `no, Nextcloud's`. Portaliq's invitation mechanism serves
portal identities. The staff half stays with the platform, and the
candidate is recorded so nobody rediscovers it.

## Out of scope

- The identity broker configuration. `portal-idp-broker-config` owns it,
  and the decision file notes it is blocked on five earlier decisions.
- What the citizen may write once they are in. That is the sibling change
  `what-the-citizen-may-write-on-their-own-case`.
- The party model on the case. openregister owns parties; this is a portal
  view, not a second party tree.
