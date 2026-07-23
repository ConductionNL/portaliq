# Tasks: portal-notifications-dispatch

> Consume the manifest `notifications` rule keys → privacy-minimal email via a
> background job, per-attempt logging, and a failure fallback flag + metric.
> Implementation-ordered; small and verifiable.

## Implementation Tasks

- [ ] T01 — Add the `portalNotification` schema to `lib/Settings/portaliq_register.json` (`accountRef`, `ruleKey`, `channel`, `status`, `attempts`, `lastAttemptAt`; `publicRead:false`/`publicWrite:false`) and bump the register `version`.
- [ ] T02 — Create `lib/Service/NotificationDispatchService.php`: given a trigger (message-created / transition-succeeded), resolve the subject's manifest, match a declared `notifications` rule key (fail-closed on unknown/malformed), and enqueue a job via `OCP\BackgroundJob\IJobList` with a content-free payload.
- [ ] T03 — Create `lib/BackgroundJob/NotificationDispatchJob.php`: resolve `portalAccount`, skip when `email` is unset, render the privacy-minimal NL/EN template, and send via `OCP\Mail\IMailer`.
- [ ] T04 — Add NL + EN B1 email templates (subject + body: org name + deep link only; no content) with English i18n key names.
- [ ] T05 — Write/update the `portalNotification` record in the job with the outcome (`status`, incremented `attempts` on failure, `lastAttemptAt`).
- [ ] T06 — After N consecutive failures (configurable, small default) flag the `portalAccount` `needs-alternative-contact` in the job.
- [ ] T07 — Wire the triggers: call `NotificationDispatchService` from the `portalMessage` create path (SubmissionReceiptService + any message create) and the successful status-transition path in `ContributionController::update()`.
- [ ] T08 — Surface count-only `portaliq_notifications_failed_total` and `portaliq_accounts_needs_alt_contact` in `lib/Controller/MetricsController.php` (no recipient identity).
- [ ] T09 — Unit test `NotificationDispatchServiceTest`: matching key enqueues; no key / unknown key does not; no-email account is skipped; enqueue never sends inline.
- [ ] T10 — Unit test `NotificationDispatchJobTest`: content-free IMailer call; `portalNotification` sent/failed logging; N-failure fallback flag; metrics count-only.
- [ ] T11 — Add Playwright e2e: trigger a message, assert (via a mail catcher / stub) a content-free notification is dispatched and the inbox deep link resolves (closes the `@e2e exclude` markers on the dispatch scenarios).
- [ ] T12 — Document the notification dispatch behaviour, `portalNotification`, and the fallback flag in `README.md`; add the requirement to the canonical `openspec/specs/supplier-portal` spec on sync.
- [ ] T13 — Run `composer check:strict` and `npm run lint` green; run Hydra gates (spdx-headers, forbidden-patterns, stub-scan, notification-dialect, spec-coverage, e2e-coverage) — confirm no legacy notification dialect is emitted.
