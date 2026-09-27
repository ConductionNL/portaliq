# Design: operate-availability-report

Read at portaliq `development` `eeda3fa`.

## What exists

- `lib/Controller/HealthController.php:91-119` `index()`: `ok` with HTTP 200
  when OpenRegister is available, `degraded` with 503 when it is not, `error`
  with 500 on an exception. Point in time only.
- `appinfo/routes.php:61,63`: `/api/metrics` (admin) and `/api/health`
  (public).
- `appinfo/info.xml:81-94`: background jobs for task delivery, traffic
  aggregation, geography, traffic reports, intake delivery and push. None
  measures availability.
- `src/manifest.json` page `Reports` (`/reports`, type `reports`) with one
  card, `Traffic`.
- `grep -riE 'uptime|availability|beschikbaarheid' lib` finds nothing, per
  the matrix's `built.evidence`.

## D1. What "available" means here

A portal is available at a check when its public site presentation route
(`GET /api/content/site?portal=<slug>`) answers 200 and the health check
answers `ok`. It is degraded when the site answers but health says
`degraded`. It is down when the site does not answer 200 within five
seconds, or when no check ran in the interval.

The check calls the instance's own absolute URL through
`IClientService`, so it passes through the web server and PHP like a
visitor's request. It does not pass through anything in front of the
installation, and the report says so.

## D2. A gap is downtime

`lib/BackgroundJob/AvailabilityProbeJob.php`, a `TimedJob` every 300
seconds, records one check per published portal. The daily roll-up counts
the intervals in the day; an interval without a check is counted as down
with cause `no-check`. So a cron that stopped, or an instance that was off,
shows as unavailable rather than as a shorter day.

## D3. Daily totals and outages, in OpenRegister

Two schemas in `lib/Settings/portaliq_register.json`, both with `portal`:

- `portalAvailabilityDaily`: `portal`, `date`, `intervals`, `available`,
  `degraded`, `down`, `noCheck`. One per portal per day.
- `portalAvailabilityOutage`: `portal`, `startedAt`, `endedAt`,
  `durationMinutes`, `cause` (`site-error`, `timeout`, `health-degraded`,
  `no-check`). One per run of consecutive non-available intervals.

The job updates today's daily record and the open outage in place. Records
older than thirteen months are deleted by the same job, so a twelve-month
report is always complete and nothing is kept for ever.

## D4. The report

A card "Availability" in the `Reports` hub, opening a custom page
`AvailabilityReport` (`/reports/availability`). For a chosen portal:

- a table of the last twelve full months with availability as a
  percentage to two decimals, computed as `available / (intervals - planned)`,
  where `planned` is the intervals covered by a published maintenance notice
  of level `warning` from `operate-maintenance-notice` when that change has
  landed, and zero otherwise;
- the outages in that period with start, end, duration and cause;
- a paragraph "How this is measured" stating D1 and D2 in plain words;
- "Download as CSV".

Served by `GET /api/availability/{portal}?months=12`, admin-only like
`/api/metrics`.

## Risks

- **The measurement shares a failure with the thing it measures.** If PHP is
  down, the job does not run; D2 turns that into downtime rather than
  silence. What it cannot see is the network in front of the installation.
- **Self-calls through a proxy may fail for proxy reasons.** They count as
  down, which errs on the side the tender reader needs.

## What this deliberately does not do

- No public status page.
- No alerting.
