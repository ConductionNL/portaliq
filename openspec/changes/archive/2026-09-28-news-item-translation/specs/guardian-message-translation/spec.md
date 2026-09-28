# guardian-message-translation Specification

## ADDED Requirements

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
