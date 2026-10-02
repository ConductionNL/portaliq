# Tasks: contribution-record-page

- [x] **T1**: the normaliser keeps `record` on a page, `recordField`/`recordKey` on collection blocks, and the `kpi`, `calendar` and `news` blocks, each fail-closed
  - PHPUnit `RecordPageNormaliserTest`
- [x] **T2**: `src/shared/recordPage.js`: narrow rows to a record, pick the KPI row, build calendar items, match news to a record
  - `node --test tests/record-page.spec.mjs`
- [x] **T3**: the site renders a record page (list, open record, back), KPI cards, the calendar (list and month) and the news block
  - `node --test tests/record-page.spec.mjs` (rendered HTML)
  - Live: Fatima Hulstkamp opens Vera on the Wilgenboom site and sees the figures, report cards, homework, attendance, calendar and news
