# Proposal: cases-my-cases-page

## Why

A resident or a company sees its cases one app at a time, on each
contribution's own nav page, and cannot see the cases of an organisation it
holds a mandate for. The merged, mandate-aware list is built:
`GET /portal/api/my-cases` (`lib/Controller/MyCasesController.php:83`, routed
at `appinfo/routes.php:287`) merges every `kind: cases` collection, adds the
cases the active mandate reaches, names the mandate per case and answers the
mandates held. It shipped with `portal-identity-space` (open, 8 of 8 tasks
checked: "A 'My cases' surface for the client audience") and
`portal-identity-and-the-organisations-cases` (open, 15 of 15, T03 to T05).
Read at `eeda3fa`, no screen calls it: `grep -rn my-cases src/` returns
nothing. And no contribution declares `kind: 'cases'`: dossiq's `mijnZaken`
(ConductionNL/dossiq development, `lib/Portal/PortalContributionProvider.php`,
`citizenCollections()`) declares none, so the list would be empty even with a
screen.

Five rows in the portaliq parity matrix (`openspec/parity/capabilities.json`,
compared 2026-09-26) name it; the OpenSpec pass of 2026-09-27 decided `build`.

**`cas-mycases-unified`**, "See every case from every organisation and app in
one merged list." Portaliq `no`, built.state `built`: "A fully built,
mandate-aware unified case list [...] that no frontend anywhere calls." Two
competitors `yes`:

- Open Inwoner Platform: "src/open_inwoner/cms/cases/views/cases.py:113 merges
  zaken from every ZGW API group and e-Suite open forms into one paginated
  list [reached on Mijn aanvragen page]".
- MijnOverheid: "logius.nl Lopende Zaken + mijn.overheid.nl/vragen/: cases from
  every connected government organisation are merged into one 'Lopende zaken'
  overview".

**`cas-mandate-org-cases`**, "See the cases of an organisation or group you hold
a mandate for." Portaliq `no`, `built`: "Substantial mandate/party-tree
machinery [...] exists server-side but is stranded behind the same unreached
/portal/api/my-cases endpoint." NL Portal is `yes`:
"backend/zgw/zaken-api/src/main/kotlin/nl/nlportal/zakenapi/service/ZakenApiService.kt:87
zaak types limited by the eHerkenning service UUIDs [...] reached on /zaken
while logged in via machtigen or bewindvoering".

**`cmp-cas-all-gov`**, "See your cases from several organisations in one
overview." Portaliq `no`, `built`: "The per-contribution nav shows one app's
cases per page, not a merged cross-organisation overview." MijnOverheid is
`yes`: "MijnOverheid is explicitly the cross-organisation aggregator".

**`cmp-cas-closed`**, "See closed cases next to open ones." Portaliq `partial`,
`built`: "there is no distinct open/closed grouping, filter or tab". Four
competitors `yes`:

- Open Inwoner Platform: "src/open_inwoner/cms/cases/views/cases.py:31 filter
  options Lopende and Afgeronde aanvragen (einddatum)".
- NL Portal: "frontend/packages/user-interface/src/pages/CasesPage.tsx:37
  getZaken isOpen false; tab 'Afgeronde zaken'".
- xxllnc Zaken PIP: "backend/perl-api/lib/Zaaksysteem/Controller/Plugins/PIP.pm:291
  afgehandelde_zaken; [...] index.tt:106 'Afgehandelde zaken'".
- MijnOverheid: "'Ook als de zaak is afgerond, blijft deze zichtbaar in het
  dossier'".

**`cmp-sig-machtiging`**, "Act on behalf of someone else through a DigiD
Machtigen or eHerkenning mandate." Portaliq `partial`, `built`: "the portal SPA
has no UI to name or switch between mandates [...] even though [...] tasks.md
T04/T05 mark 'name the mandate on the view' and 'switch the organisation or
role acted under' as done." Two competitors `yes`:

- NL Portal: "frontend/packages/app/src/App.tsx:20 proxy methods machtigen,
  bewindvoering; [...] reached on header 'Ingelogd namens' label".
- MijnOverheid: "via DigiD Machtigen a designated person can read, move, delete
  and forward the messages assigned to them".

## What changes

- A fixed "My cases" page in the signed-in portal over `GET /portal/api/my-cases`:
  every case from every contributing app in one list, newest first, each row
  naming the app and organisation it comes from.
- Two tabs, "Open" and "Closed". A contribution says which field marks a case
  closed; a case whose collection says nothing counts as open.
- An "Acting for" switcher in the portal header when the identity holds a
  mandate: yourself, or one of the mandates, by its label. The choice is sent
  as the existing `mandate` parameter and kept for the session.
- Each case from a mandate shows the mandate's label, so the person sees why
  they may read it.
- Opening a case goes to the case screen of the collection it came from.

## Rows this closes

| matrix | row | name | own rating | what is missing |
| --- | --- | --- | --- | --- |
| portaliq | `cas-mycases-unified` | Every case from every organisation and app in one list | no | the screen, and a collection that declares cases |
| portaliq | `cas-mandate-org-cases` | The cases of an organisation you hold a mandate for | no | the screen |
| portaliq | `cmp-cas-all-gov` | Your cases from several organisations in one overview | no | the screen |
| portaliq | `cmp-cas-closed` | Closed cases next to open ones | partial | the grouping and the closed marker |
| portaliq | `cmp-sig-machtiging` | Act on behalf of someone else through a mandate | partial | naming and switching the mandate |

## Existing work it builds on

- `portal-identity-space` and `portal-identity-and-the-organisations-cases`:
  `MyCasesController`, `PortalCaseListReader`, `PortalMandateService`,
  `PortalPartyTreeResolver`. This change adds the screen, the switcher and the
  closed marker. It does not change how cases are merged or scoped.
- `portal-visibility-follows-the-party-tree` (open, 9 of 9): the mandate's
  reach, and the `group_too_large` refusal the screen must show.
- `what-the-citizen-may-write-on-their-own-case`: the case screen a row opens.

## Sibling halves

- **dossiq** declares `kind: 'cases'` and `closedField: 'endDate'` on its
  `mijnZaken` collection. Without it the page lists no dossiq case. Every other
  case app that contributes cases does the same.
- Turning a DigiD Machtigen or eHerkenning ketenmachtiging claim into a
  `portalMandate` at sign-in is not part of this change. Mandates today are
  recorded on the `portalMandate` record by staff, or by a granted access
  request (`identity-access-requests`).

## Out of scope

- Search and filters beyond open and closed.
- Paging. The list reader already caps a mandate's reach; a portal with more
  cases than one page holds is a later change.
