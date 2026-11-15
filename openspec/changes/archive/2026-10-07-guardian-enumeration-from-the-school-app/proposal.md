# Proposal: guardian-enumeration-from-the-school-app

## Why

Found testing a primary school end to end (2026-09-30). `news-audience-from-the-school-app` (#995) made the news feed read a real guardian's audience from the school app, but left one half behind on purpose: `GuardianAudienceFixtureReader::guardiansMatching()` still enumerated the interim `guardianAudienceFixture` only. That method is what the newsletter preflight, the newsletter send check and the emergency push all call to ask "which guardians does this target reach?". Nothing writes the fixture for a real guardian, so at the primary school:

- the newsletter preflight reported 0 recipients for a group with parents in it, and the send was refused as "empty audience";
- an emergency push reached nobody and reported `recipientCount: 0`.

## What changes

- `GuardianAccountDirectory` lists the active portal accounts of the `parent` audience: the guardians who can sign in and receive a push.
- `guardiansMatching()` keeps enumerating the fixture rows first (a fixture row still wins, the seeded demo guardians keep working), then resolves every other active guardian through `LeafGuardianAudienceReader`, the same reader the news feed already uses, and applies the same `NewsAudienceMatcher` rule.
- Because the preflight, the send check and the emergency push all call `guardiansMatching()`, they all move together; no call site changes.

## Not changed

- The fixture schema stays. It is the documented interim source for seeded demo guardians, and a guardian with a fixture row is never resolved twice.
- Photo consent from the school app (still fails closed, as in #995).
- The lookup reads each guardian's children and groups one guardian at a time. That is fine for one school (a few hundred guardians) on a staff action; a bulk reverse lookup in the school app is a later optimisation.
