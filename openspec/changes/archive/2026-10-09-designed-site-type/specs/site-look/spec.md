## ADDED Requirements

### Requirement: A designed portal reads its body and heading faces everywhere

On a portal with the designed header, `body` MUST read the set's document font, and every heading
MUST read the set's heading font unless a rule names that heading's own family.

#### Scenario: Vaartveld
@e2e exclude CSS checks in node: tests/site-look/designed-site-type.spec.mjs; measured on :8092 (proof run 2 instance)
- GIVEN the vaartveld set, with Red Hat Text for text and Red Hat Display for headings
- WHEN the home page renders
- THEN `body` computes Red Hat Text and the hero heading Red Hat Display
