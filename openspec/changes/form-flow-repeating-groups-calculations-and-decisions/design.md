# Design: form-flow-repeating-groups-calculations-and-decisions

## Screens

The repeating group follows the Zuiddrecht board **FormulierKaart** (canvas `5NkFW28vZUUij43xzxHg5a`).

| Board element | Here |
|---|---|
| Legend "Bewoners" and hint "Welke buren in de straat zijn het eens met het feest? Voeg minstens twee bewoners toe." | the group's `label` and `description`; the minimum comes from `repeat.min` |
| Card "Bewoner 1" with "Henk de Vries", "Lindelaan 14, akkoord met het feest", "Wijzigen", "Verwijderen" | one card per item: `repeat.itemLabel` plus the number, then the item's answers on one or two lines |
| "Nog een bewoner toevoegen" | `repeat.addLabel`; hidden at `repeat.max` |

Adding or changing an item opens the sub-questions inline under the list, with "Opslaan" and "Annuleren", inside the same step. Focus goes to the first sub-question, and back to the card after saving. The error summary of `site-multi-step-forms` names a missing item count ("Voeg nog 1 bewoner toe") and links to "Nog een bewoner toevoegen".

There is no board for a calculated value or a decision. Until one exists: a calculated value renders as a read-only line with its label and value (`<output>` with `for` naming its inputs), and a decision either fills such a line or opens the step it names, with no extra screen. Both are on the missing-boards list.

## Form shape

All keys live on the published form object, which buildiq writes and `PortalFormBindingResolver` passes to the site.

```json
{ "name": "bewoners", "type": "group", "label": "Bewoners",
  "repeat": { "min": 2, "max": 20, "itemLabel": "Bewoner", "addLabel": "Nog een bewoner toevoegen" },
  "fields": [ { "name": "naam", "type": "string" }, { "name": "huisnummer", "type": "string" } ] }

{ "name": "einddatum", "type": "date", "label": "Uw vergunning loopt tot", "calculate": { "op": "addDays", "args": ["startdatum", 365] } }

{ "id": "route", "decision": { "rule": "parkeren-soort-vergunning", "inputs": { "woonplaats": "adres.plaats", "auto": "kenteken" },
  "output": "soortVergunning", "nextStep": { "bewoner": "stap-bewoner", "bedrijf": "stap-bedrijf" } } }
```

`calculate.op` is one of `sum`, `multiply`, `subtract`, `addDays`, `diffDays`, `count` (items in a group). Arguments are field names, group sub-fields (`bewoners[].aantal` for `sum`) or numbers. A form with an `op` the portal does not know is refused at publish and at render, as `intake-conditional-questions-and-drafts` does for an unknown condition (REQ-ICQ-003).

## Server

- `PortalFormValidator` validates a group as a list: each item against its sub-fields, the count against `min` and `max`.
- `PortalFormCalculator` (new) evaluates `calculate` in field order on submit and overwrites any value the browser sent for that field. The site runs the same evaluation in `src/site/components/forms/calculate.js` for display only.
- `PortalFormDecision` (new) calls the rule through the rule engine's server API with the named inputs, at the step change (`POST /api/intake/{route}/steps/{step}/decide`) and again on submit. The outcome is stored on the submission. The browser never receives the table. If the engine is unreachable, the step shows the "storing bij een koppeling" message of `data-lookups-and-checks-in-forms` and the draft stays.

## Delivery

A group travels to the case app as a JSON array under its field name. A calculated value and a decision outcome travel as plain fields, marked `computed: true` in the delivery's field metadata, so a case app can tell them from typed answers.
