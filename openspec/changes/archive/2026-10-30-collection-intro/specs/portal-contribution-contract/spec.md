## ADDED Requirements

### Requirement: A collection MAY carry an intro shown above its list

A contribution collection MAY declare `intro`, a plain text shown above the collection's list. The normaliser MUST keep it, trimmed, only when it is a non-empty string, and MUST drop it otherwise. The portal MUST show a kept intro above the collection's table, and "My cases" MUST show the intros of the `kind: cases` collections under its heading, each text once. The portal MUST render the intro as text, never as HTML.

#### Scenario: A cases collection's intro shows above its list
- GIVEN dossiq's `mijnZaken` declares `intro: 'Hier ziet u al uw lopende zaken.'`
- WHEN a resident opens "My cases", or a page that shows the collection
- THEN the sentence shows above the list
- @e2e exclude pinned by `tests/collection-intro.spec.mjs`

#### Scenario: Markup in an intro shows as typed
- GIVEN a collection declares `intro: '<b>Let op</b>'`
- WHEN the portal shows the collection
- THEN the resident reads `<b>Let op</b>` as text
- @e2e exclude pinned by `CollectionIntroKeyTest` and `tests/collection-intro.spec.mjs`

#### Scenario: An empty or non-text intro is dropped
- GIVEN a collection declares `intro: '  '` or `intro: 42`
- WHEN the manifest is normalised
- THEN `intro` is dropped
- @e2e exclude pinned by `CollectionIntroKeyTest::testAnEmptyOrNonTextIntroIsDropped`
