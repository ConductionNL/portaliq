---
kind: code
depends_on: [news-title-and-newsletter-translation]
---

# Proposal: newsletter-title-translation

## Summary

news-title-and-newsletter-translation (#842) translated a news title with its body
and made the newsletter archive show its items translated, but left the newsletter's
own title as written. This change gives the `newsletter` schema the `translations`
field in the shape `newsItem` keeps since #837 and #842, and the News page's
newsletters section shows the newsletter's title in the reader's language under the
same AI notice, with the button that shows the original.

## Motivation

The out-of-scope list of #842 reads "Translating the newsletter's own title:
`newsletter` has no `translations`". A guardian reading in Arabic now sees every
item of a newsletter in Arabic under a Dutch heading. The competitor evidence is the
row #837 and #842 cited: finding 9.4 "Automatic translation of messages"
(`learniq-mi/learniq/_round1/compare/findings.md`, rung NICE); Parentcom translates
inbox and news, Parro translates by default.

## Affected Projects

- [ ] Project: `portaliq`: the register, the translator, the archive read, the News page.

## Scope

### In Scope

- `newsletter` 0.2.0 gains `translations`: one entry per language, the translated
  title as both `text` and `title`, with the same provenance fields a news item's
  entry carries. Register 0.38.0.
- `GET /api/newsletters/archive` carries each newsletter's `translation` for the
  reader's `messageLanguage`, stored on the newsletter row, within the one
  per-request bound of three new translations that the archive's items share.
- The newsletters section shows the translated title in its heading, the AI notice
  and the show-original button, through the same `TranslatedText` component.
- A demo newsletter with an Arabic entry.

### Out of Scope

- A newsletter body: `newsletter` has none; its items are already translated.

## Approach

Reuse. `GuardianMessageTranslator::forReader()` takes the text field (`body` by
default, `title` for a newsletter) and an optional shared budget, so the archive
spends one bound across titles and items. `TranslatedText` takes the element the
shown text renders in, so a heading stays a heading.

## New Dependencies

None.

## Impact

- Register 0.38.0: `newsletter` 0.2.0, one additive server-managed property.
- Archive rows gain `translation`; the array shape is unchanged.

## Cross-Project Dependencies

- hermiq's `ai-translation-provenance` contract, optional, as in #811, #837 and #842.

## Risks

### Risk 1: The archive spends more hermiq calls per request
**Severity:** Low. **Mitigation:** titles and items share the one bound of three new
translations; a test pins that two titles and two items store three rows.

## Rollback Strategy

Revert the PR. A stored `translations` on a newsletter is ignored by the older code.
