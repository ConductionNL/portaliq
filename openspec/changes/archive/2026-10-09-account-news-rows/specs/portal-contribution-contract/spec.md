## ADDED Requirements

### Requirement: A news block on an account page shows rows, not bodies

A contributed page's `news` block MUST show each item as a row: who it is for (when the item names
an audience), its date in the page language, and its title, which opens the news screen. It MUST
NOT show the item's body.

#### Scenario: The guardian overview
@e2e exclude Rendered in node: tests/site-look/account-news-rows.spec.mjs
- GIVEN an item "De Kinderboekenweek is begonnen" for "hele school" of 2 October 2026 whose body holds markdown
- WHEN the overview renders its news block
- THEN the row reads "hele school", "2 oktober 2026" and the title, and no part of the body shows
