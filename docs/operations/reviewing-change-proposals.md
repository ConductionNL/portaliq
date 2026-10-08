---
title: Reviewing change proposals on a record
sidebar_label: Reviewing change proposals
description: How a case app shows the proposals waiting on a record, and what a reviewer does with them
---

# Reviewing change proposals on a record

A resident in the portal, or a colleague who may read a record but not change it, proposes a new value for a field. The proposal waits on that record until someone who may review it accepts or rejects it. Portaliq keeps the queue; the app that owns the record shows it.

## Placing the queue on a record

Portaliq offers two OpenRegister leaves:

| Leaf | Kind | What it does |
|---|---|---|
| `portaliq-change-proposal-queue` | render surface (widget and tab) | Shows the proposals on the record, with accept and reject |
| `portaliq-change-proposals` | data provider | Lists the proposals on a record, and adds one for the signed-in colleague |

A case app places the review surface on its detail page like any other integration widget:

```json
{ "id": "case-proposals", "type": "integration", "integrationId": "portaliq-change-proposal-queue", "title": "Change proposals" }
```

OpenRegister loads the `portaliq-leaves` bundle on the app's pages, so nothing else is needed. The case app never calls portaliq: when a proposal is accepted, the record is written through OpenRegister and the app sees an ordinary update.

## What the reviewer sees

Each proposal shows where it came from (the portal or a colleague), the proposer's note, and per field the value now and the value proposed.

- **Accept** writes the proposed values to the record as the reviewer, so the record's history names them. The queue says "The change is saved on the record."
- When the record changed after the proposal was made, accepting stops and shows both values: "This record changed after the proposal was made." **Accept anyway** writes the proposal over the newer value. Leaving it means nothing is written.
- **Reject** asks for a reason first. Without one nothing is sent.

Someone who may not review proposals on the record sees "You cannot review proposals on this record." and no proposals.

## Who may do what

- Reviewing needs the action `portal.review-proposal` (administrators by default, under Admin settings, Portaliq, Actions) and read access to the record.
- A colleague proposes through the data leaf: `POST /apps/openregister/api/objects/{register}/{schema}/{id}/integrations/portaliq-change-proposals` with `changes`, `proposable` and an optional `note`. The colleague needs read access to the record, and the proposal is recorded under their own account whatever the request says.
- A resident proposes from the portal on a record whose contribution declares a `propose-change` action; only the properties that action lists as `proposable` can be proposed.
