## Why

The Esdoornveen placement detail board opens with a "Volgende stap" card: the step that matters
now ("Tussenbeoordeling op dinsdag 13 oktober, 10.00 uur"), a line about it, and a button
("Zelfbeoordeling afmaken"). The steps provider already says which step is current and when; a
steps block could only draw all steps.

## What Changes

- A `steps` block may declare `display: "highlight"` with `eyebrow`, `buttonLabel` and `page`, a
  page of the same contribution (`withRecord: true` opens it for the open record). A page that is
  not there shows no button (`StepsHighlightKeys`, through `PortalBlockResolver`).
- `StepsBlock.vue` draws one card: the current step, else the first that is not done; nothing
  when every step is done. The title is the step's name with its day, and its time when the date
  carries one ("op dinsdag 13 oktober, 10.00 uur"; English "on Tuesday 13 October, 10:00",
  `stepMoment.js`). The step's description is the line under it, and the button opens the page.
  The card takes the set's accent light. Tokens only.
- The page hands the block the route of its page and follows its `navigate`.
- Tests: `PortalBlockResolverTest::testAStepsBlockMayDrawAHighlight`,
  `tests/site-look/steps-highlight.spec.mjs`.

## Declaration for learniq (FIX-L)

On the placement page, above the steps as bars, a second steps block over the same collection:

    {"type": "steps", "collection": "studentPlacements", "display": "highlight",
     "eyebrow": "Volgende stap", "buttonLabel": "Zelfbeoordeling afmaken",
     "page": "<self-assessment page id>", "withRecord": true}

The steps provider gives the current step a `date` (a date-time when it has a time) and a
`description` ("Ruud Hermans komt naar Bakker Techniek. ...").

## Impact

- A steps block without `display: highlight` renders as before.
