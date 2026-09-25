# Design: guardian-self-service-profile

## Architecture Overview

Nothing new joins the system. `ProposalController`/`ProposalService` already
own the queue (`change-proposal-queue`, merged); this change adds one more
read method to each, following the exact shape of the existing `forSubject()`
read, and a matching route. On the frontend, `PageView.jsx` already switches
on `block.type` and `action.type` to pick a renderer (`SchemaForm` for
create/update); this adds one more branch and one new component, reusing
`portalApi.js`'s existing `send()`/`get()` helpers.

## API Design

### `GET /portal/api/proposals/mine`

Bearer-gated (`#[PublicPage] #[NoCSRFRequired]`, same posture as
`proposeFromPortal`/`withdraw`). No query parameters — the subject comes
from the bearer alone, so there is nothing for a client to widen.

**Response (200):**
```json
{
  "proposals": [
    {
      "uuid": "00000000-0000-0000-0000-000000000000",
      "subjectRegister": "learniq",
      "subjectSchema": "guardianProfile",
      "subjectId": "00000000-0000-0000-0000-000000000000",
      "channel": "portal",
      "changes": [{"property": "phone", "currentValue": "0600000000", "proposedValue": "0611111111"}],
      "note": "Nieuw nummer sinds vorige week.",
      "state": "queued",
      "proposedAt": "2026-09-25T09:00:00+00:00"
    }
  ]
}
```

**Response (401):** `{"authenticated": false}` — no bearer, or the bearer
does not resolve to a subject.

## Nextcloud Integration

- Controllers: `ProposalController::mine()` (new method, existing class)
- Services: `ProposalService::mine()` (new method, existing class)
- Route: `appinfo/routes.php`, one line beside the existing `proposal#*`
  entries: `['name' => 'proposal#mine', 'url' => '/portal/api/proposals/mine', 'verb' => 'GET']`
- No new mappers/entities, events or migrations — `changeProposal` already
  carries `proposedBy`.

## Security Considerations

`mine()` filters by `proposedBy`, a field the client never supplies — the
controller resolves it from `PortalSessionService::resolveFromBearer()`
exactly as `proposeFromPortal()`/`withdraw()` already do, so there is no new
trust boundary. An unresolved bearer returns 401 before any read is issued
(mirrors every other bearer-gated route in this controller). The
`ProposalService::forSubject()` read this mirrors already scopes by
`scopeField: 'subjectId'`; `mine()` scopes the same reader call by
`proposedBy` instead — read the whole `changeProposal` collection filtered
server-side, never a per-id lookup a client could iterate. No write path is
touched, so `PortalCrossRefGuard`/`portal-writer-crossref-guard` has nothing
new to guard here (the write path it and `proposeFromPortal()`'s own
ownership check already cover was closed by `change-proposal-queue` and
`portal-create-cross-refs` before this change).

## NL Design System

`ProposeChangeForm` follows `ActionFieldsForm`'s existing pattern: Utrecht
`FormFieldTextbox` for text inputs (label + input associated by the
component itself, per `portal-spa-nl-design-system-styling`), a `Button`
with `appearance="primary-action-button"` to submit and
`appearance="subtle-button"` to cancel. No new component library or token is
introduced.

## File Structure

```
lib/
  Controller/ProposalController.php     (+ mine())
  Service/Proposals/ProposalService.php (+ mine())
appinfo/routes.php                      (+ 1 route)
src/portal/
  components/ProposeChangeForm.jsx      (new)
  components/PageView.jsx               (detail-block rowActions; DetailCard
                                          calls api.proposeChange/withdrawProposal/
                                          fetchMyProposals directly — the same
                                          convention FileUpload/FileList already
                                          use for their own scoped calls, so no
                                          App.jsx change is needed)
  lib/portalApi.js                      (+ proposeChange, withdrawProposal, fetchMyProposals)
tests/Unit/Service/Proposals/ProposalServiceTest.php   (+ mine() cases)
tests/Unit/Controller/ProposalControllerTest.php       (+ mine() cases, if the harness runs — see Inherited note below)
```

## Trade-offs

- **A dedicated `mine()` read vs. reusing `forSubject()` with a different
  scope field.** `forSubject()` is deliberately gated by the review
  permission one layer up (`ProposalController::index()` calls
  `PortalCaseAccessGuard::mayAct()` first) because it lists proposals ON a
  record a caller might not own. `mine()` needs no such gate — the filter
  IS the authorization (a subject's own `subjectRef`) — so giving it its own
  method keeps that distinction explicit rather than overloading one method
  with two different trust models behind a parameter.
- **No new "my proposals" leaf/widget type.** The blocked T05/T06 leaves are
  the generalised, cross-app version of this list. Building a portaliq-only
  React list component now, and swapping it for the leaf once
  `leaf-integrations` lands, is strictly additive — the leaf work is not
  narrowed by doing this first, and 9.11 does not have to wait on it.
- **Client sends only changed fields, not the whole `proposable` set.** The
  spec's example (a phone number update) and `ProposalService::propose()`'s
  own contract (each `changes` entry names ONE property) both assume a
  proposal is a targeted correction, not a full-record resubmission — sending
  every proposable field back unchanged would make `drift()`'s later
  did-the-record-move check noisier for no benefit.

## Open Questions

None.
