# Tasks: array-cells-one-line-per-item

- [x] **T1**: React portal table: a list of plain values renders one value per line (`cellLines`, `.portaliq-cell-line`)
  - `node --test tests/array-cells.spec.mjs`
- [x] **T2**: site: a top-level list joins with a line break and the table keeps the breaks
  - `node --test tests/array-cells.spec.mjs`; `tests/site-collections.spec.mjs` unchanged
  - Live: Fatima Hulstkamp's "My child's report cards" shows each grade on its own line
