# Tasks: kpi-unit-singular-and-plural

- [x] **T1**: `RecordBlockNormaliser` keeps a card's `unit` and a detail's `label` as a string or as `{one, other}` with both forms non-empty
  - PHPUnit `RecordPageNormaliserTest::testAKpiUnitMayNameItsSingularAndPlural`
- [x] **T2**: `countedWord(word, value)` in `src/shared/recordPage.js` picks the form; `KpiCards` uses it for the unit and every detail
  - `node --test tests/record-page.spec.mjs` ("a unit reads singular for one and plural for every other figure", "a kpi card says "1 dag" and "1 minuut", and "5 dagen" beside it")
  - Mutation: rendering `card.unit` as before shows "[object Object]" and fails the card test
