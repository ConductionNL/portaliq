## ADDED Requirements

### Requirement: An anonymous collection shows what the public may read

A collection a contribution declares `anonymous: true` MUST be read without the subject scope, with OpenRegister's own read rules applied to the caller as it is, so the read never shows more than OpenRegister shows that caller. Its declared `filter` MUST narrow the read, a row naming another tenant than the session's MUST be dropped, and its `fields` whitelist MUST shape every row. Every other collection MUST stay scoped to the subject.

#### Scenario: The council calendar
- GIVEN decidiq contributes `publicCalendar` over `publication-payload` with `anonymous: true` and `filter: {oriType: "Vergadering"}`
- AND three council meetings are published
- WHEN a signed-in resident opens "Vergaderkalender"
- THEN the meetings are listed
- AND a payload whose publication date lies ahead is not
- @e2e exclude pinned by `PortalObjectReaderTest::testAPublicReadLetsOpenRegisterDecideAndNarrowsAndProjects` and `ContributionControllerTest::testAnAnonymousCollectionIsReadAsThePublicMayReadIt`; the live check on the rehearsal follows the merge

#### Scenario: A resident's own records stay theirs
- GIVEN a collection without `anonymous`
- WHEN a resident reads it
- THEN only the rows their scope field names are listed, as before
- @e2e exclude pinned by `PortalObjectReaderTest::testFiltersOnScopeAndDropsForeignRows`
