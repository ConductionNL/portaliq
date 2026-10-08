## ADDED Requirements

### Requirement: The steps may draw as a row of bars

A `steps` block MAY declare `display: "bars"`. The site MUST then draw each step as a bar with the
step's name and one line under it: the step's description, else its date when the step is done.
The bar's colour MUST NOT be the only sign of a step's state: the current step MUST carry
`aria-current="step"` and each step its state in words for a screen reader. Without the key the
steps MUST stay the process-steps list.

#### Scenario: Milan's placement
@e2e exclude Rendered in node: tests/site-look/steps-as-bars.spec.mjs; PHPUnit PortalBlockResolverTest
- GIVEN the placement steps "Overeenkomst getekend" (done, 27 August, described "27 augustus 2026"), "Werkplan gemaakt" (done, 9 September), "Tussenbeoordeling" (current)
- WHEN the steps block with `display: "bars"` renders
- THEN three bars stand in a row, "27 augustus" appears once, "9 september" under the second, and the third is the current step
