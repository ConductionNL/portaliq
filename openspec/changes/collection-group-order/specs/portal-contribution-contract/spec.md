# portal-contribution-contract Delta: collection-group-order

## ADDED Requirements

### Requirement: A grouped collection MAY order its groups by its rows

A contribution collection that keeps `groupByField` MAY declare `groupOrder`. With `groupOrder: 'rows'` the portal MUST show each group where its first row stands in the table's sorted rows (the block's `sort`, else the collection's `defaultSort`, else the order the rows arrived). Without it, or with `groupOrder: 'heading'`, the portal MUST order the groups by their heading, as before. In both orders rows keep their order inside a group and rows without a value come last. The normaliser MUST keep `groupOrder` only when it is `'rows'` or `'heading'` and the collection keeps `groupByField`, and MUST drop it otherwise.

#### Scenario: The certificate that expires first stands on top
- GIVEN learniq's `employerCertificates` declares `defaultSort: { field: expiresAt, direction: asc }`, `groupByField: courseName` and `groupOrder: rows`
- AND the employer's people hold F-gassen until 2027 and BRL 6000-21 until 2028
- WHEN the employer opens "Certificaten"
- THEN the F-gassen group stands above the BRL 6000-21 group
- @e2e exclude pure function, covered by `node --test tests/collection-groups.spec.mjs` ("groupOrder 'rows': the group of the first row comes first"); the live walk is learniq's

#### Scenario: Without a group order the headings decide
- GIVEN the same collection without `groupOrder`
- WHEN the employer opens "Certificaten"
- THEN the groups read in the order of their headings
- @e2e exclude pure function, covered by `node --test tests/collection-groups.spec.mjs` ("without groupOrder the groups read in the order of their headings")

#### Scenario: A group order without a group field is dropped
- GIVEN a collection that declares `groupOrder: 'rows'` and no `groupByField`
- WHEN the manifest is normalised
- THEN `groupOrder` is dropped
- @e2e exclude pinned by `PortalManifestNormaliserTest::testAGroupOrderIsKeptOnlyAsRowsOrHeadingBesideAGroupField`
