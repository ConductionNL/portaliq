# Proposal: array-cells-one-line-per-item

## Why

Found in the primary-school live check (2026-10-02). learniq's report card collection projects `gradeLines`, a list such as `["Rekenen: 7,9", "Taal: 8,3"]`. The React portal table wrote it with `String(value)`, which joins with a bare comma: "Rekenen: 7,9,Taal: 8,3". With Dutch decimal commas a parent cannot tell where one grade ends and the next begins. The site joined a list with "; ", which is readable but still one long line.

## What changes

- React portal (`CollectionTable.jsx`): a cell whose value is a list of plain values (strings or numbers) shows one value per line. Every other value renders as before.
- Site (`cells.js` `readable()`): a top-level list is joined with a line break instead of "; ", and the table cells keep line breaks (`white-space: pre-line`). Nested lists inside an object still join with ", ".
- Badge columns are unchanged.

## How it composes with the open site PRs

- #1030 adds a header-cell rule after `.pq-collection-table`; this change adds its cell rule at the end of the same `<style>` block, so the two hunks are apart.
- #1029 does not touch `cells.js` or the table cells.
