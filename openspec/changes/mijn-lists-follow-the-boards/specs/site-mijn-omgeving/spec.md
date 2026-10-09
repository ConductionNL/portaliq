## ADDED Requirements

### Requirement: Dates read as words

Every date the site shows in a table, a detail or a list MUST read in words in the page language
("2 oktober 2026"; with the time "13 november 2025, 08.40 uur"), never as "2-10-2026" or a raw stamp.
A list may say "Vandaag", "Morgen" and "Gisteren" for the near days.

#### Scenario: A grade's date
@e2e exclude Unit test in node: tests/site-look/mijn-lists-follow-the-boards.spec.mjs; tests/site-collections.spec.mjs
- GIVEN a table column with `render: date` and the value `2026-10-02`
- WHEN a Dutch page shows it
- THEN the cell reads "2 oktober 2026"

### Requirement: A list named like its page has one heading

A display block (`rows`, `bars`, `chips`) whose heading equals the page's title MUST NOT repeat it.

#### Scenario: Inschrijvingen once
@e2e exclude Unit test in node: tests/site-look/mijn-lists-follow-the-boards.spec.mjs
- GIVEN the page "Inschrijvingen" with a rows block labelled "Inschrijvingen"
- WHEN Linda opens it
- THEN "Inschrijvingen" stands once, as the page heading
