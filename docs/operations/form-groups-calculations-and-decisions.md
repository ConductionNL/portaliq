---
title: Repeating groups, calculated values and decisions in forms
sidebar_label: Groups, calculations, decisions
---

# Repeating groups, calculated values and decisions in forms

Authoring stays in buildiq's form designer. The portal renders, validates and evaluates what a published form declares.

## A repeating group

A field of type `group` with `repeat` asks the same questions once per item.

```json
{ "name": "bewoners", "type": "group", "label": "Bewoners",
  "repeat": { "min": 2, "max": 20, "itemLabel": "Bewoner", "addLabel": "Nog een bewoner toevoegen" },
  "fields": [ { "name": "naam", "type": "string", "required": true }, { "name": "huisnummer", "type": "string" } ] }
```

The resident sees one card per item with **Wijzigen** and **Verwijderen**, and the add button, which is gone at `repeat.max`. Adding or changing opens the questions inline with **Opslaan** and **Annuleren**. The error summary says "Voeg nog 1 bewoner toe" and links to the add button.

The server checks every item against the sub-fields and refuses fewer than `repeat.min` or more than `repeat.max` items. An error names the item and the field, for example `bewoners[1].naam`. A group reaches the case app as a JSON array under its name.

## A calculated value

```json
{ "name": "einddatum", "type": "date", "label": "Uw vergunning loopt tot", "calculate": { "op": "addDays", "args": ["startdatum", 365] } }
```

`op` is one of `sum`, `multiply`, `subtract`, `addDays`, `diffDays` and `count`. An argument is a field name, a group sub-field written `bewoners[].aantal`, or a number. Dates are `yyyy-mm-dd`. The site shows the value while the form is filled in. On submit the server works it out again and stores its own result, whatever the browser sent. A form with an operation the portal does not know does not open.

## A decision

A step may name a decision:

```json
{ "id": "route", "fields": ["soortVergunning"], "decision": { "rule": "parkeren-soort-vergunning",
  "inputs": { "woonplaats": "adres.plaats", "auto": "kenteken" }, "output": "soortVergunning",
  "nextStep": { "bewoner": "stap-bewoner", "bedrijf": "stap-bedrijf" } } }
```

At the step change the server asks the rule engine with the named inputs, writes the outcome into `output` and opens the step `nextStep` names for it. The rule, its inputs and its table never reach the browser. The server asks again on submit and stores the outcome per step. If the engine does not answer, the step says that a connection has a fault, offers **Opnieuw proberen** and keeps the answers.

## Delivery

Calculated values and decided outcomes travel to the case app as plain fields. The case data also carries `fieldMeta`, which marks each of them `computed: true`, so the case app can tell them from typed answers.
