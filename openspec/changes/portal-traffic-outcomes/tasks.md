# Tasks: portal-traffic-outcomes

## 1. Goals and funnels

- [ ] 1.1 `TrafficConfigResolver`: normalise `traffic.goals[]`, `traffic.funnels[]` and `traffic.customDimensions[]`; add `form_start`, `form_field`, `form_abandon`, `page_not_found` to the known events.
- [ ] 1.2 `TrafficMatch`: the one match shape (pathPrefix, pathEquals, eventName, fileExtension, formId, term) shared by goals and funnel steps.
- [ ] 1.3 `TrafficGoals`: conversions per session, completions per event, value; unit tests per type.
- [ ] 1.4 `TrafficFunnels`: ordered matching per session; unit tests for in-order, out-of-order and partial journeys.
- [ ] 1.5 Rollup: `goals`, `conversionRate`, `funnels`; the aggregation job passes the definitions.
- [ ] 1.6 Schemas: `portal.traffic.goals`, `funnels`, `customDimensions` with descriptions the detail form renders; `portalTrafficDaily` fields; versions bumped.
- [ ] 1.7 Widgets `TrafficGoals` (with the "No goals defined" state linking to the portal) and `TrafficFunnels` (CSS step bars).

## 2. Form analytics

- [ ] 2.1 Client: `form_start` on first interaction, `form_field` on blur (fieldId and ms only), `form_abandon` on pagehide with a started, unsubmitted form, `formId` on `form_submit`; forms found by `data-portaliq-form` or the form's id.
- [ ] 2.2 `FormBlock.vue` carries `data-portaliq-form`.
- [ ] 2.3 Validator: form events refused unless enabled; `form_field` params whitelisted.
- [ ] 2.4 `TrafficFormStats`: starts, submits, abandons, completion rate, per-field time and where people leave; unit tests.
- [ ] 2.5 Widget `TrafficForms`.

## 3. Missing pages, custom dimensions, search

- [ ] 3.1 Renderer: `data-portaliq-status="404"` on the not-found state; client sends `page_not_found`; rollup `notFound`; row in `TrafficPages`.
- [ ] 3.2 Client: `window.portaliqTraffic.dimension(id, value)` attaches `cd_<id>`; validator strips undeclared; rollup `customDimensions`; widget `TrafficDimensions`.
- [ ] 3.3 `FederatedSearchBlock.vue` reports `search` with the term and the result count; a Searches list on the Traffic page.

## 4. Documentation and proof

- [ ] 4.1 `docs/operations/traffic-analytics.md`: goals, funnels, form analytics (values are never sent), missing pages, custom dimensions, search.
- [ ] 4.2 Seed: open-tilburg carries a goal, a funnel, the form and not-found events and one custom dimension.
- [ ] 4.3 E2E `tests/e2e/traffic-outcomes.spec.ts` on the throwaway instance.
