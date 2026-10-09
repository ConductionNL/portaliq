---
kind: code
---

# Proposal: news-and-newsletter-authoring

## Summary

Adds NewsItem authoring (per school, group or child, with idempotent read
receipts) and a Newsletter that composes existing news items with an archive
and a mandatory recipient-count preflight before send. Photos attached to a
news item are withheld unless every targeted child's guardian has granted
photo consent. This closes findings 9.1 and 9.2 (`change-plan.md` portaliq
row `news-and-newsletter-authoring`) — the single largest PO-pitch gap this
round: `git grep -rli "news\|newsletter\|announcement\|broadcast" lib/ src/`
on learniq returns zero hits, and journey J6 in `learniq-baseline/journeys.md`
is marked IMPOSSIBLE.

## Motivation

Every credible competitor in the corpus ships this: Social Schools'
"Een nieuwsbrief maken en inplannen" (findings 9.2), Kwieb's per-child
timeline with delivery/read receipts (findings 9.1), Parnassys Parro's
mededelingen with "Gelezen door" (findings 9.1), and Hoy's targeted
"Nieuws" + "Berichten" by leerjaar/klas/lesgroep. Gibbon's Messenger page
(`gibbon/round1/pages/Messenger.md`) shows the pattern worth copying — a
live recipient-count preflight before send — and RosarioSIS's `journeys.md`
J6 shows the pattern worth avoiding: its Portal Notes can only target a
*profile* (every Parent in the school), so a note for one group
"over-notifies every family not in Groep 5/6" because
`portal_notes.published_profiles` has no group/class column at all. This
change targets news items and newsletters at a school, group, or specific
child from the start, never falling back to "everyone".

## Affected Projects

- [x] Project: `portaliq` — new `newsItem`/`newsletter` OpenRegister schemas,
  authoring controller, guardian-facing read via the existing
  contribution-contract engine, recipient-count preflight, read-receipt
  service, photo-consent gate.

## Scope

### In Scope

- `newsItem` and `newsletter` OpenRegister schemas (register `portaliq`).
- Staff authoring: create/update/publish a news item targeted at a school,
  one or more groups, or specific children; compose a newsletter from
  existing news items.
- Guardian read: only published items whose target intersects the
  guardian's own resolved audience (school/group/child), exposed through the
  EXISTING `portal-contribution-contract` read engine (`PortalObjectReader`'s
  `via`/reverse-`scopeField` join, already spec'd and tested) — no new
  bespoke read path.
- A `newsRead` idempotent read-receipt: reading an item records the read
  exactly once per guardian.
- A newsletter archive, scoped the same way as news items.
- A recipient-count preflight endpoint that computes the EXACT delivery
  count using the same audience resolution the send path uses, and blocks a
  send whose resolved audience is empty.
- A photo-consent gate: a targeted child without granted photo consent
  strips the item's photos from output (text still delivers); an
  unresolvable consent state fails closed to "withheld".
- A `guardianAudienceFixture` OpenRegister schema + seed data — the INTERIM
  stand-in for learniq's `portal-contribution-guardian-audiences` change
  (another lane, in flight). See design.md "Audience source seam".

### Out of Scope

- The real learniq-side audience/consent provider — swapped in later by
  pointing the `via` join at learniq's schema once that change ships; this
  change only defines the seam and ships the fixture.
- Push delivery of a published news item or sent newsletter — covered by the
  separate `push-notifications-quiet-hours` change.
- Automatic translation of news/newsletter content (finding 9.4, `hermiq`'s
  `message-translation-delegate`, not this round's portaliq scope).
- A rich-text/WYSIWYG authoring surface — the admin form ships plain
  markdown/text fields, matching `portal-page-designer`'s existing pattern.

## Approach

`newsItem`/`newsletter` are OpenRegister objects in the `portaliq` register.
Targeting is 3-way (school OR group OR child), which the existing
`portal-contribution-contract` `via`-join is not shaped for in one
declaration (it supports one hop on one declared target field), so the
guardian read path is a small dedicated `NewsFeedReader` rather than a
generic-collection declaration — but it follows the contract's OWN
conventions throughout: subject derived only from the validated session,
identical 404 for "not in audience" and "does not exist" (no existence
oracle), fail-closed empty on an unresolved audience. New PHP: an authoring
controller/service for staff create/update/publish/compose, `NewsFeedReader`
and `NewsAudienceMatcher` for the guardian read, `NewsReadReceiptService` for
the idempotent-append semantics, `NewsletterPreflightService` for the
recipient count, and `NewsPhotoConsentGate` for the photo redaction.

## New Dependencies

None.

## Impact

- `lib/Settings/portaliq_register.json` — new schemas `newsItem`,
  `newsletter`, `guardianAudienceFixture`; a new seed `portalPage` object.
- `lib/Controller/NewsController.php` (new), `lib/Controller/
  NewsletterController.php` (new).
- `lib/Service/NewsReadReceiptService.php`, `NewsletterPreflightService.php`,
  `NewsPhotoConsentGate.php`, `GuardianAudienceFixtureReader.php` (new).
- `appinfo/routes.php` — new routes under `/api/news`, `/api/newsletters`.

## Cross-Project Dependencies

Depends on learniq's `portal-contribution-guardian-audiences` (another lane,
in flight) for the REAL audience/consent source. This change ships a fixture
seam so it is not blocked; swapping the fixture for the real collection is a
follow-up change that edits only the seed `portalPage`'s `via` config.

## Risks

### Risk 1: The fixture seam is mistaken for the real integration
**Severity:** Medium — **Mitigation:** the fixture schema is named
`guardianAudienceFixture` (not `guardianAudience`), carries a `@spec` comment
in every file that touches it, and design.md names the exact one-line config
swap for when learniq ships the real collection.

### Risk 2: A recipient-count preflight that drifts from the actual send
**Severity:** High — **Mitigation:** `NewsletterPreflightService` and the
send path call the identical audience-resolution method; a dedicated
PHPUnit test pins that both call sites produce the same count for the same
target.

## Rollback Strategy

Both new schemas and the seed `portalPage` are additive; disabling the
feature is deactivating the seed `portalPage` object (`status: draft`) and
removing the two controllers' routes. No data migration is required to roll
back — existing `newsItem`/`newsletter` objects are simply no longer served.

## Open Questions

None — the audience-source seam and preflight-drift risk above are resolved
by the fixture design, not left open.
