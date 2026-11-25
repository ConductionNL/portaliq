## Why

The school portal proof (06 Oct) showed English month names in Dutch content: "2 October 2026" in
the news list and "Oct" on the agenda tiles of De Wilgenboom. The blocks format dates in the
document's language (`<html lang>`). Since site-matches-the-zuiddrecht-boards the document
language follows the visitor when the portal also serves that language, so on a portal that
declares `nl` and `en` an English browser gets an English document, while the page and its
items are written in Dutch.

## What Changes

- `App.vue`: `contentLocale` is the page record's own `locale`, else the site's language. The
  shell provides it to the blocks (`siteContentLocale`) and sets it as the page's `lang`, so a
  screen reader reads Dutch content as Dutch (WCAG 3.1.2, language of parts).
- `NlNewsList`, `NlNewsArticle`, `NlEventList` (labels and tiles) and `NlCatalogue` (dated cards)
  format their dates in that language. Outside the shell (the editor preview, a test) nothing is
  provided and the document's language applies as before. The blocks' own words (buttons, empty
  states) still follow the document language.
- `tests/site-look/content-dates.spec.mjs`.

## Impact

- `App.vue` (a computed, `provide`, one attribute) and four lazy widgets. No new package import.
- A page whose record names `en` keeps English dates.
