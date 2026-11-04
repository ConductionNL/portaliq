# portaliq-cms Delta: portal-notices-as-drawn

## ADDED Requirements

### Requirement: A portal's side cards read as drawn

The side cards of a portal's page SHALL read as cards: the domain card SHALL list each domain (the first in bold), the certificate of the verified domain and the portal's languages in words ("Nederlands", not "nl"); the theme card SHALL show a swatch, the theme's name and a link to the themes; the status card SHALL show the state as a pill, when the portal last changed and how many of its pages wait to be published; the administrators card SHALL list each person with their initials, name and role.

#### Scenario: the side cards of Gemeente Zuiddrecht
@e2e exclude covered by tests/portal-side-cards-as-drawn.spec.mjs
- GIVEN the portal Gemeente Zuiddrecht in Dutch, with locale `nl` and chief editor Pieter Jansen
- WHEN Pieter opens the portal
- THEN the domain card reads Taal "Nederlands"
- AND the administrators card reads "PJ Pieter Jansen, Hoofdredacteur"
