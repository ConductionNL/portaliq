## ADDED Requirements

### Requirement: A melding may carry a button, bold words and a plain look

An `nlAlert` with `action: {label, href}` and a safe address MUST render a primary button link with
a chevron inside the melding, under the words when it has a heading and beside them when it has none.
`kind: plain` MUST render a white card with a hairline. Words between a pair of `**` MUST render bold,
as text. A `warning` and an `error` MUST show a mark before the words.

#### Scenario: "Online melden" on De Wilgenboom
@e2e exclude Render check in node: tests/site-look/callouts-steps-tables.spec.mjs; live screenshot on :8092 in the PR
- GIVEN an `nlAlert` with heading "Online melden" and `action: {"label": "Afwezig melden", "href": "/mijn"}`
- WHEN the content page renders
- THEN "Afwezig melden" is a button with a chevron inside the tinted card, under its text

#### Scenario: "Liever bellen?"
@e2e exclude Render check in node: tests/site-look/callouts-steps-tables.spec.mjs
- GIVEN an `nlAlert` with `kind: "plain"` and text "Bel **[telefoonnummer]**"
- WHEN it renders
- THEN it is a white card with a line, and "[telefoonnummer]" is bold

### Requirement: Numbered steps may be compact, with the lead in bold

An `nlList` with `display: numbered` MUST render an ordered list whose numbers stand in filled
circles in the primary colour; of a plain line only the words up to the first comma, question mark,
colon or full stop MUST be bold; of a `{title, text}` line the title.

#### Scenario: Esdoornveen's "Zo werkt het"
@e2e exclude Render check in node: tests/site-look/callouts-steps-tables.spec.mjs
- GIVEN the line "Weer beter? Meld je beter in Mijn Esdoornveen."
- WHEN the list renders with `display: numbered`
- THEN "1" stands in a purple circle and only "Weer beter?" is bold

### Requirement: A table may name each row

An `nlTable` with `rowHeaders` MUST render the first cell of each row as a bold row header
(`th scope="row"`); without it every body cell stays a data cell.

#### Scenario: "Wat meldt u hoe?"
@e2e exclude Render check in node: tests/site-look/callouts-steps-tables.spec.mjs
- GIVEN the table with `rowHeaders: true`
- WHEN it renders
- THEN "Ziek", "Dokter of tandarts", "Te laat" are bold row headers
