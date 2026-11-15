# Proposal: contribution-value-labels

## Why

Seen on the Dutch parent portal of a primary school (2026-10-02). On "Afwezigheidsmeldingen van mijn kind" the status cells read "approved" and "submitted", the raw values learniq stores. The absence form's "Soort afwezigheid" select offered "Illness" and "Bereavement": portaliq wrote the enum values as English words. An app had no way to say how its values read, so neither screen could be in the reader's language.

## What changes

- A collection column MAY declare `valueLabels`, a map of raw value to label: `{"approved": "Goedgekeurd"}`. The cell and the detail card show the label. A value without a label reads as before.
- An action field config MAY declare the same `valueLabels`. When portaliq turns the field's schema `enum` (or `oneOf`) into a select, a declared label wins over the generated words. The option still submits the raw value.
- One shape, one normaliser (`ValueLabelsNormaliser`), for both places. Only string labels on string or integer keys survive, at most 100 per field. A value is capped at 100 characters, a label at 200. A malformed map is dropped.
- The app sends the labels in the reader's language, the same way it already sends `label`. learniq translates them through its own catalogue.

## Not changed

- Which values a field accepts. The schema stays the authority, and a label for a value outside the enum adds no option.
- A manifest's own `static` options provider keeps its own labels.
- The badge modifier class still follows the raw value, so a theme can colour "approved" in every language.
- The React portal (`src/portal`), which is being retired.
