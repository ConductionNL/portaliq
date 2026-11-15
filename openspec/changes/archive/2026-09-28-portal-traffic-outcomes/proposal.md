---
kind: code
---

# Proposal: portal-traffic-outcomes

## Summary

Phase 3 of portal traffic analytics (phase 0: `portal-traffic-analytics`,
phase 1: `portal-traffic-visitors-and-geo`). The first two phases count
what happened; this one says whether it was what the portal wanted.

1. **Goals.** A portal names the outcomes it cares about in
   `traffic.goals`: a page reached, an event, a download, a form submitted
   or a search. The collector does not change. The aggregation evaluates
   each goal per session and the rollup carries conversions (sessions that
   met it), completions (events) and the value the operator assigned.
2. **Funnels.** A portal names ordered steps in `traffic.funnels`, each a
   goal-shaped match. A step counts only after the previous one, by the
   session's sequence, so the rollup says how many sessions reached each
   step and where they dropped off.
3. **Form analytics.** The client watches the forms the site renders and
   reports a form started, a field left (its id and how long it had
   focus, never what was typed) and a form abandoned. The validator keeps
   only the whitelisted field parameters, so a value cannot reach storage
   even from a client that sends one.
4. **Missing pages.** The renderer marks its not-found state and the
   client reports `page_not_found`, so a broken link shows up in a list
   rather than in a server log nobody reads.
5. **Custom dimensions.** A portal declares the dimensions it wants
   (`traffic.customDimensions`); the client attaches `cd_<id>` parameters
   through `window.portaliqTraffic.dimension(id, value)`, and the validator
   strips anything undeclared, which is the contract's existing rule for
   dimensions extended to these.
6. **Site search.** The built-in search box reports the term and the
   result count through the same `search` event the URL-derived one uses,
   so "popular search terms" come from one place.

## Decisions carried (Ruben, 2026-09-04)

- Decision 4: funnels, form analytics and page A/B are in. A/B is phase 4,
  not here.
- Decision 2 stays: nothing here adds a cookie or an identifier. A goal or
  a funnel is evaluated on the day's sessions the sessioniser already
  derives.

## Affected Projects

- [x] `portaliq`: the goal, funnel and form evaluators, the validator
      whitelist, the client, the schemas, the widgets, the docs.
- [ ] `docusaurus-plugin-portaliq`: no change; the served client is the
      same file, and an external page opts into form analytics with
      `data-portaliq-form` and into missing pages with
      `data-portaliq-status="404"`.

## Out of scope

- Page A/B experiments, heatmaps, session recording (phase 4).
- Goal values in a currency; `value` is a number the operator assigns.
