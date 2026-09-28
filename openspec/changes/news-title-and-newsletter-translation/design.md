# Design: news-title-and-newsletter-translation

## Architecture Overview

```
feed    ─> NewsFeedReader.itemsFor(audience) ─> translated(titleField: title) ─> photo gate
archive ─> sent, in-audience newsletters ─> withItems:
             itemsFor(audience) ∩ itemRefs ─> translated (one pass) ─> photo gate ─> newsletter.items
translator.forReader ─> entryFor: stored | new (body + title) | stored + title
SPA NewsPage ─> feed + archive ─> NewsItem (title in reader's language, original shows both)
```

## Decisions

- D1. The title lives in the body's entry, not in an entry of its own. The notice,
  the provenance and the show-original button are per entry, so one entry is what
  makes "same provenance, same notice" true by construction.
- D2. A stored entry without a title is topped up, spending one call of the
  per-request budget, and written back in place (no second entry for the language).
- D3. The archive translates every referenced item in one `forReader()` call, so the
  bound of three new calls per request holds across newsletters.
- D4. Archive items are read through the same published and in-audience filter as
  the feed; `readOwnItem()` now reads through that filter too, which removes the
  duplicated matching.
- D5. In the SPA an archive item uses an id prefix per newsletter, so an item that is
  both in the feed and in a newsletter does not produce duplicate element ids for
  `aria-controls`.

## Declarative-vs-imperative decision

No new behaviour of a declarative kind; the translation path was already imperative.

## Security Considerations

Archive items pass the same audience check and photo gate as feed items; the
translation write stores the stored row, before the gate.

## File Structure

- `lib/Service/Messaging/GuardianMessageTranslator.php`
- `lib/Service/NewsFeedReader.php`
- `lib/Controller/NewsGuardianController.php`
- `lib/Settings/portaliq_register.json`, `lib/Settings/portaliq_mock_register.json`
- `src/portal/components/NewsPage.jsx`, `TranslatedText.jsx`, `lib/portalApi.js`,
  `i18n/en.json`, `i18n/nl.json`, `theme.css`
- tests: `NewsFeedReaderTest`, `NewsGuardianControllerTest`,
  `PortaliqRegisterConfigTest`, `tests/news-title-and-newsletter-translation.spec.mjs`

## Seed Data

The demo news item's Arabic translation gains a title (mock register 1.0.5).
