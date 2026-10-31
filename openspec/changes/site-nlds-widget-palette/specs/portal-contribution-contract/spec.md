## ADDED Requirements

### Requirement: An action MAY offer itself as a start tile with a summary and its audiences (REQ-SNW-020)

A create or endpoint action MAY declare `summary` (a string of 1 to 200 characters) and `audiences` (a non-empty subset of the provider's audiences). Other values MUST be dropped. The site MUST offer a start tiles widget that lists, for the serving portal, every action with a `summary` that a page of its contribution offers, drawn as the Home board's "Direct regelen" list: one link per action, by its label, to that page (`/mijn/<app>/<page>`). The summary travels in the endpoint answer; the Home board draws no sentence under a tile, so the widget shows none. The list MUST come from an endpoint that returns only label, summary, audiences and route, and MUST be readable without signing in. Choosing a tile while signed out MUST lead to sign-in first.

#### Scenario: "Wat wilt u regelen?" on the signed-out home
- GIVEN dossiq's `startWooVerzoekAlgemeen`, `createBezwaar` and `createKlacht` declare a summary
- WHEN a signed-out visitor opens a home with a start tiles widget (board `Home.dc.html`, "Direct regelen")
- THEN three tiles show, among them "Bezwaar maken", and the endpoint answer carries its summary "Bent u het niet eens met een besluit? Maak binnen zes weken bezwaar."
- AND the endpoint answer carries no field names or app endpoints

#### Scenario: An unknown audience is dropped
- GIVEN an action declares `audiences: ["citizen", "alien"]` and the provider knows `citizen` only
- WHEN the contribution is normalised
- THEN the action's `audiences` is `["citizen"]`
