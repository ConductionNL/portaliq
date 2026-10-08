# Design: site-member-voting-record-and-confidential-papers

Read at portaliq development `59a772bd` and decidiq development `e46f58fa`.

## Screens

No board on the Zuiddrecht canvas (https://claude.ai/artifact/5NkFW28vZUUij43xzxHg5a, page dcb81aee8d83) draws either page. Both pages are composed from drawn boards, read only:

| Board | Taken for |
|---|---|
| **Contentpagina** (Website: informatiepagina) | The public page frame: blue header menu, breadcrumb, h1, body column. The record list and the record sit in the body column |
| **Publicatie** (Website: publicatie) | The detail layout: h1, a definition list of key facts ("Gepubliceerd op", "Verantwoordelijk"), then a section list. The record uses it: h1 the member's name, the key facts are role, party and body, then Stemgedrag |
| **DcPersoon**, tab Stemgedrag (decidiq, in-app) | The summary figures: "96%" with "deelname aan 86 stemmingen", and Voor 61, Tegen 22, Onthouden 3. The public record shows the same cards from the provider's `summary`, with the provider's note under them |
| **MijnZaken** and **Zaak** (Mijn Zuiddrecht) | The confidential paper view: the list of items as rows under the resident menu, and on the item the Documenten section with Downloaden per file, as on Zaak |
| **Inloggen** (Mijn Zuiddrecht: inloggen) | The step-up: the DigiD and eHerkenning cards, with their own wording, as the prompt when trust is too low |

Labels on the public record: h1 the name, subtitle role and party, section heading "Stemgedrag", figure cards "Deelname", "Voor", "Tegen", "Onthouden", table columns "Datum", "Onderwerp", "Stem", "Uitslag". On the paper view: page "Vertrouwelijke stukken", columns "Agendapunt", "Vergadering", "Datum", section "Stukken", per file "Downloaden".

## What exists

| Piece | Where |
|---|---|
| Contract | `lib/Contribution/IPortalContributionProvider.php` (`timeline`, `documents`, `anonymous`, `minTrust`, `scopeClaim`) |
| Normaliser | `lib/Contribution/PortalManifestNormaliser.php`, `TimelineProviderMethod.php`, `DocumentsProviderMethod.php` |
| Anonymous aggregate | `PortalContributionRegistry::aggregateAnonymous()` (:203), `ContributionController::index()` |
| Case documents | `lib/Service/CitizenCaseDocuments.php:125` `open()`, `CitizenCaseController::document()`, `src/site/components/mijn/DocumentsBlock.vue` |
| Trust comparator | `PortalSessionService::trustSatisfies()` (:252), the one comparator |
| Page step-up | `ContentController` answers `insufficient_trust` for a CMS page with `minTrust` |
| Site blocks | `src/site/components/WidgetGrid.vue` (`publicationDetail: PublicationDetailBlock`), `src/site/components/collections/KpiCards.vue`, `CollectionTable.vue` |
| Sign-in | `BrokerSessionController` start and callback through integriq; trust from the envelope |

## Approach

### D1. `publicRecords` in the contract

- `lib/Contribution/PublicRecordsNormaliser.php` keeps an entry only with a non-empty `id` (`[a-z][a-zA-Z0-9_]*`), a `label`, and two provider names that pass the timeline rule and exist as public methods on the provider (`method_exists`). An entry failing any check is dropped, fail-closed.
- `aggregateAnonymous()` and `aggregateFor()` both carry `publicRecords` (id, label, group, app), never the provider names.
- `lib/Service/PublicRecordReader.php` calls the provider through `PortalProviderLocator`. It keeps at most 500 list entries, and per row only the keys the contract names. Values are cast to strings or numbers, never HTML. A record is fetched only for an id present in the list answer. Answers are cached for 5 minutes per app and list (`ICacheFactory`, distributed), because the list holds no personal data beyond public office holders.
- Routes, `#[PublicPage]`, `#[AnonRateLimit(limit: 60, period: 60)]`: `GET /api/public-records/{app}/{list}` and `GET /api/public-records/{app}/{list}/{id}`.

### D2. The `publicRecords` site block

- `src/site/components/PublicRecordsBlock.vue`, registered in `WidgetGrid.vue` and the widget palette as "Openbare overzichten". Its config is `{app, list}`, picked from the anonymous aggregate in the page editor.
- Without `?record`: a search field ("Zoek een raadslid") and a list of links, each `title` with `subtitle` under it.
- With `?record=<id>`: the record per the Publicatie board. `summary` renders through `KpiCards.vue` (value, unit, detail). `rows` render through `CollectionTable.vue` with the provider's `columns`, and `subjectUrl` becomes the link on Onderwerp. A link back reads "Alle raadsleden" (the list's label). Unknown id: "Dit overzicht bestaat niet (meer)."
- Server rendering: `site-honest-without-javascript` already renders blocks to HTML. The block's list and record are part of that, so a search engine and a reader without JavaScript see the votes.

### D3. `documents` on any collection

- `DocumentsProviderMethod` stops checking for a case collection. A listable collection with `documents` gets a Documenten section on its detail card.
- `lib/Service/CollectionDocuments.php` is extracted from `CitizenCaseDocuments` (listing, look up again, stream). `CitizenCaseDocuments` keeps its routes and calls it.
- `GET /portal/api/collections/{app}/{collection}/{id}/documents` and `.../documents/{documentId}` on `ContributionController`, `#[PublicPage]` with the portal bearer (as every `/portal/api` route). Order: resolve the subject (401 without); find the collection in the subject's aggregate (absent means 404, so a collection filtered out by trust is 404 here too, never 403 with detail); scoped single-object read (`PortalObjectReader`, scope claim and per row verification); list through the provider; look the document id up again in that answer (404 when absent); call `opened` when declared (D4); stream; then `PortalAuditHook::download`.

### D4. The `opened` hook, fail-closed

- Normalised like `provider`: a plain identifier, a public method, not a contract method.
- Called as `opened(string $objectId, string $documentId, array $subject): bool` with `subject = {subjectRef, trust, identityType, audience}`, all from the session.
- Anything other than `true`, or a throw, means 503 with "The paper cannot be opened right now" and nothing streamed. Portaliq logs the refusal at warning level, without the subject.

### D5. Asking for a higher login

- `ContributionController::index()` adds `stepUp: [{app, collection, label, minTrust}]` to the aggregate for collections dropped only for trust. It holds labels, never rows or counts.
- On `/mijn/{app}/{page}` where the page's collection is in `stepUp`, the page shows the sign-in cards of the Inloggen board for the providers that reach that trust (DigiD for a resident, eHerkenning for a company, from `LoginProviders`). The text reads "Voor deze stukken moet u inloggen met DigiD of eHerkenning." After login the resident returns to the same route.

### D6. Trust levels

Portaliq reads the trust from the login envelope. Substantial covers DigiD Midden and Substantieel, eHerkenning EH3 and eIDAS substantial. High covers DigiD Hoog, EH4 and eIDAS high. A password account and a passkey are low.

## The DigiD dependency

The pages work with any session that carries the trust level: an organisation's own OIDC broker that reports `acr`, or a stub envelope in tests. A live DigiD or eHerkenning login through integriq waits on open decision D1, the broker vendor and contract (ConductionNL/integriq#1495). Until D1 lands the step-up cards show the routes the portal admin configured. When none reaches the trust, they say "Inloggen met DigiD is hier nog niet beschikbaar" instead of a dead button.

## Example site

`ExampleSiteCatalogue` adds the page "Hoe stemden de raadsleden" under Gemeenteraad with one `publicRecords` block (`app: decidiq`, `list: memberVotingRecords`). It is installed only when decidiq is installed.

## Files

- `lib/Contribution/IPortalContributionProvider.php` (docblock), `PortalManifestNormaliser.php`, `PublicRecordsNormaliser.php`, `DocumentsProviderMethod.php`, `PortalContributionRegistry.php`
- `lib/Service/PublicRecordReader.php`, `lib/Service/CollectionDocuments.php`, `lib/Service/CitizenCaseDocuments.php`
- `lib/Controller/PublicRecordController.php`, `lib/Controller/ContributionController.php`, `appinfo/routes.php`
- `src/site/components/PublicRecordsBlock.vue`, `src/site/components/WidgetGrid.vue`, the widget palette entry, `src/site/components/mijn/DocumentsBlock.vue`, the step-up prompt on the contributed page
- `lib/Service/ExampleSite/ExampleSiteCatalogue.php`
- `l10n/*.json` for every new string
- Tests: `tests/Unit/Contribution/PublicRecordsNormaliserTest.php`, `tests/Unit/Service/PublicRecordReaderTest.php`, `tests/Unit/Service/CollectionDocumentsTest.php`, `tests/Unit/Controller/PublicRecordControllerTest.php`, `tests/public-records.spec.mjs`, `tests/e2e/site-member-voting-record.spec.ts`, `tests/e2e/confidential-papers.spec.ts`
