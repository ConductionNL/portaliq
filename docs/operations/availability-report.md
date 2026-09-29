---
title: Availability report
sidebar_label: Availability report
description: How the portal measures its own availability, what the twelve-month report shows, and what it cannot see
---

# Availability report

A service level review asks how available the portal was over the past twelve months, month by month, and when it was down. The portal measures that itself and keeps the answer.

## Open the report

Open **Reports** and choose **Availability**. Pick a portal. You see:

- the availability per month for the last twelve full months, as a percentage to two decimals;
- every outage in that period, with its start, end, duration and cause;
- how it was measured.

**Download as CSV** gives the same figures as a file. Only administrators can open the report.

## How the portal measures itself

Every five minutes a background job opens each published portal's public site, through this installation's own web address, the way a visitor would.

| What the check found | Counts as |
|---|---|
| The site answered within five seconds and the health check said ok | Available |
| The site answered, but the health check did not say ok | Degraded |
| The site answered with an error | Down, cause "The portal answered with an error" |
| The site did not answer within five seconds | Down, cause "The portal did not answer within five seconds" |
| No check ran in that interval | Down, cause "No check ran" |

An interval without a check counts as down. So a stopped cron job, or an installation that was switched off, shows as unavailable, never as a shorter day.

The monthly figure is the available intervals divided by all intervals of that month. Degraded intervals are not counted as available. A month in which nothing was measured reads "Not measured". No maintenance window is subtracted.

A run of intervals that were not available is one outage. It keeps the cause of its first interval and ends at the first check that finds the portal available again.

## What it cannot see

The check runs inside the installation. It does not pass through the network, load balancer or proxy in front of it, so an outage there is not seen. The hosting party's own external monitor stays the reference for that part. Use both in a service level review.

## How long it is kept

The daily figures and the outages are kept for thirteen months, so a twelve-month report is always complete. Older records are deleted by the same job.

## For operators

- Background job: `OCA\Portaliq\BackgroundJob\AvailabilityProbeJob`, every five minutes. It needs Nextcloud's cron to run.
- Records: `portalAvailabilityDaily` and `portalAvailabilityOutage` in the `portaliq` register, readable by administrators only.
- Routes: `GET /apps/portaliq/api/availability/{portal}` and `GET /apps/portaliq/api/availability/{portal}/export`, both admin-only.
