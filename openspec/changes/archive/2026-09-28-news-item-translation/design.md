# Design: news-item-translation

## Architecture Overview

```
GET /api/news/feed ─> NewsGuardianController.feed
     language = PortalSelfServiceService.messageLanguage(subject)
     NewsFeedReader.feedFor(subject, language)
        published + in audience (stored rows)
        ─> GuardianMessageTranslator.forReader(rows, subject, language, 'newsItem')
             stored entry, or MessageTranslationClient (hermiq) ≤ 3 per request, saved on the row
        ─> NewsPhotoConsentGate.apply (reader's copy only)
SPA: App nav "News" (feed non-empty) ─> NewsPage ─> NewsItem ─> TranslatedText
```

## Decisions

- D1. Reuse `GuardianMessageTranslator` with a trailing `$schema` parameter
  (default `guardianMessage`). A second class would copy the budget, the cache
  lookup and the language comparison.
- D2. Translate before the photo gate: the translator saves the row it was
  given, and the gate's output has `photoRefs` withheld.
- D3. The language picker moves into an exported `MessageLanguagePicker` in
  `MessagesPage.jsx` so both pages write the same `messageLanguage`.
- D4. The feed reader and the controller take the new collaborators as trailing
  nullable parameters, as #811 did, so hand-built instances keep their shape.

## Declarative-vs-imperative decision

Translation calls an external engine (hermiq), the ADR-031 external-integration
exception, as in #811. The schema change is declarative.

## Nextcloud Integration

None new.

## Security Considerations

The feed is already scoped to the guardian's audience before translation; the
translation reads and writes with RBAC off only inside that scope, as the
read-receipt write does. No pupil data is sent beyond the news body the reader
may read.

## File Structure

- `lib/Settings/portaliq_register.json`, `lib/Settings/portaliq_mock_register.json`
- `lib/Service/Messaging/GuardianMessageTranslator.php`, `lib/Service/NewsFeedReader.php`,
  `lib/Controller/NewsGuardianController.php`
- `src/portal/components/NewsPage.jsx` (new), `MessagesPage.jsx`, `App.jsx`,
  `lib/portalApi.js`, `i18n/en.json`, `i18n/nl.json`
- tests: `NewsFeedReaderTest`, `NewsGuardianControllerTest`,
  `PortaliqRegisterConfigTest`, `tests/news-item-translation.spec.mjs`

## Seed Data

The demo news item gets a Dutch body and one Arabic translation entry, like the
demo message in #811.

## Risks / Trade-offs

- The title stays in the original language.

## Migration Plan

Additive property; version bump re-imports. No migration class.
