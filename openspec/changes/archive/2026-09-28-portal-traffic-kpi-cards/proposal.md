---
kind: code
---

# Proposal: portal-traffic-kpi-cards

## Summary

A portal's own page says nothing about its visitors today. You open the
Traffic page, pick the portal again, and read four numbers in one shared
card. This change moves those four numbers into four KPI cards and puts
them where you look first.

1. **Portal page.** Four KPI cards head the portal detail page: page
   views, sessions, visitors and engaged sessions. Each card has its own
   period picker (7, 30, 90 or 365 days, 30 by default). A card opens the
   Traffic page with this portal already selected. A portal with
   measurement off reads "Not measured" on every card, never a zero.
2. **Traffic page.** The four numbers leave the overview card and become
   four KPI cards of their own. The overview keeps the portal, period and
   segment selectors, the Export button and the notes. The cards follow
   those selectors, so the page still has one period.
3. **Summary endpoint.** `GET /api/traffic/summary?portal=&days=` folds a
   portal's "all visits" daily records into the four totals, with the
   same arithmetic as the Traffic page and the scheduled report. Admin
   only, like the export.

## Why not a stats-block over `portalTrafficDaily`

The daily records hold one row per segment beside the "all visits" row,
so a plain sum counts a segmented visit twice. And a sum answers zero for
a portal that never measured, which the traffic spec forbids.
