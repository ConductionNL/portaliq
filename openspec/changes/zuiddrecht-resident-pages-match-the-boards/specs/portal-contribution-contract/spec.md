# Spec: Portal contribution contract

## ADDED Requirements

### Requirement: A contribution may declare the board displays
A contribution MAY declare, and the server MUST keep only when well formed: on a `cases` block
`display: compact` (the number left, a tag right, the title as a link, a bar and one line under
it), `showAll: true` (the link to every case beside the heading whenever the collection has a
page) and `yourTurn` (the values of the collection's `turnField` at which the tag reads the turn's
words in the warning tone instead of the status); on a `tasks` block with `display: highlight`
a `tone` of `warning` or `info` and `dueInLine: true` (the due day joins the sub line); on an
`inbox` block `display: list` (a title and the day in words, no badge); on a `documents` block
`upload: true` (an outlined button beside the heading that adds a document through the case
screen's route); on a `detail` block a `label` (a heading over the facts) and `timeline: false`
(the card draws no history of its own); on a `citizenCase` block `display: actions` (the closed
window sentence as a notice in the ok tone, no status block, no documents section; the fields,
save and withdraw stay); on a page `record` `heading: record` (the record's name is the page's
h1, the page label and the record's reference its eyebrow) and `under: cases` (the breadcrumb
passes through Mijn zaken). A block or page that declares none of these MUST render as before.

#### Scenario: The keys survive normalisation and nothing else does
@e2e exclude Static: tests/Unit/Contribution/BoardKeysTest.php and tests/zuiddrecht-resident-boards.spec.mjs
- GIVEN a contribution whose blocks declare the keys above, some well formed and some not
- WHEN the contribution is normalised
- THEN each well formed key MUST be on its block or page
- AND a malformed one (an unknown display, a tone outside the two, a non-boolean flag, a non-string turn value) MUST be dropped

#### Scenario: A compact case card reads the resident's turn
@e2e exclude Static: tests/zuiddrecht-resident-boards.spec.mjs renders the card
- GIVEN a `cases` block with `display: compact` and `yourTurn: ['applicant']` on a collection with `turnField: portalTurn` and the value labelled "Wacht op u"
- WHEN a row with `portalTurn: applicant` is rendered
- THEN the tag MUST read "Wacht op u" in the warning tone
- AND a row at another turn MUST carry its status in the info tone

#### Scenario: Every moved control keeps a route
@e2e exclude Static: tests/zuiddrecht-resident-boards.spec.mjs
- GIVEN the Zuiddrecht pages as dossiq declares them with the keys above
- WHEN the overview and the case page render
- THEN Bezwaar maken and Klacht indienen MUST be on the overview
- AND Mijn wijziging opslaan and Deze aanvraag intrekken MUST be on the case page
- AND the documents block MUST offer Document toevoegen

### Requirement: Every other portal renders unchanged
The blocks, pages and menu of a portal that declares none of the board keys MUST render the
markup they rendered before the keys existed: the Den Haag folder card, action rows with badges,
the site's own menu groups with icons, folder cards on Mijn zaken, the page label as h1.

#### Scenario: The school blocks keep their markup
@e2e exclude Static: tests/zuiddrecht-resident-boards.spec.mjs renders each block without the keys
- GIVEN a `cases`, `tasks`, `inbox`, `documents`, `detail` and `citizenCase` block without the keys
- WHEN each is rendered
- THEN none MUST carry a compact, row, list, tone or actions class
- AND the case card MUST still be a Den Haag case card with its step line above the bar
