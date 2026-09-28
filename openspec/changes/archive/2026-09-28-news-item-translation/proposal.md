---
kind: code
depends_on: [translated-message-notice]
---

# Proposal: news-item-translation

## Summary

A guardian who picked a message language also reads school news in it. A news
item keeps its AI translations next to the original body, with the same
provenance a guardian message keeps since #811, and the portal gets a news page
that shows the same AI notice and the show-original button.

## Motivation

`translated-message-notice` (#811, decision D24) left news out on purpose: "Translating
news items. `newsItem` gains no field here; the same component and client serve it
in a later change." This is that change. The competitor evidence is the same row:
finding 9.4 "Automatic translation of messages" (`learniq-mi/learniq/_round1/compare/findings.md`,
rung NICE); Parentcom translates inbox and news, Parro translates by default.

The portal SPA has no news view today: the guardian feed route
(`GET /api/news/feed`) exists but no page reads it. The notice needs a place to
appear, so the page is part of this change.

## Affected Projects

- [ ] Project: `portaliq`: `newsItem.translations`, translation in the feed read,
  a news page in the portal SPA.

## Scope

### In Scope

- `newsItem` 0.2.0: `translations`, the same shape as `guardianMessage.translations`;
  `body` stays the original. Register 0.37.0.
- `NewsFeedReader::feedFor()` takes the reader's language and translates the
  body of up to three not-yet-translated items per request through the existing
  `GuardianMessageTranslator` and `MessageTranslationClient`, before the photo
  consent gate, so the stored row keeps its photos.
- `NewsGuardianController::feed()` reads the reader's `messageLanguage`.
- Portal SPA: a `News` page, shown when the feed holds an item, with the language
  picker the messages page has and each body rendered through `TranslatedText`.

### Out of Scope

- Translating the news title. The notice covers the body, as for a message.
- The newsletter archive.
- Marking an item read from the page.

## Approach

Reuse. The translator gets the schema it writes to as a parameter, so news and
messages share one bounded, cached path and one client that drops unlabelled
answers.

## New Dependencies

None.

## Impact

- Register 0.37.0: `newsItem` 0.2.0, additive.
- `GET /api/news/feed` rows may carry `translation`; the array shape is unchanged.

## Cross-Project Dependencies

- hermiq's `ai-translation-provenance` contract, optional, as in #811.

## Risks

### Risk 1: A translation write loses a withheld photo
**Severity:** Medium. **Mitigation:** translation runs on the stored row before
the photo consent gate redacts the reader's copy; a test pins that the saved row
keeps `photoRefs`.

## Rollback Strategy

Revert the PR. Stored `translations` stay on the rows and are ignored.
