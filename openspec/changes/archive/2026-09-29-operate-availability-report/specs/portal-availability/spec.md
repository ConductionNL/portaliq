---
status: proposed
---

# Spec: portal-availability

## Purpose

The organisation can show how available each portal was over the past
twelve months, month by month, with every outage, measured by the portal
itself. Portaliq matrix row `dem-tnd-availability-report` (TenderNed 415380).

## ADDED Requirements

### Requirement: Each published portal is checked every five minutes (REQ-OAR-001)

A background job SHALL check every published portal every 300 seconds by
calling its public site route through the instance's own URL, and SHALL
record the result as available, degraded or down.

#### Scenario: A failing portal is recorded as down
- **GIVEN** a published portal whose site route answers 500
- **WHEN** the check runs
- **THEN** today's record for that portal counts one more down interval and an outage is open with cause `site-error`
- @e2e exclude Background job; pinned by AvailabilityProbeJobTest with a stubbed HTTP client

### Requirement: An interval without a check counts as down (REQ-OAR-002)

The daily roll-up SHALL count every interval of the day. An interval in
which no check ran SHALL count as down with cause `no-check`.

#### Scenario: Cron stopped for an hour
- **GIVEN** no check ran between 03:00 and 04:00
- **WHEN** the day is rolled up
- **THEN** twelve intervals are counted as down with cause `no-check` and one outage of 60 minutes is recorded
- @e2e exclude Roll-up arithmetic; pinned by AvailabilityRollupTest

### Requirement: Thirteen months are kept, and no more (REQ-OAR-003)

Daily records and outages SHALL be kept for thirteen months and deleted
after that.

#### Scenario: An old record is removed
- **GIVEN** a daily record fourteen months old
- **WHEN** the job runs
- **THEN** the record no longer exists
- @e2e exclude Retention; pinned by AvailabilityProbeJobTest

### Requirement: An administrator reads a twelve-month report (REQ-OAR-004)

The Reports hub SHALL offer "Availability". For a chosen portal it SHALL show
the availability per month for the last twelve full months, every outage
with start, end, duration and cause, and a paragraph that says how it was
measured, and SHALL let the administrator download it as CSV. Only
administrators SHALL reach the report and its route.

#### Scenario: An administrator prepares a service level review
- **GIVEN** an administrator and a portal with twelve months of records
- **WHEN** they open Reports, choose "Availability" and pick the portal
- **THEN** they see twelve monthly percentages, the outages, and "How this is measured", and can download the CSV
- e2e: `tests/e2e/operate-availability-report.spec.ts`

#### Scenario: A non-administrator cannot read it
- **GIVEN** a signed-in user who is not an administrator
- **WHEN** they call `GET /api/availability/{portal}`
- **THEN** the request is refused
- @e2e exclude Route authorization; pinned by AvailabilityControllerTest
