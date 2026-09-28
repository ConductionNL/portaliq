# Design: leaf-integrations

## Architecture Overview

Nothing new is built; three existing leaves are **declared**. The mechanism is the one the fleet
already runs:

1. **Manifest widget** — `{"type": "integration", "integrationId": "<leaf>"}` on a detail page in
   `src/manifest.json` (manifest-v2, `$schema` → `app-manifest-v2.schema.json`). Portaliq already
   does this once: widget `document-files` on `DocumentDetail`.
2. **Schema `linkedTypes`** — declared in `lib/Settings/portaliq_register.json`, read by
   OpenRegister (`LinkedEntityService`, `src/entities/schema/schema.ts`), so the detail sidebar's
   integration tabs offer the same leaves the page body renders.
3. **Rendering** — `CnDetailPage` + the shared registry (`nextcloud-vue/src/integrations/registry.js`,
   bootstrapped by `openregister/src/integrations/bootstrap.js`) resolve `integrationId` to the
   builtin leaf component. Leaves ship in `nextcloud-vue/src/integrations/builtin/`.

## The boundary, drawn once

```
EXTERNAL (portal visitor — NOT an NC user)          INTERNAL (staff — NC users)
────────────────────────────────────────            ─────────────────────────────────────
portal bearer session (PortalSessionService)        NC session (login, CSRF)
PortalAuthMiddleware + #[PublicPage] routes         DashboardController → manifest pages
ContributionController / ContentController          CnDetailPage + integration leaves
portal-rendered surfaces (PortalPageController)     NC Forms / Talk / Calendar apps
                    │                                          │
                    └────────── OpenRegister objects ──────────┘
                         (the ONLY shared plane, ADR-046)
```

Every leaf sits in the right-hand column. The only thing the two columns share is the OpenRegister
object; a leaf attaches NC-app artifacts (a form, a conversation, an event) **to the object**, and
the portal edge continues to serve the visitor **from the object**. No leaf artifact crosses left.

## Per-leaf placement

| Leaf (registry id) | Page (manifest) | Object | What staff do with it | What the visitor sees instead |
|---|---|---|---|---|
| `forms` (requiredApp `forms`) | `PortalSubmissionDetail` (new) | `portalSubmission` | Link the NC Form behind a submission; open follow-up forms | The portal's own submission flow (`ContributionController::create`/`action`) and its WMEBV receipt (`SubmissionReceiptService` → `portalMessage`) |
| `talk` (requiredApp `spreed`) | `PortalMessageDetail` | `portalMessage` | Open/attach a staff-only Talk conversation to decide the answer | Only `portalMessage` objects in the portal inbox (`ContributionController::inbox`) — never a Talk room |
| `calendar` (requiredApp `calendar`) | `PortalAccountDetail` | `portalAccount` | See/plan meetings ("Meetings" leaf) around this subject | A portal-rendered appointment surface fed from OR via the edge — out of scope here, boundary normative |

## Decisions

### Decision 1: A new submissions surface rather than bolting forms onto an existing page

`portalSubmission` has **no manifest page today** — verified by grepping `src/manifest.json` for
`portalSubmission` (zero hits) against the register schema list (15 schemas, `portalSubmission`
among them). The forms leaf needs a host object whose meaning is "a thing a form produced", and
that object exists and is unreachable. So the change adds `PortalSubmissions` (index) +
`PortalSubmissionDetail` (detail) following the existing index/detail archetype
(`PortalMessages`/`PortalMessageDetail`), and places the leaf there. The alternative — putting a
forms leaf on `DocumentDetail` — would attach forms to the template's demo schema
(`exampleDocument`), which the manifest itself annotates as a stand-in.

### Decision 2: `linkedTypes` accompanies each widget, and nothing else does

The widget makes the leaf visible on the page body; `linkedTypes` makes the same relationship
available to the sidebar tab surface and to OpenRegister's link bookkeeping
(`LogDanglingLinkedTypes` repair). Declaring one without the other ships a half-adoption where the
page shows a leaf the sidebar denies. `configuration.linkedTypes` (the Mail-sidebar consumption
path) and `configuration.mailObjectTemplate` are **deliberately not** declared — portaliq's
outbound mail is `NotificationDispatchService`'s job and a create-from-email flow is unproposed.

### Decision 3: The boundary is enforced structurally and asserted by test, not policed by prose

Leaves render only where manifest pages render: `templates` served by `DashboardController`
(internal, NC-session). The portal edge renderers (`PortalPageController::site`, the SPA catch-all,
`ContentController`'s JSON) never read manifest `widgets`. The design adds no new mechanism — it
adds a **test** that pins the existing separation: the portal content API responses contain no
`integrationId` key, and no leaf-created artifact URL (Talk `/call/`, Forms `/apps/forms/s/`)
appears in any `/portal/*` payload built from the three touched schemas.

## Nextcloud Integration

- Leaf host: `CnDetailPage` (nextcloud-vue), integration registry per ADR-019 — the internal
  integration registry that ADR-046 explicitly keeps on the employee side.
- Optional apps: `forms`, `spreed`, `calendar` — absence renders `CnIntegrationWidgetEmpty`'s
  absent state; portaliq's `info.xml` dependency set is unchanged.
- No OCP interface is newly consumed by portaliq itself; consumption is declarative.

## Security Considerations

- **No widening of read access.** The new pages render objects staff can already read under
  OpenRegister RBAC; `payloadCopy` (the WMEBV copy of submitted data) appears on the detail page
  only, list columns stay fact fields.
- **No portal-edge change** — the visitor-reachable surface (routes under `/portal/`, `#[PublicPage]`
  controllers) has a zero-line diff in this change; that is itself an acceptance criterion.
- **Leaf artifacts are staff artifacts.** A Talk conversation attached to a `portalMessage` is
  reachable only by its Talk participants (NC users); the spec forbids ever writing a join link
  into a `portalMessage` body or any other edge-served field.

## File Structure

```
src/manifest.json                    ← pages PortalSubmissions + PortalSubmissionDetail (new),
                                        widgets: submission-forms (forms), message-talk (talk),
                                        account-calendar (calendar); menu entry
lib/Settings/portaliq_register.json  ← linkedTypes on portalSubmission, portalMessage, portalAccount
tests/                               ← boundary pin: no integrationId / leaf-artifact URL at the edge
```

## Trade-offs

Three leaves, not seventeen. Each adoption is one grounded staff task; the cost of an unused leaf
is a dead widget consuming page geometry and staff attention. The same restraint the
`portaliq-mcp-adoption` change applied to the tool surface applies to the leaf surface: adopting to
hit a number is the failure mode, not the goal.
