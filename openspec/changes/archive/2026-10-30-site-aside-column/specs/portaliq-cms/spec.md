## ADDED Requirements

### Requirement: A page may give its aside a fixed width

A grid page body MAY declare `aside` with a `width` (160 to 640 pixels) and a `gap` (0 to 160, default 48). The site MUST draw such a run as a main column taking the rest of the width and an aside of that width: a run with a widget starting at the left edge narrower than the page, a widget ending at the right edge, and otherwise only full-width widgets. A full-width widget MUST span both columns. Any other run, a page without `aside`, and a phone-width screen MUST lay out as before.

#### Scenario: Zuiddrecht Home
@e2e exclude Unit test in node: tests/site-grid.spec.mjs; PHPUnit CmsReaderTest
- GIVEN the Zuiddrecht Home declares `aside: {width: 340, gap: 56}`
- WHEN the page is drawn on a wide screen
- THEN "Mijn Zuiddrecht" and "Openbare informatie" are 340px wide beside the news, 56px apart

#### Scenario: A page without an aside
@e2e exclude Unit test in node: tests/site-grid.spec.mjs
- GIVEN a grid page that declares no `aside`
- WHEN it is drawn
- THEN every run uses the 12-column grid as before
