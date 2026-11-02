---
kind: code
---

# Proposal: case-outcome-on-the-case-page

## Why

A resident whose case has ended wants to know two things: what was decided, and why. Dossiq now projects both on its `mijnZaken` collection as `resultPublicLabel` and `resultPublicDescription` (dossiq 9489ca2c6, dossiq#3143), so Mijn zaken shows the outcome in words. The case page does not. `src/site/components/e/CitizenCase.vue` shows the status label and description (REQ-CWOC-006) and nothing about the outcome, and no requirement says it should. The board **ZaakAfgerond** draws the outcome with the organisation's explanation at the top of a finished case.

## What changes

- **A collection names its outcome fields.** A collection may declare `outcome: {labelField, descriptionField}`, each a field it projects. The normaliser drops a field the collection does not project.
- **The ended case shows its outcome.** Once a case has ended (REQ-CWOC "A case that has ended offers nothing and explains nothing"), the case page shows the outcome label as a heading and the explanation under it, above the status steps.
- **No raw reference.** A value that reads as a uuid or a `$ref` is never shown as an outcome.

## Rows covered

- `dem-cl-case-outcome-explained` (built on Mijn zaken by dossiq; the case page half is this change). Board: ZaakAfgerond, Zaak.
- `cas-citizen-status` is already described by REQ-CWOC-006 in `specs/citizen-writes-on-their-own-case`; this PR records that link in capabilities.json and adds no requirement for it.

## Out of scope

- Writing the outcome. The case app owns the result and its public explanation.
- An objection link under the outcome. That is `case-page-objection-and-complaint`.
