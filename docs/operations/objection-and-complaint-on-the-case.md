---
title: Objection and complaint on the case page
sidebar_label: Actions on the case page
---

# Objection and complaint on the case page

A resident files an objection (bezwaar) against a decision on the page of the case it was made on.
The same goes for a complaint (klacht) about how a case was handled.
The case app declares the action. Portaliq places it on the case page, fills in the case and keeps the guard.

This page is the contract a case app implements.

## The `onCase` key

Put `onCase` on a create action that already has a `crossRefs` entry pointing at its case collection.

```json
{
  "id": "createBezwaar",
  "type": "create",
  "register": "dossiq",
  "schema": "portaalVerzoek",
  "label": "Bezwaar maken",
  "fields": ["againstCaseId", "grounds"],
  "crossRefs": {
    "againstCaseId": { "register": "dossiq", "schema": "zaak", "scopeField": "portalSubject", "required": true }
  },
  "onCase": {
    "crossRef": "againstCaseId",
    "order": 10,
    "window": { "from": "decisionSentAt", "days": 42 },
    "closedText": "De termijn voor bezwaar is voorbij."
  }
}
```

| Key | Required | What it does |
| --- | --- | --- |
| `crossRef` | yes | Names a key of the same action's `crossRefs`. Its register and schema are the cases whose page shows the action. |
| `order` | no | A whole number. Lower comes first. Default 0. |
| `window.from` | no | A date field on the case. |
| `window.days` | no | A positive whole number of days. |
| `closedText` | no | The sentence a resident reads when the window is closed. Without it the site says "Dit kan niet meer bij deze zaak." |

Portaliq drops a malformed `onCase` and keeps the action where it was.
A `crossRef` that names no declared cross reference is malformed.

## The window

Without a window, the action shows on every case of the resident in that collection.

With a window, the action opens the day after the `from` date.
It stays open for `days` days, through 23:59 Europe/Amsterdam on the last day.
A case without that date, or with a date Portaliq cannot read, shows the closed sentence.
Portaliq computes this on the server; the site never computes a date.

For a bezwaar the Algemene wet bestuursrecht (Awb) gives six weeks from the day after the decision was sent.
Declare `days: 42` on the date field that holds that day.
Portaliq does not know legal terms; it applies the window you declare.

## What the resident sees

The case page answers `caseActions` next to the writable set and the documents.
Each entry says whether the action is open.
The case screen draws open actions as buttons in the group "Meer acties voor deze zaak", before "Aanvraag intrekken".
A closed action shows its sentence instead of a button.

Pressing the button opens the action's form under the group, without the case field.
After sending, the form gives way to the receipt with the reference of the new record.
Declare `successMessage` on the action to word the first sentence, for example "Uw bezwaar is ontvangen."

## What the server checks

The form posts to the usual create route with `onCase=<register>/<schema>/<id>`.
Before writing, Portaliq:

1. checks the action is placed on cases of that register and schema, else 403;
2. reads the case again through the resident's own scope, else 403 `cross_ref_refused`;
3. asks the window again, and answers 409 `case_action_closed` with your sentence when it closed;
4. writes the case id into the `crossRef` field over anything the body sent;
5. runs the cross-reference guard as for every create.

A page left open past midnight on the last day therefore cannot file late.
