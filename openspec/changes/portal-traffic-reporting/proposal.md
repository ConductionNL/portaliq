---
kind: code
---

# Proposal: portal-traffic-reporting

## Summary

Phase 4a of portal traffic analytics (phase 0: `portal-traffic-analytics`,
phase 1: `portal-traffic-visitors-and-geo`, phase 3:
`portal-traffic-outcomes`). The earlier phases count and judge; this one
lets a reader slice the figures, get them without opening the page, take
them elsewhere, and feed them from places a browser tag cannot reach.

1. **Segments.** A portal saves filters over visits in `traffic.segments`
   (a device type, a channel, a region, a campaign, whether a goal was
   met). The aggregation writes one extra daily record per segment, with
   `segment` set, beside the "all visits" record. The Traffic page gets a
   segment selector every widget follows, and a segment's record is
   deleted when the segment is.
2. **Roll-ups.** A portal with `traffic.rollupOf` sums the daily records
   of the portals it names, computed after theirs. It never has visitors
   of its own: the collector refuses its events. The page says "Roll-up
   of N portals" so a reader knows the visitors are a sum.
3. **Scheduled reports and alerts.** `traffic.reports` names reports
   (daily, weekly, monthly) sent by mail, HTML and plain text, with the
   period's figures against the period before and a link to the Traffic
   page; `traffic.alerts` names thresholds (above, below, percent change)
   that fire once per day or week. An hourly job decides what is due; a
   Nextcloud user also gets an in-app notification, rendered by this
   app's Notifier.
4. **Reporting API and export.** The daily records are documented as the
   read API, and `GET /api/traffic/export` (admin) downloads them as CSV
   (one row per portal-day-segment, the scalar metrics) or JSON. The
   Traffic page's Export button downloads exactly what the page shows.
5. **Server-side tracking and log import.** `POST /api/traffic/server`
   accepts the collector's envelope plus a `remoteAddress` and
   `userAgent` per batch or per event, guarded by a per-portal bearer
   token that `occ portaliq:traffic:token` mints and shows once (the
   portal keeps the hash). `occ portaliq:traffic:import-log` reads an
   Apache or Nginx access log (combined or JSON) as page views, skipping
   assets and bots, through the same ingest step a beacon takes.
6. **Script errors.** The client sends `js_error` (message, source file
   without its query string, line, column, stack hash; never the stack)
   when the portal enabled it; the rollup carries `errors[]` and a
   Script errors widget lists them.

## Decisions carried (Ruben, 2026-09-04)

- Decision 3 stays: everything is an object. A segment's day and a
  roll-up's day are `portalTrafficDaily` records like any other, so the
  export, the reports and the object API read one shape.
- Decision 2 stays: a segment or a report reads the day's sessions the
  sessioniser already derives; nothing here adds an identifier. A
  `visitorType` condition is only satisfiable where the portal already
  persists a client id.
- The server token is the only new secret, and it is shown once and
  stored hashed; the resolved traffic block the content API serves never
  carries it.

## Affected Projects

- [x] `portaliq`: the segment, roll-up, report, alert, export, token and
      log-import services, the job, the Notifier, the commands, the
      controllers, the schemas, the client, the widgets, the docs.
- [ ] `docusaurus-plugin-portaliq`: no change; the served client is the
      same file and `js_error` is on when the portal enables it.

## Out of scope

- Page A/B experiments, heatmaps, session recording (phase 4b).
- A UI for composing segments, reports and alerts beyond the portal's
  schema-driven form; the definitions are portal properties.
- Deduplicating a log imported twice; the command says so.
