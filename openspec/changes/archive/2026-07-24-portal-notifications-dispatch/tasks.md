# Tasks: portal-notifications-dispatch

> Consume the manifest `notifications` rule keys → privacy-minimal email via a
> background job, per-attempt logging, and a failure fallback flag + metric.
> Implementation-ordered; small and verifiable.

## Implementation Tasks

- [x] T01 — Add the `portalNotification` schema to `lib/Settings/portaliq_register.json` (`accountRef`, `ruleKey`, `channel`, `status`, `attempts`, `lastAttemptAt`; `publicRead:false`/`publicWrite:false`) and bump the register `version`.
- [x] T02 — Create `lib/Service/NotificationDispatchService.php`: given a trigger (message-created / transition-succeeded), resolve the subject's manifest, match a declared `notifications` rule key (fail-closed on unknown/malformed), and enqueue a job via `OCP\BackgroundJob\IJobList` with a content-free payload.
- [x] T03 — Create `lib/BackgroundJob/NotificationDispatchJob.php`: resolve `portalAccount`, skip when `email` is unset, render the privacy-minimal NL/EN template, and send via `OCP\Mail\IMailer`.
- [x] T04 — Add NL + EN B1 email templates (subject + body: org name + deep link only; no content) with English i18n key names.
- [x] T05 — Write/update the `portalNotification` record in the job with the outcome (`status`, incremented `attempts` on failure, `lastAttemptAt`).
- [x] T06 — After N consecutive failures (configurable, small default) flag the `portalAccount` `needs-alternative-contact` in the job.
- [x] T07 — Wire the triggers: call `NotificationDispatchService` from the `portalMessage` create path (SubmissionReceiptService + any message create) and the successful status-transition path in `ContributionController::update()`.
- [x] T08 — Surface count-only `portaliq_notifications_failed_total` and `portaliq_accounts_needs_alt_contact` in `lib/Controller/MetricsController.php` (no recipient identity).
- [x] T09 — Unit test `NotificationDispatchServiceTest`: matching key enqueues; no key / unknown key does not; no-email account is skipped; enqueue never sends inline.
- [x] T10 — Unit test `NotificationDispatchJobTest`: content-free IMailer call; `portalNotification` sent/failed logging; N-failure fallback flag; metrics count-only.
- [x] T11 — Add Playwright e2e: trigger a message, assert (via a mail catcher / stub) a content-free notification is dispatched and the inbox deep link resolves (closes the `@e2e exclude` markers on the dispatch scenarios). DEVIATION: written but NOT live-run (no live instance + mail-catcher available in this apply pass); the spec file honestly documents this and scopes to what IS Playwright-observable (deep link resolves; request never blocked by dispatch). Email content/delivery stays PHPUnit-covered (`NotificationDispatchJobTest`) — see the file's own header comment.
- [x] T12 — Document the notification dispatch behaviour, `portalNotification`, and the fallback flag in `README.md`; add the requirement to the canonical `openspec/specs/supplier-portal` spec on sync.
- [x] T13 — Run `composer check:strict` and `npm run lint` green; run Hydra gates (spdx-headers, forbidden-patterns, stub-scan, notification-dialect, spec-coverage, e2e-coverage) — confirm no legacy notification dialect is emitted. DEVIATION: the Hydra gate script (scripts/run-hydra-gates.sh) was not available in this sandbox — verified the individual gate conditions manually (SPDX headers present, no forbidden patterns, no stub markers, no legacy notification dialect keys in the register JSON, @spec tags on every changed method) instead of running the aggregate script.
