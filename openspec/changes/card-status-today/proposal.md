## Why

The guardian overview of the school boards (De Wilgenboom, MijnOverzicht) shows a chip on each
child's card: "Ziek gemeld" when the child is reported sick today, "Op school" on any other school
day. The school portal proof (06 Oct, item 11) showed no chip. learniq now declares how to tell
(learniq #1839, `ParentSitePages::childStatus()`): a `status` lookup on the cards block over the
guardian's own absence reports. Portaliq dropped the key and had nothing to draw it with.

## What Changes

- `lib/Contribution/CardStatusKeys.php`, called from `PortalBlockResolver::collectionBlock()`: a
  `cards` collection block keeps its `status` only whole and well formed:
  - `collection`, a collection of the same contribution;
  - `matchField`, `fromField` and `toField`, fields that collection projects;
  - `label` (at most 60 characters) and `tone`;
  - optionally `otherLabel` and `otherTone`, an `only` filter `{field, in[]}` and
    `schoolDaysOnly`.
  An app's `positive` reads as the chip's `success` and `negative` as `error`; any other tone reads
  as `neutral`. A half declaration drops the key, so no chip rather than a wrong one.
- `src/site/components/mijn/cardStatus.js`: the chip of one card, from the loaded rows and today
  (the site's clock, the same one the greeting uses):
  - `label` when a row whose `matchField` is the card's id, passing `only`, covers today
    (`fromField` up to and including `toField`, a missing end meaning one day);
  - else `otherLabel`;
  - nothing on Saturday or Sunday with `schoolDaysOnly`;
  - nothing while the rows are still loading or failed to load.
- The page loads the status collection (`collectionLoader.js`) and hands its rows and today to
  `ProgressCards`, which shows the derived chip in place of a stored status.
- Tests: `tests/Unit/Contribution/CardStatusKeysTest.php` (through the real manifest normaliser),
  `tests/site-look/card-status.spec.mjs`.

## Not in this change

- School holidays: `schoolDaysOnly` knows weekends only. A holiday shows "Op school" until a
  school calendar is a source the lookup can read.

## Impact

- One new server class and one call; one pure function, three small client changes. A cards block
  without `status` renders as before.
