---
kind: code
depends_on: [portal-task-delivery]
---

# Proposal: tasks-reminders-after-the-deadline

## Why

When the organisation asks a resident for a document, the resident gets a
reminder before the deadline. Once the deadline has passed, they hear nothing
more. The task list says "Overdue", but only if they open it.

The demand row, portaliq matrix, row `dem-tnd-reminders-overdue`, origin
`tender`, <https://www.tenderned.nl/aankondigingen/overzicht/409958>. The
matrix `originNote`, verbatim:

> TenderNed 409958: 'Indien de client niet tijdig de gevraagde documenten of informatie aanlevert, genereert de applicatie automatisch herinneringen ... via e-mail, brief en/of het inwonerportaal'

The row's `built.note`, verbatim:

> Automatic reminders exist, but they fire BEFORE the deadline (preBreach); once a task is actually overdue (slaBreached) the listener deliberately sends the party nothing and escalates inward. The resident sees 'Overdue' on the task list. Reminders only happen when a business timer is configured on the task.

No competitor in the matrix is rated `yes`. The `liferay-dxp` cell is
`partial`, verbatim:

> https://learn.liferay.com/w/dxp/low-code/workflow/designing-and-managing-workflows/workflow-designer/using-task-timers 'When a review task is idle for three days, the timer now reminds task assignees ... The reminder notification repeats daily'. A resident's outstanding request would have to be modelled as a workflow task assigned to them. [was unknown; custom: request-for-information task model]

The row is rated `partial` with `built.state` `built`. The lane recorded it
as `build` because a tender demand row says the missing half matters.

## What changes

- **A delivery kind for an overdue task.** The delivery job knows the kind
  `overdue` next to `ask`, `re-ask` and `reminder`, with its own inbox
  subject ("Your task is overdue: {title}") and its own body line ("This task
  was due on {date}."). Today an unknown kind falls back to the `ask` wording
  and would tell the resident they have a new task.
- **Mail that says what it is about.** The job's mail is the same for every
  kind today: "You have a new task in the portal of {organisation}". A
  reminder and an overdue notice each get their own subject and body, still
  carrying only the organisation name and the portal link.
- **Portaliq sends no reminder on a clock of its own.** How often, and for how
  long after the deadline, is the case app's business timer. Portaliq
  delivers what the ledger records.

## Rows this closes

| Matrix | Row id | Row name | Own rating | What is missing |
|---|---|---|---|---|
| portaliq | `dem-tnd-reminders-overdue` | Get automatic reminders when information or documents the organisation asked you for are overdue. | partial | A reminder to the resident after the deadline has passed, in the inbox and by mail, worded as overdue. |

## Existing work it builds on

- `portal-task-delivery` (open, this repo): the delivery job
  `lib/BackgroundJob/PortalTaskDeliveryJob.php`, the inbox message with its
  task deep link, and the "Mijn taken" page that already shows "Overdue".
  This change adds a kind and makes the mail kind-aware; it does not redo
  the job.
- `partner-tasks-in-the-portal` (open, this repo): the task seam the job
  drains.
- openregister `flow-portal-task` and `flow-business-timers` (open): the
  ledger and the rung ladder that decide when a reminder is due.

## Out of scope

- A paper letter. The tender names "brief"; the delivery ledger knows the
  channels `portal-inbox` and `mail` only
  (`lib/Db/PortalTaskDelivery.php` in openregister). A letter channel is a
  separate change with its own sibling (document generation and post).
- The inward escalation to the caseworker on `slaBreached`. It stays as it
  is.
- Choosing the cadence. The timer's rungs choose it.

## Sibling halves

- **ConductionNL/openregister** owes the event that makes this reachable.
  `lib/Listener/PortalTaskReminderListener.php:141-145` turns only a
  `preBreach` rung into a party delivery; a `slaBreached` rung "escalates
  inward" and, by the `flow-portal-task` requirement "The overdue path is
  consumed from flow-business-timers, never rebuilt", "the party MUST NOT
  receive it". Openregister owes a rung trigger after the deadline that is
  addressed to the party (for example `postBreach:<offset>:<unit>` in
  `flow-business-timers`), a `KIND_OVERDUE = 'overdue'` on
  `PortalTaskDelivery`, and the listener branch that records it. The
  `slaBreached` rule stays true. Not written here.
