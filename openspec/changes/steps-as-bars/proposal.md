## Why

The Esdoornveen placement detail board ("Waar sta je?") draws the steps as a row of bars: a bar
per step (done in the primary, the current one in the accent, the rest grey), the step's name and
one line under it. Proof run 2 (08 Oct) showed them as the vertical Den Haag process steps. The
first step also showed its date twice, once from the step's `date` and once in its description
("27 augustus" and "27 augustus 2026"). The plan's gap matrix named this "step indicator as bars"
(E, A): the block exists, the bar style was missing.

## What Changes

- A `steps` block may declare `display: "bars"` (`ListBlockNormaliser::recordBlock()` keeps
  exactly that value).
- `ProcessSteps.vue` draws `bars` as a row: a bar, the name, and one line, which is the step's own
  words, else its date when it is done, never both. The current step keeps
  `aria-current="step"` and each step its state in words for a screen reader. Tokens only.
- `StepsBlock.vue` hands the display on. Without it the steps stay the Den Haag list.
- Tests: `PortalBlockResolverTest::testAStepsBlockMayDrawAsBars`,
  `tests/site-look/steps-as-bars.spec.mjs`.

## For learniq (FIX-L)

- The placement page's steps block declares `display: "bars"`.
- A step that gives a `date` should not repeat it in `description`, so the list view does not
  show it twice either.

## Impact

- A steps block without `display` renders as before.
