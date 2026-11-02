# Design: password-reset-from-the-sign-in-page

## Screen

Follows the Zuiddrecht board **Inloggen** ("Mijn Zuiddrecht: inloggen", canvas `5NkFW28vZUUij43xzxHg5a`). Under the account sign-in the board lists three links: "Account aanmaken", "Wachtwoord vergeten", "Een zaak volgen met het zaaknummer". This change adds the second one to the sign-in routes component (`src/site/components/WaysIn.vue`), in the same link style as the others.

The board draws the e-mail and password fields on the portal's own page. Portaliq does not draw them: the account button opens Nextcloud's login form, which carries the fields. That difference stays and is named in the PR.

## Target

`signInRoutes()` returns, for a portal that offers `nextcloud`, a `lostPasswordUrl`: Nextcloud's lost-password route (`core.lost.index` when present on the running version, else the login form with its own "Wachtwoord vergeten?" link) with `redirect_url` set to `/portal/api/session/nextcloud?portal={slug}&returnTo={sign-in page}`. The URL is built server side with `IURLGenerator`, so the site never guesses a core path.

## Why not our own reset

See the proposal's "Decision taken". The security posture of `SessionController::nextcloud()` (no password of ours) is kept.
