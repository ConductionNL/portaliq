---
kind: code
depends_on:
  - extracurricular-activity-offer
---

# Proposal: activity-parental-consent

## Summary

An activity can require a guardian's permission (the permission slip for a
trip or a club outside school). The guardian agrees to the activity's own
consent text when signing up, and the sign-up keeps a record of that text, who
agreed and when. For an activity where photos are taken, the roster tells the
supervisor which children have photo consent, read through the existing
`photoConsentGranted()` helper that no code called until now.

## Motivation

Recon E (`learniq-mi/learniq/_round2/recon/E-roles-and-lesson-shop.md`),
section 1b, row "Parental consent for an activity (permission slip)":
**missing**. `eventRsvp.response` is an attendance intention, not a consent
record, and no schema carries a consent field. The same row names the
precedent: `photoConsentGranted()` in `GuardianAudienceFixtureReader`, built
for news items and never called. Section 4 proposes this change and asks to
wire that precedent rather than invent a mechanism.

A school needs the permission of a parent before a child leaves the building
or joins an activity with its own risks, and it needs to prove what the parent
agreed to. A photo of a child who has no photo consent must not be taken or
shared (AVG, and the school's own beeldmateriaal policy that learniq PR 928
records per purpose).

## Affected Projects

- [x] Project: `portaliq`: `activityOffer` gains `consentRequired`,
  `consentStatement` and `photosTaken`; `activitySignup` gains a `consent`
  record; sign-up checks the accepted text; the roster and the guardian feed
  show consent and photo consent.

## Scope

### In Scope

- Staff mark an activity as needing consent and write the consent text. An
  activity that needs consent cannot open without a text.
- A guardian signs up by sending back the exact text they were shown. A
  missing or outdated text is refused with 422 `consent_required` and nothing
  is written.
- The sign-up stores `consent: {statement, grantedByRef, grantedAt}`: the text
  as agreed, not a pointer to text that may change.
- For an activity with `photosTaken`, the staff roster and the guardian's own
  sign-ups show whether photo consent is on file, from
  `photoConsentGranted()`, fail closed.
- Withdrawing consent is withdrawing the sign-up (the existing withdraw).

### Out of Scope

- Consent on `eventSignup` for one-off events. The recon names it too; an
  activity is where a permission slip belongs, and events can follow.
- Collecting photo consent itself. It stays where it lives: the guardian
  audience seam today, learniq's `beeldmateriaalConsent` later.
- A second guardian's separate consent. One guardian's agreement is recorded,
  as learniq PR 928 decided for photo consent.

## Approach

Three fields on the activity, one record on the sign-up, one check in
`ActivitySignupService::signUp()`, one check in `ActivityController::open()`,
and a delegate on `ActivityFeedReader` that calls `photoConsentGranted()` so
the roster and the feed ask the same question.

## New Dependencies

None.

## Impact

- `lib/Settings/portaliq_register.json` (register 0.35.0, activityOffer and
  activitySignup 0.2.0), mock register, `l10n/`.
- `ActivitySignupService`, `ActivityFeedReader`, `ActivityController`,
  `ActivityGuardianController`, `ActivityDraft`.

## Cross-Project Dependencies

Stacked on `extracurricular-activity-offer` (portaliq PR #746). No other app.

## Risks

### Risk 1: A guardian agrees to one text and staff change it afterwards
**Severity:** Medium. **Mitigation:** the record keeps the text as agreed; a
sign-up after the change must agree to the new text, because the server
compares the sent text with the current one.

### Risk 2: Photo consent reads from an interim fixture
**Severity:** Low. **Mitigation:** the roster reads through the one seam every
consent check uses, so swapping the fixture for learniq's real data is a
change to that one class. An absent entry reads as no consent.

## Rollback Strategy

Revert the PR. The new fields are optional; rows written in the meantime keep
their `consent` record, unread by the reverted code.

## Open Questions

None.
