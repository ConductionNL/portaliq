# Buildiq consumer halves (handed over 25 Nov 2026)

What buildiq serves to the portal, for task 17. Both are app-local leaves
(ADR-066); with buildiq absent or answering nothing the portal behaves as it
does today.

## `buildiq-registration-form`

Asked on the case type with optional `audience`, `name` and `channel`. One
served item:

```json
{
  "id": "c1d2…",
  "name": "Evenementenvergunning",
  "audience": "resident",
  "channel": "portal",
  "isDefault": true,
  "isPublic": false,
  "minTrust": "substantial",
  "fee": {"amount": 25, "currency": "EUR", "description": "Leges evenementenvergunning"},
  "confirmationText": "We hebben uw aanvraag ontvangen.",
  "allowSaveForLater": true,
  "sections": [{"id": "event", "label": "Evenement"}],
  "fields": [{"key": "eventName", "section": "event", "required": true}],
  "steps": [],
  "formLogic": [],
  "presets": [{"key": "intakeChannel", "value": "portal"}]
}
```

The envelope is `{items, total, nextCursor, drafts}`. A hidden preset is never
in `fields[]`; it travels in `presets[]` only. `minTrust` is null for a form
that needs no sign-in; a form with a fee is always `substantial` or higher
(buildiq refuses to save it otherwise).

## `buildiq-page-layout`

Asked with the shown object's register, schema and id (pass the object as the
`object` filter when the portal holds it). The audience is resolved by buildiq
from the caller: a visitor without an account gets only `portal` and
`everyone` layers. At most one item; `items: []` means render as today.

```json
{
  "items": [{
    "id": "7f0c…",
    "header": {"fields": ["identifier", "status"]},
    "tabs": [{"id": "overview", "label": "Overzicht"}],
    "widgets": [],
    "taskList": {"panel": {"placement": "none", "tabs": [], "collapsed": false}},
    "uploadFields": []
  }],
  "total": 1,
  "nextCursor": null,
  "appliedLayers": [{"id": "7f0c…", "name": "Portaal", "audience": {"kind": "portal", "ref": null}}],
  "withheld": []
}
```
