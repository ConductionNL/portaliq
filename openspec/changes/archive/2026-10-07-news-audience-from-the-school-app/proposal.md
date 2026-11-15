# Proposal: news-audience-from-the-school-app

## Why

Found testing a primary school end to end (2026-09-30), flow "school news to parents". A teacher posted a news item for the whole school; the guardian, signed in to the parent portal, saw an empty news page.

The news feed matches an item's target (school, group or child) against the guardian's audience, and the audience came only from the `guardianAudienceFixture` schema, the interim stand-in the news change introduced until learniq exposed the real data (`news-and-newsletter-authoring/design.md`, "Audience source seam"). Nothing writes that fixture for a real guardian, so no real guardian had an audience and no news reached anyone.

## What changes

- A contribution for the `parent` audience may declare `guardianAudience`: the collection whose rows are the guardian's children, the child field that names the school, and a collection plus field that name the children's groups.
- `LeafGuardianAudienceReader` reads those collections for the guardian through the subject-scoped reader (scope claim, via join, per-row checks) and answers the same audience shape.
- `GuardianAudienceFixtureReader::resolveAudience()` falls back to it when the fixture has no row. A fixture row still wins, so the seeded demo guardians keep working. This is the swap the seam was built for, with no call-site change.

## Not changed

- `guardiansMatching()` (newsletter preflight, emergency push) still enumerates the fixture only; enumerating every guardian of a school through the leaf is a follow-up.
- Photo consent from the school app: learniq's consent purposes do not include news yet, so the photo gate keeps failing closed for these children.
