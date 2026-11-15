## ADDED Requirements

### Requirement: A case that has ended offers nothing and explains nothing

A case has ended when it carries `withdrawnAt`, or when the `closedField` its collection declares holds a value. The writable set of an ended case MUST say `ended: true`. It MUST close the amendment window, the document window and every field, each with the sentence "This case is not open for changes from the portal.". The withdrawal MUST be closed. The case screen MUST show the case's status, answers and withdrawal, and MUST NOT show a sentence about a closed window, a closed withdrawal or a field that cannot change. A running case MUST keep the case type's own sentences.

#### Scenario: A withdrawn Woo request does not invite more
- GIVEN a resident withdrew their Woo request in the portal
- WHEN they open it again
- THEN the screen shows "Ingetrokken op" with the date and the status
- AND it does not show "Wilt u iets aanvullen?" or "Stuur ons een bericht"
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
