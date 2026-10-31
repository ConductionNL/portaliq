## ADDED Requirements

### Requirement: The catalogue block reads like the search boards

The catalogue block MUST let a facet be chosen from checkboxes (default), radios or a menu, one value
at a time for the last two; MAY keep the field's label for screen readers only; MAY put the field in
the column beside the results; MAY draw a card in the `meta` style (no kind label above the title, the
first fact as a label under the summary). A card with a link MUST end in a chevron. The current page
MUST show filled in the primary colour, the other pages outlined.

#### Scenario: "Nieuws en documenten" on De Wilgenboom
@e2e exclude Render checks in node: tests/site-look/catalogue-boards.spec.mjs; live screenshot on :8092 in the PR
- GIVEN the block with `kindFacet: "Soort"`, `audienceFacet: "Voor wie"`, `facetDisplay: {"Voor wie": "radio"}` and `labelHidden: true`
- WHEN a visitor searches "ouderavond"
- THEN "Filters" shows "Soort" as checkboxes and "Voor wie" as radios
- AND the field has no visible label, and each linked result ends in a chevron

#### Scenario: "Opleidingen" on Esdoornveen
@e2e exclude Render check in node: tests/site-look/catalogue-boards.spec.mjs
- GIVEN the block with `cardStyle: "meta"` and a programme with meta "Niveau 4", "BOL of BBL", "4 jaar"
- WHEN it renders
- THEN no "Opleiding" label stands above the title, and "Niveau 4" shows as a label before "BOL of BBL · 4 jaar" under the summary

#### Scenario: "Cursusaanbod" on the Warmtepompacademie
@e2e exclude Render check in node: tests/site-look/catalogue-boards.spec.mjs
- GIVEN the block with `searchPlacement: "rail"` and `display: "dated"`
- WHEN it renders
- THEN the field and a magnifier button stand above the facets, and no fact repeats above a course title
