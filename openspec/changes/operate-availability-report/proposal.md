---
kind: code
---

# Proposal: operate-availability-report

## Why

In a service level review the municipality asks: how available was the
portal over the past twelve months, month by month, and when was it down?
Portaliq can answer "is it up now" and nothing else. Whoever runs it has to
piece the answer together from logs.

The demand row, portaliq matrix, row `dem-tnd-availability-report`, "Report
the portal's measured availability over the past twelve months for a
service level review.", origin `tender`,
<https://www.tenderned.nl/aankondigingen/overzicht/415380>. The matrix
`originNote`, verbatim:

> TenderNed 415380 Sudwest-Fryslan: 'De leverancier kan inzicht geven (gedetailleerde meetgegevens/rapportages over de afgelopen 12 maanden) m.b.t. de beschikbaarheid ... klantenportaal'

Rated `no`, `built.state` `none`. Its `built.note`, verbatim:

> An external monitor can measure availability from the health and metrics endpoints, but portaliq keeps no availability history and produces no twelve-month report.

No competitor is rated `yes`. The `liferay-dxp` cell, `unknown`, verbatim:

> not settled from docs: https://learn.liferay.com/w/dxp/cloud/support-and-troubleshooting/troubleshooting-tools-and-resources/liferay-cloud-platform-status offers current status and 'Incident History'; https://learn.liferay.com/w/dxp/cloud states 'over 99.9% uptime' in aggregate. A measured twelve-month availability report per customer is not described.

The lane recorded the row as `build` on the tender rule.

## What changes

- **The portal measures itself every five minutes.** A background job
  checks each published portal the way a visitor would reach it, and
  records whether it answered, answered degraded, or failed.
- **A missed check counts as down.** If the job did not run, nothing
  answered. A gap is recorded as unavailable, never skipped.
- **Kept as daily totals for thirteen months.** One record per portal per
  day, and one record per outage longer than the check interval.
- **An availability report in the Reports hub.** Per portal: the
  availability per month for the last twelve months, the outages with start,
  end and duration, and how it was measured. It downloads as CSV.
- **Honest about what it measures.** The report says the measurement runs
  inside the installation, so an outage of the network in front of it is
  not seen. A hosting party's external monitor stays the reference for that.

## Rows this closes

| Matrix | Row id | Row name | Own rating | What is missing |
|---|---|---|---|---|
| portaliq | `dem-tnd-availability-report` | Report the portal's measured availability over the past twelve months for a service level review. | no | A stored availability history and a twelve-month report per portal. |

## Existing work it builds on

- `observability` (spec): `GET /api/health`, `GET /api/metrics`.
- `portal-traffic-reporting` (open) and the `Reports` hub page in
  `src/manifest.json`, which lists one card, Traffic, today.
- `portal-traffic-analytics` (open): the daily aggregate pattern
  (`portalTrafficDaily`) and retention this change follows.

## Out of scope

- Monitoring from outside the installation. The report names it as the
  complement, it does not replace it.
- Alerting when the portal goes down. The hosting party's monitor does that.
- Availability of the apps a portal reads from, beyond what the portal's own
  answer shows.
