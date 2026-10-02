# Tasks: contribution-value-labels

- [x] **T1**: `ValueLabelsNormaliser` keeps a well-formed `valueLabels` map on a column and on a field config, fail-closed
  - PHPUnit `ValueLabelsNormaliserTest::testAColumnKeepsItsValueLabels`, `::testTheMapIsFailClosed`
- [x] **T2**: an enum select takes a declared label over the generated words and the `oneOf` title; the option value stays raw
  - PHPUnit `ValueLabelsNormaliserTest::testAFieldsValueLabelsLabelItsEnumOptions`, `::testAValueLabelWinsOverAOneOfTitle`
- [x] **T3**: the site's table cell and detail card show the label, else the value as before
  - `node --test tests/value-labels.spec.mjs` (`npm run check:value-labels`, part of `check:specs`)
- [x] **T4**: mutation check: removing any of the label lookups (cells.js, CollectionTable.vue, DetailCard.vue, the three PHP call sites) makes a test fail
