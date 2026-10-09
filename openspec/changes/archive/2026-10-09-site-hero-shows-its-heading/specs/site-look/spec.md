## ADDED Requirements

### Requirement: A hero must show its heading, lead and search box together

A hero block MUST show its heading and lead whether or not it holds a search box, unless the author
sets `headingVisible: false`. The search box MUST have an accessible name: the author's
`searchLabel`, shown above the box; else, when the heading is hidden, the heading, shown above the
box; else the submit button's word, for screen readers only. The plain hero's lead MUST be 20px on
a line of at most 40rem unless the set names another size.

#### Scenario: A school hero with a search box
@e2e exclude Rendered in node: tests/site-look/hero-heading.spec.mjs; live screenshot on :8092 in the PR
- GIVEN the Esdoornveen home hero "Een vak leer je door het te doen" with a search box labelled "Zoek een opleiding"
- WHEN the page renders
- THEN the heading, the lead and the labelled search box all show

#### Scenario: No label of its own
@e2e exclude Rendered in node: tests/site-look/hero-heading.spec.mjs
- GIVEN a hero "Wat wilt u regelen?" with a search box and no `searchLabel`
- WHEN it renders
- THEN the heading shows and the box is named "Zoeken" for screen readers, not by the heading again

#### Scenario: The author hides the heading
@e2e exclude Rendered in node: tests/site-look/hero-heading.spec.mjs
- GIVEN a hero with a search box and `headingVisible: false`
- WHEN it renders
- THEN the heading is for screen readers only and its text labels the box, visibly
