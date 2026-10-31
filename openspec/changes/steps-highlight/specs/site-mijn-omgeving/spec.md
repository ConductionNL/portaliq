## ADDED Requirements

### Requirement: The steps may draw the step that matters now as a highlight

A `steps` block MAY declare `display: "highlight"` with `eyebrow`, `buttonLabel` and `page`. The
site MUST then draw one card: the current step, else the first step that is not done, with the
step's name and its day (and time), its description, and a button that opens the page when the page
belongs to the same contribution. When every step is done it MUST draw no card.

#### Scenario: The next assessment
@e2e exclude Rendered in node: tests/site-look/steps-highlight.spec.mjs; PHPUnit PortalBlockResolverTest
- GIVEN the current step "Tussenbeoordeling" on 13 October 2026 at 10:00 with a description
- WHEN the placement page renders the highlight
- THEN the card reads "Volgende stap", "Tussenbeoordeling op dinsdag 13 oktober, 10.00 uur", the description and a button "Zelfbeoordeling afmaken"
