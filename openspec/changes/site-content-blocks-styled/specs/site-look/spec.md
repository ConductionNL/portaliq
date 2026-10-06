## ADDED Requirements

### Requirement: A table must draw a frame, a header row and a caption heading from the set's tokens

A table on a site page MUST draw a frame in the set's border colour with the set's large radius, a
header row in the set's hover tint, and its caption above the table at the size and weight of a
third-level heading. Each value MUST read the Utrecht table token first, then the set's
`--nldesign-*` token, and MUST NOT be a literal colour. A wide table MUST scroll inside its own
container.

#### Scenario: A school content page
@e2e exclude CSS rules and rendered markup checked in node: tests/site-look/content-blocks.spec.mjs; live screenshot on :8092 in the PR
- GIVEN the wilgenboom page "Uw kind afwezig melden" with the table "Wat meldt u hoe?"
- WHEN it renders on a desktop
- THEN the table has a thin green-grey frame with rounded corners, a tinted header row, and "Wat meldt u hoe?" above it as a heading

#### Scenario: A set that names the role
@e2e exclude Token order checked in node: tests/site-look/content-blocks.spec.mjs
- GIVEN a set that declares `--utrecht-table-header-background-color`
- WHEN a table renders
- THEN the header row takes that value

### Requirement: A melding must be a card with the tint of its kind

A melding (`nlAlert`) MUST have padding inside, a gap between its heading and text, and a
background in the tint of its kind: info the set's primary light, ok, warning and error the set's
status badge background for that kind. Each MUST read the Utrecht alert token first. Two meldingen
side by side in the grid MUST be one height.

#### Scenario: Two cards side by side
@e2e exclude CSS rules checked in node: tests/site-look/content-blocks.spec.mjs; live screenshot on :8092 in the PR
- GIVEN the meldingen "Online melden" and "Liever bellen?" in one grid row
- WHEN the page renders
- THEN both show as tinted cards of the same height with room around the text

### Requirement: A link list must be a named landmark with targets of at least 24px

A link list with a heading MUST be a `nav` named by its heading through an id unique on the page. A
link list without a heading MUST NOT be a landmark. Each link MUST be at least 24px high.

#### Scenario: Three link lists on one page
@e2e exclude Rendered in node: tests/site-look/content-blocks.spec.mjs; axe on :8092 in the PR
- GIVEN a home page with the link lists "Over onze school", "Praktisch" and "Meedoen"
- WHEN axe checks the page
- THEN it reports no `landmark-unique` and no `target-size` finding
