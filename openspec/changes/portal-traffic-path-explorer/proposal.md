---
kind: code
---

# Proposal: portal-traffic-path-explorer

## Summary

The Traffic page answers "which page led to which" with a Journeys table:
the ten most common moves from one page to the next. It cannot say what
visitors did after that next page, and chaining its pairs draws paths no
visitor took. This change replaces it with a path explorer in the style
of a web analytics "path exploration" and "behaviour flow".

1. **Start or end point.** Start from the start of a visit or from a
   page, and read the steps forward. Or pick an end point, the end of a
   visit or a page, and read the steps that led there.
2. **Steps.** Every step lists its five busiest pages with their visits,
   the rest summed into one "+N more" node, and how many visits ended on
   each page. Grey bands show how visits moved from one step to the next.
   Add or remove steps, up to ten.
3. **Expand from a node.** Click a page, or press Enter on it, and the
   next steps only count the visits that passed it. Click it again to go
   back.
4. **Honest limits.** Paths come from the raw events, which are kept for
   the portal's retention (90 days by default). A period that reaches
   further back says which days the paths cover. A read is capped at
   50,000 events; a capped result says so and names the days it covers.
5. **Segments.** The page's segment selector narrows the paths with the
   same per-visit rule the daily figures use.

## Why exact paths from raw events

The daily records keep only pairs (`transitions[]`, top 100 per day).
Two pairs that share a page do not make a path: visits from A to B and
visits from B to C may be different visits. So the explorer reads the
raw events, groups them into visits with the same sessioniser as the
daily figures, and counts each visit's own path.

## Decisions

- **Page views only.** A step is a `page_view`. Other events (clicks,
  scrolls, forms) are read, because they keep a visit alive under the
  inactivity timeout and a segment may match on them, but they never
  become a step.
- **A reload is not a step.** Two page views of the same path in a row
  count once. Otherwise a reload reads as "went from /news to /news".
- **Order.** The sessioniser's order: the client's sequence for a visit
  with a session id, the client clock for a cookieless visit. A visit
  ends after the portal's inactivity timeout and at UTC midnight, the
  same cut the daily figures make.
- **Newest first under the cap.** Days are read from the end of the
  period backwards, so a capped result shows the most recent visits.
- **Five minutes of cache.** The folded paths of one portal, period and
  segment are cached for five minutes, so expanding a node or adding a
  step does not read the events again.

## Out of scope

- Paths over events other than page views (a "click" step).
- Paths across a roll-up portal's members.
- Keeping paths longer than the raw events' retention.
