# Tasks: collection-group-order

- [x] **T1**: `groupRows()` takes an order; `groupOrderOf()` reads `groupOrder: 'rows'`; the site page passes the collection's order
  - Files: src/shared/collectionGroups.js, src/site/pages/collections/ContributionPage.vue
  - Test: `node --test tests/collection-groups.spec.mjs` ("groupOrder 'rows': the group of the first row comes first", "without groupOrder the groups read in the order of their headings", "groupOrder 'rows' still puts rows without a value last", "the site page passes the collection group order to groupRows")
- [x] **T2**: the normaliser keeps `groupOrder` only as 'rows' or 'heading' beside a kept `groupByField`
  - Files: lib/Contribution/CollectionGroupOrderKey.php, lib/Contribution/CollectionConfigNormaliser.php
  - Test: PHPUnit `PortalManifestNormaliserTest::testAGroupOrderIsKeptOnlyAsRowsOrHeadingBesideAGroupField`, `CollectionGroupOrderKeyTest`
- [ ] **T3**: Live: an employer on the warmtepompacademie portal sees F-gassen (2027) above BRL 6000-21 (2028) under "Certificaten" (learniq live lane, once learniq declares `groupOrder: 'rows'`).
