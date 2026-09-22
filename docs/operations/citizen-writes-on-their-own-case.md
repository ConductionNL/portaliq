---
title: What a citizen may write on their own case
sidebar_label: Citizen writes on a case
---

# What a citizen may write on their own case

A citizen with a case in the portal can correct an answer they already gave,
add the document that was missing, and answer what the case worker asked.
What they may touch is declared by the case app, on the case type. Portaliq
keeps no list of its own and decides nothing: it reads the declaration on
every request and renders exactly that.

This page is the contract a case app implements.

## The three acts

An amendment, a document and a task answer look alike to a database and
nothing alike to a citizen or to the law, so each has its own route, its own
refusal and its own event.

| Act | Route | Governed by |
| --- | --- | --- |
| Read the case and its writable set | `GET /portal/api/citizen/cases/{register}/{schema}/{id}` | the case type |
| Amend an answer | `PATCH /portal/api/citizen/cases/{register}/{schema}/{id}` | `portalAmendmentWindow` plus the field flag |
| Add a document | `POST /portal/api/citizen/cases/{register}/{schema}/{id}/documents` | `portalDocumentWindow` |
| Answer a task | `POST /portal/api/tasks/{uuid}/complete` | the task itself, answered once |

## What the case app declares

### On the update action

The contribution's `type: update` action for the case schema carries a
`citizenWrite` block. Without it the citizen write surface does not exist for
that schema, and all three acts answer 403.

```json
{
  "id": "amend-case",
  "type": "update",
  "register": "zaken",
  "schema": "zaak",
  "scopeField": "indiener",
  "fields": ["omschrijving", "toelichting"],
  "citizenWrite": {
    "typeField": "zaaktype",
    "typeRegister": "zaken",
    "typeSchema": "zaaktype",
    "statusField": "status",
    "recordField": "portalWrites"
  }
}
```

`typeField`, `typeRegister` and `typeSchema` are required: they say where the
case type lives. `statusField` defaults to `status` and `recordField` to
`portalWrites`. A declaration missing a required key is dropped whole, which
closes the surface rather than opening it.

The action's own `fields` whitelist still applies. A case type can never widen
what the contribution already granted.

### On the case type

The flag lives on the field, so the case type carries one entry per field.

```json
{
  "portalWritable": [
    { "field": "omschrijving", "audiences": ["client"] },
    {
      "field": "toelichting",
      "audiences": ["client"],
      "openStatuses": ["ontvangen"],
      "closedReason": "De toelichting hoort bij de indiening en staat vast."
    }
  ],
  "portalAmendmentWindow": {
    "openStatuses": ["ontvangen", "aanvullen"],
    "closedReason": "De aanvraag is in behandeling genomen."
  },
  "portalDocumentWindow": {
    "openStatuses": ["ontvangen", "aanvullen"],
    "closedReason": "De zaak neemt geen stukken meer aan."
  },
  "portalStatusLabels": {
    "ontvangen": {
      "label": "Wij hebben uw aanvraag ontvangen",
      "description": "U hoort binnen acht weken van ons."
    }
  }
}
```

A field is open when its flag names the audience, the amendment window is open
in the case's current status, and the field's own `openStatuses`, if it
declares any, include that status. Everything else is closed, and a closed
field is shown as text with the sentence that says why, never as a control
that fails on submit.

Both windows are closed when they are absent or malformed. So is the whole
surface when the case type cannot be read.

`portalStatusLabels` is the vocabulary the citizen reads. Portaliq renders the
label and description unchanged and shows nothing when a status carries none;
it holds no status words of its own.

## What lands on the case

Every citizen write appends a record to `recordField`, naming the identity,
the mandate it acted under, both answers and the time:

```json
{
  "act": "amendment",
  "identity": { "subjectRef": "…", "audience": "client", "trust": "high", "jti": "…" },
  "mandate": { "action": "amend-case", "audience": "client", "minTrust": "substantial" },
  "changes": { "omschrijving": { "from": "Een dakkapel", "to": "Een dakkapel aan de achterzijde" } },
  "at": "2026-09-14T10:00:00+00:00"
}
```

The record is appended, never written over, so a case worker sees the earlier
answer beside the amendment. The same act also lands in the portal audit
trail.

## The event

Portaliq dispatches `OCA\Portaliq\Event\PortalClientWriteEvent`
(`portal.write.client`) once per act, carrying the case, the identity, the
mandate, the act and the fields. Bind a listener by class name:

```php
$context->registerEventListener(
    \OCA\Portaliq\Event\PortalClientWriteEvent::class,
    \OCA\YourApp\Listener\PortalWriteListener::class,
);
```

Naming the class in `registerEventListener` does not autoload it, so a case
app keeps working with Portaliq absent (ADR-046). The event simply never
fires.

A staff write never raises it. The internal write does not pass through the
portal, and nothing else in Portaliq dispatches the event, so "de indiener
heeft gereageerd" and "een collega heeft gereageerd" are different facts
without anyone having to guess from the actor.

## Refusals

Each refusal carries one sentence the citizen can read, plus a slug for the
portal to match on.

| Slug | Status | When |
| --- | --- | --- |
| `portal-writes-not-declared` | 403 | no `citizenWrite` action for this schema, or the trust is too low |
| `case-not-yours` | 403 | the case is another citizen's, or does not exist |
| `amendment-window-closed` | 409 | the case type's amendment window has closed |
| `documents-closed` | 409 | the case type's document window has closed |
| `field-not-writable` | 422 | the write names a field that is not open |
| `too-many-writes` | 429 | past the per-identity or per-case throttle |
| `task-already-answered` | 409 | the task has an answer already |

A case that is not the citizen's and a case number that does not exist answer
identically, so the portal never confirms that someone else's case is real.

## Limits

The write surface is throttled per identity and per case on top of the
framework's own per-address limit, because one household behind one address is
not an attack and one identity across a mobile network's addresses is not
innocent.

Documents go through the file surface the case app already declares. Portaliq
adds no second upload path, and a citizen can add a document but never replace
or remove one: a name that collides with a document already on the case is
given a suffix.
