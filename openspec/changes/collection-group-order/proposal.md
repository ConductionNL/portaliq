# Proposal: collection-group-order

## Why

live-learniq-1 (25 Nov 2026, 20:32Z) found that learniq's warmtepompacademie "Certificaten" table cannot show "the first to expire first" (learniq portal-contribution: *An employer sees her people's certificates, the first to expire first*). learniq declares `defaultSort: expiresAt asc` and `groupByField: courseName`, and portaliq orders the groups by their heading (collection-group-by-field T1), so BRL 6000-21 (2028) stands above F-gassen (2027). Ruben decided (25 Nov 2026 21:50 CET): portaliq gets an opt-in `groupOrder: 'rows'`, learniq's certificates declare it.

## What changes

- A contribution collection MAY declare `groupOrder`: `'rows'` puts each group where its first row stands in the sorted rows; `'heading'` (the default) keeps ordering the groups by their heading.
- The normaliser keeps `groupOrder` only as `'rows'` or `'heading'` and only beside a kept `groupByField`.
- Rows without a value still come last; rows keep their order inside a group.

## Out of scope

- The learniq declaration (learniq, its own PR).
- Live check on the warmtepompacademie portal: the learniq live lane.
