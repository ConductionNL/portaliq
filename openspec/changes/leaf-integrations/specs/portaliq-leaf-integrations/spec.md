# portaliq-leaf-integrations Specification

**Status**: planned
**Scope**: portaliq
**OpenSpec changes**:
- `leaf-integrations` — adopts the forms, talk and calendar builtin leaves on the internal staff surfaces and makes the ADR-046 side-of-the-boundary rule normative per leaf (kind: code)

## Purpose

Defines which OpenRegister/nextcloud-vue builtin integration leaves portaliq consumes, where each
renders, and — because portaliq is the fleet's external auth edge (ADR-046) — on **which side of
the portal boundary** each one lives. Current state, verified in `src/manifest.json`: exactly one
leaf (`files`, widget `document-files` on `DocumentDetail`) and zero `linkedTypes` declarations.
This capability adds `forms`, `talk` and `calendar` for the internal staff view and forbids any
leaf, present or future, from surfacing at the portal edge.

## ADDED Requirements

### Requirement: Integration leaves render on the internal staff side only

Every integration leaf consumed by portaliq SHALL render exclusively on internal manifest pages
served to authenticated Nextcloud users (the `DashboardController` surface). No visitor-reachable
surface — the `#[PublicPage]` portal-edge controllers (`ContributionController`,
`ContentController`, `PortalPageController`, `SessionController`, `TrafficController`,
`WooController`), any route under `/portal/`, or any payload they serve — SHALL render, reference,
or link an integration leaf or a leaf-created Nextcloud artifact. Anything visitor-facing SHALL be
mediated by the portal edge reading OpenRegister objects; a portal bearer session
(`PortalSessionService`) SHALL never grant access to a Nextcloud app surface.

#### Scenario: The portal content API never serves a leaf

- GIVEN the portal-edge content endpoints (`ContentController::site/pages/page`, `ContributionController::inbox/collection/object`)
- WHEN any of them serialises a response for a portal visitor
- THEN no `integrationId` key and no `{"type": "integration"}` widget MUST appear in the payload

#### Scenario: A leaf artifact URL cannot reach a visitor

- GIVEN a Talk conversation, NC Form or calendar event attached to a `portalMessage`, `portalSubmission` or `portalAccount` via a leaf
- WHEN the object is served through any `/portal/*` endpoint
- THEN no Talk join URL, Forms share URL or calendar link derived from that artifact MUST be present in the response body

### Requirement: The forms leaf attaches Nextcloud Forms to portal submissions, on a submissions surface that exists

The manifest SHALL declare a `PortalSubmissions` index page and a `PortalSubmissionDetail` detail
page for schema `portalSubmission` (today the schema — `subjectRef`, `organisation`, `appId`,
`actionId`, `payloadCopy`, `receiptMessageRef`, `submittedAt`, `deliveryStatus` — has no manifest
page at all). `PortalSubmissionDetail` SHALL carry a widget
`{"type": "integration", "integrationId": "forms"}` so staff can link the Nextcloud Form behind a
submission or a follow-up form to it. Per ADR-046 this leaf SHALL live on the internal side only:
the visitor's form experience remains the portal edge's own submission flow
(`ContributionController::create`/`action`) and its WMEBV receipt (`SubmissionReceiptService`);
a portal visitor SHALL never be shown or invited into the Nextcloud Forms app.

#### Scenario: Staff triage a submission next to its form

- GIVEN a `portalSubmission` created through the portal edge
- WHEN a staff member opens `PortalSubmissionDetail` in the Nextcloud shell
- THEN the submission's fact fields render alongside a forms leaf widget
- AND linking a form associates it with the `portalSubmission` object without modifying `payloadCopy`

#### Scenario: The submissions index shows facts, not payloads

- GIVEN the `PortalSubmissions` index page
- WHEN it lists submissions
- THEN its columns are drawn from `appId`, `actionId`, `submittedAt` and `deliveryStatus`
- AND `payloadCopy` renders only on the detail page

### Requirement: The talk leaf bridges a portal message to a staff-only Talk conversation

`PortalMessageDetail` SHALL carry a widget `{"type": "integration", "integrationId": "talk"}` so
internal staff can open or attach a Talk conversation to a `portalMessage` and discuss how to
handle it. Per ADR-046 this leaf SHALL live on the internal side only: the conversation and its
participants are Nextcloud users; the portal visitor's side of the exchange SHALL remain
`portalMessage` objects served through the portal inbox (`ContributionController::inbox`,
`markRead`), and no Talk join link or conversation token SHALL ever be written into a
`portalMessage` field or any other edge-served value.

#### Scenario: Two employees discuss a citizen's message on the record

- GIVEN a `portalMessage` open in `PortalMessageDetail`
- WHEN a staff member starts a conversation from the talk leaf
- THEN the conversation is attached to that message object and visible to staff on the same page
- AND the citizen's portal inbox view of the message is unchanged

#### Scenario: The visitor's reply path stays the portal edge

- GIVEN a staff conversation attached to a `portalMessage`
- WHEN the answer is ready for the visitor
- THEN it reaches the visitor as portal-edge data (a `portalMessage`), not as a Talk invitation

### Requirement: The calendar leaf surfaces meetings around a portal account, with the visitor half portal-rendered

`PortalAccountDetail` SHALL carry a widget `{"type": "integration", "integrationId": "calendar"}`
(the builtin "Meetings" leaf) so staff can see and plan appointments related to a portal subject
alongside the existing `account-sessions` and `account-messages` widgets. Per ADR-046 this leaf
SHALL live on the internal side only: any visitor-facing appointment surface SHALL be
portal-rendered — built from OpenRegister objects served through the portal edge — and SHALL NOT
embed, link or proxy the Nextcloud Calendar app; this requirement constrains that future surface
without building it.

#### Scenario: Staff see meetings next to the account

- GIVEN a `portalAccount` open in `PortalAccountDetail`
- WHEN the calendar leaf renders
- THEN meetings associated with the account are visible in the Nextcloud shell
- AND no portal-edge route or payload changes as a result

### Requirement: Leaf adoption is declared in both the manifest and the schema

For each adopted leaf, the consuming schema (`portalSubmission`, `portalMessage`,
`portalAccount`) SHALL declare the corresponding `linkedTypes` entry in
`lib/Settings/portaliq_register.json`, so the sidebar integration-tab surface offers the same
leaves as the page body and OpenRegister's link bookkeeping sees the relationship. A leaf whose
required Nextcloud app (`forms`, `spreed`, `calendar`) is not installed SHALL render its explicit
absent/empty state rather than silently disappearing.

#### Scenario: The sidebar and the page body agree

- GIVEN a schema with an adopted leaf and its `linkedTypes` declaration
- WHEN a staff member opens the object's detail sidebar
- THEN the same integration is offered there as on the page body

#### Scenario: A missing app is visible, not invisible

- GIVEN an instance where `spreed` is not installed
- WHEN `PortalMessageDetail` renders
- THEN the talk widget shows its absent state naming the missing app
- AND it does not vanish without trace

## Non-Functional Requirements

- **Performance:** Leaf adoption adds no portaliq backend call; leaves fetch from their own apps.
  The portal edge's response payloads MUST be byte-identical for unchanged objects before and
  after this change.
- **Internationalization:** No portaliq-owned user-facing strings are added; leaf labels are
  translated in `nextcloud-vue` (`t('nextcloud-vue', ...)`). N/A for portaliq's `nl_NL`/`en_US`.

## Acceptance Criteria

- [ ] `src/manifest.json` declares `PortalSubmissions` and `PortalSubmissionDetail`, and integration widgets `forms` (submission detail), `talk` (message detail), `calendar` (account detail); the existing `files` widget on `DocumentDetail` is untouched
- [ ] `portalSubmission`, `portalMessage` and `portalAccount` declare matching `linkedTypes`
- [ ] The diff under `lib/Controller/`, `lib/Middleware/`, `lib/Auth/` and `appinfo/routes.php` is empty — zero portal-edge change
- [ ] A test pins that no `/portal/*` payload carries `integrationId` or a leaf-artifact URL

## Notes

- ADR-046 (hydra) — portal visitors are not Nextcloud users; ADR-019 — the integration registry is
  the employee-side mechanism this capability consumes.
- Builtin leaves verified in `nextcloud-vue/src/integrations/builtin/leaves.js` (`calendar` /
  "Meetings" / requiredApp `calendar`; `talk` / "Chat" / requiredApp `spreed`; `forms` /
  requiredApp `forms`) and the registry in `nextcloud-vue/src/integrations/registry.js`.
- Current single adoption verified: `src/manifest.json` widget `document-files`
  (`integrationId: "files"`) on `DocumentDetail`; `grep linkedTypes` over portaliq returns nothing.
