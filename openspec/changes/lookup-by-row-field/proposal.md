## Why

The school boards name a related record on a row:
- the guardian's task card reads "Kies een tijd voor het oudergesprek van Sami";
- each absence report names the child;
- the placement detail names the supervisors.

The rows carry only a reference (`learnerRef`). A block lookup could read a second collection,
but only keyed on the row's own id (the lookup collection's `matchField` equals the row's id). It
could not go the other way, from a field of the row to the record it names. A task title could
also only join fields with a space, so "... van Sami" could not be written.

## What Changes

- A lookup may declare `rowField`: the row field whose value is matched against the lookup
  collection's `matchField`. `matchField: "id"` is the looked-up row's id, wherever the envelope
  keeps it. Without `rowField` a lookup keys on the row's id, as before
  (`RecordScopeNormaliser`, `withLookups()`).
- A lookup's `as` name counts as a field of the block's rows on the server. So `titleFields`,
  `subtitleFields`, display fields and the task title may name it.
- A tasks block may declare `titleTemplate`, a sentence of at most 200 characters with `{field}`
  places. Each place must name a field of the rows or a lookup. A row whose places stay empty
  falls back to the joined `titleFields`.
- Only the resident's own rows: a lookup reads a collection of the same contribution, which the
  portal API already scopes to the resident like any other block; nothing new is fetched.
- Tests: `tests/Unit/Contribution/LookupByRowFieldTest.php`,
  `tests/site-look/lookup-by-row-field.spec.mjs`.

## Declarations for learniq (FIX-L)

The child of a row, from the guardian's own children:

    {"as": "childName", "collection": "parentChildren", "rowField": "learnerRef",
     "matchField": "id", "valueField": "givenName"}

- Task card: the tasks block takes that lookup in `lookups` and
  `"titleTemplate": "Kies een tijd voor het oudergesprek van {childName}"` (through learniq's
  l10n), keeping `titleFields` as the fallback.
- Absence reports: the collection block takes the lookup and names it, for example
  `"titleFields": ["childName", "reason"]` or `"subtitleFields": ["childName"]`.
- Supervisors on the placement detail: a collection block over a collection the student may read
  that holds the supervisor references, with a lookup into a collection of the supervisors' names
  that is scoped to the student, for example
  `{"as": "name", "collection": "studentSupervisors", "rowField": "supervisorRef",
  "matchField": "id", "valueField": "displayName"}`.

## Impact

- Existing lookups unchanged. `TaskTitleTemplate` is a new small class.
