# Proposal: news-publish-date

## Why

Seen in the parent portal review of 2026-10-03. Publishing a news item only changed its `status`, so OpenRegister's `@self.published` stayed empty and the feed (#1102) sorted on `@self.created`. A draft written in August and published today sat below last week's news, and nobody could see when an item went out.

## What changes

- A news item carries `publishedAt` (date-time). `NewsController::publish` stamps it from the server clock; taking the item back (`unpublish`, which makes it a draft again) clears it, and publishing again stamps a new moment. It is never taken from the request.
- The guardian's feed and the record page's news block sort on `publishedAt` first, then `@self.published`, then `@self.created`.
- A repair step (`BackfillNewsPublishedAt`, post-migration) gives every published item without `publishedAt` its creation moment, so existing news keeps its order. Drafts, stamped items and undated items stay as they are; a second run writes nothing.
- The staff News list shows a "Published on" column. A parent's news item shows "Gepubliceerd op 3-10-2026" under its title, on the Nieuws page, in the newsletter archive and in the record page's news block.
- Register 0.56.0, `newsItem` 0.3.0.

## Not changed

- Who may publish, and what a guardian may read.
