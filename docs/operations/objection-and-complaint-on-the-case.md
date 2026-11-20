---
title: Objection and complaint on the case page
sidebar_label: Bezwaar and klacht on the case
description: How a contribution places a create action, such as a bezwaar or a klacht, on the case it is about, with an optional window the server keeps
---

# Objection and complaint on the case page

A resident who disagrees with a decision files a bezwaar on the case the decision was made on. A contribution puts such a create action on the case page with the `onCase` key. The case page then lists it in the group "Meer acties voor deze zaak", before "Wijziging voorstellen" and "Aanvraag intrekken".

## The `onCase` key

`onCase` sits on a `type: create` action that has a `crossRefs` entry pointing at the case collection:

```json
{
  "id": "createBezwaar",
  "type": "create",
  "label": "Bezwaar maken",
  "register": "dossiq",
  "schema": "portaalVerzoek",
  "fields": ["grounds", "againstCaseId"],
  "crossRefs": {
    "againstCaseId": { "register": "zaken", "schema": "zaak", "scopeField": "portalSubject" }
  },
  "onCase": {
    "crossRef": "againstCaseId",
    "order": 10,
    "window": { "from": "decisionSentAt", "days": 42 },
    "closedText": "De termijn voor bezwaar is voorbij."
  }
}
```

| Key | Meaning |
| --- | --- |
| `crossRef` | A key of the same action's `crossRefs`. Its `register` and `schema` are the cases whose page shows the button. |
| `order` | An integer. The case page lists its actions in this order. |
| `window.from` | A date field on the case. The action opens the day after that date. |
| `window.days` | A positive integer. The action stays open through the last of these days, until 23:59 Europe/Amsterdam. |
| `closedText` | Optional. The sentence shown instead of the button once the window has closed. Without it the page says "Dit kan niet meer bij deze zaak." |

Portaliq keeps `onCase` only when it is well formed. A malformed `onCase` is dropped and the action stays where it was. A case without the `from` date reads as closed.

## What the server does

- `GET /portal/api/citizen/cases/{register}/{schema}/{id}` answers `caseActions`. Each entry is `{app, action, label, open, closesOn?, closedText?}`, and an open one carries `form`: the action without the cross-reference field. The server decides open or closed; the screen never computes a date.
- The form asks the action's fields without the case. On submit the create route gets `onCase=<register>/<schema>/<id>`. The server re-reads the case through the cross-reference's own scope, checks the window again, and writes the case id into the cross-reference field over anything in the body. `PortalCrossRefGuard` then runs as for every create.

| Answer | When |
| --- | --- |
| 403 `cross_ref_refused` | The case is not the resident's. |
| 403 `forbidden` | The action is not placed on that collection. |
| 409 `case_action_closed` | The window closed, also when the page still showed the button. The answer carries the closed sentence. |
| 400 `on_case_invalid` | `onCase` does not name a register, a schema and an id. |

After sending, the form is replaced by the receipt: the reference, when the created object carries one, and the line that the bezwaar or klacht is now a case of its own in Mijn zaken.

A contribution that declares no `onCase` keeps its actions where they were, for example on the overview page.
