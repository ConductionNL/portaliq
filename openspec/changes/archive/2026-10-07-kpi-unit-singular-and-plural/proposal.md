# Proposal: kpi-unit-singular-and-plural

## Why

Seen in the parent portal review of 2026-10-03: a child's figure card on the record page said "1 dagen". A `kpi` card's `unit` is one string, so the app can only pick one form, and Dutch needs "1 dag" beside "5 dagen". A detail line has the same limit ("1 minuten").

## What changes

- A card's `unit`, and a detail's `label`, is either a string (as today) or `{one, other}`. The app passes both forms already in the reader's language, as it does every other label.
- The portal shows `one` beside exactly 1 and `other` beside every other figure, also when the row holds none. That is the rule Nextcloud's plural forms use for Dutch and English (`n != 1`).
- The normaliser keeps a pair only when both forms are non-empty strings. Half a pair is dropped, so the card shows its figure without a unit.

## Shape for a contributing app

```json
{"field": "absentDays", "label": "Afwezig", "unit": {"one": "dag", "other": "dagen"},
 "details": [{"field": "lateMinutes", "label": {"one": "minuut", "other": "minuten"}}]}
```

## Not changed

- A string unit or detail label reads exactly as before.
- Portaliq adds no strings of its own; the words come from the app.
