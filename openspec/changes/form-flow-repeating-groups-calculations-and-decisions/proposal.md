---
kind: code
---

# Proposal: form-flow-repeating-groups-calculations-and-decisions

## Why

A resident who asks for a street party has to list the neighbours who agree. Today a portal form has one field per question, so the form designer has to guess "Bewoner 1, Bewoner 2, Bewoner 3" and hope nobody brings a fourth. A form also cannot work out an amount or a date while it is filled in, and it cannot let a decision table decide which question comes next. Open Formulieren 4.0.1 does all three: a repeating group (`editgrid`, `src/openforms/formio/components/vanilla.py:1147`), user-defined variables that logic rules set (`src/openforms/forms/models/form_variable.py:246`, `src/openforms/forms/models/logic.py:21`) and DMN evaluation from a logic rule (`src/openforms/dmn/contrib/camunda/plugin.py:56`).

The Zuiddrecht board **FormulierKaart** ("Buurtfeest aanvragen, stap 2") draws the repeating group: "Bewoners", "Voeg minstens twee bewoners toe", one card per neighbour with "Wijzigen" and "Verwijderen", and "Nog een bewoner toevoegen".

## What changes

- **A repeating group.** A form field of type `group` with `repeat` asks the same sub-questions once per item, between a minimum and a maximum, as the board draws.
- **A calculated value.** A field with `calculate` shows a value the portal works out from earlier answers (a sum, a product, a date plus days). The server works it out again on submit and never takes it from the browser.
- **A decision from a decision table.** A step may name a business rule. The server asks the rule engine at the step change and writes the outcome into a read-only field, or opens the step the outcome names.

## Rows covered

- `int-repeating-group` (decision 104), screen FormulierKaart.
- `int-calculated-value` (decision 104), no board yet.
- `int-decision-table-logic` (decision 104), no board yet: the resident sees only the outcome; the table itself is drawn as buildiq's BqBeslistabel.

## Who owns what

Authoring stays in buildiq's form designer (ADR-085 §6): `buildiq/nextcloud-vue/form-child-records-table` for the group (row `form-subtable-children`), `buildiq/forms-live-values-and-checks` for calculations (row `form-calculation`) and buildiq's business rules engine for decision tables (`buildiq/openspec/specs/business-rules-engine`). This change is the portal's half: it renders, validates and evaluates what a published form declares.

## Out of scope

- Writing expressions or tables in portaliq.
- Prices from calculations. A form's price is `data-lookups-and-checks-in-forms` (product variants) and `intake-pay-on-submit` (the case type's fee).
