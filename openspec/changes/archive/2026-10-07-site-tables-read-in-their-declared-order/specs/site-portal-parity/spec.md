## ADDED Requirements

### Requirement: A button link carries its stylesheet

A site component that gives a link the `utrecht-button-link` classes MUST import `@utrecht/button-link-css`, and the package MUST be a dependency of its own. A link whose classes no stylesheet defines falls back to the browser's own link colour, whatever the theme says.

#### Scenario: The sign-in link on a themed welcome page
- GIVEN a portal with a theme
- WHEN a visitor who is not signed in opens the site
- THEN "Inloggen met DigiD" in the page body draws as the theme's primary button
- AND its colour is not the browser's rgb(0, 0, 238)
- @e2e exclude pinned by `tests/ways-in-screens.spec.mjs` ("a button link ships its stylesheet, so a sign-in link never falls back to browser blue"); checked live on the Wilgenboom portal
