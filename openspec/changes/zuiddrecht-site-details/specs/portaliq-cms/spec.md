## ADDED Requirements

### Requirement: The sign-in card does not invite a signed-in visitor to sign in
The `nlSignIn` widget shown as a card MUST show its `intro` only to a visitor who is not signed in. To a signed-in visitor it MUST show `signedInIntro` when the placement wrote one, and no intro otherwise.

#### Scenario: A resident opens a publication
@e2e exclude Node: tests/site-school-blocks.spec.mjs
- GIVEN a card with the intro "Log in om deze publicatie in uw eigen dossier te bewaren." and no `signedInIntro`
- WHEN a signed-in visitor sees it
- THEN the card MUST show no intro, and its button MUST lead to the visitor's own area

#### Scenario: The page wrote a text for a signed-in visitor
@e2e exclude Node: tests/site-school-blocks.spec.mjs
- GIVEN a card with a `signedInIntro`
- WHEN a signed-in visitor sees it
- THEN the card MUST show that text instead of the intro
