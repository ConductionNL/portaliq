---
kind: code
---

# Proposal: portal-page-traffic

## Summary

A portal page's own detail page says nothing about its visitors. You
open the Traffic page, find the page in the top-pages list, and still
cannot see where its visitors came from or where they went. This change
puts that on the page.

1. **Four KPI cards.** The page detail opens with page views, sessions,
   visitors and engaged sessions for this one page. Each card has its own
   period picker (7, 30, 90 or 365 days, 30 by default) and opens the
   Traffic page with the page's portal selected. The same widget as the
   portal page's cards, with a page scope.
2. **Incoming traffic.** The previous pages within the portal, the
   sessions that entered the portal on this page, and the sites and
   channels that brought them.
3. **Outgoing traffic.** The next pages, the sessions that left the
   portal here, and the outbound links clicked on this page.
4. **Richer daily page rows.** Each row of `portalTrafficDaily.pages`
   gains `sessions`, `visitors`, `engagedSessions`, and its own top ten
   `referrers` and `outbound` links.
5. **Back-fill.** The aggregation job re-aggregates every day whose raw
   events are still retained, once, so the new fields exist for the whole
   retention window and not only from the deploy on.
6. **Page endpoint.** `GET /api/traffic/page?portal=&route=&days=` folds
   one page's rows over a period. Admin only, like the summary.

## The page and its traffic meet on the in-site route

A page is served at its `route` within its portal (`CmsReader::page`
matches the stored route exactly, with a leading slash; the home page is
`/`). The built-in site keeps that route in the `route` query parameter
(`src/site/App.vue`, `routeFromLocation` and `go`), and the collector
stores only the URL path with the query stripped
(`ReferrerClassifier::path`). So on the built-in site every page was
counted as one path, `/index.php/apps/portaliq/site`.

The daily page rows therefore count a view under its in-site route: the
`route` query parameter when the location carries one, else the stored
path, with a trailing slash dropped. That is the rule the traffic client
already uses for experiments (`siteRoute` in `src/traffic/helpers.js`).
A site on its own domain that serves a page at its route matches as it
did. A renderer that prefixes a base path or a locale does not match,
and this change does not guess one.

## Days whose raw events are gone

Raw events are kept `retentionDays` (default 90). A day older than that
keeps its old row, which has no per-page sessions, visitors, referrers
or outbound links. The page endpoint says how many days carried those
figures, and the cards and the widget say "Not available for this
period" instead of a zero. Page views, entrances, exits and the
previous and next pages come from fields every row already had.

A back-filled day is rewritten only when the recomputed record counts at
least the events and page views the stored one did. A day at the edge of
retention, with part of its events already purged, keeps its complete
old row.

## Limits

- A page outside a day's top 100 pages has no row that day, as on the
  Traffic page.
- The previous and next pages come from the day's top 100 transitions.
- Per-page referrers and outbound links are the top ten per page per day.
- Visitors add up across days, like the portal summary: one visitor on
  two days counts twice.
