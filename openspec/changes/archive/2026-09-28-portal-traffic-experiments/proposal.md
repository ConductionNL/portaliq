---
kind: code
---

# Proposal: portal-traffic-experiments

## Summary

Phase 4b, the last, of portal traffic analytics (phase 0:
`portal-traffic-analytics`, phase 1: `portal-traffic-visitors-and-geo`,
phase 3: `portal-traffic-outcomes`, phase 4a: `portal-traffic-reporting`).
The earlier phases count, judge and report; this one lets a portal try
something and see whether it worked, and gives an operator who accepts
the warning two behavioural instruments that the earlier phases only
named.

1. **Page experiments.** A portal declares A/B experiments in
   `traffic.experiments`: a page, two or more variants (another page
   shown in its place without a reload, or the same page with some text
   changed), a goal and a status. The client puts a visitor on a variant,
   sticky for the visit, and tags every event of that session with the
   experiment and the variant. The aggregation counts sessions and
   conversions per variant and names a winner only with thirty sessions
   per variant and a two-proportion z-test at 95 percent. An Experiments
   widget shows the variants, the rates and the verdict, and says "not
   enough data" until there is.
2. **Heatmaps, off by default.** With `sensitive.heatmaps` on, the client
   sends where a click landed (fractions of the document, a viewport
   bucket, the element's tag and a short selector without ids) and how
   far a page was scrolled; nothing else. The rollup carries a fifty by
   fifty click grid and scroll deciles per page; a Heatmap widget draws
   them on a plain rectangle, not a screenshot. With the switch off the
   events are refused as `sensitive-off`.
3. **Session recording, off by default.** With `sensitive.sessionRecording`
   on, the client lazily loads a separate recorder that streams the
   page's layout as a tree in which every text node is only its length
   and every input only the length of its value, plus the pointer, the
   clicks, the scrolling and the navigations. The collector masks every
   chunk again on arrival, bounds a chunk at 256 KB and a visit at 2 MB,
   stores the visit as a `portalTrafficRecording` object with the raw
   events' retention, never records an external portal and never before
   consent where consent is required. A Recordings widget lists the
   visits and a player replays them in a sandboxed frame.

## Decisions carried (Ruben, 2026-09-04)

- Decision 4: page A/B is in; heatmaps and session recording exist but
  are off by default, switched on per portal with an explicit warning.
- Decision 2 stays: nothing here adds a cookie or an identifier. A
  cookieless visitor's variant is sticky for the page load and the client
  side navigation of one visit, and stored nowhere; with a persisted
  client id it is derived from that id and survives a reload.
- Decision 3 stays: a recording is an object, admin-readable like a raw
  event, purged by the same aggregation run.

## What could not be done as asked

A custom form field for the `sensitive` block, rendering the four
switches with their warnings inside the portal's detail form, was
checked and is not feasible on `@conduction/nextcloud-vue` 2.37: the
library validates a `kind: 'form-field'` registry entry and records its
`appliesTo`, but no component consumes `appliesTo` (a grep of the
distributed bundle finds it in `CnAppRoot` only, as required metadata).
The schema descriptions on each switch and the warning card on the
Traffic overview stay the admin UI for the switches. The library change
is a separate piece of work.

## Affected Projects

- [x] `portaliq`: the experiment, heatmap and recording services, the
      validator rules, the client and the recorder, the schemas, the
      widgets and the player, the docs.
- [ ] `docusaurus-plugin-portaliq`: no change; the served client is the
      same file. A Docusaurus site's `history.replaceState` plus
      `popstate` moves its router to a variant page like the built-in
      renderer's, and its own content security policy decides whether
      the recorder may load from the portal's origin.

## Out of scope

- A screenshot under the heatmap: the page is public, the reader opens
  it beside the map.
- Multivariate experiments on more than one page per session: a session
  takes part in the first experiment it meets.
- Replay of typed values or text: by design there is nothing to replay.
