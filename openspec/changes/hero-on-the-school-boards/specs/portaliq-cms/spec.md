## ADDED Requirements

### Requirement: The hero hands its portal to the block beside it and draws its search on the band

The site MUST hand the portal to a hero, and the hero MUST give it to the block beside its text,
after that block's authored props. A plain hero's search box MUST have no background of its own,
and its label MUST read the set's label weight and size, regular and 18px when the set names
none.

#### Scenario: The academy's course days fill themselves
@e2e exclude Method checks in node: tests/site-look/hero-on-the-school-boards.spec.mjs
- GIVEN the academy hero with `aside` `nlEventList` from the catalogue and no `portal` in its props
- WHEN the home page renders
- THEN the list reads the warmtepompacademie catalogue

#### Scenario: Esdoornveen's search on a white hero
@e2e exclude CSS check in node; screenshot on :8092 in the PR
- GIVEN the Esdoornveen plain hero with a search box
- WHEN the home page renders
- THEN no coloured strip stands behind the label
