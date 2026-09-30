# Proposal: signin-eherkenning-branch

## Why

A business user who signs in with eHerkenning always acts for the whole company.
A chain with twenty shops cannot give the manager of one shop access to that
shop's permits only, and a manager who signed in for one branch still sees the
other nineteen. Portaliq reads no branch at all: the eHerkenning preset in
`lib/Service/OidcClaimMapperService.php:82-88` maps the subject and the
assurance level, and `grep -riE 'vestiging|establishment' lib src` finds only a
mock-data sentence.

One row in the portaliq parity matrix (`openspec/parity/capabilities.json`,
compared 2026-09-26) names it:

**`dem-cl-eherkenning-branch`**, "Act for one branch (vestiging) of your company
after signing in with eHerkenning, and see only that branch's cases." Portaliq
`no`, built.state `none`: "No branch (vestigingsnummer) claim is read or scoped
on; an eHerkenning login acts for the whole company subjectRef." Demand:
changelog, https://github.com/maykinmedia/open-inwoner/blob/v2.4.3/CHANGELOG.rst#L1155.
Three competitors are rated `yes`:

- Open Inwoner Platform: "src/open_inwoner/kvk/views.py:23
  CompanyBranchChoiceView; src/open_inwoner/openzaak/clients.py:190 cases
  filtered by vestigingsnummer [reached on after eHerkenning login and 'Wissel
  van vestiging' on the profile]".
- NL Portal: "backend/zgw/zaken-api/src/main/kotlin/nl/nlportal/zakenapi/client/request/AuthenticationFilter.kt:15
  withKvkAndVestigingsNummer; [...] reached on /zaken after eHerkenning login
  with a vestigingsnummer claim".
- xxllnc Zaken PIP: "backend/perl-api/lib/Zaaksysteem/SAML2.pm:1276 Vestigingsnr
  from eHerkenning; backend/perl-api/root/tpl/zaak_v1/nl_NL/plugins/pip/layouts/aanvraag_picker.tt:15
  'Kies vestiging'".

The row is in portaliq's core area (sign-in), and three competitors are `yes`;
the OpenSpec pass of 2026-09-27 decided `build`.

## What changes

- The eHerkenning login reads a branch claim (the vestigingsnummer) when the
  broker supplies one, and the portal session carries it.
- A session whose login was restricted to a branch sees only that branch: a
  case collection that declares its branch field is filtered to the branch, and
  a collection that declares none is not shown to that session at all.
- A business user whose login carried no branch may choose one of the company's
  branches, or the whole company, from "Acting for". That choice filters; it
  does not restrict.
- The branch in effect is shown in the header.

## Rows this closes

| matrix | row | name | own rating | what is missing |
| --- | --- | --- | --- | --- |
| portaliq | `dem-cl-eherkenning-branch` | Act for one branch of your company after signing in with eHerkenning | no | the claim, the session field, the scoping and the choice |

## Existing work it builds on

- `2026-07-24-portal-oidc-broker-login` (archived): `OidcClaimMapperService`
  presets and the `claimMap` override per organisation.
- `supplier-portal`: `PortalSessionService::issueSession()` and
  `resolveFromBearer()`, which carry the session's claims.
- `cases-my-cases-page` (this OpenSpec pass): the "Acting for" switcher in the
  header, which this change adds the branch to.
- `identity-registered-details` (this OpenSpec pass): the KvK lookup that lists
  the company's branches for the choice.

## Sibling halves

- **dossiq** (and any case app with business cases) stores the branch number
  on a case filed by a business and declares `branchField` on its collection.
  Until it does, a branch-restricted session sees none of its cases, which is
  the safe answer.
- **integriq**: where portaliq signs in through integriq's broker envelope
  (`signin-integriq-broker-login`), the envelope has to carry the branch the
  eHerkenning assertion restricted the login to. That is integriq's to add to
  its `digid-eherkenning-auth-adapter` envelope.

## Out of scope

- Branches of a company the user holds a mandate for. A mandate scopes by
  party (`portal-identity-and-the-organisations-cases`); a branch within a
  mandated company is a later change.
