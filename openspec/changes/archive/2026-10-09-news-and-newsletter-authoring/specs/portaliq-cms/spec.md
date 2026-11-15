# portaliq-cms Specification (delta: news-and-newsletter-authoring)

## ADDED Requirements

### Requirement: A NewsItem is authored per school, group or child, and tracks read receipts

A `newsItem` OpenRegister schema (register `portaliq`) MUST carry `title`,
`body`, a `target` object (`schoolRef?`, `groupRefs[]?`, `childRefs[]?` — at
least one MUST be present), `authorRef`, `status` (`draft`|`published`), and
an optional `photoRefs[]`. Staff MUST be able to create, update and publish a
news item scoped to the AUTHORING portal. A published item MUST be readable
by a guardian ONLY when the item's `target` intersects the guardian's own
resolved audience (school, group, OR child — matching ANY), resolved through
the audience source named in design.md. A draft item MUST be indistinguishable
from an absent one, exactly like an unpublished `page` (existing requirement,
same fail-closed shape). A guardian's read of a published, in-audience item
MUST be recorded as a read receipt exactly once per guardian, however many
times they read it (idempotent append, never a duplicate row).

#### Scenario: A group-targeted item reaches only that group's guardians

- GIVEN a published `newsItem` targeting one group
- WHEN a guardian whose resolved audience includes that group reads the feed
- THEN the item appears
- AND a guardian whose audience does not include that group, that school, or
  any of that item's targeted children never sees it, even though both
  guardians query the same endpoint
- @e2e exclude backend audience-scoping contract — covered by the PHPUnit
  audience-matcher suite over the fixture seam; no distinct portaliq UI ships
  a group picker in this change (staff authoring is a plain form)

#### Scenario: A draft item is indistinguishable from an absent one

- GIVEN a `newsItem` with `status: draft` targeting a guardian's own group
- WHEN that guardian reads the feed
- THEN the item is absent, byte-identical to a feed with no matching item at
  all
- @e2e exclude mirrors the existing `site-security.spec.ts` (S4) draft/absent
  invariant already proven for `page`; no new UI surface

#### Scenario: A second read of the same item does not duplicate the receipt

- GIVEN a guardian who has already read a published, in-audience `newsItem`
  once
- WHEN they read it again
- THEN the read-receipt store still contains exactly one entry for that
  guardian on that item
- @e2e exclude idempotency invariant — pinned by
  `NewsReadReceiptServiceTest::testASecondReadDoesNotDuplicateTheReceipt`; no
  UI surface distinguishes a first from a second read

### Requirement: A Newsletter composes existing news items with an archive

A `newsletter` schema MUST carry `title`, `itemRefs[]` (existing `newsItem`
ids), the same `target` shape as `newsItem`, `sentAt` (null until sent) and
`archived` (bool). Staff MUST be able to compose a newsletter from published
OR draft news items and send it, which stamps `sentAt` and leaves `archived:
false`; staff MAY archive a sent newsletter explicitly. A guardian's
newsletter archive MUST list only SENT newsletters whose `target` intersects
their own resolved audience, most recent `sentAt` first — the identical
audience-matching rule as `newsItem`, not a second implementation of it.

#### Scenario: A sent newsletter appears in the in-audience archive, ordered by send date

- GIVEN two sent newsletters targeting the same group, sent on different days
- WHEN a guardian in that group reads the archive
- THEN both appear, most recently sent first
- @e2e exclude backend ordering/scoping contract — covered by PHPUnit; no
  distinct UI beyond the existing list-rendering pattern

#### Scenario: An out-of-audience newsletter never appears in the archive

- GIVEN a sent newsletter targeting a group the guardian is not in
- WHEN that guardian reads the archive
- THEN it does not appear, using the same matcher as `newsItem` (asserted by
  calling the identical `NewsAudienceMatcher` in the same test)
- @e2e exclude backend scoping contract — no UI surface distinguishes this
  from an empty archive

### Requirement: A newsletter send is preceded by a recipient-count preflight

Before a newsletter is sent, staff MUST be able to call a preflight that
returns the EXACT count of guardians the resolved `target` reaches, computed
by the SAME audience-resolution method the send path itself uses — never a
separate estimate that could drift. Sending a newsletter whose resolved
audience is EMPTY MUST be rejected with a reason naming that the target
resolves to zero guardians, requiring staff to either broaden the target or
cancel. This is the Gibbon Messenger pattern (`gibbon/round1/pages/
Messenger.md`: "a live recipient-count preflight before send") and closes
the gap RosarioSIS's Portal Notes shows (`rosariosis/round1/journeys.md` J6:
no group/class column at all, so the only available target "over-notifies
every family").

#### Scenario: Staff sees the exact count before sending

- GIVEN a newsletter targeting one group with three guardians in it
- WHEN staff calls the preflight
- THEN the response reports exactly 3, and sending immediately afterwards
  reaches exactly those 3 guardians' archives
- @e2e exclude backend count/send-parity contract — pinned by
  `NewsletterPreflightServiceTest::testPreflightCountMatchesActualDeliveryAudience`;
  no distinct UI ships a send button with a live counter in this change

#### Scenario: Sending to an empty resolved audience is refused

- GIVEN a newsletter whose `target` resolves to zero guardians (e.g. a group
  with no children currently enrolled, per the audience source)
- WHEN staff attempts to send it
- THEN the send is refused with a machine-readable reason, and `sentAt`
  remains null
- @e2e exclude fail-closed refusal — pinned by PHPUnit; no UI surface

### Requirement: Photos in a news item are gated by the target child's photo consent

When a `newsItem` carries `photoRefs`, portaliq MUST check, for every
targeted child, whether photo consent is granted FOR THE `news` PURPOSE
(resolved through the same audience source, extended with a `photoConsent`
map keyed `{childRef: {purpose: granted}}` — findings 2.8 documents
schoolgids/website/nieuwsbrief/social media/Parro as distinct purposes; this
change enforces the one purpose its own schemas need and leaves the rest for
when the real per-purpose data ships). If ANY targeted child's `news`
consent is not granted, the item's `photoRefs` MUST be withheld from every
read of that item — the text still delivers — rather than the whole item
being blocked. An item targeting ONLY children with granted `news` consent
serves its photos unchanged. An unresolvable consent lookup (provider error,
absent child, absent purpose entry) MUST be treated as WITHHELD, never as
granted (ADR-005 fail-closed) — strengthening the nearest documented
competitor behaviour, Parnassys's per-purpose privacy preference "reviewed...
by the teacher" (findings 9.5), from a UI checklist into a server-enforced
gate.

#### Scenario: One withheld consent strips photos, text still delivers

- GIVEN a `newsItem` targeting two children, one with granted and one with
  withheld photo consent, carrying two `photoRefs`
- WHEN any guardian in its audience reads the item
- THEN the title and body are present and `photoRefs` is empty
- @e2e exclude server-side redaction contract — pinned by
  `NewsPhotoConsentGateTest::testAnyWithheldConsentStripsAllPhotos`; the
  redaction happens before the item leaves the server, so a browser
  assertion could only observe the absence, which is equally provable at the
  seam

#### Scenario: All-granted consent serves photos unchanged

- GIVEN a `newsItem` targeting only children with granted photo consent
- WHEN it is read
- THEN `photoRefs` is returned unchanged
- @e2e exclude same seam as above — the positive control

#### Scenario: An unresolvable consent lookup fails closed to withheld

- GIVEN a targeted child with no consent entry in the audience source
- WHEN the item is read
- THEN photos are withheld, exactly as an explicit refusal
- @e2e exclude fail-closed invariant — pinned by PHPUnit; no UI surface

## Non-Functional Requirements

- **Performance:** the audience match and consent check run per read against
  already-fetched fixture rows (no additional OpenRegister round trip beyond
  the existing `via`-join the reader already performs for a scoped
  collection).
- **Accessibility:** the staff authoring form follows the same NcSelect/
  NcTextField pattern as the existing CMS admin surface (`portal-cms-admin-ui`),
  including `inputLabel` on every select.
- **Internationalization:** authoring labels and guardian-facing strings ship
  Dutch and English (ADR-007); news/newsletter body content is
  author-provided free text, not translated by this change.
- **Security (ADR-005):** every new path fails closed — an unresolvable
  audience is "not in audience", an unresolvable consent is "withheld", an
  empty preflight audience blocks the send.

## Acceptance Criteria

- [ ] A published, in-audience `newsItem` is readable by a matching guardian
  and absent for a non-matching one
- [ ] A draft `newsItem` is byte-identical to an absent one
- [ ] A second read of the same item by the same guardian does not duplicate
  the read receipt
- [ ] A sent newsletter appears in the archive of every in-audience guardian,
  ordered by `sentAt` descending, and never for an out-of-audience guardian
- [ ] The preflight count and the actual send reach the identical set of
  guardians
- [ ] Sending to a zero-guardian resolved target is refused
- [ ] A news item with any withheld-consent targeted child never serves its
  photos; an all-granted item serves them unchanged; an unresolvable lookup
  withholds

## Notes

- These requirements were added by the `news-and-newsletter-authoring`
  change (delta: `openspec/changes/news-and-newsletter-authoring/specs/
  portaliq-cms/spec.md`); same sync discipline as the app's other change
  deltas until this change archives.
- The audience/consent source is a fixture seam (design.md), pending
  learniq's `portal-contribution-guardian-audiences` change.
