## ADDED Requirements

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
