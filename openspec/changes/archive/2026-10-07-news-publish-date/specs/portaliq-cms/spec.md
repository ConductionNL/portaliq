## ADDED Requirements

### Requirement: A news item carries the moment it was published

Publishing a news item MUST set `publishedAt` to the server's current time, never to a value from the request. Taking it back MUST clear `publishedAt`. The guardian's news feed and the record page's news block MUST sort newest first on `publishedAt`, then `@self.published`, then `@self.created`. On upgrade, every published item without `publishedAt` MUST get its creation moment. The staff News list MUST show the date, and a guardian's news item MUST say "Gepubliceerd op" with the date.

#### Scenario: A draft published today is today's news
- GIVEN a draft written in August and an item published last week
- WHEN staff publish the draft today
- THEN the draft carries today's `publishedAt` and the parent's feed lists it first
- @e2e exclude pinned by `NewsControllerTest::testPublishStampsTheMomentAndTakeBackClearsIt`, `NewsFeedReaderTest::testFeedSortsOnThePublishMomentFirst` and the node test "a draft written long ago and published today is the newest news"; the live check on :8090 is in the PR

#### Scenario: Taking an item back clears the moment
- GIVEN a published item
- WHEN staff take it back
- THEN it is a draft without `publishedAt`
- @e2e exclude pinned by `NewsControllerTest::testPublishStampsTheMomentAndTakeBackClearsIt`

#### Scenario: Existing news keeps its order
- GIVEN items published before this change, without `publishedAt`
- WHEN the app upgrades
- THEN each gets its creation moment, and a second run changes nothing
- @e2e exclude pinned by `BackfillNewsPublishedAtTest`

#### Scenario: The parent sees when an item went out
- GIVEN a published item with `publishedAt` 2026-10-03
- WHEN the parent opens Nieuws
- THEN the item says "Gepubliceerd op 3-10-2026"
- @e2e exclude pinned by the node test "a news item says when it was published, and nothing while it has no date"
