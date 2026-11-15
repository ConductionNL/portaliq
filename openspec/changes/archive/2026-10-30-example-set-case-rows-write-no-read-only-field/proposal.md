# Proposal: example-set-case-rows-write-no-read-only-field

## Why

On :8080 (24 Oct) the Zuiddrecht install reported both Lindelaan sub-cases as "written but not kept: deadline". The dossiq case schema declares `deadline` readOnly: dossiq derives it from the statutory term (or the case type's processing deadline), so the value the fragment wrote (`{{date:5}}`, `{{date:9}}`) is never kept, and the install cannot exit 0.

## What Changes

- The two sub-cases no longer write `deadline`; their deadline is the one dossiq derives.
- A test reads the dossiq case schema fixture and refuses any dossiq case row in the fragment that writes a readOnly property.

## Impact

- `lib/Settings/example-sets/zuiddrecht.json`, `tests/Unit/Settings/ZuiddrechtLindelaanRelatedTest.php`.
