## ADDED Requirements

### Requirement: A choice card may carry a second line

A `collection` options provider MAY declare `descriptionField`; each option MUST then carry that row field as its description when it is a non-empty string. A choice card MUST show an option's description as a second line under its label, and MUST draw no empty line for an option without one. A named-day card MUST read as a name over its date: "Vandaag" or "Morgen" over the date in the site's language, and a later day as its date alone.

#### Scenario: Who is absent, as cards with the group and teacher
- GIVEN the absence form's `learnerRef` provider with `labelField: givenName` and `descriptionField: groupLabel`
- WHEN a guardian of Vera ("Groep 7 · Meester Daan") and Sami ("Groep 4 · Juf Esra") opens the form
- THEN she sees a card "Vera" with "Groep 7 · Meester Daan" under it and a card "Sami" with "Groep 4 · Juf Esra" under it
- @e2e exclude component behaviour, covered by `node --test tests/site-form-widgets.spec.mjs` ("a child card carries its group and teacher as a second line", "a collection option carries the row field its provider names")

#### Scenario: When, as today and tomorrow
- GIVEN a `dateChoices` field with two named days, opened on Monday 5 October 2026
- WHEN the form renders in Dutch
- THEN the cards read "Vandaag" over "Maandag 5 oktober" and "Morgen" over "Dinsdag 6 oktober", then "Een andere dag"
- @e2e exclude pure function and component, covered by `node --test tests/site-form-widgets.spec.mjs` ("named days: a name over the date")
