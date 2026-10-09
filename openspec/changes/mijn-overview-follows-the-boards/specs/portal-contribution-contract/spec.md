## ADDED Requirements

### Requirement: A block may stand in a column and in a frame

Any block of a contributed page MAY declare `column` (`main` or `side`) and `frame` (`line`, `tinted`,
or `true` for `line`). From tablet width the site MUST place consecutive blocks that declare a column
in one band of two columns, a wider main column and a side column, each column stacking its blocks
from the band's first row. A block without a column MUST span the page. On a phone the blocks MUST
keep the declared order in one column. A page that declares none of these keys MUST render as before.

#### Scenario: The Vaartveld overview in two columns
@e2e exclude Unit test in node: tests/site-look/mijn-overview-follows-the-boards.spec.mjs; PHPUnit BlockLayoutKeysTest
- GIVEN an overview with the greeting, the timetable (`column: main`), homework and grades (`column: side`) and the absence strip
- WHEN Noor opens `/mijn` on a laptop
- THEN the timetable stands left, homework above grades on the right, and the greeting and the strip span the page

#### Scenario: A value that does not fit is dropped
@e2e exclude PHPUnit BlockLayoutKeysTest
- GIVEN a block with `column: "left"` and `frame: "shadow"`
- WHEN the contribution is normalised
- THEN the block survives without `column` and `frame`

### Requirement: A block may carry a link to all of it

Any block MAY declare `more` with a `label` (at most 60 characters) and exactly one of `page` (a page
of the same contribution) or `route` (a path inside the site). The site MUST show the link at the end
of the block's heading row, or under the block with `placement: "end"`. A link whose page or route does
not resolve MUST be dropped.

#### Scenario: "Hele week" beside the timetable heading
@e2e exclude Unit test in node: tests/site-look/mijn-overview-follows-the-boards.spec.mjs
- GIVEN the timetable block with `more: {label: "Hele week", page: "studentSessions"}`
- WHEN Noor opens the overview
- THEN "Hele week" is a link at the end of the "Je rooster vandaag" heading row that opens the timetable page

### Requirement: A greeting may name the week

A `greeting` block MAY declare `showWeek: true`. Its date line MUST then end with " · week N", N the ISO 8601 week.

#### Scenario: Week 41
@e2e exclude Unit test in node: tests/site-look/mijn-overview-follows-the-boards.spec.mjs; PHPUnit BlockLayoutKeysTest
- GIVEN a greeting with `showWeek: true`
- WHEN it renders on Monday 5 October 2026
- THEN the date line reads "Maandag 5 oktober 2026 · week 41"

### Requirement: A figure block may stand as one line

A `kpi` block MAY declare `display: "strip"`. The site MUST show its heading and its figures on one
line, each figure with its unit in bold followed by the card's `stripLabel`, else its label.

#### Scenario: The absence strip
@e2e exclude Unit test in node: tests/site-look/mijn-overview-follows-the-boards.spec.mjs
- GIVEN the absence figures with `display: "strip"` and the strip labels "ziek", "te laat" and "zonder melding"
- WHEN Noor opens the overview
- THEN one line reads "Afwezigheid dit schooljaar  1 dag ziek  2 keer te laat  0 uur zonder melding"
