## ADDED Requirements

### Requirement: A row may open its own page

A collection block MAY declare `rowPage`, a page of the same contribution, and `rowIdField`, a field
it projects. Each row of a `rows`, `cards` or `chips` display MUST then link to that page for the row
(`/mijn/<app>/<page>/<id>`, the id from `rowIdField`, else the row's own id) with a chevron after it.
A page the contribution does not declare MUST be dropped.

#### Scenario: A booking opens its detail
@e2e exclude Unit test in node: tests/site-look/mijn-lists-follow-the-boards.spec.mjs; PHPUnit ListKeysTest
- GIVEN the bookings block with `rowPage: "employerBooking"`
- WHEN Linda opens "Inschrijvingen"
- THEN each booking's title is a link to its page, and a chevron ends the row

### Requirement: A list may show its rows under tabs

A collection block MAY declare 2 to 6 `tabs`, each `{label}` or `{label, field, values}`. The site MUST
show them as a tab list over the rows, the first chosen at first; a tab with a field MUST show the
rows whose value is one of its values, a tab without one every row. The arrow keys, Home and End MUST
move between the tabs.

#### Scenario: Komend, Afgerond, Geannuleerd
@e2e exclude Unit test in node: tests/site-look/mijn-lists-follow-the-boards.spec.mjs; PHPUnit ListKeysTest
- GIVEN tabs on `lifecycle`: Komend (`confirmed`, `waiting`), Afgerond (`completed`), Geannuleerd (`cancelled`)
- WHEN Linda chooses "Afgerond"
- THEN only the finished bookings show

### Requirement: Rows may read as the boards' lists

A `rows` display MAY declare `valueField`, `newField`, `eyebrowField`, `dateDisplay` (`tile`, `line`,
`eyebrow`, `end`), `dateLabel` and `rowStyle` (`cards`, `lines`). The site MUST show the value as a large
figure at the end of the row, a "Nieuw" pill when the new field is true or a date of the last seven
days, and the date as the declared display says, in words.

#### Scenario: The latest grades
@e2e exclude Unit test in node: tests/site-look/mijn-lists-follow-the-boards.spec.mjs
- GIVEN grade rows with `subtitleField: test`, `valueField: value`, `dateDisplay: line`, `rowStyle: lines`
- WHEN Noor opens the overview on Monday 5 October 2026
- THEN a row reads "Engels" with "Leestoets · vrijdag 2 oktober" under it and "6,9" at its end

### Requirement: Marks may be grouped per subject with their average

A `chips` display MAY declare `groupField` over one row per mark. The site MUST show one row per
subject with the subject's name, the `subtitleField` of its first mark, the marks as chips in date order,
the average (weighted by `weightField` when declared) as a large figure, and an "Onder {mark}" pill when
the average is below `lowBelow`. With `summary: true` the site MUST show the mean of the averages, the
number of subjects, and the `summaryText` with `{pass}`, `{fail}` and `{count}` filled in.

#### Scenario: Wiskunde A below the pass mark
@e2e exclude Unit test in node: tests/site-look/mijn-lists-follow-the-boards.spec.mjs
- GIVEN Wiskunde A marks 5,1, 4,7 and 5,8 and `lowBelow: 5.5`
- WHEN Noor opens "Cijfers"
- THEN the Wiskunde A row shows the three chips, the 4,7 and 5,1 marked low, "Onder 5,5" and "5,2"
