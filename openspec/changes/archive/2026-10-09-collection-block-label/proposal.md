## Why

The pupil's overview board (Vaartveld, MijnOverzicht) heads the grades with "Laatste cijfers".
learniq declares that as the collection block's `label` (school portal proof item 10). Portaliq
dropped a collection block's `label` on the server and headed the table with the collection's own
label ("Mijn cijfers"), so the board's heading never showed. The same block on another page could
not be named differently from its collection.

## What Changes

- `CollectionListKeys::collectionKeys()` keeps a collection block's `label`: a non-blank string of
  at most 120 characters, trimmed.
- `ContributionPage.vue` heads a collection table with the block's `label`, else the collection's
  (`headingOf()`). The rule that hides a heading equal to the page's own title stays.
- Tests: `tests/Unit/Contribution/CollectionBlockLabelTest.php` (through the real manifest
  normaliser) and `tests/site-look/collection-label.spec.mjs`.

## Impact

- A block without `label` renders as before. The rows, bars and chips displays already read
  `block.label`; it now arrives.
