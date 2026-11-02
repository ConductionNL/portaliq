---
kind: code
---

# Proposal: change-proposals-reach-the-case-page

## Why

Portaliq's half of the change proposal queue is built (archived `change-proposal-queue`, 9 of 9 tasks): the `changeProposal` schema, the citizen's propose and withdraw in the site, the colleague route `POST /api/proposals`, the two OpenRegister leaves and `ProposalQueueWidget.vue` with accept and reject. No page shows any of it to staff. Dossiq does not place `portaliq-change-proposal-queue` on its case page and declares no `propose-change` action (ConductionNL/dossiq#3215 is open). Three gaps sit on portaliq's side as well:

- The widget offers a reviewer accept and reject, but a colleague who may read the case and not change it has no form to propose with. The route exists; nothing posts to it.
- `GET /api/proposals` answers only a reviewer, so that colleague cannot see the proposals they made on the case.
- `ProposalController::proposeAsColleague()` takes the `proposable` list from the request. The allow-list then limits nothing on the staff channel: any property the caller names is proposable.

The board **PtVoorstelBeoordelen** draws the staff side: the queue on the case. Whether it also draws the colleague's propose form was not checked; if not, the board gets it before T04 is built (boards are the UI canon). **ZaakWijzigingVoorstellen** draws the resident's side, which is built and stays as it is.

## What changes

- **The widget serves both readers and reviewers.** A reviewer sees the queue with accept and reject, as now. A colleague who may read but not review sees "Wijziging voorstellen" with the proposable fields, prefilled from the record, and their own proposals on this record with a withdraw button.
- **The allow-list comes from the server.** The colleague route uses the proposable properties the owning app's contribution declares for that register and schema, and ignores any list in the request.
- **Dossiq places the leaf.** The case detail page in dossiq places `portaliq-change-proposal-queue` and its mijnZaken contribution declares `propose-change` with its proposable fields. That work is done in dossiq, not parked.

## Rows covered

- `act-propose-colleague`, `act-staff-review-queue`, `sib-dossiq-2-21` (all building). Boards: PtVoorstelBeoordelen, ZaakWijzigingVoorstellen.

## Out of scope

- The citizen's propose and withdraw in the site (REQ-CPQ-006, built).
- Proposals on records of apps other than dossiq. Any app can place the leaf the same way once this lands.
