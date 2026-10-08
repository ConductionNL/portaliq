## ADDED Requirements

### Requirement: A hero may hold a list or a photo beside its text

A hero block MAY declare `aside` (`widgetKey` one of `nlEventList`, `nlLinkList`, `nlNewsList`,
and its `props`) or `asideImage` (`src` on this site or `https:` with `alt`, or `label`). With one
of them the band MUST show the text in one column and the aside beside it, and one column on a
narrow screen. Any other widget key and any other address MUST be ignored. Without an aside the
hero MUST lay out as before.

#### Scenario: The academy's next course days
@e2e exclude Unit test in node: tests/site-look/hero-aside.spec.mjs
- GIVEN the Warmtepompacademie hero with `aside` `nlEventList` "Eerstvolgende cursusdagen"
- WHEN the home page renders on a desktop
- THEN the course days stand in a card beside the hero text

#### Scenario: Esdoornveen's photo
@e2e exclude Rendered in node: tests/site-look/hero-aside.spec.mjs
- GIVEN the Esdoornveen hero with `asideImage` and an alternative text
- WHEN the home page renders
- THEN the photo stands beside the text, with that alternative text

#### Scenario: Not a band, not a script
@e2e exclude Unit and render tests in node: tests/site-look/hero-aside.spec.mjs
- GIVEN an `aside` naming `hero`, or an `asideImage` with a `javascript:` address
- WHEN the hero renders
- THEN nothing is drawn beside the text
