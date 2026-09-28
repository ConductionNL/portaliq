# Design: newsletter-title-translation

## Architecture overview

`NewsFeedReader::archiveFor()` reads the sent newsletters in the reader's audience,
then:

1. `translatedTitles()` passes them to `GuardianMessageTranslator::forReader()` with
   `schema: 'newsletter'`, `textField: 'title'` and `titleField: 'title'`. The
   translator asks hermiq for the title once, stores the entry on the newsletter
   row (the row minus its envelope, the same write as a news item) and sets the
   entry's `title` to its `text`, so the entry has the shape a news item's has.
2. `withItems()` translates the referenced items as before.

Both steps pass one `$budget` by reference, so the per-request bound of
`GuardianMessageTranslator::NEW_PER_REQUEST` covers titles and items together,
titles first.

## Frontend

`NewsletterArchive` renders the title through `TranslatedText` with `as="h3"` and
`id="newsletter-<id>"`: a labelled translation shows in the heading with its
`lang`, the notice and the button follow, and the original title sits in the
hidden blockquote. Without a labelled translation the heading renders as before.

## Decisions

- One entry shape for news items and newsletters (`text` and `title`), so the
  component and any later reader need no special case.
- The title is stored as `text` too, because `isLabelledTranslation()` requires a
  non-empty `text`.
