# Design: tasks-reminders-after-the-deadline

Read at portaliq `development` `eeda3fa` and openregister `development`.

## What happens today

Openregister records deliveries in a ledger; portaliq drains it.

- `openregister lib/Listener/PortalTaskReminderListener.php:133-162`:
  `remind()` records a `KIND_REMINDER` delivery only for a `preBreach` rung
  addressed to the external party. Any other trigger returns at line 145.
- `openregister lib/Db/PortalTaskDelivery.php:88-92`: the kinds are `ask`,
  `re-ask` and `reminder`; the channels are `portal-inbox` and `mail`.
- `lib/BackgroundJob/PortalTaskDeliveryJob.php:216-252` `settleRow()`
  dispatches on channel. `deliverInbox()` (line 268) writes a `portalMessage`
  with `subjectLine(kind)` (line 360) and `bodyText(message)` (line 374).
  `subjectLine()` falls back to the `ask` key for an unknown kind (line 361).
  `bodyText()` always says "Please finish this task before %1$s."
  (`BODY_DUE_KEY`, line 125), which reads wrong once the date has passed.
- `deliverMail(subjectRef)` (line 314) takes no kind and always sends
  `MAIL_SUBJECT_KEY` "You have a new task in the portal of %1$s" (line 135)
  and `MAIL_BODY_KEY` (line 137). A pre-deadline reminder already goes out
  as "You have a new task" by mail today.
- `src/portal/components/TasksPage.jsx:253-258` and `:384-389` show
  "Overdue" when the seam's task carries `overdue: true`.

## D1. `overdue` is its own kind, not a flag on `reminder`

A reminder before the deadline asks the resident to finish in time. A notice
after it tells them they are late, and may carry a consequence the case type
words. Two kinds keep the wording honest and let the caseworker's delivery
state say which one went out. `SUBJECT_KEYS` gains
`'overdue' => 'Your task is overdue: %1$s'`.

The job accepts the kind before openregister emits it. Until then nothing
changes for a resident; after it, nothing in portaliq has to land in the
same release.

## D2. An unknown kind is a failure, not an `ask`

`subjectLine()` falls back to `ask` for any kind it does not know. A future
kind would then reach the resident as "You have a new task". The fallback
becomes a `markFailed` with the reason `unknown delivery kind: <kind>`, the
same honesty `settleRow()` already applies to an unknown channel (line 236).

## D3. The body line follows the kind

`bodyText()` takes the kind. For `overdue` it writes "This task was due on
%1$s." and, when the engine's message carries a `consequence` descriptor,
"If you do not respond: %1$s". For every other kind it keeps
`BODY_DUE_KEY`. No case data enters the body beyond what the engine already
renders into `message` today.

## D4. The mail names its kind and nothing else

`deliverMail()` takes the row's kind. Subject and body keys per kind:

| Kind | Subject | Body |
|---|---|---|
| `ask`, `re-ask` | "You have a new task in the portal of %1$s" (unchanged) | unchanged |
| `reminder` | "Reminder: you have an open task in the portal of %1$s" | "You have an open task in the portal of %1$s. Log in to finish it: %2$s" |
| `overdue` | "Your task in the portal of %1$s is overdue" | "A task in the portal of %1$s is past its deadline. Log in to finish it: %2$s" |

Still organisation name and link only, the privacy-minimal posture the
`portal-task-delivery` spec requires. Dutch in `l10n/nl.json`, bilingual as
`bilingual()` (line 427) already composes it.

## D5. No clock in portaliq

The job drains the ledger every 300 seconds (`INTERVAL`, line 110). It never
decides that a task is overdue and never sends a second notice on its own.
How many overdue notices, and how far apart, is the rung ladder in
`flow-business-timers`.

## Risks

- **Openregister never emits `overdue`.** Then this change is dormant but
  harmless, and D2 and D4 still fix the mislabelled reminder mail. The
  sibling half is named in the proposal.
- **A resident is nagged.** The cadence is the case type's. Note that
  `deliverMail()` does not read any mail preference today (no opt-out check
  in `PortalTaskDeliveryJob.php`); whether a task mail should honour the
  opt-out from `notification-preferences-per-role` is left to that change and
  is not decided here.

## What this deliberately does not do

- No letter channel.
- No change to the inward `slaBreached` escalation.
- No new schema: `portalMessage` already carries `taskUuid` and
  `deliveryUuid`.
