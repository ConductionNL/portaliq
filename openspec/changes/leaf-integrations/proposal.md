---
kind: code
---

# Proposal: leaf-integrations

## Summary

Portaliq consumes exactly **one** of OpenRegister's app-agnostic integration leaves today: a single
`files` widget on the `DocumentDetail` manifest page (`src/manifest.json`, widget `document-files`,
`{"type": "integration", "integrationId": "files"}`). No portaliq schema declares `linkedTypes`, no
page declares `configuration.linkedTypes`, and no `configuration.mailObjectTemplate` exists. This
change adopts three more leaves where portaliq's internal staff view has a real, grounded need —
**forms** (portal submissions), **talk** (staff discussion on a portal message), and **calendar**
(appointments around a portal account) — and makes the ADR-046 boundary normative for every leaf:
integration leaves render for **internal Nextcloud staff only**; an external portal visitor never
touches a Nextcloud app, and anything visitor-facing stays mediated by the portal edge.

## Motivation

**The internal staff view is under-served relative to what the fleet already ships.** The builtin
leaf registry (`nextcloud-vue/src/integrations/builtin/leaves.js`) declares ~20 app-agnostic leaves
— `calendar` (label "Meetings", requiredApp `calendar`), `talk` (label "Chat", requiredApp
`spreed`), `forms` (requiredApp `forms`), plus files, email, contacts, deck, maps, photos, polls,
shares, bookmarks, collectives, notes, activity, time-tracker, xwiki, analytics and more — all
consumable from a manifest with one widget declaration. Portaliq uses one.

Three concrete staff pains map directly onto three leaves:

- **A `portalSubmission` is invisible in the internal UI.** The schema exists
  (`lib/Settings/portaliq_register.json`: `subjectRef`, `organisation`, `appId`, `actionId`,
  `payloadCopy`, `receiptMessageRef`, `submittedAt`, `deliveryStatus`) and
  `SubmissionReceiptService` writes one per WMEBV submission — but `src/manifest.json` declares
  **no page** for it. Staff triaging a failed delivery read the database or nothing. A submissions
  surface with a **forms** leaf lets staff link the NC Form that generated (or follows up) a
  submission.
- **Staff discussion about a portal message happens outside the record.** `PortalMessageDetail`
  renders one `data` widget. When two employees need to decide how to answer a citizen's message,
  that conversation happens in an unlinked Talk room or in email — the **talk** leaf attaches the
  conversation to the object.
- **Appointments with a portal subject have no home.** A `portalAccount` detail page shows sessions
  and messages (`account-sessions`, `account-messages` object-lists) but no way to see or plan a
  meeting with that citizen/supplier — the **calendar** leaf ("Meetings") is built for exactly
  this.

**And the boundary must be said out loud.** Portaliq is the fleet's external auth edge (ADR-046):
portal visitors are **not** Nextcloud users, hold a portal bearer session
(`PortalSessionService` / `PortalAuthMiddleware`), and reach data only through `#[PublicPage]`
portal-edge controllers (`ContributionController`, `ContentController`). Every integration leaf is
a Vue component running in the **Nextcloud** shell against **Nextcloud** apps as a **Nextcloud**
user. Adopting leaves without a normative side-of-the-boundary rule invites exactly the mistake
ADR-046 exists to prevent — a Talk join link or an NC Forms share URL leaking into a
visitor-facing surface. Each leaf requirement therefore carries a hard SHALL on which side it
lives.

## Affected Projects

- [ ] Project: `portaliq` — manifest gains a `portalSubmission` surface and three integration-leaf widgets; the register JSON gains `linkedTypes` on three schemas; no portal-edge (visitor-facing) surface changes.

## Scope

### In Scope

- New manifest pages `PortalSubmissions` (index) and `PortalSubmissionDetail` (detail) for schema
  `portalSubmission` — currently absent from `src/manifest.json` entirely.
- **forms** leaf widget on `PortalSubmissionDetail`.
- **talk** leaf widget on `PortalMessageDetail`.
- **calendar** leaf widget on `PortalAccountDetail`.
- `linkedTypes` declarations on `portalSubmission`, `portalMessage` and `portalAccount` in
  `lib/Settings/portaliq_register.json`, so the sidebar/tab surface offers the same leaves as the
  page body.
- The normative ADR-046 side-of-the-boundary requirement, per leaf and globally.

### Out of Scope

- Any change to the portal edge: no new `/portal/api/*` route, no visitor-facing rendering of any
  Nextcloud app. The visitor-facing half of appointments (a portal page showing "your appointment")
  is a separate portal-edge change; this change only states the boundary it must respect.
- `configuration.mailObjectTemplate` / the **email** leaf — portaliq's outbound mail is owned by
  `NotificationDispatchService` per the fleet notification plan; a create-from-email button on
  portal objects is a real idea but needs its own proposal.
- The remaining ~15 leaves (deck, maps, photos, polls, …) — no grounded staff need identified;
  padding the surface is the anti-goal.
- Building leaves themselves — they live in `nextcloud-vue` and are consumed as-is.

## Approach

Manifest-declared consumption only: each adoption is a
`{"type": "integration", "integrationId": "..."}` widget in `src/manifest.json` (exactly the shape
the existing `document-files` widget uses), plus `linkedTypes` in the register JSON. No portaliq
PHP or Vue code renders a leaf; `CnDetailPage` and the shared integration registry
(`nextcloud-vue/src/integrations/registry.js`) do.

## New Dependencies

None in code. Runtime-optional Nextcloud apps per leaf (`forms`, `spreed`, `calendar`) — the leaf
registry already declares `requiredApp` per leaf and renders its empty/absent state when the app is
not installed, so none becomes an install-time dependency of portaliq.

## Impact

- `src/manifest.json` — two new pages (`PortalSubmissions`, `PortalSubmissionDetail`); one new
  widget on each of `PortalSubmissionDetail`, `PortalMessageDetail`, `PortalAccountDetail`; menu
  entry for submissions.
- `lib/Settings/portaliq_register.json` — `linkedTypes` on `portalSubmission`, `portalMessage`,
  `portalAccount`.
- No controller, service, middleware or portal-edge change.

## Cross-Project Dependencies

- `nextcloud-vue` — builtin leaves `forms`, `talk`, `calendar` already ship in
  `src/integrations/builtin/leaves.js`; no nc-vue change required.
- `openregister` — the shared integration registry bootstrap
  (`openregister/src/integrations/bootstrap.js`) and `LinkedEntityService`; no OR change required.
- ADR-046 (hydra) — the external-auth-edge boundary this change makes normative per leaf.

## Risks

### Risk 1: A leaf surface leaks a Nextcloud artifact to a portal visitor

**Severity:** High — **Mitigation:** The spec makes the boundary a hard SHALL per leaf, and the
mechanism is structural, not disciplinary: leaves render only on manifest pages, manifest pages
render only in the Nextcloud shell (`DashboardController` — internal, session-authenticated), and
the portal edge (`PortalPageController`, `ContentController`, `ContributionController`) reads no
manifest widget. A task adds a test asserting the portal content API never serves an
`integrationId` and that no `/portal/*` response body carries a Talk join URL or Forms share URL
sourced from a leaf link object.

### Risk 2: The submissions surface exposes `payloadCopy` wider than intended

**Severity:** Medium — **Mitigation:** `payloadCopy` is the WMEBV "copy of submitted data" — it can
carry anything a citizen typed. The new pages change **who renders** it (internal staff, who can
already read it through OpenRegister RBAC), not who may read it; access stays OpenRegister
RBAC-enforced exactly as for the existing `account-messages` object-list. The index page projects
list columns to the fact fields (`appId`, `actionId`, `submittedAt`, `deliveryStatus`) and leaves
`payloadCopy` to the detail page.

### Risk 3: A leaf renders dead weight when its app is absent

**Severity:** Low — **Mitigation:** The leaf registry's `requiredApp` handling already renders the
explicit empty/absent state (`CnIntegrationWidgetEmpty`); the spec requires the absent state to be
visible rather than the widget silently vanishing, so a misconfigured install is diagnosable.

## Rollback Strategy

Revert the manifest hunks (pages and widgets disappear; no data is touched) and the register JSON
hunk (`linkedTypes` is declarative — removing it removes the offer, not any linked object).
Existing `files` adoption on `DocumentDetail` is untouched by rollback.

## Open Questions

- Should the visitor-facing appointment surface (the portal-edge half that this change explicitly
  does not build) be a portal contribution (`IPortalContributionProvider`) from a calendar-owning
  app, or a portaliq-owned portal page? The boundary requirement here holds either way.
- `portalSubmission.deliveryStatus` values are currently free-form in the schema; a submissions
  index that filters on it would benefit from an enum. Schema tightening is a data migration and
  deliberately not smuggled into this change.
