# guardian-message-translation Specification

## ADDED Requirements

### Requirement: A newsletter keeps its title translations next to the original

The `newsletter` schema SHALL carry `translations`, server-managed, in the shape a
`newsItem` keeps: one entry per target language with the translated title as
`text` and as `title`, the source language, the model, a reference to the
newsletter and the disclosure. The newsletter's `title` SHALL stay as written.
`GET /api/newsletters/archive` SHALL carry, for a reader with a `messageLanguage`,
each newsletter's `translation` when a labelled AI translation into another
language applies, reusing a stored entry or storing a new one. New translations of
newsletter titles and of the archive's items SHALL share one per-request bound. The
News page SHALL show the translated title in the newsletter's heading, with the AI
notice and a button that shows the original title.

#### Scenario: The archive translates a newsletter's own title

- GIVEN a sent Dutch newsletter titled Nieuwsbrief september and a reader with `messageLanguage` `ar`
- WHEN the reader reads the archive
- THEN the newsletter carries a `translation` with the Arabic title as `text` and `title`, and one entry is stored on the newsletter row
- @e2e exclude server read path; covered by `NewsFeedReaderTest::testArchiveTranslatesTheNewslettersOwnTitle`

#### Scenario: A stored title translation is reused

- GIVEN a newsletter with a stored Arabic entry
- WHEN the reader reads the archive
- THEN the stored entry is shown and nothing is stored
- @e2e exclude server read path; covered by `NewsFeedReaderTest::testArchiveReusesAStoredNewsletterTitleTranslation`

#### Scenario: Titles and items share the per-request bound

- GIVEN two sent newsletters, each referencing one untranslated item
- WHEN a reader with `messageLanguage` `ar` reads the archive
- THEN three rows are translated in total, the two titles first
- @e2e exclude server read path; covered by `NewsFeedReaderTest::testNewsletterTitlesAndItemsShareThePerRequestBound`

#### Scenario: Without a language the title stays as written

- GIVEN a reader without a `messageLanguage`
- WHEN the reader reads the archive
- THEN no newsletter carries a `translation` and nothing is stored
- @e2e exclude server read path; covered by `NewsFeedReaderTest::testArchiveWithoutALanguageLeavesTheNewsletterTitleAsWritten`

#### Scenario: The newsletters section shows the translated title under the notice

- GIVEN a newsletter whose labelled translation carries an Arabic title
- WHEN the News page renders the archive
- THEN the heading shows the Arabic title with its language, one notice appears, and the button shows the original title
- @e2e exclude rendered component; covered by `tests/newsletter-title-translation.spec.mjs`

#### Scenario: The register declares the field

- GIVEN the portaliq register
- WHEN it is read
- THEN `newsletter` is at 0.2.0 with an array `translations`, and the register is at 0.38.0
- @e2e exclude register descriptor; covered by `PortaliqRegisterConfigTest::testRegisterJsonParsesAndVersionsAreBumped`
