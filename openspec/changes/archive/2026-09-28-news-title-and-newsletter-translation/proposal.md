---
kind: code
depends_on: [news-item-translation]
---

# Proposal: news-title-and-newsletter-translation

## Summary

news-item-translation (#837) translated a news body and left two things out of
scope: the title and the newsletter archive. This change translates a news title
with its body, into the same stored entry, under the same AI notice, and makes the
newsletter archive show its items with the same translation and notice the News
page shows.

## Motivation

The out-of-scope list of news-item-translation reads "Translating the news title.
The notice covers the body, as for a message." and "The newsletter archive." A
guardian reading in Arabic now sees a translated body under a Dutch heading, and a
newsletter in the archive is a title with nothing to read. The competitor evidence
is the row #837 cited: finding 9.4 "Automatic translation of messages"
(`learniq-mi/learniq/_round1/compare/findings.md`, rung NICE); Parentcom translates
inbox and news, Parro translates by default.

## Affected Projects

- [ ] Project: `portaliq`: the translator, the feed and archive reads, the News page.

## Scope

### In Scope

- A translation entry of a news item carries `title` next to `text`: one entry,
  one provenance, one notice. An entry stored before this change gets its title on
  the next read, within the same per-request bound of three calls.
- `GET /api/newsletters/archive` reads in the guardian's `messageLanguage` and each
  newsletter carries `items`: the news items it references that are published and
  in the reader's audience, translated and photo-gated exactly as the feed serves
  them.
- The News page shows a translated title in the reader's language, the original
  shows title and body, and a newsletters section renders the archive's items
  through the same component.
- `newsItem` 0.2.1 (the `translations` description names the title), register 0.37.1.

### Out of Scope

- Translating the newsletter's own title: `newsletter` has no `translations`, and
  its items are what a guardian reads.
- A separate archive page in the navigation.

## Approach

Reuse. `GuardianMessageTranslator::forReader()` takes an optional title field;
news passes `title`, messages pass nothing and are unchanged. The title goes
through the same `MessageTranslationClient`, so an unlabelled answer adds no title.

## New Dependencies

None.

## Impact

- Register 0.37.1: `newsItem` 0.2.1, description only.
- `GET /api/newsletters/archive` rows gain `items`; the array shape is unchanged.

## Cross-Project Dependencies

- hermiq's `ai-translation-provenance` contract, optional, as in #811 and #837.

## Risks

### Risk 1: An archive item bypasses the audience or the photo gate
**Severity:** Medium. **Mitigation:** archive items come from the same published,
in-audience read as the feed and pass the same photo gate; a test pins that a draft
and an out-of-audience item are dropped and that the stored row keeps its photos.

## Rollback Strategy

Revert the PR. A stored `title` in a translation entry is ignored by the older code.
