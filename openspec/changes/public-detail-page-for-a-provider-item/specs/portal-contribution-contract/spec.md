## ADDED Requirements

### Requirement: An app may declare a public detail page for an item of its public index

A contribution MAY declare `publicDetail` for an item kind of its public index: a route, the facts to show, text sections, a dates list and documents. Portaliq MUST serve it anonymously at the route with the item's slug, MUST render only fields the app's public index returns for that item, and MUST answer the shared 404 for an unknown slug or an item the index does not return.

#### Scenario: The F-gassen course page
- **GIVEN** learniq declares a course detail with facts, programme, bring list and three course days with their places
- **WHEN** a visitor opens `/cursusaanbod/f-gassen-herhaling-en-examen`
- **THEN** she reads the facts, the programme, "Meenemen" and three date cards, one reading "Op deze dag zijn 7 plekken vrij"
- @e2e exclude spec-only proposal; learniq's proof run asserts the board (`tests/e2e/portal-design/warmtepompacademie.spec.ts`)

#### Scenario: An item that is not public
- **GIVEN** a course without a run to come
- **WHEN** its address is opened
- **THEN** the answer is the shared 404
- @e2e exclude asserted in PHPUnit

### Requirement: A public page may hold an action that asks for sign-in first

A public detail page MAY place an app action with `requiresSignIn`. For a visitor who is not signed in, the site MUST show the action's card with its fields and a sign-in button instead of submit, and after sign-in MUST return to the same page with the chosen values kept. A signed-in person whose audience may not perform the action MUST read why, and the action MUST NOT be submitted.

#### Scenario: Linda books three places
- **GIVEN** Linda chose 22 October and 3 participants while signed out
- **WHEN** she signs in with eHerkenning and returns
- **THEN** the page shows 22 October and 3 still chosen, and "3 deelnemers inschrijven" submits learniq's booking action
- @e2e exclude spec-only proposal; asserted in learniq's proof run
