# citizen-writes-on-their-own-case Specification

## Purpose
A citizen corrects their own request, adds the document that was missing,
and answers what the case worker asked, without phoning the desk. What
they may touch is declared on the case type. Requested by the dossiq
competitor analysis, round 4 cluster 48, decision D16.

## Requirements

### Requirement: The writable set comes from the case type (REQ-CWOC-001)

The portal SHALL render as writable only the fields the contribution
reports as writable for this audience, on this case, in its current
status. The portal SHALL keep no writable list of its own. A write to a
field outside that set SHALL be refused.

#### Scenario: Only the declared fields are editable
- **GIVEN** a case whose type flags two fields writable for the client audience
- **WHEN** the citizen opens the case in the portal
- **THEN** those two fields are editable and every other field is read-only
- e2e: `tests/e2e/what-the-citizen-may-write-on-their-own-case.spec.ts`

#### Scenario: A write outside the set is refused
- **GIVEN** the same case
- **WHEN** a write names a field that is not flagged writable
- **THEN** it is refused and nothing on the case changes
- @e2e exclude Refusal at the contract seam; covered by PHPUnit

#### Scenario: A closed field says why
- **GIVEN** a field that is writable only before a status the case has passed
- **WHEN** the citizen opens the case
- **THEN** the field is read-only and the reason is shown
- e2e: `tests/e2e/what-the-citizen-may-write-on-their-own-case.spec.ts`

### Requirement: The applicant amends their own submitted request (REQ-CWOC-002)

A citizen SHALL be able to change the answers they gave, inside the
window the case type declares, rendered from the same form the request
was submitted with. The change SHALL be recorded on the case as the
citizen's. Outside the window the request SHALL be read-only.

#### Scenario: A correction lands without a phone call
- **GIVEN** a submitted request inside its amendment window
- **WHEN** the citizen corrects an answer and saves
- **THEN** the case carries the new answer and a record naming the citizen
- e2e: `tests/e2e/what-the-citizen-may-write-on-their-own-case.spec.ts`

#### Scenario: The window closes
- **GIVEN** the same request with the window closed
- **WHEN** the citizen opens it
- **THEN** the answers are read-only and the reason is shown
- e2e: `tests/e2e/what-the-citizen-may-write-on-their-own-case.spec.ts`

#### Scenario: The history keeps both answers
- **GIVEN** an amended answer
- **WHEN** a case worker opens the case
- **THEN** the earlier answer and the amendment are both visible with their times

### Requirement: The citizen adds a document to their running case (REQ-CWOC-003)

A citizen SHALL be able to add a document to their own case from the
portal, through the file surface the case app declares. The document
SHALL be recorded as theirs. The portal SHALL add no second upload path
and SHALL NOT let a citizen replace or remove a document already on the
case.

#### Scenario: An aanvulling reaches the case
- **GIVEN** a running case open for documents
- **WHEN** the citizen adds a file from the portal
- **THEN** the file is on the case, recorded as the citizen's
- e2e: `tests/e2e/what-the-citizen-may-write-on-their-own-case.spec.ts`

#### Scenario: Nothing already on the case is replaced
- **GIVEN** a case with a document the municipality added
- **WHEN** the citizen adds a document of the same name
- **THEN** both documents exist and neither is overwritten
- e2e: `tests/e2e/what-the-citizen-may-write-on-their-own-case.spec.ts`

### Requirement: A task is published to the citizen and its answer returns to the process (REQ-CWOC-004)

The case app SHALL be able to raise a task for the `client` audience with
a deadline. The portal SHALL show it on the citizen's case, accept the
answer, and return it to the process that raised the task. An answered
task SHALL NOT be answerable twice.

#### Scenario: The citizen answers what was asked
- **GIVEN** a task raised for the client audience on a case
- **WHEN** the citizen answers it in the portal
- **THEN** the answer reaches the process that raised it and the task reads answered
- e2e: `tests/e2e/what-the-citizen-may-write-on-their-own-case.spec.ts`

#### Scenario: A task is answered once
- **GIVEN** an answered task
- **WHEN** the citizen opens it again
- **THEN** the answer is shown and no second answer is accepted
- e2e: `tests/e2e/what-the-citizen-may-write-on-their-own-case.spec.ts`

#### Scenario: A deadline is visible before it passes
- **GIVEN** a task with a deadline
- **WHEN** the citizen opens their case
- **THEN** the deadline is shown beside the task

### Requirement: A citizen write raises its own event (REQ-CWOC-005)

Every write a citizen makes through the portal SHALL raise a portal write
event naming the case, the identity, the act and the fields. A write made
by a staff user SHALL NOT raise it. The event SHALL reach the case app so
a rule can fire on it.

#### Scenario: The citizen's write fires a citizen rule
- **GIVEN** a rule bound to the portal write event
- **WHEN** the citizen amends their request
- **THEN** the rule fires once, with the case and the identity
- e2e: `tests/e2e/what-the-citizen-may-write-on-their-own-case.spec.ts`

#### Scenario: A staff write is not a portal write
- **GIVEN** the same rule
- **WHEN** a case worker changes the same field internally
- **THEN** the rule does not fire
- @e2e exclude Event absence on the internal path; covered by PHPUnit

### Requirement: The citizen reads the status vocabulary the case app supplied (REQ-CWOC-006)

The portal SHALL render the public status label and description the
contribution carries. It SHALL NOT hold a status vocabulary of its own
and SHALL NOT translate or rewrite the label it is given.

#### Scenario: The citizen sees the public label
- **GIVEN** a case whose status carries a public label
- **WHEN** the citizen opens the case in the portal
- **THEN** the public label is shown
- e2e: `tests/e2e/what-the-citizen-may-write-on-their-own-case.spec.ts`

#### Scenario: The portal invents no words
- **GIVEN** a case whose status carries the label the case app chose
- **WHEN** the case is rendered
- **THEN** the text shown is the label from the contribution, unchanged
- @e2e exclude Identity of the rendered string with the contribution payload; covered by PHPUnit

### Requirement: A case that has ended takes no changes and keeps the question to the handler

A case has ended when it carries `withdrawnAt`, or when the `closedField` its collection declares holds a value. The writable set of an ended case MUST say `ended: true`. It MUST close the amendment window, the document window and every field, each with the sentence "This case is not open for changes from the portal.". The withdrawal MUST be closed. The case screen MUST show the case's status, answers and withdrawal, and MUST NOT show a sentence about a closed window, a closed withdrawal or a field that cannot change. The case screen MUST still offer a question to the handler, as board `portaliq/ZaakAfgerond` draws it: the heading "Een vraag over deze zaak?", the line "Ook nu uw zaak is afgerond, kunt u ons een vraag stellen. Uw bericht komt bij de behandelaar van deze zaak.", a message field and the button "Bericht sturen". A running case MUST keep the case type's own sentences.

#### Scenario: A closed case still takes a question
- GIVEN a resident's parking permit case that staff closed on 14 oktober 2026
- WHEN the resident opens it
- THEN the screen shows "Deze zaak is afgerond op 14 oktober 2026." with the status and the decision to download
- AND it shows "Een vraag over deze zaak?" with a message field and "Bericht sturen"
- AND it does not show "Wilt u iets aanvullen?" or a way to add a document or withdraw
- @e2e exclude the question block is drawn on portaliq/ZaakAfgerond; pinned by the node test for the ended case once the site renders it

#### Scenario: A withdrawn Woo request takes no more changes
- GIVEN a resident withdrew their Woo request in the portal
- WHEN they open it again
- THEN the screen shows "Ingetrokken op" with the date and the status
- AND it does not show "Wilt u iets aanvullen?"
- AND it offers "Een vraag over deze zaak?" with "Bericht sturen", as a closed case does
- @e2e exclude pinned by `CitizenWritableSetResolverTest::testAWithdrawnCaseHasEndedAndInvitesNothing` and the node test "site: an ended case shows its state, never an invitation to add to it"; the live check on :8090 is in the PR

#### Scenario: A case closed by staff takes nothing more
- GIVEN the collection declares `closedField: isFinalStatus` and the case's status is final
- WHEN the resident opens the case or sends a change
- THEN nothing is writable, the change is refused with the neutral sentence, and no withdrawal is offered
- @e2e exclude pinned by `CitizenCaseControllerTest::testACaseItsCollectionMarksClosedHasEnded`

#### Scenario: A running case keeps its sentences
- GIVEN a running case whose amendment window has closed
- WHEN the resident opens it
- THEN the screen shows the case type's sentence for the closed window
- @e2e exclude pinned by `CitizenWritableSetResolverTest::testARunningCaseKeepsTheCaseTypesSentences`

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

### Requirement: A resident's writes on her own case are limited per case and per identity (REQ-CWL-001)

The portal SHALL count every amendment, added document and withdrawal a portal identity sends on its own case, per case and per identity, in a window of one hour, refused writes included. It SHALL refuse the 21st write on one case and the 61st for one identity within that window with 429 `too-many-writes` and the sentence "You have made a lot of changes in a short time. Please try again later." in the portal's language, and change nothing on the case.

#### Scenario: The 21st correction in an hour
- **GIVEN** a resident who sent 20 corrections on case 2026-0082 in the last hour
- **WHEN** she sends one more
- **THEN** the answer is 429 with `too-many-writes` and the case is unchanged
- @e2e exclude Server-side guard; pinned by CitizenWriteThrottleTest::testOneCaseIsStoppedAtThePerCaseLimit and CitizenCaseControllerTest::testPastTheThrottleTheWriteIsRefused

#### Scenario: Another case keeps its own budget
- **GIVEN** the same resident at the limit on case 2026-0082
- **WHEN** she adds a document to case 2026-0061
- **THEN** the document is added
- @e2e exclude Server-side guard; pinned by CitizenWriteThrottleTest::testASecondCaseHasItsOwnBudget

#### Scenario: A withdrawal counts as a write
- **GIVEN** a resident at the limit on a case
- **WHEN** she withdraws that case
- **THEN** the answer is 429 and the case is not withdrawn
- @e2e exclude Server-side guard; pinned by CitizenCaseControllerTest

#### Scenario: A refused write counts too
- **GIVEN** a resident whose 21st correction on a case was refused
- **WHEN** she sends another one within the hour
- **THEN** it is refused as well
- @e2e exclude Server-side guard; pinned by CitizenWriteThrottleTest::testARefusedWriteIsCountedSoTheNextWriteIsStillRefused

#### Scenario: A write without an identity is refused
- **GIVEN** a write that carries no subject reference
- **WHEN** it reaches the guard
- **THEN** it is refused
- @e2e exclude Server-side guard; pinned by CitizenWriteThrottleTest::testAWriteWithNoIdentityIsRefused

#### Scenario: No counter store lets the write through
- **GIVEN** an instance without a distributed cache
- **WHEN** a resident sends a correction
- **THEN** the guard lets it through and the framework's own route limits remain the floor
- @e2e exclude Server-side guard; pinned by CitizenWriteThrottleTest::testNoCacheFailsOpenRatherThanRefusingEveryone

### Requirement: The open question is answered on the case page (REQ-WDM-001)

`GET /portal/api/citizen/cases/{app}/{id}` SHALL answer `questions`: the rows of every collection of the case's contributing app that is declared with schema `aanvullingsverzoek` or carries the endpoint action the app names for answering, whose `case` is this case and whose `state` is `open`, scoped to the signed-in subject as that collection declares. Each row SHALL carry its `summary`, its `hersteltermijn` when set, and the endpoint action the app declared on that collection. The case page SHALL show each row above the status steps, in the warning-toned banner, with the action's form. Sending SHALL go through portaliq's existing endpoint action forward with the signed `X-Portal-Subject` assertion. After a 2xx answer the row SHALL read "Wij hebben uw antwoord. De behandelaar kijkt ernaar." and SHALL offer no second form. Portaliq SHALL keep no term state and SHALL NOT say the term runs again.

#### Scenario: The resident answers the question on the case
- **GIVEN** a Woo case 2026-0087 with an open question "Over welke speeltuinen en welke jaren gaat uw verzoek?"
- **WHEN** the resident opens the case and sends "De speeltuinen in Oosthaven, 2023 tot nu"
- **THEN** dossiq receives the answer through `beantwoordVraag`
- **AND** the banner reads "Wij hebben uw antwoord. De behandelaar kijkt ernaar."
- e2e: `tests/e2e/woo-dossier-in-my-cases.spec.ts` "the resident answers the question on the case"

#### Scenario: A question on another case is not shown
<!-- @e2e exclude Scope at the API seam; proven by CitizenCaseControllerTest::testQuestionsOfAnotherCaseAreNotAnswered. -->
- **GIVEN** a resident with two cases, and an open question on the other one
- **WHEN** she opens the first case
- **THEN** `questions` is empty

#### Scenario: A refused answer says so
<!-- @e2e exclude Error mapping; proven by CitizenCaseQuestionTest (vitest), which renders a 404 from the forward. -->
- **GIVEN** a question the handler closed a moment ago
- **WHEN** the resident sends an answer
- **THEN** the page says "Deze vraag is al afgehandeld." and reloads the case

### Requirement: The case shows the sentence the case app wrote about the term (REQ-WDM-002)

`portalCase` SHALL declare `termNote` (string, at most 200 characters, plain text). The case page SHALL show it under the row "Uiterlijk klaar op". When `legalDecisionDate` is empty and `termNote` is set, the page SHALL show `termNote` in that row's place. Portaliq SHALL render it as text, never as markup, and SHALL add no words to it. An empty or absent `termNote` SHALL show nothing.

#### Scenario: A paused term shows the case app's sentence instead of a date
- **GIVEN** a Woo case with no `legalDecisionDate` and `termNote` "De termijn staat stil sinds 6 oktober 2026. Hij loopt weer zodra wij uw antwoord hebben."
- **WHEN** the resident opens the case
- **THEN** the Gegevens show that sentence where the decision date would be
- e2e: `tests/e2e/woo-dossier-in-my-cases.spec.ts` "a paused term shows the sentence"

#### Scenario: Markup in the sentence stays text
<!-- @e2e exclude Escaping; proven by casePage.spec.js termNoteRows, which renders "<b>x</b>" as literal text. -->
- **GIVEN** a `termNote` that contains `<b>`
- **WHEN** the case page renders it
- **THEN** the angle brackets show as text

### Requirement: The case links to where its result is public (REQ-WDM-003)

`portalCase` SHALL declare `resultLink` (object with `label`, string, at most 80 characters, and `url`, string, `format: uri`). The case page SHALL show it as a link under the status steps when `url` uses `https` and its host is one of the Nextcloud instance's `trusted_domains`, the hosts the publication link of the case app is built on. Any other `resultLink` SHALL not be shown, and portaliq SHALL log it once per case at warning level. An empty `label` SHALL show "Bekijk het resultaat".

#### Scenario: The decision is public and the case links there
- **GIVEN** a Woo case with `resultLink` `{label: "Bekijk wat openbaar is gemaakt", url: "https://zuiddrecht.nl/woo/publicaties/2026-0087"}`
- **WHEN** the resident opens the case
- **THEN** the page shows the link "Bekijk wat openbaar is gemaakt"
- e2e: `tests/e2e/woo-dossier-in-my-cases.spec.ts` "the case links to the publication"

#### Scenario: A link to a foreign host is not shown
<!-- @e2e exclude Allow-list; proven by CitizenCaseResultLinkTest::testAForeignHostIsDropped. -->
- **GIVEN** a `resultLink` with url `http://example.com/x`
- **WHEN** the case page is built
- **THEN** no link is shown and one warning is logged
