# Tasks: portal-public-catalogue

- [x] **T1**: `PublicIndexItems` (the item shape), `getPublicIndex` reserved in `TimelineProviderMethod`
  - PHPUnit `PublicCatalogueTest::testAnAppAnswerIsHeldToItsShape`
- [x] **T2**: `PublicCatalogue` (news + each app, cached per portal), `PublicCatalogueQuery` (words, types, facets with counts, upcoming, sort, pages), `PublicNewsReader::allFor`
  - PHPUnit `PublicCatalogueTest` (gathering, cache, query, upcoming, sort, pages)
- [x] **T3**: `GET /api/content/catalogue`, gated by the portal's sign-in modes
  - PHPUnit `PublicCatalogueTest::testTheEndpointAnswersAVisitorOfAPublicPortalAndRefusesOneThatIsNot`
- [x] **T4**: `nlCatalogue` widget (on demand), registry, meta, `SITE_COMPOSITIONS`; `nlEventList` `source`; `WidgetGrid` hands both the portal
  - node `tests/portal-public-catalogue.spec.mjs` (`check:portal-public-catalogue`, in `check:specs`); `widget-registry`, `widget-tokens` green
- [ ] **T5**: live check next to the Vaartveld, Esdoornveen and Warmtepompacademie `Zoeken` boards, after learniq declares its index (`portal-public-index`) and the example portals place the block — not run: needs a live instance and learniq
- [x] **T6**: `openspec validate portal-public-catalogue --strict`
