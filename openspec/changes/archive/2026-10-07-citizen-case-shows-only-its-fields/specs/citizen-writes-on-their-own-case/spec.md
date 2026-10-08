## ADDED Requirements

### Requirement: The case screen receives only the fields its collection declares

The server MUST project the case it returns from the case screen's read, its amendment and its withdrawal to the `fields` the contribution's collection on the case's register and schema declares. It MUST add only what the screen works with: the action's `fields`, the fields the writable set names, the status field and `withdrawnAt` and `withdrawalReason`. It MUST keep the identifiers and reduce `@self` to its `id`. The writable set and the withdrawal MUST still be resolved from the full row on the server. A collection that declares no `fields` MUST pass the row whole, as its list does. A malformed declaration MUST project to the identifiers only.

#### Scenario: A resident's browser never receives a staff field
- GIVEN dossiq's `mijnZaken` collection declares `fields` without `assignee`
- AND a resident's own Woo request has an assignee
- WHEN the resident opens the case, amends it or withdraws it
- THEN the case in each answer has no `assignee`, no `qualityScore` and no `portalWrites`
- AND the writable set is the same as before
- @e2e exclude pinned by `CitizenCaseControllerTest::testTheCaseScreenReceivesOnlyTheDeclaredFields`, `::testAWithdrawnCaseComesBackWithoutTheStaffFields` and `::testAnAmendedCaseComesBackWithoutTheStaffFields`; the live check on the dossiq Woo flow is in the PR

#### Scenario: A malformed declaration fails narrow
- GIVEN the collection declares `fields: "title"`
- WHEN the resident opens the case
- THEN the case carries only `id` and `@self`
- @e2e exclude pinned by `CitizenCaseControllerTest::testAMalformedDeclarationShowsOnlyTheIdentifiers`
