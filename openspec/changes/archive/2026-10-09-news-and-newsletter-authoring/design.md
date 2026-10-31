# Design: news-and-newsletter-authoring

## Architecture Overview

`newsItem` and `newsletter` are ordinary OpenRegister objects in the
`portaliq` register. Targeting is genuinely 3-way ("school OR group OR
child, any match" — finding 9.1's exact phrase), which the existing
`portal-contribution-contract` `via`-join supports only as ONE hop on ONE
declared `targetField` per collection — it is not shaped for a 3-way OR
across school/group/child in a single collection declaration. Rather than
force that fit (three separate declared collections merged client-side would
split one feed into three network calls for no benefit), the guardian read
path is a small DEDICATED reader (`NewsFeedReader`) that calls the SAME
`GuardianAudienceFixtureReader` + `NewsAudienceMatcher` the preflight and
consent gate use, and follows the contract's OWN conventions throughout: the
subject is derived only from the validated portal session
(`PortalSessionService`, never client input), an out-of-audience item and a
non-existent item return the IDENTICAL 404 (no existence oracle, mirroring
"Scoped single-object read"), and an unresolved audience yields zero rows,
never an error (fail-closed empty, mirroring "An absent claim yields an
empty collection"). Staff authoring/publish, the idempotent read-receipt
append, the recipient-count preflight, and the photo-consent gate are all
new PHP; nothing routes through the generic `ContributionController`.

```
staff (admin UI) --> NewsController / NewsletterController --> ObjectService (OR)
guardian (portal) --> NewsController::feed / NewsletterController::archive
                         --> NewsFeedReader --> GuardianAudienceFixtureReader (interim)
                                             --> NewsAudienceMatcher
guardian read receipt --> NewsController::markRead --> NewsReadReceiptService --> ObjectService
staff preflight --> NewsletterController::preflight --> NewsletterPreflightService --> GuardianAudienceFixtureReader
```

## Audience source seam

`portal-contribution-guardian-audiences` (learniq, another lane, in flight)
will eventually expose the guardian's own school/group/child audience and
per-child photo consent as a real OpenRegister collection this app can
`via`-join against, per the ALREADY-SPEC'D "reverse scopeField join"
requirement in `portal-contribution-contract`. Until it ships, this change
defines the SAME shape as a portaliq-owned fixture:

- Schema `guardianAudienceFixture` (register `portaliq`): `guardianRef`
  (subjectRef), `schoolRef`, `groupRefs[]`, `childRefs[]`, `photoConsent`
  (`{childRef: {purpose: bool}}` — this change models one purpose,
  `GuardianAudienceFixtureReader::PURPOSE_NEWS = "news"`; findings 2.8 names
  schoolgids/website/nieuwsbrief/social media/Parro as separate purposes,
  the rest are out of scope until the real learniq contribution ships).
- Seeded with 5 rows covering: a guardian with one child in one group, a
  guardian with two children in two different groups, a guardian whose child
  has withheld photo consent, a guardian with no rows at all (fail-closed
  empty case), and a guardian whose child moved schools mid-year (stale
  `schoolRef`, to exercise the "school OR group OR child, any match" rule).

**The one-line swap when learniq ships the real collection**: the seed
`portalPage`'s `via.register`/`via.schema` moves from
`portaliq`/`guardianAudienceFixture` to `learniq`/`<learniq's real schema>`,
and `NewsAudienceMatcher`/`NewsletterPreflightService`/`NewsPhotoConsentGate`
(which all read through `GuardianAudienceFixtureReader`, a THIN adapter
returning the same shape) get that adapter's internals swapped for a
`via`-join based reader — no call-site change. This is why the reader
methods take `subjectRef` and return a small `GuardianAudience` value shape
rather than reaching into fixture rows directly: the seam is the return
type, not the storage.

## Declarative-vs-imperative decision (ADR-031)

- **Lifecycle** (`newsItem`/`newsletter` draft → published/sent): stays on
  the EXISTING imperative pattern this app already uses for `page`/`portal`
  status (`status` field + controller-side transition), not a new
  `x-openregister-lifecycle` declaration — `portaliq_register.json` does not
  use the declarative lifecycle extension anywhere yet, and introducing it
  for exactly two schemas while the rest of the app stays imperative would
  be inconsistent rather than simpler.
- **Aggregation** (recipient count): imperative
  (`NewsletterPreflightService`) — ADR-031's exception applies (a
  domain-rule selector: "which guardians match this target" is exactly the
  join-and-count logic `x-openregister-aggregations` is not shaped for).
- **Notification** (this change does NOT send a push or email on publish —
  that is `push-notifications-quiet-hours`): out of scope here by design, so
  no declarative-vs-imperative call is needed for it in this change.

## API Design

### `POST /api/news`
Staff creates a `newsItem`. Body: `title`, `body`, `target`, `photoRefs?`.
Response: the created object (`status: draft`).

### `PUT /api/news/{id}/publish`
Staff publishes (or re-publishes after an edit). Response: the object with
`status: published`.

### `GET /api/news/feed`
Guardian reads every published item in their own resolved audience, most
recent first.

### `POST /api/news/{id}/read`
Guardian marks an item read (idempotent). 404 if the item is not in the
subject's audience or does not exist (same no-oracle discipline as the
contract's scoped single-object read). 204 on success.

### `POST /api/newsletters`
Staff composes a newsletter from existing `newsItem` ids. Response: the
created draft.

### `GET /api/newsletters/{id}/preflight`
Staff previews the send. Response: `{"recipientCount": <int>}`.

### `POST /api/newsletters/{id}/send`
Staff sends. 422 with `{"error": "empty-audience"}` if the resolved audience
is zero; otherwise stamps `sentAt` and returns the sent object.

## Nextcloud Integration

- Controllers: `NewsController`, `NewsletterController` (both
  `#[NoAdminRequired]`, staff-only via the app's existing session/permission
  posture — same pattern as `CmsEditorController`).
- Services: `NewsReadReceiptService`, `NewsletterPreflightService`,
  `NewsPhotoConsentGate`, `NewsAudienceMatcher`, `GuardianAudienceFixtureReader`,
  `NewsFeedReader`.
- No new Mappers — OpenRegister `ObjectService` via `ContainerInterface`,
  exactly like `PortalObjectReader`/`PortalObjectWriter`.
- No new events/hooks in this change.

## Security Considerations

- The guardian read path inherits the contribution-contract's existing
  fail-closed discipline (unresolved audience → empty, not an error).
- The read-receipt endpoint re-verifies the item is in the CALLING guardian's
  own audience before recording a read — a guardian cannot mark an
  out-of-audience item read (which would otherwise be a minor oracle: "does
  this id exist" leaking through a 204/404 split). Both the not-in-audience
  and not-exists cases return the identical 404.
- Photo consent fails closed to withheld, per ADR-005.
- Staff authoring endpoints require the same admin/staff session posture as
  the existing `CmsEditorController` — no new permission model introduced.

## NL Design System

The staff authoring form (news item create/edit, newsletter compose) reuses
`NcTextField`/`NcSelect`/`NcButton` exactly as `portal-cms-admin-ui`'s
existing page editor does, including `inputLabel` on every `NcSelect`
(hydra-gate-nc-input-labels).

## File Structure

```
lib/
  Controller/
    NewsController.php
    NewsletterController.php
  Service/
    NewsReadReceiptService.php
    NewsletterPreflightService.php
    NewsPhotoConsentGate.php
    NewsAudienceMatcher.php
    GuardianAudienceFixtureReader.php
    NewsFeedReader.php
lib/Settings/portaliq_register.json   (schemas: newsItem, newsletter, guardianAudienceFixture; seed portalPage)
appinfo/routes.php                    (new routes)
src/views/News/NewsListView.vue       (staff authoring list, minimal)
src/views/News/NewsEditorView.vue     (staff authoring form, minimal)
tests/Unit/Service/NewsReadReceiptServiceTest.php
tests/Unit/Service/NewsletterPreflightServiceTest.php
tests/Unit/Service/NewsPhotoConsentGateTest.php
tests/Unit/Service/NewsAudienceMatcherTest.php
```

## Seed Data

### Schema: `guardianAudienceFixture`

| Field | Row 1 (single-group) | Row 2 (multi-child) | Row 3 (withheld consent) | Row 4 (no rows — omitted) | Row 5 (stale school) |
| --- | --- | --- | --- | --- | --- |
| guardianRef | `guardian-anna-devries` | `guardian-piet-bakker` | `guardian-fatima-elamrani` | — | `guardian-noor-yilmaz` |
| schoolRef | `school-de-regenboog` | `school-de-regenboog` | `school-het-kompas` | — | `school-oude-vestiging` |
| groupRefs | `["groep-5a"]` | `["groep-3b","groep-7a"]` | `["groep-4c"]` | — | `["groep-6b"]` |
| childRefs | `["child-devries-lars"]` | `["child-bakker-eva","child-bakker-tim"]` | `["child-elamrani-yusuf"]` | — | `["child-yilmaz-can"]` |
| photoConsent | `{"child-devries-lars": {"news": true}}` | `{"child-bakker-eva": {"news": true}, "child-bakker-tim": {"news": true}}` | `{"child-elamrani-yusuf": {"news": false}}` | — | `{"child-yilmaz-can": {"news": true}}` |

Row 4 (`guardian-jan-smit`) is deliberately absent from the fixture register
— it exercises the fail-closed "no rows for this subject" path without a row
to represent it.

### Schema: `newsItem`

| Field | Object 1 | Object 2 | Object 3 |
| --- | --- | --- | --- |
| title | "Herfstvakantie rooster" | "Groep 5a: schoolreisje" | "Foto's sportdag" |
| target | `{"schoolRef":"school-de-regenboog"}` | `{"groupRefs":["groep-5a"]}` | `{"groupRefs":["groep-4c"]}` |
| status | published | published | published |
| photoRefs | `[]` | `[]` | `["photo-sportdag-1.jpg"]` |
| authorRef | `staff-directie-1` | `staff-leerkracht-5a` | `staff-leerkracht-4c` |

Object 3 targets `groep-4c`, whose only fixture guardian
(`guardian-fatima-elamrani`) has withheld photo consent for
`child-elamrani-yusuf` — proving the redaction with real seed data rather
than only a unit-test fixture.

### Schema: `newsletter`

| Field | Object 1 |
| --- | --- |
| title | "Weekbrief De Regenboog" |
| itemRefs | `["<Object 1 id above>"]` |
| target | `{"schoolRef":"school-de-regenboog"}` |
| sentAt | null (draft, so the preflight/send flow is exercisable on install) |

## Risks / Trade-offs

- [Risk] The fixture is mistaken for production data → [Mitigation] distinct
  schema name, `@spec` comments, and this design's named swap point.
- [Risk] `NewsAudienceMatcher`'s "any of school/group/child matches" could
  over-broadcast if a school-wide target is used carelessly → [Mitigation]
  the preflight makes the resolved count visible before every send, closing
  exactly the RosarioSIS over-notification gap by making the count a gate
  rather than a surprise.

## Migration Plan

Additive schemas only; no existing schema is modified. Nothing to roll back
beyond deactivating the seed `portalPage` (see proposal.md Rollback Strategy).

## Open Questions

None.
