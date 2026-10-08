# guardian-message-translation Specification

## ADDED Requirements

### Requirement: A news title is translated with its body

A news item's translation entry SHALL carry `title`, the item's title translated
into the same target language, next to `text`, so one entry, one provenance and one
AI notice cover both. The title SHALL be kept only when the answer is a labelled AI
translation. An entry stored without a title SHALL get it on a later read, within
the per-request bound, and SHALL be written back in place. The portal SHALL show a
translated title in the reader's language and the original SHALL show the title
and the body as written. A guardian message SHALL be unchanged.

#### Scenario: The title is translated with the body

- GIVEN a published Dutch news item titled Studiedag and a reader with `messageLanguage` `ar`
- WHEN the reader reads the feed
- THEN the item's `translation` carries the Arabic title and text, and one entry is stored
- @e2e exclude server read path; covered by `NewsFeedReaderTest::testFeedTranslatesTheTitleWithTheBody`

#### Scenario: An entry stored before titles gets its title

- GIVEN a news item with a stored Arabic entry without a title
- WHEN the reader reads the feed
- THEN the entry gains the Arabic title, is stored in place, and a second read stores nothing
- @e2e exclude server read path; covered by `NewsFeedReaderTest::testAStoredTranslationWithoutATitleGetsItsTitle`

#### Scenario: The portal shows the translated title under the one notice

- GIVEN a news item whose labelled translation carries a title
- WHEN the News page renders it
- THEN the heading shows the translated title with its language, one notice appears, and the original shows title and body
- @e2e exclude rendered component; covered by `tests/news-title-and-newsletter-translation.spec.mjs`

### Requirement: The newsletter archive shows its items as the News page does

`GET /api/newsletters/archive` SHALL read in the guardian's `messageLanguage`. Each
newsletter SHALL carry `items`: the news items in its `itemRefs`, in that order,
that are published and in the reader's audience, translated and photo-gated as the
feed serves them. The News page SHALL render the archive's items through the same
item component, with the same translation and notice.

#### Scenario: The archive carries translated items and drops what the reader may not see

- GIVEN a sent newsletter referencing a published item, a draft, an item for another school and a missing id
- WHEN a reader with `messageLanguage` `ar` reads the archive
- THEN the newsletter carries only the published item, translated with its title, photo-gated, and the stored row keeps its photos
- @e2e exclude server read path; covered by `NewsFeedReaderTest::testArchiveCarriesItsItemsTranslatedLikeTheFeed`

#### Scenario: The archive is read in the guardian's language

- GIVEN a guardian whose `messageLanguage` is `ar`
- WHEN the archive endpoint is called
- THEN the reader reads the archive in `ar`
- @e2e exclude controller; covered by `NewsGuardianControllerTest::testArchiveIsReadInTheGuardiansMessageLanguage`

#### Scenario: The News page renders the archive

- GIVEN an archive with one newsletter holding a translated item and one with none for the reader
- WHEN the News page renders
- THEN a newsletters section shows the item with the AI notice and says the other has no items for the reader
- @e2e exclude rendered component; covered by `tests/news-title-and-newsletter-translation.spec.mjs`
