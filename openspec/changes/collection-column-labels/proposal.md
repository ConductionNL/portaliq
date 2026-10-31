## Why

The fresh install of the four school portals (proof run 1, 06 Oct, item 10, portaliq half) showed a
pupil's grades table with the headers "Course name", "Method name", "Method block", "Weight",
"Component id", "Value", "Period", "Graded at". The collection declares no `columns`, so the site
derived them from the row keys and wrote each key as words. The schema already says what each
field is called (its property `title`), and the contract already has a place for a field's name
(`fieldConfigs.<field>.label`); neither reached the table.

## What Changes

- `lib/Contribution/CollectionSchemaLabels.php`, called by `PortalManifestNormaliser` after the
  collections are normalised: for every projected field (`fields`, else the columns' fields)
  without a label in `fieldConfigs`, the label becomes the schema property's `title` (trimmed, at
  most 200 characters). The app's own label wins. Each schema is read once per manifest. Without a
  schema reader, or for a schema that cannot be read, nothing changes.
- `src/site/components/collections/cells.js` `deriveColumns()`: a header is the column's own label,
  else the field's `fieldConfigs` label, else the key as words.
- Tests: `tests/Unit/Contribution/CollectionSchemaLabelsTest.php` (through the real normaliser,
  only the register lookup is a double) and `tests/site-look/column-labels.spec.mjs`.

## Not in this change

- learniq's schema titles are English ("Grade Value", "Graded At"), so the grades table reads
  English titles until learniq declares Dutch `columns` with labels, or translated titles, and the
  "Laatste cijfers" heading (lane L3).

## Impact

- One new class, one call in `PortalManifestNormaliser`, `cells.js`, two tests.
- The site API carries a few more `fieldConfigs` labels: metadata the schema already exposes to a
  manifest that names it.
