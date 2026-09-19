# Tasks: a-report-without-an-account-and-a-custodian-who-may-reveal-it

## The door

- [x] **T01**: Add the `receipt` identity kind: a report accepted with no account and no address (REQ-RWA-001)
- [x] **T02**: Issue the code from a cryptographic source, show it once, store only its hash (REQ-RWA-002)
- [x] **T03**: Open a thread on a valid code; register every failed attempt with the throttler (REQ-RWA-002, REQ-RWA-003)

## The thread

- [x] **T04**: Two-way messages against the code, readable by the reporter and by the handler (REQ-RWA-003)
- [x] **T05**: Render the acknowledgement and feedback terms from the declaration, with where they stand (REQ-RWA-006)

## The identity and the reveal

- [x] **T06**: Store contact details apart from the report body, joined by reference and never returned with it (REQ-RWA-004)
- [x] **T07**: A motivated reveal request, answered by the custodian the declaration names (REQ-RWA-005)
- [x] **T08**: Record every reveal: asker, motivation, custodian, time, what was revealed; raise the reveal event (REQ-RWA-005)

## The hardening

- [x] **T09**: Exclude the reporting surface from visitor analytics by declaration, and record no client address against a report (REQ-RWA-007)
- [x] **T10**: Proof of work in front of the form, run here, with no call to a challenge vendor (REQ-RWA-007)

## Quality

- [x] **T11**: PHPUnit: a wrong code is throttled, the identity is absent from list, search and export, a non-custodian reveal is refused, a reveal without a motivation is refused
- [x] **T12**: Playwright `tests/e2e/a-report-without-an-account-and-a-custodian-who-may-reveal-it.spec.ts`: file a report with no account, keep the code, return with it, read the answer
- [x] **T13**: Dutch and English strings; docs; `openspec validate a-report-without-an-account-and-a-custodian-who-may-reveal-it --type change --strict`

## What shipped, and what is left for the case app

Shipped and covered: `portalReport`, `portalReporterContact`,
`portalReportMessage` and `portalRevealRequest`, the four schemas that keep the
report and the reporter apart; `ReportIntakeService`, which mints the receipt
code from `ISecureRandom`, stores only its SHA-256, and moves any contact field
the form asked for out of the answers into the separate record;
`ReportThreadService`, which opens a thread on a code and registers every
failed attempt with Nextcloud's own throttler; `ReportTermsService`, which
renders the declared terms and nothing else; `RevealService`, which refuses an
unmotivated request outright, refuses anybody outside the declared custodian
group including an administrator, records both the reveal and the refusal, and
raises `PortalReportRevealedEvent`; `ReportProjection`, the one function every
answer passes through; and `ReportController` with three anonymous portal
routes and four staff routes.

The reporting surface is kept out of visitor analytics by declaration:
`TrafficConfigResolver` reads `excludedPaths` and `TrafficIngestService`
refuses an event on one under `excluded-path`, with an ordinary page in the
same batch as the control. The challenge in front of the form is the portal's
own proof of work, so no request leaves for a vendor.

The `receipt` identity kind of T01 is the report's own code rather than an
entry in `PortalSessionService`: the code opens one report and nothing else,
so giving it a portal session would widen it. That is a deliberate narrowing of
T01, not a gap.

For the case app: declare `portalReportDeclaration` on the case type
(`custodianGroup`, `acknowledgementDays`, `feedbackDays`,
`excludeFromAnalytics`), and listen for `portal.report.revealed`. The portal
holds no term and no custodian of its own.

Left for a follow-up: the reporting form and the thread page are reachable over
the API and are not yet rendered by a portal page block. The Vue surface waits
on the same manifest work the other portal surfaces use.
