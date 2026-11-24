## ADDED Requirements

### Requirement: Today reads as the whole day

On a `dateChoices` field, today's card MUST read its date followed by ", de hele dag" in Dutch (", the whole day" in English). Tomorrow and later days MUST read their date alone.

#### Scenario: Sami is ill today
- GIVEN the absence form on Monday 5 October 2026
- WHEN his mother opens "Wanneer?"
- THEN the first card reads "Vandaag" over "Maandag 5 oktober, de hele dag"
- AND the second reads "Morgen" over "Dinsdag 6 oktober"
- @e2e exclude pure function, covered by `node --test tests/site-form-widgets.spec.mjs` ("named days: a name over the date")
