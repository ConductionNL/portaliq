# guardian-message-translation Specification

## Purpose
TBD - created by archiving change news-item-translation. Update Purpose after archive.

## Requirements

### Requirement: A news item keeps its AI translations next to the original

`newsItem` SHALL declare `translations`, one server-managed entry per target
language with the translated text and the provenance fields a `guardianMessage`
entry carries (`targetLanguage`, `text`, `translatedByAi`, `sourceLanguage`,
`model`, `originalRef`, `disclosure`, `translatedAt`). `body` SHALL stay as
written. The feed read SHALL translate the body of at most three not-yet-translated
items per request into the reader's `messageLanguage`, reuse a stored entry, and
store a new one with `originalRef` `portaliq:newsItem:<id>`. The row it stores SHALL
be the stored item, not the reader's photo-redacted copy.

#### Scenario: A guardian reading in Arabic gets the news body translated

- GIVEN a published Dutch news item in the guardian's audience and `messageLanguage` `ar`
- WHEN the guardian reads the feed
- THEN the item carries `translation` with the Arabic text and `translatedByAi` true
- AND the item is stored with that entry in `translations`, its `body` unchanged and its `photoRefs` kept
- @e2e exclude server read path; covered by `NewsFeedReaderTest::testFeedTranslatesTheBodyAndStoresTheStoredRow`

#### Scenario: No language, no translation

- GIVEN a guardian without a `messageLanguage`
- WHEN the guardian reads the feed
- THEN no item carries `translation` and nothing is stored
- @e2e exclude server read path; covered by `NewsFeedReaderTest::testFeedWithoutALanguageTranslatesNothing`

### Requirement: The news page shows the AI notice with the original one step away

The portal SHALL show a News page when the guardian's feed holds an item. Each
item SHALL render its body through `TranslatedText`: a translated body shows the
translation, the notice "Translated by AI from <language>" in an `aside` landmark
with a mark and text, and a button that shows the original. The page SHALL carry
the same language picker as the messages page.

#### Scenario: A translated news item shows the notice and the original button

- GIVEN a feed item with a labelled Arabic translation of a Dutch body
- WHEN the news item renders
- THEN the Arabic text shows, the notice names Dutch, and a button controls the hidden original
- @e2e exclude rendered in `tests/news-item-translation.spec.mjs`

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

### Requirement: A guardian chooses the language messages are shown in

The portal account SHALL carry `messageLanguage`, a BCP-47 tag, empty by default. Only the account holder SHALL set it, through `PATCH /portal/api/identity/details` with `messageLanguage`; an empty string SHALL switch translation off; a value that is not a BCP-47 shaped tag SHALL be refused with 400. `GET /portal/api/identity/details` SHALL return the holder's own `displayName`, `email`, `emailNotifications` and `messageLanguage`, and nothing about any other account. The entity is a schema.org `Person` with `knowsLanguage`.

#### Scenario: A guardian picks Arabic

- GIVEN an authenticated portal subject with an account
- WHEN they send `PATCH /portal/api/identity/details` with `messageLanguage: "ar"`
- THEN the account's `messageLanguage` MUST be `"ar"`
- AND `GET /portal/api/identity/details` MUST return `messageLanguage: "ar"`

#### Scenario: A guardian switches translation off

- GIVEN an account with `messageLanguage: "ar"`
- WHEN the holder sends `messageLanguage: ""`
- THEN the account's `messageLanguage` MUST be empty

#### Scenario: A value that is not a language is refused

- WHEN the holder sends `messageLanguage: "Arabic please"`
- THEN the response MUST be 400
- AND the account MUST NOT change

### Requirement: Messages are translated through hermiq only when hermiq is there and labels its answer

portaliq SHALL call `OCA\Hermiq\Service\MessageTranslationEngine::translate()` only when that class exists, resolved by name at runtime, never through a hard dependency. It SHALL pass the message body, the reader's language and `originalRef: "portaliq:guardianMessage:<id>"`. It SHALL keep an answer only when `available` is true and `translatedByAi` is `true`; any other answer, a missing class or a thrown error SHALL yield no translation, and the message SHALL be shown as written.

#### Scenario: No hermiq means no translation and no error

- GIVEN hermiq is not installed
- WHEN a guardian with `messageLanguage: "ar"` reads a thread
- THEN every message MUST be returned as written, without `translation`

#### Scenario: An unlabelled answer is dropped

- GIVEN hermiq answers `available: true` without `translatedByAi`
- WHEN a guardian reads a thread
- THEN no translation MUST be stored or returned

### Requirement: The stored message keeps both texts and the provenance

A translated `guardianMessage` SHALL keep `body` as written and SHALL hold one `translations` entry per target language with `targetLanguage`, `text`, `translatedByAi`, `sourceLanguage`, `sourceLanguageDetected`, `model`, `originalRef`, `disclosure`, `disclosureLanguage` and `translatedAt`. `translations` SHALL be server-managed. The entity is a schema.org `Message`; each entry is a `CreativeWork` with `translationOfWork` and `inLanguage`.

#### Scenario: A translation is stored next to the original

- GIVEN hermiq is enabled and labels its answers
- WHEN a guardian with `messageLanguage: "tr"` reads a thread holding an untranslated Dutch message
- THEN the stored message MUST keep its Dutch `body`
- AND MUST gain a `translations` entry with `targetLanguage: "tr"`, the Turkish `text` and `sourceLanguage: "nl"`

#### Scenario: A stored translation is reused

- GIVEN a message already holds a `tr` entry
- WHEN a guardian with `messageLanguage: "tr"` reads the thread again
- THEN hermiq MUST NOT be called for that message

### Requirement: Translation work per request is bounded and skips what needs none

The translator SHALL ask hermiq for at most three new translations per request, SHALL NOT translate a message the reader sent, and SHALL NOT show a translation whose source language equals the reader's language (primary subtag). Messages over the budget SHALL be returned as written and translated on a later request.

#### Scenario: A long untranslated thread is translated three at a time

- GIVEN a thread with five untranslated messages from staff
- WHEN a guardian with `messageLanguage: "ar"` reads it
- THEN hermiq MUST be called three times
- AND two messages MUST be returned without `translation`

#### Scenario: The reader's own message is not translated

- GIVEN a thread holding a message the reader sent
- WHEN the reader reads the thread
- THEN hermiq MUST NOT be called for that message

### Requirement: The reader sees a notice that AI translated the text, with the original one step away

Wherever the portal shows a message carrying a `translation`, it SHALL show the translated text marked with its language, followed by a notice reading "Translated by AI from <language>" (or "Translated by AI" when the source language is `und`), where `<language>` is the source language's name in the portal's language. The notice SHALL be an `aside` landmark with an accessible name, SHALL carry a visible mark and text, not colour alone, and SHALL hold a button that shows and hides the original text, with `aria-expanded` and `aria-controls`. The original SHALL be marked with its language.

#### Scenario: A guardian sees the Arabic translation and the notice

- GIVEN a message with a `translation` from `nl` into `ar`
- WHEN the Messages page renders it in an English portal
- THEN the Arabic text MUST be shown with `lang="ar"`
- AND an `aside` named "AI translation" MUST read "Translated by AI from Dutch"
- AND a button "Show the original text" MUST have `aria-expanded="false"`

#### Scenario: The original is one click away

- GIVEN the notice is shown
- WHEN the reader presses "Show the original text"
- THEN the Dutch original MUST be shown with `lang="nl"`
- AND the button MUST read "Hide the original text" with `aria-expanded="true"`

#### Scenario: A message without a translation shows no notice

- GIVEN a message without `translation`
- WHEN it renders
- THEN no notice MUST be shown
