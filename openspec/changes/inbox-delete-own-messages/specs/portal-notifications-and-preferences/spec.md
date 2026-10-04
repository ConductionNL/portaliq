## ADDED Requirements

### Requirement: A resident can delete their own inbox messages

A resident MUST be able to delete one or several of their own inbox messages. Portaliq's own notices MAY always be deleted; a message in an app's inbox MAY be deleted only when its collection declares `deletable: true`. The server MUST check that the (register, schema) is an inbox the resident may read, MUST check the trust level again, and MUST delete a row only when its scope field holds the resident's own reference alone and its tenant matches. Another resident's message, a message shared with someone else and an unknown id MUST answer the same 404 with nothing deleted. The page MUST ask for confirmation on the page itself before deleting, never with a browser dialog, and MUST say afterwards what was deleted or that a message could not be deleted.

#### Scenario: A parent deletes one message after confirming
- GIVEN a parent with a notice in Berichten
- WHEN she presses "Verwijderen" and then "Ja, verwijderen"
- THEN the notice is gone from her inbox and the page says "Het bericht is verwijderd."
- @e2e exclude pinned by the node tests in `tests/inbox-delete.spec.mjs` and `ContributionControllerTest::testDeleteMessageRemovesTheResidentsOwnNotice`; the live check on :8090 is in the PR

#### Scenario: Cancel deletes nothing
- GIVEN the question is on the page
- WHEN the parent presses "Annuleren"
- THEN nothing is deleted
- @e2e exclude pinned by the node test "deleting one message asks first on the page, and Cancel deletes nothing"

#### Scenario: Several messages at once
- GIVEN three notices
- WHEN the parent chooses "Alles selecteren", "Geselecteerde verwijderen (3)" and confirms
- THEN all three are deleted and the unread count follows
- @e2e exclude pinned by the node test "deleting the selected messages removes them, updates the count and says so"

#### Scenario: Another resident's message is never deleted
- GIVEN a message id that belongs to another resident, or is shared with one
- WHEN a parent sends a delete for it
- THEN the answer is 404 and nothing is deleted
- @e2e exclude pinned by `PortalObjectWriterDeleteTest::testItNeverDeletesARowThatIsNotTheSubjectsAlone` and `ContributionControllerTest::testDeleteMessageOfAnotherResidentIs404`

#### Scenario: An app's inbox decides
- GIVEN an app's inbox collection without `deletable: true`
- WHEN a parent sends a delete for one of its messages
- THEN the answer is 403 and the page offers no delete for it
- @e2e exclude pinned by `ContributionControllerTest::testDeleteMessageFromAnAppsInboxNeedsItsConsent` and `PortalInboxReaderTest::testEachRowSaysWhetherTheResidentMayDeleteIt`
