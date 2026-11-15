## ADDED Requirements

### Requirement: A contribution MAY declare public records read through provider methods (REQ-SCR-001)

A contribution MAY declare `publicRecords`, each entry with an `id`, a `label`, an optional `group`, a `listProvider` and a `recordProvider`. The normaliser MUST keep an entry only when both providers are plain identifiers that name public methods on the provider and are not contract methods, and MUST drop it otherwise. Portaliq MUST call the providers without a subject, MUST keep only the keys the contract names with plain string or number values, and MUST fetch a record only for an id the list answer holds. The aggregate MUST never carry the provider names.

#### Scenario: decidiq's record list reaches the anonymous aggregate
- GIVEN decidiq declares `publicRecords` with `memberVotingRecords`, `listProvider: publicMembers`, `recordProvider: publicVotingRecord`
- WHEN an anonymous visitor's site loads the aggregate
- THEN it lists `memberVotingRecords` with its label and app, and no provider name
- @e2e exclude pinned by `PublicRecordsNormaliserTest` and `PublicRecordControllerTest`; the rendered page is covered by `tests/e2e/site-member-voting-record.spec.ts`

#### Scenario: A provider name that is a contract method is dropped
- GIVEN an entry with `recordProvider: getContribution`
- WHEN the manifest is normalised
- THEN the entry is dropped
- @e2e exclude pinned by `PublicRecordsNormaliserTest::testAContractMethodIsNeverAProvider`

#### Scenario: An id outside the list
- GIVEN the list answer does not hold person id X
- WHEN a visitor requests the record for X
- THEN the answer is 404 and the record provider is not called
- @e2e exclude pinned by `PublicRecordReaderTest::testARecordIsFetchedOnlyForAListedId`

### Requirement: A documents provider MUST work on any listable collection, scoped and at the collection's trust (REQ-SCR-002)

The `documents` key MUST be honoured on every listable collection, not only on case collections. A document MUST open only after the subject's scoped read of the object succeeded, only when the collection is in the subject's aggregate (so its `minTrust` is met), and only for an id the provider lists for that object at that moment. A collection the subject cannot reach and an unlisted document MUST both answer 404.

#### Scenario: The named reader opens a confidential paper
- GIVEN decidiq's `confidentialAgendaItems` at `minTrust: substantial`, and Pieter Bos signed in with DigiD at substantial and named on "Grondaankoop Lindelaan"
- WHEN he chooses Downloaden on "Taxatierapport Lindelaan"
- THEN he receives the file
- e2e: `tests/e2e/confidential-papers.spec.ts`

#### Scenario: The same reader with a password login
- GIVEN Pieter Bos signed in with a password at trust low
- WHEN he requests the paper's download address directly
- THEN the answer is 404 and nothing is streamed
- @e2e exclude pinned by `CollectionDocumentsTest::testACollectionBelowTheSessionTrustIsNotFound`

#### Scenario: Another resident with DigiD
- GIVEN a resident signed in with DigiD who is not named on the item
- WHEN she requests the paper's download address
- THEN the answer is 404
- @e2e exclude pinned by `CollectionDocumentsTest::testAnObjectOutsideTheScopeIsNotFound`

### Requirement: A documents provider MAY require a successful opened hook before streaming (REQ-SCR-003)

The `documents` key MAY name an `opened` provider method, normalised like `provider`. Portaliq MUST call it before streaming, with the object id, the document id and the subject's reference, trust, identity type and audience taken from the session. When it returns anything but `true`, or throws, portaliq MUST stream nothing and answer 503 with "The paper cannot be opened right now".

#### Scenario: The app records the opening first
- GIVEN decidiq declares `opened: confidentialPaperOpened`
- WHEN Pieter Bos opens a paper
- THEN the hook is called once with his trust `substantial` before the first byte is sent
- @e2e exclude pinned by `CollectionDocumentsTest::testTheOpenedHookRunsBeforeTheStream`

#### Scenario: The app cannot record the opening
- GIVEN the hook returns false
- WHEN Pieter Bos opens a paper
- THEN he gets 503 with "The paper cannot be opened right now" and no file
- @e2e exclude pinned by `CollectionDocumentsTest::testAFailedHookStreamsNothing`

### Requirement: A page whose collection needs a higher login MUST say so and offer it (REQ-SCR-004)

The aggregate MUST name, under `stepUp`, the collections dropped for trust alone, with their label and `minTrust` and nothing else. A signed-in resident who opens such a page MUST see which login reaches it and the sign-in choices that do, and MUST come back to the same page after signing in. When no configured sign-in route reaches the trust, the page MUST say that this login is not available here yet.

#### Scenario: A password account opens the confidential papers page
- GIVEN Pieter Bos signed in with a password
- WHEN he opens "Vertrouwelijke stukken"
- THEN he reads "Voor deze stukken moet u inloggen met DigiD of eHerkenning." with the DigiD and eHerkenning choices, and no item or count
- e2e: `tests/e2e/confidential-papers.spec.ts`
