# Proposal: identity-registered-details

## Why

A resident who signs in to a portal expects to see what the government holds
about them. Portaliq shows nothing: the account record keeps only what the
broker's claims or a clerk typed in. Four rows in the portaliq parity matrix
(`openspec/parity/capabilities.json`, compared 2026-09-26) name the gap.

**`cmp-id-brp`**, "See the personal details the base registration (BRP) holds
about you." Portaliq `no`, built.state `none`. The matrix evidence: "Searched
lib/ for BRP/StUF-BG/Basisregistratie/haalcentraal: no match outside comment
prose. portalAccount only stores identityType/identityRef/displayName/email
(lib/Settings/portaliq_register.json:356-400), no BRP-sourced field set."
Four competitors are rated `yes`:

- Open Inwoner Platform: "src/open_inwoner/accounts/views/profile.py:564
  MyDataView, :585 get_brp_data via Haal Centraal BRPClient [...] reached on
  profile, 'Mijn gegevens' (/mijn-profiel/mydata/); needs Haal Centraal BRP".
- NL Portal: "backend/haalcentraal2/src/main/resources/graphql/schema.graphqls:3
  getPersoonV2; frontend/packages/user-interface/src/pages/AccountPage.tsx:258
  personal details section".
- xxllnc Zaken PIP: "backend/perl-api/root/tpl/zaak_v1/nl_NL/plugins/pip/contact.tt:23
  view_natuurlijk_persoon [...] shows the copy held in the case system (StUF/BRP
  sync), not a live BRP call".
- MijnOverheid: "https://mijn.overheid.nl/vragen/ Persoonlijke gegevens: BRP
  data, family relations, address history back to 1 October 1994".

**`cmp-id-company`**, "See the details the Chamber of Commerce (KvK) holds about
your company." Portaliq `no`, `none`: "eHerkenning login authenticates the
business identity but portaliq does not fetch or display KvK company data."
Two competitors `yes`:

- NL Portal: "backend/haalcentraal-hr/src/main/resources/graphql/schema.graphqls:3
  getBedrijf; frontend/packages/user-interface/src/pages/AccountPage.tsx:116
  company details [reached on /account when logged in with eHerkenning]".
- xxllnc Zaken PIP: "backend/perl-api/root/tpl/zaak_v1/nl_NL/plugins/pip/contact.tt:19
  view_bedrijf [reached on /pip/contact 'Gegevens' for companies]".

**`cmp-id-residents-at-address`**, "See how many people are registered at your
address, and start an address investigation when that looks wrong." Portaliq
`no`, `none`. NL Portal is `yes`: "backend/haalcentraal2/src/main/resources/graphql/schema.graphqls:6
getBewonersAantalV2; frontend/packages/user-interface/src/pages/AccountPage.tsx:423
inhabitant count; :435 address investigation link". MijnOverheid is `partial`:
"https://www.logius.nl/actueel/mijnoverheid-toont-aantal-bewoners-op-woonadres
(29-09-2023)".

**`cmp-id-correct`**, "Ask for a correction of your personal details."
Portaliq `no`, built.state `built`: "updateDetails() edits portaliq's own copy
of the two fields it stores; it is not a correction-request workflow against a
base registration." NL Portal, MijnOverheid and Liferay DXP are `partial`; NL
Portal "links out to configured URLs for BRP change requests, address research
and confidentiality".

All four rows sit in portaliq's core area (identity), which is why the
OpenSpec pass of 2026-09-27 decided `build`.

## What changes

- A "My registered details" section in the signed-in portal (`/portal`). A
  resident signed in with DigiD sees the BRP record: name, date of birth,
  address, and the number of people registered at that address. A business
  user signed in with eHerkenning sees the KvK record: trade name, KvK number,
  legal form and the registered branches.
- Portaliq reads both through OpenRegister, never through its own HTTP client:
  the person lookup of openregister's `integration-brp-haalcentraal`
  (`GET /api/integrations/brp/person`, `BrpPersoonProvider`) and the company
  lookup of `integration-kvk-opencorporates` (`KvkProvider`,
  `GET /api/integrations/kvk/company`).
- Two links the portal administrator binds per portal: "Report an error in
  these details" and "Something wrong at this address?". Each opens an intake
  form the portal already serves through a `portalFormBinding`, so the request
  lands with the case app that handles it. Portaliq changes no base
  registration.
- Nothing is stored. The record is read on request and never written to
  `portalAccount`.

## Rows this closes

| matrix | row | name | own rating | what is missing |
| --- | --- | --- | --- | --- |
| portaliq | `cmp-id-brp` | See the personal details the BRP holds about you | no | the whole capability |
| portaliq | `cmp-id-company` | See the details the KvK holds about your company | no | the whole capability |
| portaliq | `cmp-id-residents-at-address` | See how many people are registered at your address, and start an address investigation | no | the count and the investigation link |
| portaliq | `cmp-id-correct` | Ask for a correction of your personal details | no | a correction request against the base registration |

## Existing work it builds on

- `portal-identity-and-the-organisations-cases` (open, 15 of 15 tasks): the
  portal account and the identity kinds. This change reads the account; it
  adds no identity kind.
- `portal-intake-form-as-an-object` (open): `portalFormBinding` gives a portal
  route to a published intake form. The two request links point at bindings.
- openregister `integration-brp-haalcentraal` and
  `integration-kvk-opencorporates` (both open, all tasks checked): the lookups.

## Sibling halves

- **openregister** owes a count of the people registered at an address. Its
  `BrpPersoonProvider` answers `RaadpleegMetBurgerservicenummer` only; the
  count needs a query on the address object
  (`ZoekMetAdresseerbaarObjectIdentificatie`) that returns a number and no
  names. Until it lands, the count is left out and says so.
- **integriq** decides whether the DigiD broker hands portaliq a BSN or only a
  pseudonym (`idp-broker-envelope-runtime`). Without a BSN on the account there
  is nothing to look up, and the section says that instead of guessing.

## Out of scope

- Changing a BRP or KvK record. A correction is a request to the organisation.
- Family relations and address history (MijnOverheid shows both).
- Caching the record. A lookup is made when the resident opens the section.
