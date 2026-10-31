## ADDED Requirements

### Requirement: Enter in a search field must search for the typed words

The site MUST hand a widget's search to the shell only when it is the typed words. A browser
event MUST NOT become a search term.

#### Scenario: Enter in the catalogue's search field
@e2e exclude Node: tests/portal-subject-rate-limit.spec.mjs; checked live on the proof instance
- GIVEN the catalogue page with its search field
- WHEN the visitor types "rekenen" and presses Enter
- THEN the results are for "rekenen", never for "[object Event]"
